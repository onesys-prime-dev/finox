import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Icon from '@/Components/Icon';
import PrintSheet, { SheetFrame } from '@/Components/PrintSheet';
import { Banner, Field, PageHeader, Spinner } from '@/Components/ui';

const SAMPLE_BREAKDOWN = {
    rows: [['2026-11-02', 'OR-1001', 'Sample Supplies Inc.', 'Office supplies', '10,000.00'], ['2026-11-03', 'INV-2201', 'Toner World Corp.', 'Toner cartridges', '25,500.50'], ['2026-11-04', 'OR-1003', 'Sample Supplies Inc.', 'Bond paper', '3,000.00']]
        .map(([line_date, reference_no, vendor, particulars, amount]) => ({ line_date, reference_no, vendor, particulars, amount })),
    count: 3, total: '38,500.50',
};
const SAMPLE = {
    cheque: { payee_line: 'SAMPLE PAYEE NAME CORPORATION', amount_number: '1,234,567.89', amount_words: 'ONE MILLION TWO HUNDRED THIRTY FOUR THOUSAND FIVE HUNDRED SIXTY SEVEN AND 89/100 PESOS ONLY', date: '2026-11-09', check_no: '0000094658', purpose: 'Sample purpose of payment', reference: 'REF-0001', breakdown: SAMPLE_BREAKDOWN, prepared_by: 'Prepared By', printed_on: '2026-11-09', bank_account_name: 'ACCOUNT NAME' },
    receipt: { org_name: 'Your Organization', org_address: '123 Sample Street, City', receipt_no: 'OR-0001', date: '2026-11-09', vendor: 'Sample Vendor Inc.', description: 'Sample description of goods', amount_number: '1,234.56', tax: '₱148.15', reference: 'REF-1', prepared_by: 'Prepared By', printed_on: '2026-11-09' },
};
const DEFAULT_COLUMNS = [
    { key: 'line_date', label: 'DATE', x: 0, width: 18, align: 'left' }, { key: 'reference_no', label: 'OR / INV NO.', x: 20, width: 28, align: 'left' },
    { key: 'vendor', label: 'VENDOR', x: 50, width: 42, align: 'left' }, { key: 'particulars', label: 'PARTICULARS', x: 94, width: 52, align: 'left' },
    { key: 'amount', label: 'AMOUNT', x: 148, width: 32, align: 'right' },
];
const COLUMN_KEYS = [['line_date', 'Date'], ['reference_no', 'OR / invoice no.'], ['vendor', 'Vendor'], ['particulars', 'Particulars'], ['amount', 'Amount']];
const MM = 3.7795;
const FORMATS = [['', 'Plain text'], ['date_segmented', 'Date in boxes (MMDDYYYY)'], ['date_long', 'Long date'], ['amount_stars', 'Amount with ** **']];

