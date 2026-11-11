import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import DocPreview from '@/Components/DocPreview';
import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import StatusBadge, { statusLabel } from '@/Components/StatusBadge';
import { Banner, Field, PageHeader } from '@/Components/ui';
import { bytes, today, useFmt } from '@/lib/format';

const STEPS = ['draft', 'prepared', 'printed', 'released', 'for_clearing', 'done'];

function Timeline({ status, history }) {
    const stopped = ['cancelled', 'voided'].includes(status);
    const reached = new Set(history.map((h) => h.to));
    const done = ['cleared', 'bounced', 'stale'].includes(status);
    const cur = done ? 5 : STEPS.indexOf(status);
    return (
        <ol className="flex items-start overflow-x-auto pb-1">
            {STEPS.map((s, i) => {
                // Completed = earlier than the current status (works for imported records too); stopped cheques use their history.
                const isDone = s === 'done' ? done : stopped ? reached.has(s) : i <= cur;
                const current = s === 'done' ? done : status === s;
                const label = s === 'done' ? (done ? statusLabel(status) : 'Cleared') : statusLabel(s);
                const bad = s === 'done' && (status === 'bounced');
                return (
                    <li key={s} className="flex min-w-[5.5rem] flex-1 flex-col items-center text-center">
                        <div className="flex w-full items-center">
                            <div className={`h-0.5 flex-1 ${i === 0 ? 'opacity-0' : isDone || current ? (stopped ? 'bg-slate-300' : 'bg-brand-500') : 'bg-slate-200'}`} />
                            <div className={`flex h-7 w-7 items-center justify-center rounded-full text-xs font-semibold ring-4 ${current && !stopped ? (bad ? 'bg-red-600 text-white ring-red-100' : 'bg-brand-600 text-white ring-brand-100') : isDone ? 'bg-brand-100 text-brand-700 ring-transparent' : 'bg-slate-100 text-slate-400 ring-transparent'}`}>
                                {isDone && !current ? <Icon name="check" className="h-3.5 w-3.5" stroke={2.5} /> : i + 1}
                            </div>
                            <div className={`h-0.5 flex-1 ${i === STEPS.length - 1 ? 'opacity-0' : isDone && !current ? 'bg-brand-500' : 'bg-slate-200'}`} />
                        </div>
                        <p className={`mt-1.5 text-xs font-medium ${current ? 'text-slate-900' : 'text-slate-500'}`}>{label}</p>
                    </li>
                );
            })}
        </ol>
    );
}

