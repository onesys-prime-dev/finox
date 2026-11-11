<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\PrintJob;
use App\Models\PrintLog;
use App\Models\PrintTemplate;
use App\Models\Receipt;
use App\Services\PrintingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PrintingController extends Controller
{
    public function __construct(private PrintingService $service) {}

    /** Print queue: eligible records, awaiting confirmation, failed prints. */
    public function index(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('print.print'), 403);
        $category = $request->category === 'receipt' ? 'receipt' : 'cheque';

        $ready = $category === 'cheque'
            ? Cheque::with('payee:id,name', 'bankAccount.bank:id,name', 'bankAccount.template:id,is_active')
                ->where('status', 'prepared')
                ->when($request->bank_account_id, fn ($q, $v) => $q->where('bank_account_id', $v))
                ->orderBy('cheque_date')->orderBy('id')->limit(300)->get()
                ->map(fn ($c) => [
                    'id' => $c->id, 'template_id' => $c->bankAccount?->print_template_id, 'status' => $c->status->value, 'label' => $c->cheque_number, 'party' => $c->printed_payee, 'amount' => (string) $c->amount,
                    'date' => $c->cheque_date->toDateString(), 'bank' => $c->bankAccount?->bank?->name,
                    'blocked' => $c->bankAccount?->template?->is_active ? null : 'No active template on bank account',
                ])
            : Receipt::with('payee:id,name')->whereIn('status', ['recorded', 'verified', 'processed'])
                ->orderByDesc('id')->limit(300)->get()
                ->map(fn ($r) => [
                    'id' => $r->id, 'status' => $r->status->value, 'label' => $r->receipt_number ?: '#'.$r->id, 'party' => $r->payee?->name, 'amount' => (string) $r->amount,
                    'date' => $r->receipt_date?->toDateString(), 'bank' => null, 'blocked' => null,
                ]);

        // A specific record can be pulled in for a reprint (e.g. from the cheque page).
        $pick = collect(explode(',', (string) $request->select))->filter()->map(fn ($v) => (int) $v)->all();
        if ($pick && $category === 'cheque' && $request->user()->hasPermission('print.reprint')) {
            $extra = Cheque::with('payee:id,name', 'bankAccount.bank:id,name', 'bankAccount.template:id,is_active')
                ->whereIn('id', array_diff($pick, $ready->pluck('id')->all()))->whereIn('status', ['printed', 'released', 'for_clearing'])->get()
                ->map(fn ($c) => [
                    'id' => $c->id, 'template_id' => $c->bankAccount?->print_template_id, 'status' => $c->status->value, 'label' => $c->cheque_number, 'party' => $c->printed_payee, 'amount' => (string) $c->amount,
                    'date' => $c->cheque_date->toDateString(), 'bank' => $c->bankAccount?->bank?->name,
                    'blocked' => $c->bankAccount?->template?->is_active ? null : 'No active template on bank account',
                ]);
            $ready = $ready->concat($extra)->values();
        }

        $jobBase = PrintJob::with('user:id,name', 'logs');
        $mine = fn ($q) => $request->user()->isAdmin() ? $q : $q->where('user_id', $request->user()->id);

        return Inertia::render('Printing/Index', [
            'category' => $category,
            'ready' => $ready,
            'awaiting' => $mine((clone $jobBase)->where('status', 'initiated'))->latest('id')->get()->map(fn ($j) => $this->job($j))->values(),
            'failed' => (clone $jobBase)->whereIn('status', ['failed', 'partial'])->latest('id')->limit(10)->get()->map(fn ($j) => $this->job($j))->values(),
            'accounts' => BankAccount::with('bank:id,name')->where('is_active', true)->get()->map(fn ($a) => ['id' => $a->id, 'label' => $a->display_name]),
            'bankAccountId' => $request->bank_account_id,
            'canReprint' => $request->user()->hasPermission('print.reprint'),
            'canCalibrate' => $request->user()->hasPermission('print.calibrate'),
        ]);
    }

    /** Validate selection and return layouts + values for the browser to render and print. */
    public function preview(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('print.print'), 403);
        $data = $request->validate([
            'category' => 'required|in:cheque,receipt',
            'ids' => 'required|array|min:1|max:100', 'ids.*' => 'integer',
            'reprint' => 'boolean',
        ]);
        $records = $this->service->load($data['category'], $data['ids']);
        $reprint = (bool) ($data['reprint'] ?? false);
        if ($reprint) {
            abort_unless($request->user()->hasPermission('print.reprint'), 403);
        }
        $check = $this->service->eligibility($data['category'], $records, $reprint, $request->user());
        if (! $check['ok']) {
            return response()->json(['ok' => false, 'failures' => array_values($check['failures'])], 422);
        }

        return response()->json([
            'ok' => true,
            'pages' => $records->map(fn ($r) => [
                'id' => $r->id,
                'label' => $r instanceof Cheque ? $r->cheque_number : ($r->receipt_number ?: '#'.$r->id),
                'template' => $this->service->templatePayload($this->service->templateFor($r)),
                'values' => $this->service->values($r, $request->user()),
                'warn_printed' => $r instanceof Cheque && $r->print_count > 0,
            ])->values(),
        ]);
    }

    public function templates(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('print.calibrate') || $request->user()->hasPermission('templates.manage'), 403);

        return response()->json(PrintTemplate::where('is_active', true)->orderBy('name')->get(['id', 'name', 'kind']));
    }

    public function start(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission('print.print'), 403);
        $data = $request->validate([
            'category' => 'required|in:cheque,receipt',
            'ids' => 'required|array|min:1|max:100', 'ids.*' => 'integer',
            'reprint' => 'boolean', 'reason' => 'nullable|string|max:500',
            'settings' => 'nullable|array',
        ]);
        if (! empty($data['reprint'])) {
            abort_unless($request->user()->hasPermission('print.reprint'), 403);
        }
        $job = $this->service->start($request->user(), $data['category'], $data['ids'], (bool) ($data['reprint'] ?? false), $data['reason'] ?? null, $data['settings'] ?? []);

        return response()->json(['job_id' => $job->id]);
    }

    public function confirm(Request $request, PrintJob $job): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('confirm', $job);
        $data = $request->validate(['failed_ids' => 'nullable|array', 'failed_ids.*' => 'integer', 'note' => 'nullable|string|max:1000']);
        $this->service->confirm($job, $request->user(), $data['failed_ids'] ?? [], $data['note'] ?? null);

        return back()->with(empty($data['failed_ids']) ? 'success' : 'warning',
            empty($data['failed_ids']) ? 'Print confirmed.' : 'Problem recorded. Reprint the affected items from the queue.');
    }

    public function history(Request $request): Response
    {
        abort_unless($request->user()->hasPermission('print.print') || $request->user()->hasPermission('reports.printing') || $request->user()->hasPermission('reports.view'), 403);
        $logs = PrintLog::with('user:id,name', 'job')->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->reprint, fn ($q) => $q->where('is_reprint', true))
            ->latest('id')->paginate(30)->withQueryString()
            ->through(function ($l) {
                $rec = $l->printable_type === 'cheque' ? Cheque::find($l->printable_id) : Receipt::find($l->printable_id);

                return [
                    'id' => $l->id, 'job_id' => $l->print_job_id, 'type' => $l->printable_type,
                    'label' => $l->printable_type === 'cheque' ? 'Cheque '.($rec?->cheque_number) : 'Receipt '.($rec?->receipt_number ?: '#'.$l->printable_id),
                    'url' => $rec ? route($l->printable_type === 'cheque' ? 'cheques.show' : 'receipts.show', $rec) : null,
                    'status' => $l->status, 'is_reprint' => $l->is_reprint, 'reason' => $l->job?->reason, 'note' => $l->note,
                    'user' => $l->user?->name, 'at' => $l->created_at->toDateTimeString(),
                ];
            });

        return Inertia::render('Printing/History', ['logs' => $logs, 'filters' => $request->only('status', 'reprint')]);
    }

    /** Calibration: template + sample values; never logged as a real print. */
    public function calibrate(Request $request, PrintTemplate $template): Response
    {
        abort_unless($request->user()->hasPermission('print.calibrate') || $request->user()->hasPermission('templates.manage'), 403);

        return Inertia::render('Printing/Calibrate', [
            'template' => $this->service->templatePayload($template),
            'sample' => $template->kind === 'cheque' ? [
                'payee_line' => 'SAMPLE PAYEE NAME CORPORATION', 'amount_number' => '1,234,567.89',
                'amount_words' => 'ONE MILLION TWO HUNDRED THIRTY FOUR THOUSAND FIVE HUNDRED SIXTY SEVEN AND 89/100 PESOS ONLY',
                'date' => now()->toDateString(), 'check_no' => '0000000000', 'purpose' => 'SAMPLE PURPOSE', 'reference' => 'REF-0001',
                'prepared_by' => $request->user()->name,
            ] : [
                'org_name' => \App\Support\Settings::get('org_name'), 'org_address' => \App\Support\Settings::get('org_address'),
                'receipt_no' => 'SAMPLE-001', 'date' => now()->toDateString(), 'vendor' => 'Sample Vendor Inc.',
                'description' => 'Sample description', 'amount_number' => '1,234.56', 'tax' => '₱0.00', 'prepared_by' => $request->user()->name,
            ],
            'globalOffset' => ['x' => (float) \App\Support\Settings::get('print_offset_x_mm', 0), 'y' => (float) \App\Support\Settings::get('print_offset_y_mm', 0)],
        ]);
    }

    private function job(PrintJob $j): array
    {
        return [
            'id' => $j->id, 'category' => $j->category, 'status' => $j->status, 'is_reprint' => $j->is_reprint,
            'user' => $j->user?->name, 'count' => $j->item_count, 'at' => $j->created_at->toDateTimeString(), 'note' => $j->note,
            'items' => $j->logs->map(function ($l) {
                $rec = $l->printable_type === 'cheque' ? Cheque::find($l->printable_id) : Receipt::find($l->printable_id);

                return ['id' => $l->printable_id, 'label' => $l->printable_type === 'cheque' ? ($rec?->cheque_number) : ($rec?->receipt_number ?: '#'.$l->printable_id), 'status' => $l->status];
            })->values(),
        ];
    }
}
