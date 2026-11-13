<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PrintTemplate;
use App\Services\ActivityLogService;
use App\Services\DocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TemplateController extends Controller
{
    private const SOURCES = [
        'cheque' => ['payee_line', 'amount_number', 'amount_words', 'date', 'check_no', 'purpose', 'reference', 'prepared_by', 'printed_on', 'bank_account_name', 'breakdown', 'free_text'],
        'receipt' => ['org_name', 'org_address', 'receipt_no', 'date', 'vendor', 'description', 'amount_number', 'tax', 'reference', 'prepared_by', 'printed_on', 'free_text'],
    ];

    public function index(): Response
    {
        return Inertia::render('Admin/Templates/Index', [
            'templates' => PrintTemplate::withCount('bankAccounts')->orderBy('kind')->orderBy('name')->get()->map(fn ($t) => [
                'id' => $t->id, 'name' => $t->name, 'kind' => $t->kind, 'version' => $t->version, 'is_active' => $t->is_active,
                'size' => "{$t->paper_width_mm} × {$t->paper_height_mm} mm", 'accounts' => $t->bank_accounts_count, 'in_use' => $t->isInUse(),
                'fields' => count($t->layout['fields'] ?? []),
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Admin/Templates/Edit', ['template' => null, 'sources' => self::SOURCES, 'kind' => $request->kind === 'receipt' ? 'receipt' : 'cheque']);
    }

    public function edit(PrintTemplate $template): Response
    {
        return Inertia::render('Admin/Templates/Edit', [
            'template' => [
                'id' => $template->id, 'name' => $template->name, 'kind' => $template->kind, 'version' => $template->version,
                'paper_width_mm' => $template->paper_width_mm, 'paper_height_mm' => $template->paper_height_mm,
                'offset_x_mm' => $template->offset_x_mm, 'offset_y_mm' => $template->offset_y_mm,
                'background_url' => $template->background_url, 'layout' => $template->layout, 'is_active' => $template->is_active,
                'notes' => $template->notes, 'in_use' => $template->isInUse(),
            ],
            'sources' => self::SOURCES, 'kind' => $template->kind,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->assertBackground($request);
        $t = PrintTemplate::create($data + ['version' => 1]);
        $this->saveBackground($request, $t);
        ActivityLogService::log('template.created', 'templates', "Created print template {$t->name}", $t);

        return redirect()->route('admin.templates.edit', $t)->with('success', 'Template created.');
    }

    /** Saving bumps the version so earlier print logs still reference the layout they used. */
    public function update(Request $request, PrintTemplate $template): RedirectResponse
    {
        $data = $this->validated($request);
        $this->assertBackground($request);
        $changedLayout = $data['layout'] != $template->layout
            || (float) $data['paper_width_mm'] !== (float) $template->paper_width_mm || (float) $data['paper_height_mm'] !== (float) $template->paper_height_mm
            || (float) $data['offset_x_mm'] !== (float) $template->offset_x_mm || (float) $data['offset_y_mm'] !== (float) $template->offset_y_mm
            || $request->hasFile('background');
        $template->fill($data);
        if ($changedLayout) {
            $template->version++;
        }
        $template->save();
        $this->saveBackground($request, $template);
        ActivityLogService::log('template.updated', 'templates', "Updated print template {$template->name}".($changedLayout ? " (v{$template->version})" : ''), $template);

        return back()->with('success', $changedLayout ? "Template saved as version {$template->version}." : 'Template saved.');
    }

    public function destroy(PrintTemplate $template): RedirectResponse
    {
        if ($template->isInUse()) {
            return back()->with('error', 'A template in use cannot be deleted. Deactivate it instead.');
        }
        ActivityLogService::log('template.deleted', 'templates', "Deleted print template {$template->name}", $template);
        $template->delete();

        return redirect()->route('admin.templates.index')->with('success', 'Template deleted.');
    }

    /** Background scan: private, streamed to authenticated users only. */
    public function background(PrintTemplate $template): StreamedResponse
    {
        abort_unless($template->background_path && Storage::disk('local')->exists($template->background_path), 404);

        return Storage::disk('local')->response($template->background_path, null, ['Cache-Control' => 'private, max-age=3600']);
    }

    private function assertBackground(Request $request): void
    {
        if ($request->hasFile('background')) {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($request->file('background')->getRealPath());
            if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
                throw ValidationException::withMessages(['background' => 'Background must be a JPG or PNG image.']);
            }
        }
    }

    private function saveBackground(Request $request, PrintTemplate $t): void
    {
        if (! $request->hasFile('background')) {
            return;
        }
        $f = $request->file('background');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f->getRealPath());
        if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages(['background' => 'Background must be a JPG or PNG image.']);
        }
        $t->update(['background_path' => $f->storeAs('templates', Str::uuid().'.'.($mime === 'image/png' ? 'png' : 'jpg'), 'local')]);
    }

    /**
     * The editor posts multipart form data, so every value arrives as a string ("12", "0", "1").
     * Store real numbers and booleans, otherwise the layout is corrupted ("0" is truthy, "12" + 0 is "120").
     */
    private function normaliseLayout(array $layout): array
    {
        $num = fn ($v) => is_numeric($v) ? (float) $v : $v;
        $bool = fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN);
        foreach (['preview_crop', 'advice_crop'] as $crop) {
            if (isset($layout[$crop]) && is_array($layout[$crop])) {
                $layout[$crop] = array_map($num, $layout[$crop]);
            }
        }
        foreach ($layout['fields'] ?? [] as $i => $f) {
            foreach (['x', 'y', 'width', 'font_size', 'row_height', 'min_font_size'] as $k) {
                if (isset($f[$k])) {
                    $f[$k] = $num($f[$k]);
                }
            }
            foreach (['max_lines', 'max_rows'] as $k) {
                if (isset($f[$k])) {
                    $f[$k] = (int) $f[$k];
                }
            }
            foreach (['uppercase', 'bold', 'header', 'total'] as $k) {
                if (array_key_exists($k, $f)) {
                    $f[$k] = $bool($f[$k]);
                }
            }
            if (isset($f['char_x']) && is_array($f['char_x'])) {
                $f['char_x'] = array_map($num, $f['char_x']);
            }
            if (isset($f['columns']) && is_array($f['columns'])) {
                $f['columns'] = array_values(array_map(fn ($c) => ['x' => $num($c['x'] ?? 0), 'width' => $num($c['width'] ?? 0)] + $c, $f['columns']));
            }
            if (($f['format'] ?? null) === '') {
                $f['format'] = null;
            }
            $layout['fields'][$i] = $f;
        }

        return $layout;
    }

    private function validated(Request $request): array
    {
        $kind = $request->input('kind', 'cheque');
        $data = $request->validate([
            'name' => 'required|string|max:255', 'kind' => 'required|in:cheque,receipt',
            'paper_width_mm' => 'required|numeric|min:20|max:500', 'paper_height_mm' => 'required|numeric|min:20|max:700',
            'offset_x_mm' => 'nullable|numeric|between:-20,20', 'offset_y_mm' => 'nullable|numeric|between:-20,20',
            'is_active' => 'boolean', 'notes' => 'nullable|string|max:2000',
            'layout' => 'required|array', 'layout.fields' => 'required|array|max:60',
            'layout.advice_crop' => 'nullable|array', 'layout.advice_crop.top' => 'numeric|min:0', 'layout.advice_crop.height' => 'numeric|min:10',
            'layout.preview_crop' => 'nullable|array', 'layout.preview_crop.top' => 'numeric|min:0', 'layout.preview_crop.height' => 'numeric|min:10',
            'layout.fields.*.key' => 'required|string|max:40',
            'layout.fields.*.source' => 'required|in:'.implode(',', self::SOURCES[$kind] ?? self::SOURCES['cheque']),
            'layout.fields.*.x' => 'required|numeric', 'layout.fields.*.y' => 'required|numeric', 'layout.fields.*.width' => 'required|numeric|min:1',
            'layout.fields.*.font_size' => 'required|numeric|min:5|max:40',
            'layout.fields.*.align' => 'required|in:left,center,right',
            'layout.fields.*.max_lines' => 'nullable|integer|min:1|max:10',
            'layout.fields.*.format' => 'nullable|string|max:30', 'layout.fields.*.region' => 'nullable|string|max:30',
            'layout.fields.*.label' => 'nullable|string|max:60', 'layout.fields.*.text' => 'nullable|string|max:200',
            'layout.fields.*.char_x' => 'nullable|array', 'layout.fields.*.char_x.*' => 'numeric',
            'layout.fields.*.uppercase' => 'boolean', 'layout.fields.*.bold' => 'boolean',
            // Table field (breakdown rows)
            'layout.fields.*.type' => 'nullable|in:text,table',
            'layout.fields.*.row_height' => 'nullable|numeric|min:2|max:20',
            'layout.fields.*.max_rows' => 'nullable|integer|min:1|max:60',
            'layout.fields.*.header' => 'nullable|boolean', 'layout.fields.*.total' => 'nullable|boolean',
            'layout.fields.*.total_label' => 'nullable|string|max:30',
            'layout.fields.*.overflow' => 'nullable|in:schedule,shrink',
            'layout.fields.*.min_font_size' => 'nullable|numeric|min:5|max:14',
            'layout.fields.*.columns' => 'nullable|array|max:8',
            'layout.fields.*.columns.*.key' => 'required|in:line_date,reference_no,vendor,particulars,amount',
            'layout.fields.*.columns.*.label' => 'nullable|string|max:30',
            'layout.fields.*.columns.*.x' => 'required|numeric', 'layout.fields.*.columns.*.width' => 'required|numeric|min:2',
            'layout.fields.*.columns.*.align' => 'required|in:left,center,right',
            'background' => 'nullable|file|max:10240',
        ]);
        unset($data['background']);
        // "breakdown" is rows, not text: it belongs to a table field, and a table field shows nothing else.
        foreach ($data['layout']['fields'] as $i => $f) {
            $isTable = ($f['type'] ?? 'text') === 'table';
            if ($isTable !== (($f['source'] ?? null) === 'breakdown')) {
                throw ValidationException::withMessages(["layout.fields.{$i}.source" => $isTable
                    ? 'A table field must show the breakdown.'
                    : 'Field "'.($f['key'] ?? $i).'": the breakdown can only be printed by a table field. Use "Add breakdown table".']);
            }
        }
        $data['layout'] = $this->normaliseLayout($data['layout']);
        $data['offset_x_mm'] ??= 0;
        $data['offset_y_mm'] ??= 0;

        return $data;
    }
}
