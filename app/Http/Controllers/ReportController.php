<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\DocumentFile;
use App\Models\PrintLog;
use App\Models\Receipt;
use App\Services\ActivityLogService;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /** key => [title, group, permission] */
    private const REPORTS = [
        'cheque-register' => ['Cheque Register', 'Cheques', 'reports.view'],
        'cheques-daily' => ['Daily Cheque Report', 'Cheques', 'reports.view'],
        'cheques-monthly' => ['Monthly Cheque Report', 'Cheques', 'reports.view'],
        'cheques-outstanding' => ['Outstanding Cheques', 'Cheques', 'reports.view'],
        'cheques-released' => ['Released Cheques', 'Cheques', 'reports.view'],
        'cheques-cleared' => ['Cleared Cheques', 'Cheques', 'reports.view'],
        'cheques-bounced' => ['Bounced Cheques', 'Cheques', 'reports.view'],
        'cheques-stopped' => ['Cancelled & Voided Cheques', 'Cheques', 'reports.view'],
        'receipt-register' => ['Receipt Register', 'Receipts', 'reports.view'],
        'receipts-daily' => ['Daily Receipt Report', 'Receipts', 'reports.view'],
        'receipts-monthly' => ['Monthly Receipt Report', 'Receipts', 'reports.view'],
        'receipts-category' => ['Receipts by Category', 'Receipts', 'reports.view'],
        'transaction-summary' => ['Transaction Summary', 'System', 'reports.view'],
        'printing-history' => ['Printing History', 'System', 'reports.printing'],
        'user-activity' => ['User Activity', 'System', 'activity.view'],
        'document-uploads' => ['Document Uploads', 'System', 'reports.view'],
    ];

    public function index(Request $request): Response
    {
        $u = $request->user();
        $list = collect(self::REPORTS)->filter(fn ($r) => $this->allowed($u, $r[2]))
            ->map(fn ($r, $k) => ['key' => $k, 'title' => $r[0], 'group' => $r[1]])->values();
        abort_if($list->isEmpty(), 403);

        return Inertia::render('Reports/Index', ['reports' => $list]);
    }

    public function show(Request $request, string $report): Response
    {
        [$title, $columns, $rows, $summary] = $this->build($request, $report);

        // Screens never ship tens of thousands of rows; the summary above is computed on the full set,
        // and CSV export (or "show all") returns everything.
        $total = count($rows);
        $cap = $request->boolean('all') ? PHP_INT_MAX : 500;

        return Inertia::render('Reports/Show', [
            'report' => $report, 'title' => $title, 'columns' => $columns, 'rows' => array_slice($rows, 0, $cap), 'summary' => $summary,
            'totalRows' => $total, 'truncated' => $total > $cap,
            'filters' => $request->only('from', 'to', 'status', 'bank_account_id', 'user_id', 'all'),
            'accounts' => BankAccount::with('bank:id,name')->get()->map(fn ($a) => ['id' => $a->id, 'label' => $a->display_name]),
            'users' => \App\Models\User::orderBy('name')->get(['id', 'name']),
            'org' => \App\Support\Settings::get('org_name'),
            'generatedBy' => $request->user()->name,
        ]);
    }

    public function export(Request $request, string $report): StreamedResponse
    {
        [$title, $columns, $rows] = $this->build($request, $report);
        ActivityLogService::log('report.exported', 'reports', "Exported report: {$title} (CSV)");

        return response()->streamDownload(function () use ($columns, $rows) {
            $o = fopen('php://output', 'w');
            fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, array_column($columns, 'label'));
            foreach ($rows as $r) {
                fputcsv($o, array_map(fn ($c) => $r[$c['key']] ?? '', $columns));
            }
            fclose($o);
        }, $report.'-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }

    private function allowed($u, string $perm): bool
    {
        return $u->hasPermission($perm) || ($perm === 'reports.printing' && $u->hasPermission('reports.view'));
    }

    /** @return array{0:string,1:array,2:array,3:array} */
    private function build(Request $r, string $key): array
    {
        abort_unless(isset(self::REPORTS[$key]) && $this->allowed($r->user(), self::REPORTS[$key][2]), 403);
        $title = self::REPORTS[$key][0];
        ActivityLogService::log('report.generated', 'reports', "Generated report: {$title}");

        $col = fn ($k, $l, $type = 'text') => ['key' => $k, 'label' => $l, 'type' => $type];
        $date = fn ($q, $f) => $q->when($r->from, fn ($x, $v) => $x->whereDate($f, '>=', $v))->when($r->to, fn ($x, $v) => $x->whereDate($f, '<=', $v));

        $chequeCols = [$col('number', 'Cheque No.'), $col('date', 'Date', 'date'), $col('payee', 'Payee'), $col('bank', 'Bank'),
            $col('amount', 'Amount', 'money'), $col('status', 'Status', 'status'), $col('reference', 'Reference')];
        // Plain joined rows (no model hydration) so large registers stay fast.
        $chequeRows = fn ($q) => $q->toBase()->join('payees', 'payees.id', '=', 'cheques.payee_id')
            ->join('bank_accounts', 'bank_accounts.id', '=', 'cheques.bank_account_id')->join('banks', 'banks.id', '=', 'bank_accounts.bank_id')
            ->select('cheques.cheque_number', 'cheques.cheque_date', 'cheques.is_cash', 'cheques.payee_line', 'payees.name as payee_name', 'banks.name as bank_name',
                'cheques.amount', 'cheques.status', 'cheques.reference_no')->get()->map(fn ($c) => [
                    'number' => $c->cheque_number, 'date' => substr((string) $c->cheque_date, 0, 10),
                    'payee' => $c->is_cash ? 'CASH' : ($c->payee_line ?: $c->payee_name),
                    'bank' => $c->bank_name, 'amount' => (string) $c->amount, 'status' => $c->status, 'reference' => $c->reference_no,
                ])->all();
        $cq = fn () => $date(Cheque::query()->when($r->status, fn ($q, $v) => $q->where('cheques.status', $v))
            ->when($r->bank_account_id, fn ($q, $v) => $q->where('cheques.bank_account_id', $v))
            ->when($r->user_id, fn ($q, $v) => $q->where('cheques.created_by', $v)), 'cheques.cheque_date')->orderBy('cheques.cheque_date')->orderBy('cheques.id');
        $sumC = fn (array $rows) => [['label' => 'Cheques', 'value' => count($rows)], ['label' => 'Total amount', 'value' => Money::format(Money::sum(array_column($rows, 'amount')))]];

        $receiptCols = [$col('number', 'Receipt No.'), $col('date', 'Date', 'date'), $col('vendor', 'Vendor'), $col('category', 'Category'),
            $col('amount', 'Amount', 'money'), $col('tax', 'Tax', 'money'), $col('status', 'Status', 'status')];
        $rq = fn () => $date(Receipt::query()->when($r->status, fn ($q, $v) => $q->where('receipts.status', $v))
            ->when($r->user_id, fn ($q, $v) => $q->where('receipts.created_by', $v)), 'receipts.receipt_date')->orderBy('receipts.receipt_date')->orderBy('receipts.id');
        $receiptRows = fn ($q) => $q->toBase()->leftJoin('payees', 'payees.id', '=', 'receipts.payee_id')->leftJoin('receipt_categories', 'receipt_categories.id', '=', 'receipts.category_id')
            ->select('receipts.receipt_number', 'receipts.receipt_date', 'payees.name as vendor', 'receipt_categories.name as category', 'receipts.amount', 'receipts.tax_amount', 'receipts.status')
            ->get()->map(fn ($x) => [
                'number' => $x->receipt_number, 'date' => $x->receipt_date ? substr((string) $x->receipt_date, 0, 10) : null, 'vendor' => $x->vendor, 'category' => $x->category,
                'amount' => (string) $x->amount, 'tax' => (string) $x->tax_amount, 'status' => $x->status,
            ])->all();
        $sumR = fn (array $rows) => [['label' => 'Receipts', 'value' => count($rows)], ['label' => 'Total amount', 'value' => Money::format(Money::sum(array_column($rows, 'amount')))]];

        switch ($key) {
            case 'cheque-register':
                $rows = $chequeRows($cq());

                return [$title, $chequeCols, $rows, $sumC($rows)];
            case 'cheques-daily':
                $r->merge(['from' => $r->from ?: now()->toDateString(), 'to' => $r->to ?: now()->toDateString()]);
                $rows = $chequeRows($cq());

                return [$title, $chequeCols, $rows, $sumC($rows)];
            case 'cheques-monthly':
                $r->merge(['from' => $r->from ?: now()->startOfMonth()->toDateString(), 'to' => $r->to ?: now()->endOfMonth()->toDateString()]);
                $rows = $chequeRows($cq());

                return [$title, $chequeCols, $rows, $sumC($rows)];
            case 'cheques-outstanding':
                $rows = $chequeRows($cq()->whereIn('status', ['released', 'for_clearing']));

                return [$title, $chequeCols, $rows, $sumC($rows)];
            case 'cheques-released':
                $rows = $chequeRows($cq()->whereIn('status', ['released', 'for_clearing', 'cleared', 'bounced', 'stale']));

                return [$title, $chequeCols, $rows, $sumC($rows)];
            case 'cheques-cleared':
                $rows = $chequeRows($cq()->where('status', 'cleared'));

                return [$title, $chequeCols, $rows, $sumC($rows)];
            case 'cheques-bounced':
                $rows = $chequeRows($cq()->where('status', 'bounced'));

                return [$title, $chequeCols, $rows, $sumC($rows)];
            case 'cheques-stopped':
                $rows = $chequeRows($cq()->whereIn('status', ['cancelled', 'voided']));

                return [$title, $chequeCols, $rows, $sumC($rows)];
            case 'receipt-register':
                $rows = $receiptRows($rq());

                return [$title, $receiptCols, $rows, $sumR($rows)];
            case 'receipts-daily':
                $r->merge(['from' => $r->from ?: now()->toDateString(), 'to' => $r->to ?: now()->toDateString()]);
                $rows = $receiptRows($rq());

                return [$title, $receiptCols, $rows, $sumR($rows)];
            case 'receipts-monthly':
                $r->merge(['from' => $r->from ?: now()->startOfMonth()->toDateString(), 'to' => $r->to ?: now()->endOfMonth()->toDateString()]);
                $rows = $receiptRows($rq());

                return [$title, $receiptCols, $rows, $sumR($rows)];
            case 'receipts-category':
                $rows = $rq()->toBase()->reorder()->leftJoin('receipt_categories', 'receipt_categories.id', '=', 'receipts.category_id')
                    ->where('receipts.status', '!=', 'cancelled')->groupBy('receipt_categories.name')->orderBy('receipt_categories.name')
                    ->selectRaw('receipt_categories.name as category, count(*) as c, sum(receipts.amount) as s, sum(receipts.tax_amount) as t')->get()
                    ->map(fn ($g) => ['category' => $g->category ?? 'Uncategorised', 'count' => (int) $g->c, 'amount' => Money::sum([$g->s ?? 0]), 'tax' => Money::sum([$g->t ?? 0])])->all();

                return [$title, [$col('category', 'Category'), $col('count', 'Receipts'), $col('amount', 'Amount', 'money'), $col('tax', 'Tax', 'money')], $rows,
                    [['label' => 'Total amount', 'value' => Money::format(Money::sum(array_column($rows, 'amount')))]]];
            case 'transaction-summary':
                $c = $date(Cheque::query(), 'cheque_date')->selectRaw('status, count(*) n, sum(amount) s')->groupBy('status')->get();
                $x = $date(Receipt::query(), 'receipt_date')->selectRaw('status, count(*) n, sum(amount) s')->groupBy('status')->get();
                $rows = [];
                foreach ($c as $v) { $rows[] = ['type' => 'Cheque', 'status' => $v->status->value, 'count' => $v->n, 'amount' => (string) ($v->s ?? 0)]; }
                foreach ($x as $v) { $rows[] = ['type' => 'Receipt', 'status' => $v->status->value, 'count' => $v->n, 'amount' => (string) ($v->s ?? 0)]; }

                return [$title, [$col('type', 'Type'), $col('status', 'Status', 'status'), $col('count', 'Count'), $col('amount', 'Amount', 'money')], $rows,
                    [['label' => 'Note', 'value' => 'Cheque and receipt amounts are never combined']]];
            case 'printing-history':
                $q = $date(PrintLog::with('user:id,name', 'job')->latest('id'), 'created_at')->limit(1000)->get();
                $rows = $q->map(fn ($l) => [
                    'at' => $l->created_at->toDateTimeString(), 'type' => $l->printable_type, 'record' => $l->printable_id,
                    'user' => $l->user?->name, 'reprint' => $l->is_reprint ? 'Yes' : 'No', 'status' => $l->status, 'reason' => $l->job?->reason,
                ])->all();

                return [$title, [$col('at', 'When'), $col('type', 'Type'), $col('record', 'Record #'), $col('user', 'By'), $col('reprint', 'Reprint'), $col('status', 'Result', 'status'), $col('reason', 'Reason')], $rows,
                    [['label' => 'Entries', 'value' => count($rows)], ['label' => 'Reprints', 'value' => collect($rows)->where('reprint', 'Yes')->count()]]];
            case 'user-activity':
                $q = $date(ActivityLog::with('user:id,name')->when($r->user_id, fn ($q, $v) => $q->where('user_id', $v))->latest('id'), 'created_at')->limit(1000)->get();
                $rows = $q->map(fn ($l) => ['at' => $l->created_at->toDateTimeString(), 'user' => $l->user?->name, 'module' => $l->module, 'action' => $l->action, 'description' => $l->description, 'reason' => $l->reason])->all();

                return [$title, [$col('at', 'When'), $col('user', 'User'), $col('module', 'Module'), $col('action', 'Action'), $col('description', 'Description'), $col('reason', 'Reason')], $rows, [['label' => 'Entries', 'value' => count($rows)]]];
            case 'document-uploads':
                $q = $date(DocumentFile::with('uploader:id,name')->where('version', 1)->latest('id'), 'created_at')->limit(1000)->get();
                $rows = $q->map(fn ($d) => ['at' => $d->created_at->toDateTimeString(), 'name' => $d->original_name, 'category' => $d->category, 'size' => round($d->size / 1024).' KB', 'user' => $d->uploader?->name])->all();

                return [$title, [$col('at', 'Uploaded'), $col('name', 'File'), $col('category', 'Category'), $col('size', 'Size'), $col('user', 'By')], $rows, [['label' => 'Files', 'value' => count($rows)]]];
        }
        abort(404);
    }
}
