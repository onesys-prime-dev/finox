import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import Icon from '@/Components/Icon';
import ReceiptFields from '@/Components/ReceiptFields';
import { Banner, PageHeader, Spinner } from '@/Components/ui';
import { bytes, today } from '@/lib/format';

const OK = ['application/pdf', 'image/jpeg', 'image/png'];
/** A draft whose required details are all filled in (it only needs "Save & next" to be recorded). */
const isReady = (q) => !!(q.receipt_number && q.payee_id && q.amount);

export default function Intake({ categories, queue, maxMb }) {
    const params = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
    const [activeId, setActiveId] = useState(Number(params.get('open')) || queue[0]?.id || null);
    const [drag, setDrag] = useState(false);
    const [progress, setProgress] = useState(null);
    const [problems, setProblems] = useState([]);
    const input = useRef();
    const active = queue.find((q) => q.id === activeId) ?? queue[0];
    const index = Math.max(0, queue.findIndex((q) => q.id === active?.id));
    const ready = queue.filter(isReady).length;
    const go = (step) => { const next = queue[index + step]; if (next) setActiveId(next.id); };

    useEffect(() => { if (!queue.find((q) => q.id === activeId)) setActiveId(queue[0]?.id ?? null); }, [queue.length]);

    const upload = (fileList) => {
        const files = [...fileList];
        const bad = files.filter((f) => !OK.includes(f.type) || f.size > maxMb * 1048576).map((f) => `${f.name}: ${!OK.includes(f.type) ? 'only PDF, JPG or PNG' : `larger than ${maxMb} MB`}`);
        setProblems(bad);
        const good = files.filter((f) => OK.includes(f.type) && f.size <= maxMb * 1048576).slice(0, 10);
        if (!good.length) return;
        router.post(route('receipts.upload'), { files: good }, {
            forceFormData: true, preserveScroll: true, onProgress: (e) => setProgress(e?.percentage ?? 0),
            onFinish: () => setProgress(null),
            onSuccess: () => router.reload({ only: ['queue'], onSuccess: () => {} }),
        });
    };

    const drop = (e) => { e.preventDefault(); setDrag(false); upload(e.dataTransfer.files); };
    const hasQueue = queue.length > 0;

    return (
        <AuthenticatedLayout>
            <Head title="Receipt intake" />
            {/* On large screens the page frame is fixed to the window: only the preview and the form scroll, inside their own panels. */}
            <div className="flex flex-col xl:h-[calc(100vh-7rem)] xl:overflow-hidden">
            <PageHeader className="mb-3 shrink-0" title="Receipt intake" subtitle="Drop one or many files. Each becomes a draft — fill in the details beside the preview."
                back={{ href: route('receipts.index'), label: 'All receipts' }}
                actions={<Link href={route('receipts.new')} className="btn-secondary"><Icon name="edit" className="h-4 w-4" /> Enter without a file</Link>} />

            <div onDragOver={(e) => { e.preventDefault(); setDrag(true); }} onDragLeave={() => setDrag(false)} onDrop={drop}
                className={`mb-3 shrink-0 flex cursor-pointer items-center justify-center rounded-xl border-2 border-dashed text-center transition ${hasQueue ? 'gap-3 px-4 py-3' : 'flex-col px-4 py-12'} ${drag ? 'border-brand-500 bg-brand-50' : 'border-slate-300 bg-white hover:border-brand-400'}`}
                onClick={() => input.current.click()} role="button" tabIndex={0} onKeyDown={(e) => e.key === 'Enter' && input.current.click()}>
                <input ref={input} type="file" multiple accept=".pdf,.jpg,.jpeg,.png" className="hidden" onChange={(e) => { upload(e.target.files); e.target.value = ''; }} />
                {progress !== null ? (
                    <div className="w-full max-w-sm"><p className="mb-2 flex items-center justify-center gap-2 text-sm font-medium text-slate-700"><Spinner /> Uploading… {Math.round(progress)}%</p>
                        <div className="h-2 overflow-hidden rounded-full bg-slate-100"><div className="h-full bg-brand-500 transition-all" style={{ width: `${progress}%` }} /></div></div>
                ) : hasQueue ? (
                    <>
                        <Icon name="upload" className="h-5 w-5 text-brand-500" />
                        <p className="text-sm font-medium text-slate-800">Drop more files here, or <span className="text-brand-700">browse</span></p>
                        <p className="hidden text-xs text-slate-500 sm:block">PDF, JPG or PNG · up to {maxMb} MB each · 10 at a time</p>
                    </>
                ) : (
                    <>
                        <Icon name="upload" className="mb-2 h-7 w-7 text-brand-500" />
                        <p className="text-sm font-medium text-slate-800">Drag files here, or <span className="text-brand-700">browse</span></p>
                        <p className="mt-1 text-xs text-slate-500">PDF, JPG or PNG · up to {maxMb} MB each · 10 at a time</p>
                    </>
                )}
            </div>
            {problems.length > 0 && <div className="mb-3 shrink-0"><Banner tone="warn"><p className="font-medium">Some files were skipped:</p><ul className="ml-4 list-disc">{problems.map((p) => <li key={p}>{p}</li>)}</ul></Banner></div>}

            {hasQueue && (
                <div className="grid gap-5 lg:grid-cols-[17rem_minmax(0,1fr)] xl:min-h-0 xl:flex-1 xl:grid-rows-[minmax(0,1fr)]">
                    <div className="card flex max-h-[70vh] min-h-[12rem] flex-col self-start overflow-hidden xl:max-h-full">
                        <div className="shrink-0 border-b border-slate-100 px-4 py-3">
                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">To fill in · {queue.length}</p>
                            <p className="mt-0.5 text-xs text-slate-500">{ready > 0 ? `${ready} with details complete` : 'Pick a file, then fill in its details'}</p>
                        </div>
                        <ul className="min-h-0 flex-1 overflow-y-auto scroll-thin">
                            {queue.map((q, i) => (
                                <li key={q.id}>
                                    <button onClick={() => setActiveId(q.id)} aria-current={active?.id === q.id}
                                        className={`flex w-full items-center gap-3 border-b border-l-4 border-b-slate-100 px-3 py-2.5 text-left transition ${active?.id === q.id ? 'border-l-brand-600 bg-brand-50' : 'border-l-transparent hover:bg-slate-50'}`}>
                                        <Thumb file={q.file} />
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium" title={q.file?.name}>{q.receipt_number || q.payee_name || `File ${i + 1}`}</p>
                                            <p className={`truncate text-xs ${isReady(q) ? 'text-emerald-700' : 'text-amber-700'}`}>{isReady(q) ? 'Details complete' : 'Details needed'}{q.file ? ` · ${bytes(q.file.size)}` : ''}</p>
                                        </div>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                    {active && <QueueItem key={active.id} item={active} categories={categories} position={index + 1} total={queue.length}
                        onPrev={index > 0 ? () => go(-1) : null} onNext={index < queue.length - 1 ? () => go(1) : null} />}
                </div>
            )}
            </div>
        </AuthenticatedLayout>
    );
}

/** Small preview in the queue; the original file names are usually phone-generated codes, so the picture is what identifies a file. */
function Thumb({ file }) {
    const img = file?.mime?.startsWith('image/');
    return (
        <span className="grid h-11 w-11 shrink-0 place-items-center overflow-hidden rounded-lg border border-slate-200 bg-slate-50 text-slate-400">
            {img ? <img src={route('documents.show', file.id)} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover" /> : <Icon name={file ? 'file' : 'receipt'} className="h-5 w-5" />}
        </span>
    );
}

function QueueItem({ item, categories, position, total, onPrev, onNext }) {
    const form = useForm({
        receipt_number: item.receipt_number ?? '', receipt_date: item.receipt_date ?? today(), payee_id: item.payee_id ?? '',
        description: item.description ?? '', amount: item.amount ?? '', tax_amount: item.tax_amount === '0.00' ? '' : item.tax_amount, tax_rate: item.tax_rate ?? '',
        category_id: item.category_id ?? '', cheque_id: item.cheque_id ?? '', reference_no: item.reference_no ?? '', remarks: item.remarks ?? '', duplicate_override_reason: '',
        _next: 'record', _queue: true, _method: 'put',
    });
    const [payee, setPayee] = useState(item.payee_id ? { id: item.payee_id, name: item.payee_name } : null);
    const [removing, setRemoving] = useState(false);
    const [busy, setBusy] = useState(false);
    const [zoom, setZoom] = useState(false);
    const isImg = item.file?.mime?.startsWith('image/');
    const remove = () => router.post(route('receipts.discard', item.id), {}, {
        preserveScroll: true, onStart: () => setBusy(true), onFinish: () => { setBusy(false); setRemoving(false); },
    });
    const save = (next) => {
        form.transform((d) => ({ ...d, _next: next }));
        form.post(route('receipts.update', item.id), { preserveScroll: true });
    };
    useEffect(() => {
        const h = (e) => { if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); save('record'); } };
        window.addEventListener('keydown', h);
        return () => window.removeEventListener('keydown', h);
    });

    return (
        <div className="grid gap-5 xl:h-full xl:min-h-0 xl:grid-cols-2 xl:grid-rows-[minmax(0,1fr)]">
            <div className="card flex flex-col overflow-hidden xl:h-full">
                <div className="flex shrink-0 items-center gap-2 border-b border-slate-100 px-3 py-2">
                    <div className="flex shrink-0 items-center">
                        <button className="btn-ghost btn-sm !px-1.5" disabled={!onPrev} onClick={onPrev} aria-label="Previous file"><Icon name="chevronLeft" className="h-4 w-4" /></button>
                        <span className="px-1 text-xs tabular-nums text-slate-500">{position} / {total}</span>
                        <button className="btn-ghost btn-sm !px-1.5" disabled={!onNext} onClick={onNext} aria-label="Next file"><Icon name="chevronRight" className="h-4 w-4" /></button>
                    </div>
                    <p className="min-w-0 flex-1 truncate text-sm font-medium text-slate-800" title={item.file?.name}>{item.file?.name ?? 'No file'}</p>
                    {item.file && <a className="btn-ghost btn-sm" href={route('documents.download', item.file.id)} title="Download" aria-label="Download file"><Icon name="download" className="h-4 w-4" /></a>}
                    <button type="button" className="btn-ghost btn-sm text-red-600 hover:bg-red-50" title="Remove from queue" aria-label="Remove from queue" onClick={() => setRemoving(true)}><Icon name="trash" className="h-4 w-4" /></button>
                </div>
                <div className="relative h-[60vh] min-h-[22rem] bg-slate-100 xl:h-auto xl:min-h-0 xl:flex-1">
                    {item.file ? (isImg
                        ? <div className="absolute inset-0 flex items-start justify-center overflow-auto p-3 scroll-thin">
                            <img src={route('documents.show', item.file.id)} alt={`Preview of ${item.file.name}`} onClick={() => setZoom(!zoom)}
                                title={zoom ? 'Click to fit the picture' : 'Click to zoom in'}
                                className={`rounded shadow ${zoom ? 'max-w-none cursor-zoom-out' : 'max-w-full cursor-zoom-in'}`} />
                        </div>
                        : <iframe className="absolute inset-0 h-full w-full" src={route('documents.show', item.file.id)} title="Receipt preview" />)
                        : <div className="absolute inset-0 flex items-center justify-center text-sm text-slate-400">No file attached</div>}
                </div>
            </div>
            <form onSubmit={(e) => { e.preventDefault(); save('record'); }} className="card flex flex-col overflow-hidden xl:h-full">
                {/* the fields scroll; the buttons stay at the bottom of the panel */}
                <div className="scroll-thin p-5 xl:min-h-0 xl:flex-1 xl:overflow-y-auto">
                    <div className="mb-4">
                        <h2 className="text-base font-semibold text-slate-900">Receipt details</h2>
                        <p className="text-xs text-slate-500">Read these from the picture. <span className="text-red-500">*</span> = required to record the receipt.</p>
                    </div>
                    <ReceiptFields data={form.data} set={form.setData} errors={form.errors} categories={categories} payee={payee} setPayee={setPayee} ignoreId={item.id} autoFocus compact />
                    <p className="mt-4 text-xs text-slate-500">Uploading does not verify a receipt — a finance user verifies it afterwards. {total - 1 > 0 ? `${total - 1} more in the queue.` : 'This is the last one.'}</p>
                </div>
                <div className="sticky bottom-0 z-10 flex shrink-0 flex-wrap items-center gap-2 border-t border-slate-200 bg-white px-5 py-3 xl:static">
                    <button type="button" className="btn-ghost text-red-600 hover:bg-red-50" disabled={form.processing} onClick={() => setRemoving(true)}><Icon name="trash" className="h-4 w-4" /> Remove</button>
                    <button type="button" className="btn-secondary ml-auto" disabled={form.processing} onClick={() => save('draft')}>Save draft</button>
                    <button className="btn-primary" disabled={form.processing}>{form.processing && <Spinner />} {total > 1 && position < total ? 'Save & next' : 'Save'} <span className="kbd !border-brand-400 !bg-brand-700 !text-brand-100">Ctrl ↵</span></button>
                </div>
            </form>
            <ConfirmDialog show={removing} onClose={() => setRemoving(false)} tone="danger" processing={busy}
                title="Remove this file from the queue?" confirmLabel="Remove from queue" onConfirm={remove}
                message={`${item.file?.name ?? 'This draft'} will leave the queue and no receipt will be created from it.`}
                warning="Use this when the wrong file was uploaded. The upload stays in the activity log as a removed draft; to use the receipt later, upload the file again." />
        </div>
    );
}
