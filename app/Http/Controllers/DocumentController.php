<?php

namespace App\Http\Controllers;

use App\Enums\DocumentCategory;
use App\Models\Cheque;
use App\Models\DocumentFile;
use App\Models\Receipt;
use App\Services\ActivityLogService;
use App\Services\DocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function __construct(private DocumentService $docs) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', DocumentFile::class);
        $u = $request->user();

        $q = DocumentFile::query()->where('is_current', true)->with('uploader:id,name', 'documentable')
            ->when($request->search, fn ($q, $s) => $q->where('original_name', 'like', "%{$s}%"))
            ->when($request->category, fn ($q, $v) => $q->where('category', $v))
            ->when($request->link, fn ($q, $v) => $v === 'unlinked' ? $q->whereNull('documentable_type') : $q->where('documentable_type', $v))
            // People without blanket document access only see their own uploads.
            ->when(! $u->hasPermission('documents.view'), fn ($q) => $q->where('uploaded_by', $u->id))
            ->when(! $request->boolean('archived'), fn ($q) => $q->where('created_at', '>=', \App\Support\Settings::retentionCutoff()))
            ->latest('id');

        return Inertia::render('Documents/Index', [
            'documents' => $q->paginate(25)->withQueryString()->through(fn ($d) => [
                'id' => $d->id, 'name' => $d->original_name, 'mime' => $d->mime, 'size' => $d->size,
                'category' => DocumentCategory::tryFrom($d->category)?->label() ?? $d->category, 'version' => $d->version,
                'uploaded_by' => $d->uploader?->name, 'at' => $d->created_at->toDateTimeString(),
                'linked' => $d->documentable ? [
                    'type' => $d->documentable_type,
                    'label' => $d->documentable instanceof Cheque ? 'Cheque '.$d->documentable->cheque_number : 'Receipt '.($d->documentable->receipt_number ?: '#'.$d->documentable_id),
                    'url' => route($d->documentable_type === 'cheque' ? 'cheques.show' : 'receipts.show', $d->documentable_id),
                ] : null,
            ]),
            'filters' => $request->only('search', 'category', 'link', 'archived'),
            'retentionYears' => (int) \App\Support\Settings::get('retention_years', 10),
            'categories' => DocumentCategory::options(),
            'canUpload' => $u->hasPermission('documents.upload'),
            'maxMb' => (int) \App\Support\Settings::get('max_upload_mb', 10),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('upload', DocumentFile::class);
        $data = $request->validate([
            'file' => 'required|file',
            'category' => 'required|in:'.implode(',', DocumentCategory::values()),
            'link_type' => 'nullable|in:cheque,receipt',
            'link_id' => 'nullable|integer',
        ]);
        $parent = null;
        if (! empty($data['link_type']) && ! empty($data['link_id'])) {
            $parent = ($data['link_type'] === 'cheque' ? Cheque::class : Receipt::class)::findOrFail($data['link_id']);
            $this->authorize('view', $parent);
        }
        $this->docs->store($data['file'], $data['category'], $parent, $request->user());

        return back()->with('success', 'Document uploaded.');
    }

    /** Attach a file straight onto a cheque (supporting document). */
    public function attach(Request $request, Cheque $cheque): RedirectResponse
    {
        $this->authorize('upload', DocumentFile::class);
        $this->authorize('view', $cheque);
        $request->validate(['file' => 'required|file']);
        $this->docs->store($request->file('file'), 'cheque_support', $cheque, $request->user());

        return back()->with('success', 'Supporting document attached.');
    }

    /** Inline preview. Streams after a policy check; there are no public URLs. */
    public function show(DocumentFile $document): StreamedResponse
    {
        $this->authorize('view', $document);

        return $this->stream($document, 'inline');
    }

    public function download(Request $request, DocumentFile $document): StreamedResponse
    {
        $this->authorize('view', $document);
        ActivityLogService::log('document.downloaded', 'documents', "Downloaded {$document->original_name} (v{$document->version})", $document);

        return $this->stream($document, 'attachment');
    }

    private function stream(DocumentFile $d, string $disposition): StreamedResponse
    {
        abort_unless($this->docs->disk()->exists($d->path), 404, 'File is missing from storage.');

        return $this->docs->disk()->response($d->path, $d->original_name, [
            'Content-Type' => $d->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ], $disposition);
    }
}
