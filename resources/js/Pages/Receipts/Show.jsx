import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import DocPreview from '@/Components/DocPreview';
import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import StatusBadge, { statusLabel } from '@/Components/StatusBadge';
import { Banner, Field, PageHeader } from '@/Components/ui';
import { bytes, useFmt } from '@/lib/format';

const FLOW = ['draft', 'recorded', 'verified', 'processed', 'archived'];

export default function Show({ receipt: r, can }) {
    const fmt = useFmt();
    const [preview, setPreview] = useState(null);
    const [cancel, setCancel] = useState(false);
    const [replace, setReplace] = useState(null);
    const [busy, setBusy] = useState(false);
    const [errs, setErrs] = useState({});
    const done = (r.status === 'cancelled') ? -1 : FLOW.indexOf(r.status);

    const act = (action, data = {}, after) => {
        setBusy(true); setErrs({});
        router.post(route('receipts.transition', [r.id, action]), data, { preserveScroll: true, onError: setErrs, onSuccess: () => after?.(), onFinish: () => setBusy(false) });
    };

    const primary = r.status === 'draft' && can.edit ? <Link className="btn-primary" href={route('receipts.edit', r.id)}><Icon name="edit" className="h-4 w-4" /> Complete details</Link>
        : r.status === 'recorded' && can.verify ? <button className="btn-primary" disabled={busy} onClick={() => act('verify')}><Icon name="check" className="h-4 w-4" /> Mark verified</button>
        : r.status === 'verified' && can.process ? <button className="btn-primary" disabled={busy} onClick={() => act('process')}>Mark processed</button>
        : r.status === 'processed' && can.archive ? <button className="btn-primary" disabled={busy} onClick={() => act('archive')}>Archive</button> : null;

    return (
        <AuthenticatedLayout>
            <Head title={`Receipt ${r.receipt_number ?? '#' + r.id}`} />
            <PageHeader back={{ href: route('receipts.index'), label: 'All receipts' }}
                title={<span className="flex flex-wrap items-center gap-3"><span className="font-mono">{r.receipt_number ?? `#${r.id}`}</span><StatusBadge status={r.status} /></span>}
                subtitle={`Created by ${r.created_by} on ${fmt.date(r.created_at)}`}
                actions={<>
                    {can.edit && r.status !== 'draft' && <Link href={route('receipts.edit', r.id)} className="btn-secondary"><Icon name="edit" className="h-4 w-4" /> Edit</Link>}
                    {can.print && <Link href={route('printing.index', { category: 'receipt', select: r.id })} className="btn-secondary"><Icon name="printer" className="h-4 w-4" /> Print</Link>}
                    {primary}
                </>} />

            {r.status === 'draft' && <div className="mb-4"><Banner tone="info">This receipt is a draft. Complete the required details to record it. Uploading a file never verifies a receipt.</Banner></div>}
            {r.status === 'cancelled' && <div className="mb-4"><Banner tone="bad">This receipt was cancelled. The record is kept for traceability.</Banner></div>}

            <div className="card card-pad mb-6">
                <ol className="flex items-center justify-between gap-1 overflow-x-auto">
                    {FLOW.map((s, i) => (
                        <li key={s} className="flex flex-1 items-center gap-2">
                            <span className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold ${i < done ? 'bg-brand-100 text-brand-700' : i === done ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-400'}`}>{i < done ? <Icon name="check" className="h-3 w-3" stroke={3} /> : i + 1}</span>
                            <span className={`text-xs font-medium ${i === done ? 'text-slate-900' : 'text-slate-500'}`}>{statusLabel(s)}</span>
                            {i < FLOW.length - 1 && <span className={`h-0.5 flex-1 ${i < done ? 'bg-brand-400' : 'bg-slate-200'}`} />}
                        </li>
                    ))}
                </ol>
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="card">
                        <div className="border-b border-slate-100 px-5 py-3"><p className="card-title">Receipt details</p></div>
                        <div className="p-5">
                            <div className="mb-5 grid gap-3 sm:grid-cols-2">
                                <div className="rounded-xl bg-slate-50 p-4"><p className="text-xs text-slate-500">Amount</p><p className="text-2xl font-semibold tabular-nums">{fmt.money(r.amount)}</p></div>
                                <div className="rounded-xl bg-slate-50 p-4"><p className="text-xs text-slate-500">Tax{r.tax_rate ? ` (${r.tax_rate}%)` : ''}</p><p className="text-2xl font-semibold tabular-nums">{fmt.money(r.tax_amount)}</p></div>
                            </div>
                            <dl className="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                                {[['Vendor / payee', r.payee ?? '—'], ['Receipt date', fmt.date(r.receipt_date)], ['Category', r.category ?? '—'], ['Reference no.', r.reference_no ?? '—'],
                                    ['Description', r.description ?? '—'], ['Remarks', r.remarks ?? '—']].map(([k, v]) => <div key={k}><dt className="text-xs text-slate-500">{k}</dt><dd className="mt-0.5 font-medium">{v}</dd></div>)}
                                <div><dt className="text-xs text-slate-500">Linked cheque</dt><dd className="mt-0.5 font-medium">{r.cheque ? <Link className="text-brand-700 hover:underline" href={route('cheques.show', r.cheque.id)}>{r.cheque.number}</Link> : '—'}</dd></div>
                            </dl>
                            {r.duplicate_override_reason && <div className="mt-4"><Banner tone="warn">Saved despite a duplicate number. Override reason: {r.duplicate_override_reason}</Banner></div>}
                        </div>
                    </div>

                    <div className="card">
                        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                            <p className="card-title">Files</p>
                            {can.upload && r.documents.length === 0 && (
                                <label className="btn-secondary btn-sm cursor-pointer"><Icon name="upload" className="h-3.5 w-3.5" /> Attach file
                                    <input type="file" className="hidden" accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => { if (e.target.files[0]) { router.post(route('receipts.files.add', r.id), { file: e.target.files[0] }, { forceFormData: true, preserveScroll: true }); e.target.value = ''; } }} /></label>
                            )}
                        </div>
                        {r.documents.length === 0 ? <p className="px-5 py-6 text-center text-sm text-slate-400">No file attached.</p> : r.documents.map((d) => {
                            const vers = r.versions.filter((v) => v.group === r.versions.find((x) => x.id === d.id)?.group);
                            return (
                                <div key={d.id} className="px-5 py-4">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <button className="flex min-w-0 items-center gap-3 text-left" onClick={() => setPreview(d)}><Icon name="file" className="h-6 w-6 shrink-0 text-slate-400" /><span><span className="block truncate text-sm font-medium hover:text-brand-700">{d.name}</span><span className="text-xs text-slate-500">{bytes(d.size)} · version {vers[0]?.version ?? 1}</span></span></button>
                                        <div className="flex gap-2"><button className="btn-secondary btn-sm" onClick={() => setPreview(d)}><Icon name="eye" className="h-4 w-4" /> Preview</button>
                                            <a className="btn-secondary btn-sm" href={route('documents.download', d.id)}><Icon name="download" className="h-4 w-4" /> Download</a>
                                            {can.upload && <button className="btn-secondary btn-sm" onClick={() => setReplace(d)}>Replace</button>}</div>
                                    </div>
                                    {vers.length > 1 && (
                                        <details className="mt-3 text-sm"><summary className="cursor-pointer text-xs font-medium text-slate-500">File history ({vers.length} versions)</summary>
                                            <ul className="mt-2 space-y-1.5">{vers.map((v) => <li key={v.id} className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-xs"><span>v{v.version} · {v.name} · {v.by} · {fmt.date(v.at, true)}{v.reason ? ` · “${v.reason}”` : ''}{v.is_current && <b className="ml-1 text-brand-700">current</b>}</span>
                                                <a className="text-brand-700 hover:underline" href={route('documents.download', v.id)}>Download</a></li>)}</ul></details>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </div>

                <div className="space-y-6">
                    <div className="card">
                        <div className="border-b border-slate-100 px-5 py-3"><p className="card-title">History</p></div>
                        <ol className="space-y-4 p-5">
                            {[...r.history].reverse().map((h, i) => (
                                <li key={i} className="relative pl-5 text-sm before:absolute before:left-0 before:top-1.5 before:h-2 before:w-2 before:rounded-full before:bg-brand-400">
                                    <p className="font-medium">{h.from ? <>{statusLabel(h.from)} → {statusLabel(h.to)}</> : <>Created as {statusLabel(h.to)}</>}</p>
                                    <p className="text-xs text-slate-500">{h.user} · {fmt.date(h.at, true)}</p>
                                    {h.reason && <p className="mt-1 rounded bg-slate-50 px-2 py-1 text-xs text-slate-600">“{h.reason}”</p>}
                                </li>
                            ))}
                        </ol>
                    </div>
                    {can.cancel && <div className="card card-pad"><p className="card-title mb-1">Cancel receipt</p><p className="mb-3 text-xs text-slate-500">Receipts are never deleted. Cancelling keeps the record with your reason.</p><button className="btn-danger btn-sm" onClick={() => setCancel(true)}>Cancel receipt</button></div>}
                </div>
            </div>

            <ConfirmDialog show={cancel} onClose={() => setCancel(false)} processing={busy} errors={errs} tone="danger" title="Cancel this receipt?" confirmLabel="Cancel receipt" reasonLabel="Reason" onConfirm={({ reason }) => act('cancel', { reason }, () => setCancel(false))} />
            <ReplaceDialog doc={replace} receiptId={r.id} onClose={() => setReplace(null)} />
            <DocPreview doc={preview} onClose={() => setPreview(null)} />
        </AuthenticatedLayout>
    );
}

function ReplaceDialog({ doc, receiptId, onClose }) {
    const f = useForm({ file: null, reason: '' });
    return (
        <Modal show={!!doc} onClose={onClose} title="Replace file" maxWidth="md"
            footer={<><button className="btn-secondary" onClick={onClose}>Cancel</button>
                <button className="btn-primary" disabled={f.processing || !f.data.file || f.data.reason.trim().length < 3} onClick={() => f.post(route('receipts.files.replace', [receiptId, doc.id]), { forceFormData: true, preserveScroll: true, onSuccess: () => { f.reset(); onClose(); } })}>Replace file</button></>}>
            <p className="mb-3 text-sm text-slate-600">The current file is kept as an earlier version in the file history.</p>
            <Field label="New file" required error={f.errors.file}><input type="file" accept=".pdf,.jpg,.jpeg,.png" className="input" onChange={(e) => f.setData('file', e.target.files[0] ?? null)} /></Field>
            <Field label="Reason for replacing" required error={f.errors.reason} className="mt-3"><input className="input" value={f.data.reason} onChange={(e) => f.setData('reason', e.target.value)} /></Field>
        </Modal>
    );
}
