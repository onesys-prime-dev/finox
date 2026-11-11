import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Icon from '@/Components/Icon';
import ReceiptFields from '@/Components/ReceiptFields';
import { Banner, PageHeader, Spinner } from '@/Components/ui';
import { bytes, today, token } from '@/lib/format';

const OK = ['application/pdf', 'image/jpeg', 'image/png'];

export default function Intake({ categories, queue, maxMb }) {
    const params = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
    const [activeId, setActiveId] = useState(Number(params.get('open')) || queue[0]?.id || null);
    const [drag, setDrag] = useState(false);
    const [progress, setProgress] = useState(null);
    const [problems, setProblems] = useState([]);
    const input = useRef();
    const active = queue.find((q) => q.id === activeId) ?? queue[0];

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

    return (
        <AuthenticatedLayout>
            <Head title="Receipt intake" />
            <PageHeader title="Receipt intake" subtitle="Drop one or many files. Each becomes a draft — fill in the details beside the preview."
                back={{ href: route('receipts.index'), label: 'All receipts' }}
                actions={<Link href={route('receipts.new')} className="btn-secondary"><Icon name="edit" className="h-4 w-4" /> Enter without a file</Link>} />

            <div onDragOver={(e) => { e.preventDefault(); setDrag(true); }} onDragLeave={() => setDrag(false)} onDrop={drop}
                className={`mb-5 flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-4 py-${queue.length ? 5 : 12} text-center transition ${drag ? 'border-brand-500 bg-brand-50' : 'border-slate-300 bg-white hover:border-brand-400'}`}
                onClick={() => input.current.click()} role="button" tabIndex={0} onKeyDown={(e) => e.key === 'Enter' && input.current.click()}>
                <input ref={input} type="file" multiple accept=".pdf,.jpg,.jpeg,.png" className="hidden" onChange={(e) => { upload(e.target.files); e.target.value = ''; }} />
                {progress !== null ? (
                    <div className="w-full max-w-sm"><p className="mb-2 flex items-center justify-center gap-2 text-sm font-medium text-slate-700"><Spinner /> Uploading… {Math.round(progress)}%</p>
                        <div className="h-2 overflow-hidden rounded-full bg-slate-100"><div className="h-full bg-brand-500 transition-all" style={{ width: `${progress}%` }} /></div></div>
                ) : (
                    <>
                        <Icon name="upload" className="mb-2 h-7 w-7 text-brand-500" />
                        <p className="text-sm font-medium text-slate-800">Drag files here, or <span className="text-brand-700">browse</span></p>
                        <p className="mt-1 text-xs text-slate-500">PDF, JPG or PNG · up to {maxMb} MB each · 10 at a time</p>
                    </>
                )}
            </div>
            {problems.length > 0 && <div className="mb-4"><Banner tone="warn"><p className="font-medium">Some files were skipped:</p><ul className="ml-4 list-disc">{problems.map((p) => <li key={p}>{p}</li>)}</ul></Banner></div>}

            {queue.length > 0 && (
                <div className="grid gap-5 lg:grid-cols-[16rem_minmax(0,1fr)]">
                    <div className="card max-h-[70vh] overflow-y-auto scroll-thin">
                        <p className="border-b border-slate-100 px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Queue · {queue.length}</p>
                        <ul>
                            {queue.map((q, i) => (
                                <li key={q.id}>
                                    <button onClick={() => setActiveId(q.id)} className={`flex w-full items-center gap-3 border-b border-slate-100 px-4 py-3 text-left transition ${active?.id === q.id ? 'bg-brand-50' : 'hover:bg-slate-50'}`}>
                                        <Icon name={q.file?.mime === 'application/pdf' ? 'file' : 'receipt'} className="h-5 w-5 shrink-0 text-slate-400" />
                                        <div className="min-w-0"><p className="truncate text-sm font-medium">{q.receipt_number || q.file?.name || `Draft #${q.id}`}</p><p className="truncate text-xs text-slate-500">{q.payee_name ?? 'Details needed'}{q.file ? ` · ${bytes(q.file.size)}` : ''}</p></div>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                    {active && <QueueItem key={active.id} item={active} categories={categories} remaining={queue.length} />}
                </div>
            )}
        </AuthenticatedLayout>
    );
}

function QueueItem({ item, categories, remaining }) {
    const form = useForm({
        receipt_number: item.receipt_number ?? '', receipt_date: item.receipt_date ?? today(), payee_id: item.payee_id ?? '',
        description: item.description ?? '', amount: item.amount ?? '', tax_amount: item.tax_amount === '0.00' ? '' : item.tax_amount, tax_rate: item.tax_rate ?? '',
        category_id: item.category_id ?? '', cheque_id: item.cheque_id ?? '', reference_no: item.reference_no ?? '', remarks: item.remarks ?? '', duplicate_override_reason: '',
        _next: 'record', _queue: true, _method: 'put',
    });
    const [payee, setPayee] = useState(item.payee_id ? { id: item.payee_id, name: item.payee_name } : null);
    const isImg = item.file?.mime?.startsWith('image/');
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
        <div className="grid gap-5 xl:grid-cols-2">
            <div className="card overflow-hidden xl:sticky xl:top-24 xl:self-start">
                <div className="flex items-center justify-between border-b border-slate-100 px-4 py-2.5"><p className="truncate text-sm font-medium">{item.file?.name ?? 'No file'}</p>
                    {item.file && <a className="btn-ghost btn-sm" href={route('documents.download', item.file.id)}><Icon name="download" className="h-4 w-4" /></a>}</div>
                <div className="h-[60vh] bg-slate-100">
                    {item.file ? (isImg ? <div className="flex h-full items-start justify-center overflow-auto p-3"><img src={route('documents.show', item.file.id)} alt="" className="max-w-full rounded shadow" /></div> : <iframe className="h-full w-full" src={route('documents.show', item.file.id)} title="Receipt preview" />)
                        : <div className="flex h-full items-center justify-center text-sm text-slate-400">No file attached</div>}
                </div>
            </div>
            <form onSubmit={(e) => { e.preventDefault(); save('record'); }} className="card card-pad">
                <ReceiptFields data={form.data} set={form.setData} errors={form.errors} categories={categories} payee={payee} setPayee={setPayee} ignoreId={item.id} autoFocus />
                <div className="mt-5 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
                    <button type="button" className="btn-secondary" disabled={form.processing} onClick={() => save('draft')}>Save draft</button>
                    <button className="btn-primary ml-auto" disabled={form.processing}>{form.processing && <Spinner />} Save & next <span className="kbd !border-brand-400 !bg-brand-700 !text-brand-100">Ctrl ↵</span></button>
                </div>
                <p className="mt-2 text-xs text-slate-500">Uploading does not verify a receipt — a finance user verifies it afterwards. {remaining - 1 > 0 ? `${remaining - 1} more in the queue.` : 'This is the last one.'}</p>
            </form>
        </div>
    );
}