export default function Show({ cheque: c, can, preparationErrors, staleDays }) {
    const fmt = useFmt();
    const [dlg, setDlg] = useState(null); // release | clearing | stop | stale
    const [preview, setPreview] = useState(null);
    const [busy, setBusy] = useState(false);
    const [errs, setErrs] = useState({});
    const stopped = ['cancelled', 'voided'].includes(c.status);
    const prepErr = Object.values(preparationErrors ?? {});

    const post = (name, data = {}, after) => {
        setBusy(true); setErrs({});
        router.post(route(name, c.id), data, {
            preserveScroll: true, forceFormData: true,
            onError: (e) => setErrs(e), onSuccess: () => { setDlg(null); after?.(); }, onFinish: () => setBusy(false),
        });
    };

    const primary = (() => {
        if (c.status === 'draft' && can.prepare) return <button className="btn-primary" disabled={busy} onClick={() => post('cheques.prepare')}><Icon name="check" className="h-4 w-4" /> Prepare cheque</button>;
        if (c.status === 'prepared' && can.print) return <Link className="btn-primary" href={route('printing.index', { select: c.id })}><Icon name="printer" className="h-4 w-4" /> Print cheque</Link>;
        if (c.status === 'printed' && can.release) return <button className="btn-primary" onClick={() => setDlg('release')}><Icon name="arrowRight" className="h-4 w-4" /> Record release</button>;
        if (c.status === 'released' && can.clear) return <button className="btn-primary" onClick={() => post('cheques.for-clearing')}>Mark as for clearing</button>;
        if (c.status === 'for_clearing' && can.clear) return <button className="btn-primary" onClick={() => setDlg('clearing')}>Record clearing result</button>;
        return null;
    })();

    return (
        <AuthenticatedLayout>
            <Head title={`Cheque ${c.cheque_number}`} />
            <PageHeader back={{ href: route('cheques.index'), label: 'All cheques' }}
                title={<span className="flex flex-wrap items-center gap-3"><span className="font-mono">{c.cheque_number}</span><StatusBadge status={c.status} /></span>}
                subtitle={`${c.bank_account} · created by ${c.created_by} on ${fmt.date(c.created_at)}`}
                actions={<>
                    {can.edit && <Link href={route('cheques.edit', c.id)} className="btn-secondary"><Icon name="edit" className="h-4 w-4" /> Edit</Link>}
                    {can.duplicate && <Link href={route('cheques.create', { duplicate: c.id })} className="btn-secondary"><Icon name="copy" className="h-4 w-4" /> Duplicate</Link>}
                    {can.reprint && <Link href={route('printing.index', { select: c.id, reprint: 1 })} className="btn-secondary"><Icon name="printer" className="h-4 w-4" /> Reprint</Link>}
                    {primary}
                </>} />

            {c.flagged && !stopped && can.clear && (
                <div className="mb-4"><Banner tone="warn">This cheque has passed the {staleDays}-day stale period. Review it and mark it <b>Stale</b> if it can no longer be presented. <button className="ml-2 font-semibold underline" onClick={() => setDlg('stale')}>Mark as stale</button></Banner></div>
            )}
            {c.status === 'draft' && prepErr.length > 0 && can.prepare && (
                <div className="mb-4"><Banner tone="warn"><p className="font-medium">Fix these before preparing:</p><ul className="ml-4 list-disc">{prepErr.map((e) => <li key={e}>{e}</li>)}</ul></Banner></div>
            )}
            {(c.status === 'released' || c.status === 'for_clearing') && <div className="mb-4"><Banner tone="info">Release and clearing information is <b>recorded manually</b> by staff. FINOX is not connected to any bank.</Banner></div>}
            {stopped && <div className="mb-4"><Banner tone="bad">This cheque is <b>{c.status}</b>. The record is kept for traceability.{c.stop_payment_note && <> Stop-payment note: {c.stop_payment_note}</>}</Banner></div>}

            <div className="card card-pad mb-6"><Timeline status={c.status} history={c.history} /></div>

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div className="card">
                        <div className="border-b border-slate-100 px-5 py-3"><p className="card-title">Cheque details</p></div>
                        <div className="p-5">
                            <div className="mb-5 rounded-xl bg-slate-50 p-4 text-center">
                                <p className="text-xs uppercase tracking-wide text-slate-500">Amount</p>
                                <p className="mt-1 text-3xl font-semibold tabular-nums">{fmt.money(c.amount)}</p>
                                <p className="mx-auto mt-2 max-w-lg font-mono text-xs text-slate-500">{c.amount_words}</p>
                            </div>
                            <dl className="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                                {[
                                    ['Payee (as printed)', c.is_cash ? 'CASH' : c.printed_payee], ['Cheque date', fmt.date(c.cheque_date)],
                                    ['Purpose', c.purpose ?? '—'], ['Reference no.', c.reference_no ?? '—'],
                                    ['Printed', c.printed_at ? `${fmt.date(c.printed_at, true)} · ${c.print_count}×` : 'Not yet'], ['Remarks', c.remarks ?? '—'],
                                ].map(([k, v]) => <div key={k}><dt className="text-xs text-slate-500">{k}</dt><dd className="mt-0.5 font-medium">{v}</dd></div>)}
                            </dl>
                        </div>
                    </div>

                    {c.release && (
                        <div className="card">
                            <div className="border-b border-slate-100 px-5 py-3"><p className="card-title">Release</p></div>
                            <dl className="grid gap-4 p-5 text-sm sm:grid-cols-2">
                                {[['Released on', fmt.date(c.release.released_at)], ['Released to', c.release.released_to], ['Released by', c.release.released_by], ['Recorded by', c.release.recorded_by], ['Reference', c.release.supporting_reference ?? '—'], ['Remarks', c.release.remarks ?? '—']].map(([k, v]) => <div key={k}><dt className="text-xs text-slate-500">{k}</dt><dd className="mt-0.5 font-medium">{v}</dd></div>)}
                            </dl>
                        </div>
                    )}
                    {c.clearing && (
                        <div className="card">
                            <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3"><p className="card-title">Clearing result</p><span className="text-xs text-slate-500">Recorded manually · not verified with the bank</span></div>
                            <dl className="grid gap-4 p-5 text-sm sm:grid-cols-2">
                                {[['Result', c.clearing.result === 'cleared' ? 'Cleared' : 'Bounced'], ['Date', fmt.date(c.clearing.cleared_at)], ['Recorded by', c.clearing.recorded_by], ['Remarks', c.clearing.remarks ?? '—']].map(([k, v]) => <div key={k}><dt className="text-xs text-slate-500">{k}</dt><dd className="mt-0.5 font-medium">{v}</dd></div>)}
                            </dl>
                        </div>
                    )}

                    <div className="card">
                        <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3">
                            <p className="card-title">Supporting documents</p>
                            {can.upload && !stopped && (
                                <label className="btn-secondary btn-sm cursor-pointer"><Icon name="upload" className="h-3.5 w-3.5" /> Attach file
                                    <input type="file" className="hidden" accept=".pdf,.jpg,.jpeg,.png" onChange={(e) => { if (e.target.files[0]) { router.post(route('cheques.documents', c.id), { file: e.target.files[0] }, { forceFormData: true, preserveScroll: true }); e.target.value = ''; } }} />
                                </label>
                            )}
                        </div>
                        {c.documents.length === 0 ? <p className="px-5 py-6 text-center text-sm text-slate-400">No documents attached.</p> : (
                            <ul className="divide-y divide-slate-100">
                                {c.documents.map((d) => (
                                    <li key={d.id} className="flex items-center justify-between gap-3 px-5 py-3">
                                        <button className="flex min-w-0 items-center gap-3 text-left" onClick={() => setPreview(d)}><Icon name="file" className="h-5 w-5 shrink-0 text-slate-400" /><span className="truncate text-sm font-medium hover:text-brand-700">{d.name}</span><span className="text-xs text-slate-400">{bytes(d.size)}</span></button>
                                        <a href={route('documents.download', d.id)} className="btn-ghost btn-sm" aria-label="Download"><Icon name="download" className="h-4 w-4" /></a>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>

                <div className="space-y-6">
                    <div className="card">
                        <div className="border-b border-slate-100 px-5 py-3"><p className="card-title">History</p></div>
                        <ol className="space-y-4 p-5">
                            {[...c.history].reverse().map((h, i) => (
                                <li key={i} className="relative pl-5 text-sm before:absolute before:left-0 before:top-1.5 before:h-2 before:w-2 before:rounded-full before:bg-brand-400">
                                    <p className="font-medium">{h.from ? <>{statusLabel(h.from)} → {statusLabel(h.to)}</> : <>Created as {statusLabel(h.to)}</>}</p>
                                    <p className="text-xs text-slate-500">{h.user} · {fmt.date(h.at, true)}</p>
                                    {h.reason && <p className="mt-1 rounded bg-slate-50 px-2 py-1 text-xs text-slate-600">“{h.reason}”</p>}
                                </li>
                            ))}
                        </ol>
                    </div>
                    {c.receipts.length > 0 && (
                        <div className="card">
                            <div className="border-b border-slate-100 px-5 py-3"><p className="card-title">Linked receipts</p></div>
                            <ul className="divide-y divide-slate-100">{c.receipts.map((r) => <li key={r.id}><Link href={route('receipts.show', r.id)} className="flex items-center justify-between px-5 py-3 text-sm hover:bg-slate-50"><span className="font-medium">{r.number ?? `#${r.id}`}</span><StatusBadge status={r.status} /></Link></li>)}</ul>
                        </div>
                    )}
                    {can.stop && (
                        <div className="card card-pad">
                            <p className="card-title mb-1">Cancel or void</p>
                            <p className="mb-3 text-xs text-slate-500">A cheque that was never printed is cancelled; one where paper exists is voided. A reason is required and the record is kept.</p>
                            <div className="flex gap-2">
                                {['draft', 'prepared'].includes(c.status)
                                    ? <button className="btn-secondary btn-sm" onClick={() => setDlg('cancel')}>Cancel</button>
                                    : <button className="btn-danger btn-sm" onClick={() => setDlg('void')}>Void</button>}
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <ReleaseDialog show={dlg === 'release'} onClose={() => setDlg(null)} busy={busy} errors={errs} onSubmit={(d) => post('cheques.release', d)} defaultTo={c.is_cash ? 'Bearer' : c.printed_payee} />
            <ClearingDialog show={dlg === 'clearing'} onClose={() => setDlg(null)} busy={busy} errors={errs} onSubmit={(d) => post('cheques.clearing', d)} />
            <ConfirmDialog show={dlg === 'cancel' || dlg === 'void'} onClose={() => setDlg(null)} processing={busy} errors={errs}
                title={dlg === 'void' ? 'Void this cheque?' : 'Cancel this cheque?'} tone="danger" confirmLabel={dlg === 'void' ? 'Void cheque' : 'Cancel cheque'}
                warning={dlg === 'void' ? 'Voiding is permanent. The record stays in registers and reports.' : 'Cancelling is permanent. The record stays in registers and reports.'}
                reasonLabel="Reason" extraLabel={dlg === 'void' && c.status === 'released' ? 'Stop-payment note' : null} extraRequired
                onConfirm={({ reason, extra }) => post('cheques.stop', { kind: dlg, reason, stop_payment_note: extra })} />
            <ConfirmDialog show={dlg === 'stale'} onClose={() => setDlg(null)} processing={busy} title="Mark as stale?" confirmLabel="Mark stale" message="Confirm that this cheque has passed its stale date and should no longer be treated as outstanding." onConfirm={() => post('cheques.stale')} />
            <DocPreview doc={preview} onClose={() => setPreview(null)} />
        </AuthenticatedLayout>
    );
}

function ReleaseDialog({ show, onClose, onSubmit, busy, errors, defaultTo }) {
    const f = useForm({ released_at: today(), released_to: defaultTo ?? '', released_by: '', remarks: '', supporting_reference: '' });
    const e = { ...errors };
    return (
        <Modal show={show} onClose={onClose} title="Record cheque release" maxWidth="lg"
            footer={<><button className="btn-secondary" onClick={onClose}>Cancel</button><button className="btn-primary" disabled={busy || !f.data.released_to || !f.data.released_by} onClick={() => onSubmit(f.data)}>Record release</button></>}>
            <div className="mb-3"><Banner tone="info">Record this only after the cheque has physically been handed over.</Banner></div>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Release date" required error={e.released_at}><input type="date" className="input" value={f.data.released_at} onChange={(x) => f.setData('released_at', x.target.value)} /></Field>
                <Field label="Released to" required error={e.released_to}><input className="input" value={f.data.released_to} onChange={(x) => f.setData('released_to', x.target.value)} /></Field>
                <Field label="Released by" required error={e.released_by}><input className="input" value={f.data.released_by} onChange={(x) => f.setData('released_by', x.target.value)} /></Field>
                <Field label="Supporting reference" error={e.supporting_reference}><input className="input" value={f.data.supporting_reference} onChange={(x) => f.setData('supporting_reference', x.target.value)} /></Field>
            </div>
            <Field label="Remarks" className="mt-3"><textarea className="input" rows={2} value={f.data.remarks} onChange={(x) => f.setData('remarks', x.target.value)} /></Field>
        </Modal>
    );
}

function ClearingDialog({ show, onClose, onSubmit, busy, errors }) {
    const f = useForm({ result: 'cleared', cleared_at: today(), remarks: '', file: null });
    return (
        <Modal show={show} onClose={onClose} title="Record clearing result" maxWidth="lg"
            footer={<><button className="btn-secondary" onClick={onClose}>Cancel</button><button className={f.data.result === 'bounced' ? 'btn-danger' : 'btn-primary'} disabled={busy} onClick={() => onSubmit(f.data)}>Record as {f.data.result}</button></>}>
            <div className="mb-3"><Banner tone="warn"><b>Recorded manually. Not verified with the bank.</b> Enter the result from information you obtained outside FINOX.</Banner></div>
            <div className="mb-3 grid grid-cols-2 gap-2">
                {['cleared', 'bounced'].map((r) => (
                    <button key={r} type="button" onClick={() => f.setData('result', r)} className={`rounded-lg border px-3 py-2.5 text-sm font-medium capitalize ${f.data.result === r ? (r === 'cleared' ? 'border-emerald-500 bg-emerald-50 text-emerald-800' : 'border-red-500 bg-red-50 text-red-800') : 'border-slate-200 hover:bg-slate-50'}`}>{r}</button>
                ))}
            </div>
            <Field label="Date" required error={errors.cleared_at}><input type="date" className="input" value={f.data.cleared_at} onChange={(x) => f.setData('cleared_at', x.target.value)} /></Field>
            <Field label="Remarks" className="mt-3"><textarea className="input" rows={2} value={f.data.remarks} onChange={(x) => f.setData('remarks', x.target.value)} /></Field>
            <Field label="Supporting document (optional)" className="mt-3" error={errors.file} hint="PDF, JPG or PNG"><input type="file" accept=".pdf,.jpg,.jpeg,.png" className="input" onChange={(x) => f.setData('file', x.target.files[0] ?? null)} /></Field>
        </Modal>
    );
}
