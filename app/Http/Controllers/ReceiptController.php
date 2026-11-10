<?php

namespace App\Http\Controllers;

use App\Enums\DocumentCategory;
use App\Enums\ReceiptStatus;
use App\Models\Cheque;
use App\Models\DocumentFile;
use App\Models\Receipt;
use App\Models\ReceiptCategory;
use App\Services\DocumentService;
use App\Services\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReceiptController extends Controller
{
    public function __construct(private ReceiptService $service, private DocumentService $docs) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Receipt::class);
        $user = $request->user();

        $q = Receipt::query()->visibleTo($user)->with('payee:id,name', 'category:id,name')->withCount(['documents'])
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('receipt_number', 'like', "%{$s}%")
                ->orWhere('reference_no', 'like', "%{$s}%")->orWhere('description', 'like', "%{$s}%")
                ->orWhereHas('payee', fn ($p) => $p->where('name', 'like', "%{$s}%"))))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->category_id, fn ($q, $v) => $q->where('category_id', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('receipt_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('receipt_date', '<=', $v))
            ->when($request->mine, fn ($q) => $q->where('created_by', $user->id));

        $sort = in_array($request->sort, ['receipt_date', 'amount', 'receipt_number', 'created_at', 'status'], true) ? $request->sort : 'id';
        $q->orderBy($sort, $request->dir === 'asc' ? 'asc' : 'desc');
        $per = in_array((int) $request->per_page, [25, 50, 100], true) ? (int) $request->per_page : 25;

        return Inertia::render('Receipts/Index', [
            'receipts' => $q->paginate($per)->withQueryString()->through(fn ($r) => $this->row($r)),
            'filters' => $request->only('search', 'status', 'category_id', 'from', 'to', 'mine', 'sort', 'dir', 'per_page'),
            'statuses' => ReceiptStatus::options(),
            'categories' => ReceiptCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'savedFilters' => $user->savedFilters('receipts'),
        ]);
    }

    /** Upload queue: drafts that still need details, plus the entry form. */
    public function create(Request $request): Response
    {
        $this->authorize('create', Receipt::class);

        return Inertia::render('Receipts/Intake', [
            'categories' => ReceiptCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'queue' => Receipt::where('created_by', $request->user()->id)->where('status', 'draft')
                ->with('documents', 'payee:id,name')->latest('id')->limit(100)->get()->map(fn ($r) => $this->intakeRow($r))->values(),
            'maxMb' => (int) \App\Support\Settings::get('max_upload_mb', 10),
        ]);
    }

    /** Upload one or many files; each becomes a draft row in the queue. */
    public function upload(Request $request): RedirectResponse
    {
        $this->authorize('create', Receipt::class);
        $request->validate(['files' => 'required|array|min:1|max:20', 'files.*' => 'file']);
        $n = 0;
        foreach ($request->file('files') as $file) {
            $r = $this->service->create([], $request->user());
            $this->docs->store($file, 'receipt', $r, $request->user());
            $n++;
        }

        return back()->with('success', "{$n} file(s) added to the queue. Fill in the details for each.");
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Receipt::class);
        $data = $this->validated($request);
        $receipt = $this->service->create($data, $request->user(), record: $request->input('_next') !== 'draft');
        if ($request->hasFile('file')) {
            $this->docs->store($request->file('file'), 'receipt', $receipt, $request->user());
        }

        return redirect()->route('receipts.show', $receipt)->with('success', 'Receipt saved.');
    }

    /** Save details for a queued draft and optionally jump to the next one. */
    public function update(Request $request, Receipt $receipt): RedirectResponse
    {
        $this->authorize('update', $receipt);
        $data = $this->validated($request);
        $draft = $request->input('_next') === 'draft';
        $this->service->update($receipt, $data, $request->user(), record: ! $draft);

        if ($request->boolean('_queue')) {
            $next = Receipt::where('created_by', $request->user()->id)->where('status', 'draft')->where('id', '!=', $receipt->id)->orderBy('id')->first();

            return redirect()->route('receipts.create', $next ? ['open' => $next->id] : [])->with('success', 'Saved.');
        }

        return redirect()->route('receipts.show', $receipt)->with('success', 'Receipt updated.');
    }

    public function show(Receipt $receipt): Response
    {
        $this->authorize('view', $receipt);
        $receipt->load('payee', 'category', 'cheque:id,cheque_number', 'creator:id,name', 'documents', 'histories.user:id,name');
        $u = request()->user();
        $versions = DocumentFile::whereIn('group_uuid', $receipt->documents->pluck('group_uuid'))->with('uploader:id,name')->orderByDesc('version')->get();

        return Inertia::render('Receipts/Show', [
            'receipt' => $this->row($receipt) + [
                'tax_amount' => (string) $receipt->tax_amount, 'tax_rate' => $receipt->tax_rate, 'description' => $receipt->description,
                'remarks' => $receipt->remarks, 'cheque' => $receipt->cheque ? ['id' => $receipt->cheque->id, 'number' => $receipt->cheque->cheque_number] : null,
                'created_by' => $receipt->creator?->name, 'created_at' => $receipt->created_at->toDateTimeString(),
                'duplicate_override_reason' => $receipt->duplicate_override_reason,
                'documents' => $receipt->documents->map(fn ($d) => $this->doc($d))->values(),
                'versions' => $versions->map(fn ($d) => $this->doc($d) + ['version' => $d->version, 'is_current' => $d->is_current,
                    'reason' => $d->replace_reason, 'by' => $d->uploader?->name, 'at' => $d->created_at->toDateTimeString(), 'group' => $d->group_uuid])->values(),
                'history' => $receipt->histories->map(fn ($h) => [
                    'from' => $h->from_status, 'to' => $h->to_status, 'reason' => $h->reason, 'user' => $h->user?->name, 'at' => $h->created_at->toDateTimeString(),
                ])->values(),
            ],
            'can' => [
                'edit' => $u->can('update', $receipt), 'verify' => $u->can('verify', $receipt), 'process' => $u->can('process', $receipt),
                'archive' => $u->can('archive', $receipt), 'cancel' => $u->can('cancel', $receipt),
                'upload' => $u->hasPermission('documents.upload') && $receipt->status !== ReceiptStatus::Archived && $receipt->status !== ReceiptStatus::Cancelled,
                'print' => $u->hasPermission('print.print') && ! in_array($receipt->status, [ReceiptStatus::Draft, ReceiptStatus::Cancelled], true),
                'record' => $receipt->status === ReceiptStatus::Draft && $u->can('update', $receipt),
            ],
            'categories' => ReceiptCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function edit(Receipt $receipt): Response
    {
        $this->authorize('update', $receipt);
        $receipt->load('payee:id,name', 'documents', 'cheque:id,cheque_number');

        return Inertia::render('Receipts/Form', [
            'receipt' => [
                'id' => $receipt->id, 'receipt_number' => $receipt->receipt_number, 'receipt_date' => $receipt->receipt_date?->toDateString(),
                'payee_id' => $receipt->payee_id, 'payee_name' => $receipt->payee?->name, 'description' => $receipt->description,
                'amount' => $receipt->amount ? (string) $receipt->amount : '', 'tax_amount' => (string) $receipt->tax_amount,
                'tax_rate' => $receipt->tax_rate, 'category_id' => $receipt->category_id, 'cheque_id' => $receipt->cheque_id,
                'cheque_label' => $receipt->cheque?->cheque_number, 'reference_no' => $receipt->reference_no, 'remarks' => $receipt->remarks,
                'status' => $receipt->status->value, 'documents' => $receipt->documents->map(fn ($d) => $this->doc($d))->values(),
            ],
            'categories' => ReceiptCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function createForm(): Response
    {
        $this->authorize('create', Receipt::class);

        return Inertia::render('Receipts/Form', [
            'receipt' => null,
            'categories' => ReceiptCategory::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function transition(Request $request, Receipt $receipt, string $action): RedirectResponse
    {
        $u = $request->user();
        abort_unless(in_array($action, ['verify', 'process', 'archive', 'cancel'], true), 404);
        if ($action === 'cancel') {
            $this->cancel($request, $receipt);
        } else {
            $this->authorize($action, $receipt);
            $this->service->{$action}($receipt, $u);
        }

        return back()->with('success', 'Receipt '.['verify' => 'verified', 'process' => 'processed', 'archive' => 'archived', 'cancel' => 'cancelled'][$action].'.');
    }

    public function replaceFile(Request $request, Receipt $receipt, DocumentFile $document): RedirectResponse
    {
        $this->authorize('update', $receipt);
        abort_unless($document->documentable_type === 'receipt' && $document->documentable_id === $receipt->id, 404);
        $this->authorize('replace', $document);
        $data = $request->validate(['file' => 'required|file', 'reason' => 'required|string|min:3|max:500']);
        $this->docs->replace($document, $data['file'], $data['reason'], $request->user());

        return back()->with('success', 'File replaced. The previous version is kept in the history.');
    }

    public function addFile(Request $request, Receipt $receipt): RedirectResponse
    {
        $this->authorize('update', $receipt);
        abort_unless($request->user()->hasPermission('documents.upload'), 403);
        $request->validate(['file' => 'required|file']);
        $this->docs->store($request->file('file'), 'receipt', $receipt, $request->user());

        return back()->with('success', 'File attached.');
    }

    /** Live duplicate check while typing. */
    public function duplicates(Request $request): JsonResponse
    {
        $this->authorize('create', Receipt::class);
        $d = $this->service->duplicates($request->number, $request->payee_id ? (int) $request->payee_id : null, $request->ignore);
        $fmt = fn ($r) => ['id' => $r->id, 'url' => route('receipts.show', $r), 'vendor' => $r->payee?->name, 'status' => $r->status->label()];

        return response()->json(['exact' => $d['exact'] ? $fmt($d['exact']) : null, 'similar' => $d['similar']->map($fmt)->values()]);
    }

    public function chequeSearch(Request $request): JsonResponse
    {
        $this->authorize('create', Receipt::class);
        $rows = Cheque::visibleTo($request->user())->with('payee:id,name')
            ->when($request->payee_id, fn ($q, $v) => $q->where('payee_id', $v))
            ->when($request->q, fn ($q, $s) => $q->where('cheque_number', 'like', "%{$s}%"))
            ->latest('id')->limit(15)->get();

        return response()->json($rows->map(fn ($c) => ['id' => $c->id, 'label' => "{$c->cheque_number} · {$c->payee?->name} · ₱".number_format((float) $c->amount, 2)]));
    }

    private function cancel(Request $request, Receipt $receipt): void
    {
        $this->authorize('cancel', $receipt);
        $data = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        $this->service->cancel($receipt, $data['reason'], $request->user());
    }

    private function validated(Request $request): array
    {
        $draft = $request->input('_next') === 'draft';
        $req = $draft ? 'nullable' : 'required';

        return $request->validate([
            'receipt_number' => "{$req}|string|max:60",
            'receipt_date' => "{$req}|date|before_or_equal:today",
            'payee_id' => "{$req}|exists:payees,id",
            'description' => 'nullable|string|max:500',
            'amount' => $req,
            'tax_amount' => 'nullable',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'category_id' => 'nullable|exists:receipt_categories,id',
            'cheque_id' => 'nullable|exists:cheques,id',
            'reference_no' => 'nullable|string|max:100',
            'remarks' => 'nullable|string|max:2000',
            'duplicate_override_reason' => 'nullable|string|max:500',
            'file' => 'nullable|file',
        ]);
    }

    private function doc(DocumentFile $d): array
    {
        return ['id' => $d->id, 'name' => $d->original_name, 'mime' => $d->mime, 'size' => $d->size];
    }

    private function row(Receipt $r): array
    {
        return [
            'id' => $r->id, 'receipt_number' => $r->receipt_number, 'receipt_date' => $r->receipt_date?->toDateString(),
            'payee' => $r->payee?->name, 'amount' => $r->amount !== null ? (string) $r->amount : null, 'status' => $r->status->value,
            'category' => $r->category?->name, 'reference_no' => $r->reference_no, 'has_file' => ($r->documents_count ?? $r->documents->count()) > 0,
        ];
    }

    private function intakeRow(Receipt $r): array
    {
        $d = $r->documents->first();

        return [
            'id' => $r->id, 'receipt_number' => $r->receipt_number, 'receipt_date' => $r->receipt_date?->toDateString(),
            'payee_id' => $r->payee_id, 'payee_name' => $r->payee?->name, 'description' => $r->description,
            'amount' => $r->amount ? (string) $r->amount : '', 'tax_amount' => (string) $r->tax_amount, 'category_id' => $r->category_id,
            'reference_no' => $r->reference_no, 'cheque_id' => $r->cheque_id,
            'file' => $d ? $this->doc($d) : null,
        ];
    }
}
