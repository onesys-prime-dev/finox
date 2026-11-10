<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\PrintJob;
use App\Models\PrintTemplate;
use App\Models\Receipt;
use App\Models\User;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $u = $request->user();
        $from = $request->from ?: null;
        $to = $request->to ?: null;
        $bank = $request->bank_account_id ?: null;

        $filtered = (bool) ($from || $to || $bank);
        $key = 'finox.dash.'.$u->id.'.'.md5(json_encode([$from, $to, $bank]));
        $stats = Cache::remember($key, $filtered ? 30 : 120, fn () => $this->stats($u, $from, $to, $bank));

        return Inertia::render('Dashboard', [
            'queues' => $this->queues($u),
            'stats' => $stats,
            'recent' => $this->recent($u),
            'filters' => ['from' => $from, 'to' => $to, 'bank_account_id' => $bank],
            'accounts' => BankAccount::with('bank:id,name')->get()->map(fn ($a) => ['id' => $a->id, 'label' => $a->display_name]),
            'alerts' => $u->isAdmin() ? $this->adminAlerts() : [],
        ]);
    }

    /** Work queues: what needs doing next for this user's role. */
    private function queues(User $u): array
    {
        $q = [];
        $add = function (string $label, int $count, string $url, string $tone = 'neutral') use (&$q) {
            $q[] = compact('label', 'count', 'url', 'tone');
        };
        $cheques = fn () => Cheque::query()->visibleTo($u);

        if ($u->hasPermission('cheques.create')) {
            $add('My drafts', Cheque::where('created_by', $u->id)->where('status', 'draft')->count()
                + Receipt::where('created_by', $u->id)->where('status', 'draft')->count(), route('cheques.index', ['status' => 'draft', 'mine' => 1]));
        }
        if ($u->hasPermission('cheques.prepare')) {
            $add('To prepare', $cheques()->where('status', 'draft')->count(), route('cheques.index', ['status' => 'draft']), 'info');
        }
        if ($u->hasPermission('receipts.verify')) {
            $add('To verify', Receipt::where('status', 'recorded')->count(), route('receipts.index', ['status' => 'recorded']), 'info');
        }
        if ($u->hasPermission('print.print')) {
            $add('Ready to print', Cheque::where('status', 'prepared')->count(), route('printing.index'), 'purple');
            $add('Awaiting confirmation', PrintJob::where('status', 'initiated')->when(! $u->isAdmin(), fn ($x) => $x->where('user_id', $u->id))->count(), route('printing.index'), 'warn');
            if (Settings::get('notify_failed_prints', true)) {
                $add('Failed prints', PrintJob::whereIn('status', ['failed', 'partial'])->count(), route('printing.history', ['status' => 'failed']), 'danger');
            }
        }
        if ($u->hasPermission('cheques.release')) {
            $add('To release', Cheque::where('status', 'printed')->count(), route('cheques.index', ['status' => 'printed']), 'warn');
        }
        if ($u->hasPermission('cheques.clear')) {
            $add('For clearing', Cheque::whereIn('status', ['released', 'for_clearing'])->count(), route('cheques.index', ['status' => 'outstanding']), 'warn');
            if (Settings::get('notify_stale_cheques', true)) {
                $add('Stale review', Cheque::whereNotNull('stale_flagged_at')->whereIn('status', ['released', 'for_clearing'])->count(), route('cheques.index', ['flagged' => 1]), 'danger');
            }
        }

        return $q;
    }

    private function stats(User $u, ?string $from, ?string $to, ?string $bank): array
    {
        $out = ['cheques' => null, 'receipts' => null, 'recent' => []];

        if ($u->hasPermission('cheques.view_all') || $u->hasPermission('cheques.view')) {
            $base = fn () => Cheque::query()->visibleTo($u)
                ->when($from, fn ($q) => $q->whereDate('cheque_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('cheque_date', '<=', $to))
                ->when($bank, fn ($q) => $q->where('bank_account_id', $bank));
            $counts = $base()->selectRaw('status, count(*) c, sum(amount) s')->groupBy('status')->get()->keyBy(fn ($r) => $r->status->value);
            $by = fn ($s) => (int) ($counts[$s]->c ?? 0);
            $out['cheques'] = [
                'total' => (int) $counts->sum('c'),
                'by_status' => collect(\App\Enums\ChequeStatus::cases())->map(fn ($s) => ['status' => $s->value, 'label' => $s->label(), 'count' => $by($s->value)])->values(),
                'outstanding_count' => $by('released') + $by('for_clearing'),
                'outstanding_amount' => Money::sum([$counts['released']->s ?? 0, $counts['for_clearing']->s ?? 0]),
                'total_amount' => Money::sum($counts->where(fn ($r, $k) => ! in_array($k, ['cancelled', 'voided']))->pluck('s')),
            ];
        }
        if ($u->hasPermission('receipts.view_all') || $u->hasPermission('receipts.view')) {
            $base = fn () => Receipt::query()->visibleTo($u)
                ->when($from, fn ($q) => $q->whereDate('receipt_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('receipt_date', '<=', $to));
            $counts = $base()->selectRaw('status, count(*) c, sum(amount) s')->groupBy('status')->get()->keyBy(fn ($r) => $r->status->value);
            $out['receipts'] = [
                'total' => (int) $counts->sum('c'),
                'by_status' => collect(\App\Enums\ReceiptStatus::cases())->map(fn ($s) => ['status' => $s->value, 'label' => $s->label(), 'count' => (int) ($counts[$s->value]->c ?? 0)])->values(),
                'total_amount' => Money::sum($counts->where(fn ($r, $k) => $k !== 'cancelled' && $k !== 'draft')->pluck('s')),
            ];
        }

        return $out;
    }

    private function recent(User $u): array
    {
        $log = [];
        if ($u->hasPermission('activity.view')) {
            $log = ActivityLog::with('user:id,name')->latest('id')->limit(8)->get()->map(fn ($l) => [
                'id' => $l->id, 'text' => $l->description, 'user' => $l->user?->name, 'module' => $l->module, 'at' => $l->created_at->toIso8601String(),
            ])->all();
        }
        $tx = [];
        if ($u->hasPermission('register.view')) {
            $tx = (new \App\Services\RegisterQuery)->recent(6)->map(fn ($t) => (array) $t)->all();
        }

        return ['activity' => $log, 'transactions' => $tx];
    }

    private function adminAlerts(): array
    {
        $a = [];
        if ($n = User::where('is_active', false)->count()) {
            $a[] = ['tone' => 'neutral', 'text' => "{$n} inactive user account(s)", 'url' => route('admin.users.index')];
        }
        $accs = BankAccount::where('is_active', true)->whereNull('print_template_id')->count();
        if ($accs) {
            $a[] = ['tone' => 'warn', 'text' => "{$accs} bank account(s) have no print template", 'url' => route('admin.banks.index')];
        }
        if ($n = \DB::table('failed_jobs')->count()) {
            $a[] = ['tone' => 'danger', 'text' => "{$n} failed background job(s)", 'url' => null];
        }
        if ($n = \App\Models\ProblemReport::where('is_resolved', false)->count()) {
            $a[] = ['tone' => 'warn', 'text' => "{$n} open problem report(s)", 'url' => route('admin.problems.index')];
        }

        return $a;
    }
}