export default function Edit({ template, sources, kind }) {
    const editing = !!template;
    const form = useForm({
        name: template?.name ?? '', kind, paper_width_mm: template?.paper_width_mm ?? (kind === 'cheque' ? 200 : 148), paper_height_mm: template?.paper_height_mm ?? (kind === 'cheque' ? 280 : 210),
        offset_x_mm: template?.offset_x_mm ?? 0, offset_y_mm: template?.offset_y_mm ?? 0, is_active: template?.is_active ?? true, notes: template?.notes ?? '',
        layout: template?.layout ?? { fields: [] }, background: null,
    });
    const { data, setData } = form;
    const fields = data.layout.fields;
    const [sel, setSel] = useState(fields.length ? 0 : null);
    const [bgUrl, setBgUrl] = useState(template?.background_url ?? null);
    const [showBg, setShowBg] = useState(true);
    const [scale, setScale] = useState(0.62);
    const drag = useRef(null);
    const tpl = { ...data, layout: data.layout, background_url: showBg ? bgUrl : null, paper_width_mm: Number(data.paper_width_mm) || 100, paper_height_mm: Number(data.paper_height_mm) || 100, offset_x_mm: 0, offset_y_mm: 0 };

    const setField = (i, patch) => setData('layout', { ...data.layout, fields: fields.map((f, k) => (k === i ? { ...f, ...patch } : f)) });

    const onDown = (e, i) => {
        e.preventDefault(); setSel(i);
        drag.current = { i, sx: e.clientX, sy: e.clientY, fx: fields[i].x, fy: fields[i].y, cx: fields[i].char_x };
    };
    useEffect(() => {
        const move = (e) => {
            const d = drag.current; if (!d) return;
            const dx = (e.clientX - d.sx) / (MM * scale), dy = (e.clientY - d.sy) / (MM * scale);
            const patch = { x: Math.round((d.fx + dx) * 10) / 10, y: Math.round((d.fy + dy) * 10) / 10 };
            if (d.cx?.length) patch.char_x = d.cx.map((c) => Math.round((c + dx) * 10) / 10);
            setField(d.i, patch);
        };
        const up = () => { drag.current = null; };
        window.addEventListener('mousemove', move); window.addEventListener('mouseup', up);
        return () => { window.removeEventListener('mousemove', move); window.removeEventListener('mouseup', up); };
    });

    // Arrow keys nudge the selected field by 0.1mm (1mm with Shift).
    useEffect(() => {
        const h = (e) => {
            if (sel === null || ['INPUT', 'SELECT', 'TEXTAREA'].includes(e.target.tagName)) return;
            const s = e.shiftKey ? 1 : 0.1; const f = fields[sel]; if (!f) return;
            const m = { ArrowLeft: [-s, 0], ArrowRight: [s, 0], ArrowUp: [0, -s], ArrowDown: [0, s] }[e.key]; if (!m) return;
            e.preventDefault();
            const patch = { x: Math.round((f.x + m[0]) * 10) / 10, y: Math.round((f.y + m[1]) * 10) / 10 };
            if (f.char_x?.length) patch.char_x = f.char_x.map((c) => Math.round((c + m[0]) * 10) / 10);
            setField(sel, patch);
        };
        window.addEventListener('keydown', h);
        return () => window.removeEventListener('keydown', h);
    });

    const add = () => {
        const n = { key: `field_${fields.length + 1}`, source: sources[kind][0], x: 20, y: 20 + fields.length * 8, width: 80, font_size: 11, align: 'left', uppercase: kind === 'cheque', max_lines: 1, format: '', region: kind };
        setData('layout', { ...data.layout, fields: [...fields, n] }); setSel(fields.length);
    };
    const addTable = () => {
        const n = { key: 'breakdown', source: 'breakdown', type: 'table', region: 'advice', x: 12, y: 100, width: 180, font_size: 9, align: 'left', uppercase: false, max_lines: 1,
            row_height: 4, max_rows: 15, header: true, total: true, total_label: 'TOTAL', overflow: 'schedule', min_font_size: 7, columns: DEFAULT_COLUMNS.map((c) => ({ ...c })) };
        setData('layout', { ...data.layout, fields: [...fields, n] }); setSel(fields.length);
    };
    const remove = (i) => { setData('layout', { ...data.layout, fields: fields.filter((_, k) => k !== i) }); setSel(null); };
    const f = sel !== null ? fields[sel] : null;

    const save = () => {
        form.transform((d) => ({
            ...d, is_active: d.is_active ? 1 : 0, background: d.background,
            layout: { ...d.layout, fields: d.layout.fields.map((x) => ({ ...x, uppercase: x.uppercase ? 1 : 0, bold: x.bold ? 1 : 0, format: x.format || null, max_lines: x.max_lines || 1, ...(x.type === 'table' ? { header: x.header === false || x.header === 0 ? 0 : 1, total: x.total === false || x.total === 0 ? 0 : 1 } : {}) })) },
        }));
        editing ? form.post(route('admin.templates.update', template.id), { preserveScroll: true, forceFormData: true }) : form.post(route('admin.templates.store'), { forceFormData: true });
    };

    return (
        <AuthenticatedLayout>
            <Head title={editing ? `Edit ${template.name}` : 'New template'} />
            <PageHeader title={editing ? template.name : `New ${kind} template`} subtitle={editing ? `Version ${template.version}. Saving a change creates a new version; earlier prints keep the version they used.` : 'Drag fields over the scan to position them.'}
                back={{ href: route('admin.templates.index'), label: 'All templates' }}
                actions={<>{editing && <Link href={route('printing.calibrate', template.id)} className="btn-secondary"><Icon name="ruler" className="h-4 w-4" /> Calibrate</Link>}<button className="btn-primary" disabled={form.processing || !data.name.trim()} onClick={save}>{form.processing ? <Spinner /> : <Icon name="save" className="h-4 w-4" />} Save template</button></>} />
            {Object.keys(form.errors).length > 0 && <div className="mb-4"><Banner tone="bad"><ul className="list-disc pl-4">{Object.values(form.errors).slice(0, 5).map((e, i) => <li key={i}>{e}</li>)}</ul></Banner></div>}

            <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="space-y-3">
                    <div className="flex flex-wrap items-center gap-4 text-sm">
                        <label className="flex items-center gap-2"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={showBg} onChange={(e) => setShowBg(e.target.checked)} /> Show scan</label>
                        <label className="flex items-center gap-2">Zoom <input type="range" min="0.3" max="1.4" step="0.05" value={scale} onChange={(e) => setScale(Number(e.target.value))} /></label>
                        <span className="text-xs text-slate-500">Drag a field to move it · arrow keys nudge 0.1 mm (Shift = 1 mm)</span>
                    </div>
                    <div className="max-h-[78vh] overflow-auto rounded-xl bg-slate-200 p-4">
                        <SheetFrame template={tpl} scale={scale}>
                            <PrintSheet template={tpl} values={SAMPLE[kind]} showBackground guides interactive scale={scale} selected={sel} onSelect={setSel} onFieldMouseDown={onDown} />
                        </SheetFrame>
                    </div>
                </div>

                <div className="space-y-4">
                    <div className="card card-pad space-y-3">
                        <p className="card-title">Template</p>
                        <Field label="Name" required><input className="input" value={data.name} onChange={(e) => setData('name', e.target.value)} /></Field>
                        <div className="grid grid-cols-2 gap-2">
                            <Field label="Paper width (mm)"><input type="number" step="0.1" className="input" value={data.paper_width_mm} onChange={(e) => setData('paper_width_mm', e.target.value)} /></Field>
                            <Field label="Paper height (mm)"><input type="number" step="0.1" className="input" value={data.paper_height_mm} onChange={(e) => setData('paper_height_mm', e.target.value)} /></Field>
                            <Field label="Offset X (mm)"><input type="number" step="0.1" className="input" value={data.offset_x_mm} onChange={(e) => setData('offset_x_mm', e.target.value)} /></Field>
                            <Field label="Offset Y (mm)"><input type="number" step="0.1" className="input" value={data.offset_y_mm} onChange={(e) => setData('offset_y_mm', e.target.value)} /></Field>
                        </div>
                        <Field label="Scan / background image" hint="Preview only — never printed. JPG or PNG."><input type="file" accept=".jpg,.jpeg,.png" className="input" onChange={(e) => { const file = e.target.files[0]; setData('background', file ?? null); if (file) setBgUrl(URL.createObjectURL(file)); }} /></Field>
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} /> Active</label>
                    </div>

                    <div className="card">
                        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3"><p className="card-title">Fields</p><button className="btn-secondary btn-sm" onClick={add}><Icon name="plus" className="h-3.5 w-3.5" /> Add</button>{kind === 'cheque' && !fields.some((x) => x.type === 'table') && <button className="btn-secondary btn-sm" onClick={addTable}><Icon name="list" className="h-3.5 w-3.5" /> Add table</button>}</div>
                        <ul className="max-h-48 divide-y divide-slate-100 overflow-auto">
                            {fields.map((x, i) => <li key={i}><button className={`flex w-full items-center justify-between px-5 py-2 text-left text-sm ${sel === i ? 'bg-brand-50 font-medium text-brand-800' : 'hover:bg-slate-50'}`} onClick={() => setSel(i)}><span>{x.key}<span className="ml-2 text-xs text-slate-400">{x.source}</span></span><span className="font-mono text-[11px] text-slate-400">{x.x}, {x.y}</span></button></li>)}
                        </ul>
                    </div>

                    {f && f.type === 'table' && <TablePanel f={f} sel={sel} setField={setField} remove={remove} />}
                    {f && f.type !== 'table' && (
                        <div className="card card-pad space-y-3">
                            <div className="flex items-center justify-between"><p className="card-title">Field: {f.key}</p><button className="text-xs font-medium text-red-600 hover:underline" onClick={() => remove(sel)}>Remove</button></div>
                            <Field label="Name"><input className="input" value={f.key} onChange={(e) => setField(sel, { key: e.target.value })} /></Field>
                            <Field label="Shows"><select className="input" value={f.source} onChange={(e) => setField(sel, { source: e.target.value })}>{sources[kind].filter((s) => s !== 'breakdown').map((s) => <option key={s}>{s}</option>)}</select></Field>
                            {f.source === 'free_text' && <Field label="Text"><input className="input" value={f.text ?? ''} onChange={(e) => setField(sel, { text: e.target.value })} /></Field>}
                            <div className="grid grid-cols-3 gap-2">
                                <Field label="X (mm)"><input type="number" step="0.1" className="input" value={f.x} onChange={(e) => setField(sel, { x: Number(e.target.value) })} /></Field>
                                <Field label="Y (mm)"><input type="number" step="0.1" className="input" value={f.y} onChange={(e) => setField(sel, { y: Number(e.target.value) })} /></Field>
                                <Field label="Width"><input type="number" step="0.1" className="input" value={f.width} onChange={(e) => setField(sel, { width: Number(e.target.value) })} /></Field>
                                <Field label="Font (pt)"><input type="number" step="0.5" className="input" value={f.font_size} onChange={(e) => setField(sel, { font_size: Number(e.target.value) })} /></Field>
                                <Field label="Max lines"><input type="number" min="1" className="input" value={f.max_lines ?? 1} onChange={(e) => setField(sel, { max_lines: Number(e.target.value) })} /></Field>
                                <Field label="Align"><select className="input" value={f.align} onChange={(e) => setField(sel, { align: e.target.value })}><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select></Field>
                            </div>
                            <Field label="Format"><select className="input" value={f.format ?? ''} onChange={(e) => setField(sel, { format: e.target.value, ...(e.target.value === 'date_segmented' && !f.char_x?.length ? { char_x: [0, 5, 10, 15, 20, 25, 30, 35].map((o) => f.x + 2 + o) } : {}) })}>{FORMATS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}</select></Field>
                            {f.format === 'date_segmented' && (
                                <Field label="Character centres (mm, 8 values)" hint="X position of the centre of each box: M M D D Y Y Y Y.">
                                    <input className="input font-mono text-xs" value={(f.char_x ?? []).join(', ')} onChange={(e) => setField(sel, { char_x: e.target.value.split(',').map((v) => Number(v.trim())).filter((v) => !Number.isNaN(v)) })} /></Field>
                            )}
                            <Field label="Label before text (optional)"><input className="input" value={f.label ?? ''} onChange={(e) => setField(sel, { label: e.target.value })} /></Field>
                            <div className="flex gap-5 text-sm">
                                <label className="flex items-center gap-2"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={!!f.uppercase} onChange={(e) => setField(sel, { uppercase: e.target.checked })} /> UPPERCASE</label>
                                <label className="flex items-center gap-2"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={!!f.bold} onChange={(e) => setField(sel, { bold: e.target.checked })} /> Bold</label>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}


function TablePanel({ f, sel, setField, remove }) {
    const cols = f.columns ?? [];
    const setCol = (i, patch) => setField(sel, { columns: cols.map((c, k) => (k === i ? { ...c, ...patch } : c)) });
    const num = (k, step = 0.1) => (e) => setField(sel, { [k]: Number(e.target.value) });
    return (
        <div className="card card-pad space-y-3">
            <div className="flex items-center justify-between"><p className="card-title">Breakdown table</p><button className="text-xs font-medium text-red-600 hover:underline" onClick={() => remove(sel)}>Remove</button></div>
            <p className="text-xs text-slate-500">Prints the cheque's breakdown rows. Drag the block on the sheet to position it.</p>
            <div className="grid grid-cols-3 gap-2">
                <Field label="X (mm)"><input type="number" step="0.1" className="input" value={f.x} onChange={num('x')} /></Field>
                <Field label="Y (mm)"><input type="number" step="0.1" className="input" value={f.y} onChange={num('y')} /></Field>
                <Field label="Width"><input type="number" step="0.1" className="input" value={f.width} onChange={num('width')} /></Field>
                <Field label="Font (pt)"><input type="number" step="0.5" className="input" value={f.font_size} onChange={num('font_size')} /></Field>
                <Field label="Row height"><input type="number" step="0.1" min="2" className="input" value={f.row_height ?? 4} onChange={num('row_height')} /></Field>
                <Field label="Rows that fit"><input type="number" min="1" className="input" value={f.max_rows ?? 15} onChange={(e) => setField(sel, { max_rows: Number(e.target.value) })} /></Field>
            </div>
            <Field label="When there are more rows" hint={f.overflow === 'shrink' ? 'Shrinks the text down to the minimum size, then uses the attached schedule.' : 'Prints the rows that fit plus "see attached schedule"; the full list prints on plain A4.'}>
                <select className="input" value={f.overflow ?? 'schedule'} onChange={(e) => setField(sel, { overflow: e.target.value })}><option value="schedule">Attached schedule</option><option value="shrink">Shrink to fit first</option></select>
            </Field>
            {f.overflow === 'shrink' && <Field label="Smallest font (pt)"><input type="number" step="0.5" className="input" value={f.min_font_size ?? 7} onChange={num('min_font_size')} /></Field>}
            <div className="flex flex-wrap gap-5 text-sm">
                <label className="flex items-center gap-2"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={f.header !== false && f.header !== 0} onChange={(e) => setField(sel, { header: e.target.checked })} /> Header row</label>
                <label className="flex items-center gap-2"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={f.total !== false && f.total !== 0} onChange={(e) => setField(sel, { total: e.target.checked })} /> Total row</label>
                <label className="flex items-center gap-2"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={!!f.uppercase} onChange={(e) => setField(sel, { uppercase: e.target.checked })} /> UPPERCASE</label>
            </div>
            <div>
                <p className="label">Columns (x is measured from the table's left edge)</p>
                <div className="space-y-1.5">
                    {cols.map((c, i) => (
                        <div key={i} className="grid grid-cols-[1fr_1fr_3.2rem_3.2rem_4.2rem_1.5rem] items-center gap-1">
                            <select className="input !py-1 text-xs" value={c.key} onChange={(e) => setCol(i, { key: e.target.value })}>{COLUMN_KEYS.map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select>
                            <input className="input !py-1 text-xs" placeholder="Heading" value={c.label ?? ''} onChange={(e) => setCol(i, { label: e.target.value })} />
                            <input type="number" step="0.5" className="input !px-1 !py-1 text-xs" title="x (mm)" value={c.x} onChange={(e) => setCol(i, { x: Number(e.target.value) })} />
                            <input type="number" step="0.5" className="input !px-1 !py-1 text-xs" title="width (mm)" value={c.width} onChange={(e) => setCol(i, { width: Number(e.target.value) })} />
                            <select className="input !px-1 !py-1 text-xs" value={c.align} onChange={(e) => setCol(i, { align: e.target.value })}><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select>
                            <button type="button" className="text-slate-400 hover:text-red-600" aria-label="Remove column" onClick={() => setField(sel, { columns: cols.filter((_, k) => k !== i) })}><Icon name="x" className="h-3.5 w-3.5" /></button>
                        </div>
                    ))}
                </div>
                {cols.length < 8 && <button type="button" className="mt-2 text-xs font-medium text-brand-700 hover:underline" onClick={() => setField(sel, { columns: [...cols, { key: 'particulars', label: '', x: 0, width: 30, align: 'left' }] })}>+ Add column</button>}
            </div>
        </div>
    );
}
