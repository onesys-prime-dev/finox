<?php

namespace App\Http\Controllers;

use App\Enums\ChequeStatus;
use App\Models\BankAccount;
use App\Models\Cheque;
use App\Models\Payee;
use App\Services\ChequeService;
use App\Services\DocumentService;
use App\Support\AmountInWords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChequeController extends Controller
{
    public function __construct(private ChequeService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Cheque::class);
        $user = $request->user();

        $q = Cheque::query()->visibleTo($user)->with('payee:id,name', 'bankAccount.bank:id,name')
            ->when($request->search, function ($q, $s) {
                $q->where(fn ($w) => $w->where('cheque_number', 'like', "%{$s}%")
                    ->orWhere('reference_no', 'like', "%{$s}%")
                    ->orWhere('purpose', 'like', "%{$s}%")
                    ->orWhereHas('payee', fn ($p) => $p->where('name', 'like', "%{$s}%")));
            })
            ->when($request->status === 'outstanding', fn ($q) => $q->whereIn('status', ['released', 'for_clearing']))
            ->when($request->status && $request->status !== 'outstanding', fn ($q) => $q->where('status', $request->status))
            ->when($request->bank_account_id, fn ($q, $v) => $q->where('bank_account_id', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('cheque_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('cheque_date', '<=', $v))
            ->when($request->mine, fn ($q) => $q->where('created_by', $user->id))
            ->when($request->flagged, fn ($q) => $q->whereNotNull('stale_flagged_at')->whereIn('status', ['released', 'for_clearing']));

        $sort = in_array($request->sort, ['cheque_date', 'amount', 'cheque_number', 'created_at', 'status'], true) ? $request->sort : 'id';
        $q->orderBy($sort, $request->dir === 'asc' ? 'asc' : 'desc');

        $per = in_array((int) $request->per_page, [25, 50, 100], true) ? (int) $request->per_page : 25;

        return Inertia::render('Cheques/Index', [
            'cheques' => $q->paginate($per)->withQueryString()->through(fn ($c) => $this->row($c)),
            'filters' => $request->only('search', 'status', 'bank_account_id', 'from', 'to', 'mine', 'flagged', 'sort', 'dir', 'per_page'),
            'statuses' => ChequeStatus::options(),
            'accounts' => $this->accounts(),
            'savedFilters' => $user->savedFilters('cheques'),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Cheque::class);
        $prefill = [];
        if ($request->duplicate) {
            $src = Cheque::with('lines')->findOrFail($request->duplicate);
            $this->authorize('view', $src);
            $prefill = $this->service->duplicateAttributes($src);
        }

        return Inertia::render('Cheques/Form', [
            'cheque' => $prefill ?: null,
            'accounts' => $this->accounts(),
            'duplicateOf' => $request->duplicate ? (int) $request->duplicate : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Cheque::class);
        $cheque = $this->service->create($this->validated($request), $request->user());

        $next = $request->input('_next');
        if ($next === 'prepare' && $request->user()->can('prepare', $cheque)) {
            $this->service->prepare($cheque, $request->user());

            return redirect()->route('cheques.show', $cheque)->with('success', 'Cheque prepared and ready to print.');
        }
        if ($next === 'another') {
            return redirect()->route('cheques.create', ['bank_account_id' => $cheque->bank_account_id])
                ->with('success', "Cheque {$cheque->cheque_number} saved. Add the next one.");
        }

        return redirect()->route('cheques.show', $cheque)->with('success', 'Draft saved.');
    }

    public function show(Cheque $cheque): Response
    {
        $this->authorize('view', $cheque);
        $cheque->load('payee', 'bankAccount.bank', 'bankAccount.template', 'creator:id,name', 'release.recorder:id,name',
            'clearing.recorder:id,name', 'lines', 'documents', 'receipts:id,cheque_id,receipt_number,status', 'histories.user:id,name');
        $user = request()->user();

        return Inertia::render('Cheques/Show', [
            'cheque' => $this->detail($cheque),
            'can' => [
                'edit' => $user->can('update', $cheque),
                'prepare' => $user->can('prepare', $cheque),
                'release' => $user->can('release', $cheque),
                'clear' => $user->can('clear', $cheque),
                'stop' => $user->can('stop', $cheque),
                'print' => $user->hasPermission('print.print') && $cheque->status === ChequeStatus::Prepared,
                'reprint' => $user->hasPermission('print.reprint')
                    && in_array($cheque->status, [ChequeStatus::Printed, ChequeStatus::Released, ChequeStatus::ForClearing], true),
                'duplicate' => $user->can('create', Cheque::class),
                'upload' => $user->hasPermission('documents.upload'),
            ],
            'preparationErrors' => $cheque->status === ChequeStatus::Draft ? $this->service->preparationErrors($cheque) : [],
            'staleDays' => (int) \App\Support\Settings::get('stale_days', 180),
        ]);
    }

    /** Full breakdown on plain A4, for when the rows do not fit on the Payment Advice. */
    public function schedule(Cheque $cheque): Response
    {
        $this->authorize('view', $cheque);
        $cheque->load('lines', 'payee', 'bankAccount.bank', 'creator:id,name');

        return Inertia::render('Cheques/Schedule', [
            'cheque' => [
                'id' => $cheque->id, 'cheque_number' => $cheque->cheque_number, 'cheque_date' => $cheque->cheque_date->toDateString(),
                'payee' => $cheque->printed_payee, 'amount' => (string) $cheque->amount, 'purpose' => $cheque->purpose, 'reference_no' => $cheque->reference_no,
                'bank_account' => $cheque->bankAccount?->display_name, 'prepared_by' => $cheque->creator?->name,
                'lines' => $this->linesPayload($cheque), 'lines_total' => $cheque->linesTotal(),
            ],
            'org' => \App\Support\Settings::get('org_name'),
            'by' => request()->user()->name,
        ]);
    }

    public function schedulePrinted(Cheque $cheque): \Illuminate\Http\JsonResponse
    {
        $this->authorize('view', $cheque);
        \App\Services\ActivityLogService::log('cheque.schedule_printed', 'printing', "Printed breakdown schedule for cheque {$cheque->cheque_number}", $cheque);

        return response()->json(['ok' => true]);
    }

    public function edit(Cheque $cheque): Response
    {
        $this->authorize('update', $cheque);
        $cheque->load('payee', 'lines');

        return Inertia::render('Cheques/Form', [
            'cheque' => [
                'id' => $cheque->id, 'bank_account_id' => $cheque->bank_account_id, 'cheque_number' => $cheque->cheque_number,
                'cheque_date' => $cheque->cheque_date->toDateString(), 'payee_id' => $cheque->payee_id,
                'payee_name' => $cheque->payee?->name, 'payee_line' => $cheque->payee_line, 'is_cash' => $cheque->is_cash,
                'amount' => (string) $cheque->amount, 'purpose' => $cheque->purpose, 'reference_no' => $cheque->reference_no,
                'remarks' => $cheque->remarks, 'status' => $cheque->status->value,
                'lines' => $this->linesPayload($cheque),
            ],
            'locked' => $cheque->isLocked(),
            'accounts' => $this->accounts(),
        ]);
    }

    public function update(Request $request, Cheque $cheque): RedirectResponse
    {
        $this->authorize('update', $cheque);
        $data = $cheque->isLocked()
            ? $request->validate(['purpose' => 'nullable|string|max:500', 'reference_no' => 'nullable|string|max:100', 'remarks' => 'nullable|string|max:2000'])
            : $this->validated($request);
        $this->service->update($cheque, $data, $request->user());

        if ($request->input('_next') === 'prepare' && $request->user()->can('prepare', $cheque->fresh())) {
            $this->service->prepare($cheque, $request->user());

            return redirect()->route('cheques.show', $cheque)->with('success', 'Cheque prepared and ready to print.');
        }

        return redirect()->route('cheques.show', $cheque)->with('success', 'Cheque updated.');
    }

    public function prepare(Request $request, Cheque $cheque): RedirectResponse
    {
        $this->authorize('prepare', $cheque);
        $this->service->prepare($cheque, $request->user());

        return back()->with('success', 'Cheque prepared. It is now ready for printing.');
    }

    public function bulkPrepare(Request $request): RedirectResponse
    {
        $ids = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer'])['ids'];
        $ok = 0;
        $failed = [];
        foreach (Cheque::whereIn('id', $ids)->get() as $c) {
            if (! $request->user()->can('prepare', $c)) {
                $failed[] = "{$c->cheque_number} (not allowed)";
                continue;
            }
            try {
                $this->service->prepare($c, $request->user());
                $ok++;
            } catch (\Illuminate\Validation\ValidationException $e) {
                $failed[] = "{$c->cheque_number}: ".collect($e->errors())->flatten()->first();
            }
        }

        return back()->with($failed ? 'warning' : 'success', "Prepared {$ok} cheque(s)".($failed ? '. Skipped: '.implode('; ', $failed) : '.'));
    }

    public function release(Request $request, Cheque $cheque): RedirectResponse
    {
        $this->authorize('release', $cheque);
        $data = $request->validate([
            'released_at' => 'required|date|before_or_equal:today',
            'released_to' => 'required|string|max:255',
            'released_by' => 'required|string|max:255',
            'remarks' => 'nullable|string|max:2000',
            'supporting_reference' => 'nullable|string|max:255',
        ]);
        $this->service->release($cheque, $data, $request->user());

        return back()->with('success', 'Release recorded.');
    }

    public function forClearing(Request $request, Cheque $cheque): RedirectResponse
    {
        $this->authorize('clear', $cheque);
        $this->service->markForClearing($cheque, $request->user());

        return back()->with('success', 'Cheque is now awaiting clearing.');
    }

    public function clearing(Request $request, Cheque $cheque, DocumentService $docs): RedirectResponse
    {
        $this->authorize('clear', $cheque);
        $data = $request->validate([
            'result' => 'required|in:cleared,bounced',
            'cleared_at' => 'required|date|before_or_equal:today',
            'remarks' => 'nullable|string|max:2000',
            'file' => 'nullable|file',
        ]);
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $docs, $cheque, &$data) {
            $stored = null;
            try {
                if ($request->hasFile('file')) {
                    $stored = $docs->store($request->file('file'), 'clearing_proof', $cheque, $request->user());
                    $data['document_id'] = $stored->id;
                }
                $this->service->recordClearing($cheque, $data, $request->user());
            } catch (\Throwable $e) {
                // Don't leave the uploaded proof behind when the clearing itself is rejected.
                if ($stored) {
                    $docs->disk()->delete($stored->path);
                }
                throw $e;
            }
        });

        return back()->with('success', 'Clearing result recorded (manual entry).');
    }

    public function stale(Request $request, Cheque $cheque): RedirectResponse
    {
        $this->authorize('clear', $cheque);
        $this->service->markStale($cheque, $request->user());

        return back()->with('success', 'Cheque marked as stale.');
    }

    public function stop(Request $request, Cheque $cheque): RedirectResponse
    {
        $this->authorize('stop', $cheque);
        $data = $request->validate([
            'kind' => 'required|in:cancel,void',
            'reason' => 'required|string|min:3|max:1000',
            'stop_payment_note' => 'nullable|string|max:1000',
        ]);
        $this->service->stop($cheque, $data['kind'], $data['reason'], $data['stop_payment_note'] ?? null, $request->user());

        return back()->with('success', 'Cheque '.($data['kind'] === 'void' ? 'voided' : 'cancelled').'.');
    }

    /** Live helpers for the entry form: next number, duplicate warning, words. */
    public function check(Request $request): JsonResponse
    {
        $this->authorize('create', Cheque::class);
        $account = $request->bank_account_id ? BankAccount::find($request->bank_account_id) : null;
        $dup = null;
        if ($account && $request->cheque_number) {
            $found = Cheque::where('bank_account_id', $account->id)->where('cheque_number', trim($request->cheque_number))
                ->when($request->ignore, fn ($q, $v) => $q->where('id', '!=', $v))->first();
            $dup = $found ? ['id' => $found->id, 'url' => route('cheques.show', $found), 'status' => $found->status->label()] : null;
        }
        $words = null;
        if ($request->filled('amount') && ($m = \App\Support\Money::parse($request->amount)) && (float) $m > 0) {
            $words = AmountInWords::convert($m);
        }

        return response()->json([
            'next_number' => $request->boolean('want_next') ? $account?->nextChequeNumber() : null,
            'duplicate' => $dup,
            'words' => $words,
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'bank_account_id' => 'required|exists:bank_accounts,id',
            'cheque_number' => 'required|string|max:30',
            'cheque_date' => 'required|date',
            'payee_id' => 'required|exists:payees,id',
            'payee_line' => 'nullable|string|max:500',
            'is_cash' => 'boolean',
            'amount' => 'required',
            'purpose' => 'nullable|string|max:500',
            'reference_no' => 'nullable|string|max:100',
            'remarks' => 'nullable|string|max:2000',
            'lines' => 'nullable|array|max:200',
            'lines.*.line_date' => 'nullable|date',
            'lines.*.reference_no' => 'nullable|string|max:60',
            'lines.*.vendor' => 'nullable|string|max:255',
            'lines.*.particulars' => 'nullable|string|max:255',
            'lines.*.amount' => 'nullable',
            'lines.*.receipt_id' => 'nullable|integer|exists:receipts,id',
        ]);
    }

    /** Receipts that can still be added to a cheque's breakdown (not already on another active cheque). */
    public function availableReceipts(Request $request): JsonResponse
    {
        $this->authorize('create', Cheque::class);
        $ignore = (int) $request->ignore;
        $rows = \App\Models\Receipt::visibleTo($request->user())->with('payee:id,name')
            ->whereIn('status', ['recorded', 'verified', 'processed'])
            ->whereDoesntHave('chequeLines', fn ($l) => $l->where('cheque_id', '!=', $ignore)
                ->whereHas('cheque', fn ($c) => $c->whereNotIn('status', ['cancelled', 'voided'])))
            ->when($request->payee_id, fn ($q, $v) => $q->where('payee_id', $v))
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('receipt_number', 'like', "%{$s}%")
                ->orWhere('description', 'like', "%{$s}%")->orWhereHas('payee', fn ($p) => $p->where('name', 'like', "%{$s}%"))))
            ->latest('receipt_date')->latest('id')->limit(50)->get();

        return response()->json($rows->map(fn ($r) => [
            'id' => $r->id, 'receipt_number' => $r->receipt_number, 'receipt_date' => $r->receipt_date?->toDateString(),
            'vendor' => $r->payee?->name, 'description' => $r->description, 'amount' => (string) $r->amount, 'status' => $r->status->value,
        ])->values());
    }

    /** Breakdown rows as the form and pages use them. */
    private function linesPayload(Cheque $c): array
    {
        return $c->lines->map(fn ($l) => [
            'id' => $l->id, 'line_date' => $l->line_date?->toDateString(), 'reference_no' => $l->reference_no, 'vendor' => $l->vendor,
            'particulars' => $l->particulars, 'amount' => (string) $l->amount, 'receipt_id' => $l->receipt_id,
            'receipt_url' => $l->receipt_id ? route('receipts.show', $l->receipt_id) : null,
        ])->values()->all();
    }

    private function accounts()
    {
        return BankAccount::with('bank:id,name', 'template')->where('is_active', true)->whereHas('bank', fn ($b) => $b->where('is_active', true))->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'label' => $a->display_name, 'bank' => $a->bank?->name,
                'has_template' => (bool) $a->template?->is_active, 'template_id' => $a->print_template_id,
                'template' => $a->template?->is_active ? app(\App\Services\PrintingService::class)->templatePayload($a->template) : null,
            ])->values();
    }

    private function row(Cheque $c): array
    {
        return [
            'id' => $c->id, 'cheque_number' => $c->cheque_number, 'cheque_date' => $c->cheque_date->toDateString(),
            'payee' => $c->payee?->name, 'is_cash' => $c->is_cash, 'amount' => (string) $c->amount, 'status' => $c->status->value,
            'bank' => $c->bankAccount?->bank?->name, 'purpose' => $c->purpose, 'reference_no' => $c->reference_no,
            'flagged' => (bool) $c->stale_flagged_at, 'print_count' => $c->print_count,
        ];
    }

    private function detail(Cheque $c): array
    {
        return $this->row($c) + [
            'bank_account' => $c->bankAccount?->display_name, 'bank_account_id' => $c->bank_account_id,
            'payee_line' => $c->payee_line, 'printed_payee' => $c->printed_payee, 'amount_words' => $c->amount_words,
            'remarks' => $c->remarks, 'stop_payment_note' => $c->stop_payment_note,
            'lines' => $this->linesPayload($c), 'lines_total' => $c->linesTotal(),
            'lines_mismatch' => $c->lines->isNotEmpty() && $c->linesTotal() !== \App\Support\Money::parse((string) $c->amount),
            'created_by' => $c->creator?->name, 'created_at' => $c->created_at->toDateTimeString(),
            'printed_at' => $c->printed_at?->toDateTimeString(), 'has_template' => (bool) $c->bankAccount?->template?->is_active,
            'release' => $c->release ? [
                'released_at' => $c->release->released_at->toDateString(), 'released_to' => $c->release->released_to,
                'released_by' => $c->release->released_by, 'remarks' => $c->release->remarks,
                'supporting_reference' => $c->release->supporting_reference, 'recorded_by' => $c->release->recorder?->name,
            ] : null,
            'clearing' => $c->clearing ? [
                'result' => $c->clearing->result, 'cleared_at' => $c->clearing->cleared_at->toDateString(),
                'remarks' => $c->clearing->remarks, 'recorded_by' => $c->clearing->recorder?->name,
            ] : null,
            'documents' => $c->documents->map(fn ($d) => [
                'id' => $d->id, 'name' => $d->original_name, 'mime' => $d->mime, 'size' => $d->size, 'category' => $d->category,
            ])->values(),
            'receipts' => $c->receipts->map(fn ($r) => ['id' => $r->id, 'number' => $r->receipt_number, 'status' => $r->status->value])->values(),
            'history' => $c->histories->map(fn ($h) => [
                'from' => $h->from_status, 'to' => $h->to_status, 'reason' => $h->reason,
                'user' => $h->user?->name, 'at' => $h->created_at->toDateTimeString(),
            ])->values(),
        ];
    }
}
