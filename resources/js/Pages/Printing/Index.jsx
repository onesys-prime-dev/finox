import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import PrintSheet, { SheetFrame } from '@/Components/PrintSheet';
import StatusBadge from '@/Components/StatusBadge';
import { Banner, EmptyState, PageHeader, Spinner, Tabs } from '@/Components/ui';
import { useFmt } from '@/lib/format';

const REASONS = ['Paper jam', 'Misaligned print', 'Wrong paper / cheque stock', 'Printer error', 'Smudged or damaged', 'Other'];
const load = (k, d) => { try { return JSON.parse(localStorage.getItem(k)) ?? d; } catch { return d; } };

export default function Index({ category, ready, awaiting, failed, accounts, bankAccountId, canReprint, canCalibrate }) {
    const fmt = useFmt();
    const q = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : new URLSearchParams();
    const initial = (q.get('select') || '').split(',').filter(Boolean).map(Number);
    const [sel, setSel] = useState(initial.filter((id) => ready.some((r) => r.id === id)));
    const [preview, setPreview] = useState(null);
    const [loading, setLoading] = useState(false);
    const [err, setErr] = useState([]);
    const [nudge, setNudge] = useState(() => load('finox.print.nudge', { x: 0, y: 0 }));
    const [reason, setReason] = useState('');
    const [pick, setPick] = useState('');
    const [ack, setAck] = useState(false);
    const [full, setFull] = useState(false);
    const [printing, setPrinting] = useState(null); // {jobId, pages}
    const [confirmJob, setConfirmJob] = useState(null);
    const [starting, setStarting] = useState(false);

    useEffect(() => { try { localStorage.setItem('finox.print.nudge', JSON.stringify(nudge)); } catch {} }, [nudge]);
    useEffect(() => setSel((s) => s.filter((id) => ready.some((r) => r.id === id))), [ready]);

    const rows = ready;
    const selRows = rows.filter((r) => sel.includes(r.id));
    const isReprint = selRows.length > 0 && selRows.some((r) => r.status !== 'prepared' && category === 'cheque');
    const mixed = selRows.some((r) => r.status === 'prepared') && selRows.some((r) => r.status !== 'prepared') && category === 'cheque';
    const blocked = selRows.filter((r) => r.blocked);
    const mixedTemplates = category === 'cheque' && new Set(selRows.map((r) => r.template_id)).size > 1;
    const toggle = (id) => setSel(sel.includes(id) ? sel.filter((x) => x !== id) : [...sel, id]);
    const selectable = rows.filter((r) => !r.blocked);
    const allOn = selectable.length > 0 && selectable.every((r) => sel.includes(r.id));

    const openPreview = async () => {
        setLoading(true); setErr([]);
        try {
            const r = await axios.post(route('printing.preview'), { category, ids: sel, reprint: isReprint });
            setPreview(r.data); setReason(''); setPick(''); setAck(false);
        } catch (e) { setErr(e.response?.data?.failures ?? [e.response?.data?.message ?? 'Could not build the preview.']); }
        finally { setLoading(false); }
    };

    const startPrint = async () => {
        setStarting(true);
        try {
            const r = await axios.post(route('printing.start'), { category, ids: sel, reprint: isReprint, reason: isReprint ? `${pick}${reason ? ': ' + reason : ''}` : null, settings: { nudge }, _submit_token: crypto.randomUUID() });
            setPrinting({ jobId: r.data.job_id, pages: preview.pages });
            setPreview(null);
        } catch (e) {
            const m = e.response?.data?.errors; setErr(m ? Object.values(m).flat() : ['Could not start the print job.']); setPreview(null);
        } finally { setStarting(false); }
    };

    // Open the browser print dialog once the print root is rendered; then ask for confirmation.
    useEffect(() => {
        if (!printing) return;
        const after = () => { setConfirmJob({ id: printing.jobId, pages: printing.pages }); setPrinting(null); };
        window.addEventListener('afterprint', after, { once: true });
        const t = setTimeout(() => window.print(), 250);
        return () => { clearTimeout(t); window.removeEventListener('afterprint', after); };
    }, [printing]);

    const canGo = sel.length > 0 && !mixed && !mixedTemplates && blocked.length === 0 && (!isReprint || canReprint);
    const tpl0 = preview?.pages?.[0]?.template;

    return (
        <AuthenticatedLayout>
            <Head title="Print center" />
            <PageHeader title="Print center" subtitle="Select, preview, print — then confirm what actually came out of the printer."
                actions={<><Link href={route('printing.history')} className="btn-secondary"><Icon name="history" className="h-4 w-4" /> Print history</Link></>} />

            {awaiting.length > 0 && (
                <div className="mb-5 space-y-2">
                    {awaiting.map((j) => (
                        <div key={j.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4">
                            <div className="flex items-center gap-3"><Icon name="printer" className="h-5 w-5 text-amber-700" /><div>
                                <p className="text-sm font-semibold text-amber-900">Job #{j.id}: did {j.count} {j.category}{j.count > 1 ? 's' : ''} print correctly?</p>
                                <p className="text-xs text-amber-800">{j.items.map((i) => i.label).join(', ')} · started {fmt.date(j.at, true)}{j.is_reprint ? ' · reprint' : ''}</p></div></div>
                            <button className="btn-primary" onClick={() => setConfirmJob({ id: j.id, items: j.items })}>Confirm result</button>
                        </div>
                    ))}
                </div>
            )}
            {failed.length > 0 && (
                <div className="mb-5"><Banner tone="bad">
                    <p className="font-medium">Recent print problems</p>
                    <ul className="ml-4 list-disc">{failed.slice(0, 4).map((j) => <li key={j.id}>Job #{j.id}: {j.items.filter((i) => i.status === 'failed').map((i) => i.label).join(', ') || 'see history'} — {j.note}</li>)}</ul>
                    <p className="mt-1 text-xs">Fix by reprinting the affected cheques below. The earlier attempt stays in the history.</p></Banner></div>
            )}

            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                <Tabs value={category} onChange={(v) => router.get(route('printing.index'), { category: v })} tabs={[{ value: 'cheque', label: 'Cheques' }, { value: 'receipt', label: 'Receipts' }]} />
                {category === 'cheque' && accounts.length > 1 && (
                    <select className="input !w-auto" value={bankAccountId ?? ''} onChange={(e) => router.get(route('printing.index'), { category, bank_account_id: e.target.value || undefined })}>
                        <option value="">All bank accounts</option>{accounts.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}
                    </select>
                )}
            </div>

            <div className="card">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3">
                    <div><p className="card-title">{category === 'cheque' ? 'Ready to print' : 'Printable receipts'}</p><p className="text-xs text-slate-500">{category === 'cheque' ? 'Prepared cheques that have not been printed yet.' : 'Recorded or later receipts.'}</p></div>
                    <div className="flex items-center gap-2">
                        {canCalibrate && <CalibrateMenu category={category} />}
                        <button className="btn-primary" disabled={!canGo || loading} onClick={openPreview}>{loading ? <Spinner /> : <Icon name="eye" className="h-4 w-4" />} Preview {sel.length > 0 ? `(${sel.length})` : ''}{isReprint ? ' reprint' : ''}</button>
                    </div>
                </div>
                {mixed && <div className="p-4 pb-0"><Banner tone="warn">Select either new prints or reprints, not both at once.</Banner></div>}
                {mixedTemplates && <div className="p-4 pb-0"><Banner tone="warn">These cheques use different print templates (paper layouts). Print one bank account at a time — use the bank account filter above.</Banner></div>}
                {blocked.length > 0 && <div className="p-4 pb-0"><Banner tone="warn">{blocked.map((b) => `${b.label}: ${b.blocked}`).join('; ')}</Banner></div>}
                {err.length > 0 && <div className="p-4 pb-0"><Banner tone="bad"><ul className="list-disc pl-4">{err.map((e, i) => <li key={i}>{e}</li>)}</ul></Banner></div>}
                <div className="overflow-x-auto">
                    <table className="tbl">
                        <thead><tr>
                            <th className="w-10"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={allOn} onChange={() => setSel(allOn ? [] : selectable.map((r) => r.id))} aria-label="Select all" /></th>
                            <th>{category === 'cheque' ? 'Cheque no.' : 'Receipt no.'}</th><th>Date</th><th>{category === 'cheque' ? 'Payee' : 'Vendor'}</th>{category === 'cheque' && <th>Bank</th>}<th className="text-right">Amount</th><th>Status</th>
                        </tr></thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr key={r.id} className={`cursor-pointer ${sel.includes(r.id) ? 'bg-brand-50/60' : 'hover:bg-slate-50'}`} onClick={() => !r.blocked && toggle(r.id)}>
                                    <td><input type="checkbox" disabled={!!r.blocked} className="rounded border-slate-300 text-brand-600" checked={sel.includes(r.id)} onChange={() => toggle(r.id)} onClick={(e) => e.stopPropagation()} /></td>
                                    <td className="font-mono text-[13px] font-medium">{r.label}</td>
                                    <td className="whitespace-nowrap text-slate-600">{fmt.date(r.date)}</td>
                                    <td className="max-w-[16rem] truncate">{r.party}</td>
                                    {category === 'cheque' && <td className="text-slate-600">{r.bank}{r.blocked && <span className="ml-2 text-xs font-medium text-amber-700">⚠ {r.blocked}</span>}</td>}
                                    <td className="num font-medium">{fmt.money(r.amount)}</td>
                                    <td><StatusBadge status={r.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {rows.length === 0 && <EmptyState icon="printer" title="Nothing waiting to print" text={category === 'cheque' ? 'Prepare cheques first; they will appear here.' : 'Recorded receipts will appear here.'} />}
                </div>
            </div>

            {/* ---------- Preview & settings ---------- */}
            <Modal show={!!preview} onClose={() => setPreview(null)} title={`Preview · ${preview?.pages.length ?? 0} page(s)`} maxWidth="6xl"
                footer={<><button className="btn-secondary" onClick={() => setPreview(null)}>Back</button>
                    <button className="btn-primary" disabled={starting || (isReprint && (!pick || (pick === 'Other' && !reason.trim()) || !ack))} onClick={startPrint}>{starting ? <Spinner /> : <Icon name="printer" className="h-4 w-4" />} {isReprint ? 'Reprint' : 'Print'} {preview?.pages.length} page(s)</button></>}>
                {preview && (
                    <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]">
                        <div className="max-h-[65vh] space-y-4 overflow-auto rounded-xl bg-slate-100 p-4">
                            {preview.pages.slice(0, 8).map((p) => (
                                <div key={p.id}><p className="mb-1.5 text-xs font-medium text-slate-600">{p.label}</p>
                                    <SheetFrame template={p.template} scale={0.62} crop={full ? null : p.template.layout?.preview_crop}>
                                        <PrintSheet template={p.template} values={p.values} showBackground scale={0.62} offset={{ x: nudge.x, y: nudge.y }} />
                                    </SheetFrame></div>
                            ))}
                            {preview.pages.length > 8 && <p className="text-center text-xs text-slate-500">+ {preview.pages.length - 8} more page(s) not shown</p>}
                        </div>
                        <div className="space-y-4">
                            <div className="rounded-xl border border-slate-200 p-4">
                                <p className="mb-2 text-sm font-semibold">Printer settings</p>
                                <p className="mb-2 text-xs text-slate-500">Paper: {tpl0?.paper_width_mm} × {tpl0?.paper_height_mm} mm. In the browser dialog choose <b>Margins: None</b>, <b>Scale: 100%</b> and the right paper size.</p>
                                <div className="grid grid-cols-2 gap-2">
                                    <div><label className="label !text-xs">Shift X (mm)</label><input type="number" step="0.1" className="input" value={nudge.x} onChange={(e) => setNudge({ ...nudge, x: Number(e.target.value) })} /></div>
                                    <div><label className="label !text-xs">Shift Y (mm)</label><input type="number" step="0.1" className="input" value={nudge.y} onChange={(e) => setNudge({ ...nudge, y: Number(e.target.value) })} /></div>
                                </div>
                                <p className="hint">Remembered for this computer. Use the calibration print to find the right values.</p>
                                {category === 'cheque' && <button className="mt-2 text-xs font-medium text-brand-700 hover:underline" onClick={() => setFull(!full)}>{full ? 'Show cheque area only' : 'Show full sheet'}</button>}
                            </div>
                            {isReprint && (
                                <div className="rounded-xl border border-amber-300 bg-amber-50 p-4">
                                    <p className="mb-2 flex items-center gap-1.5 text-sm font-semibold text-amber-900"><Icon name="alert" className="h-4 w-4" /> Reprinting cheques</p>
                                    <p className="mb-3 text-xs text-amber-900">A physical cheque may already exist. A reprint creates <b>no new transaction</b> and the earlier print stays in the history.</p>
                                    <label className="label !text-xs">Reason</label>
                                    <select className="input" value={pick} onChange={(e) => setPick(e.target.value)}><option value="">Select…</option>{REASONS.map((r) => <option key={r}>{r}</option>)}</select>
                                    <input className="input mt-2" placeholder={pick === 'Other' ? 'Describe the reason (required)' : 'Details (optional)'} value={reason} onChange={(e) => setReason(e.target.value)} />
                                    <label className="mt-3 flex items-start gap-2 text-xs text-amber-900"><input type="checkbox" className="mt-0.5 rounded border-amber-400 text-brand-600" checked={ack} onChange={(e) => setAck(e.target.checked)} /> I understand the earlier paper cheque must be destroyed or accounted for.</label>
                                </div>
                            )}
                            {!isReprint && preview.pages.some((p) => p.warn_printed) && <Banner tone="warn">One or more of these cheques was already printed.</Banner>}
                            <p className="text-xs text-slate-500">Pre-printed details (cheque no., account, MICR) are not printed.</p>
                        </div>
                    </div>
                )}
            </Modal>

            <ConfirmPrint job={confirmJob} onClose={() => setConfirmJob(null)} />
            {printing && <PrintRoot pages={printing.pages} nudge={nudge} />}
        </AuthenticatedLayout>
    );
}

function CalibrateMenu({ category }) {
    const [open, setOpen] = useState(false);
    const [list, setList] = useState(null);
    return (
        <div className="relative">
            <button className="btn-secondary" onClick={() => { setOpen(!open); if (!list) axios.get(route('printing.templates')).then((r) => setList(r.data)).catch(() => setList([])); }}><Icon name="ruler" className="h-4 w-4" /> Test print</button>
            {open && <>
                <div className="fixed inset-0 z-20" onClick={() => setOpen(false)} />
                <div className="absolute right-0 z-30 mt-1 w-72 rounded-lg border border-slate-200 bg-white p-1.5 shadow-pop">
                    <p className="px-2 py-1.5 text-xs text-slate-500">Prints guide marks and sample values on plain paper. Never logged as a real print.</p>
                    {(list ?? []).filter((t) => t.kind === category).map((t) => <Link key={t.id} href={route('printing.calibrate', t.id)} className="block rounded px-2 py-2 text-sm hover:bg-slate-50">{t.name}</Link>)}
                    {list && list.filter((t) => t.kind === category).length === 0 && <p className="px-2 py-2 text-sm text-slate-400">No templates.</p>}
                </div>
            </>}
        </div>
    );
}

function PrintRoot({ pages, nudge }) {
    const t = pages[0].template;
    return createPortal(
        <div id="print-root" className="print-only">
            <style>{`@page { size: ${t.paper_width_mm}mm ${t.paper_height_mm}mm; margin: 0; } html, body { margin: 0; padding: 0; }`}</style>
            {pages.map((p) => <PrintSheet key={p.id} template={p.template} values={p.values} offset={nudge} />)}
        </div>, document.body);
}

function ConfirmPrint({ job, onClose }) {
    const [mode, setMode] = useState('ok');
    const [failed, setFailed] = useState([]);
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [errs, setErrs] = useState({});
    useEffect(() => { if (job) { setMode('ok'); setFailed([]); setNote(''); setErrs({}); } }, [job?.id]);
    if (!job) return null;
    const items = job.items ?? job.pages?.map((p) => ({ id: p.id, label: p.label })) ?? [];
    const submit = () => {
        setBusy(true);
        router.post(route('printing.confirm', job.id), { failed_ids: mode === 'ok' ? [] : (failed.length ? failed : items.map((i) => i.id)), note }, {
            preserveScroll: true, onError: setErrs, onSuccess: onClose, onFinish: () => setBusy(false),
        });
    };
    return (
        <Modal show onClose={onClose} title={`Print job #${job.id}: what happened?`} maxWidth="md" closeable={false}
            footer={<><button className="btn-secondary" onClick={onClose}>Decide later</button><button className={mode === 'ok' ? 'btn-primary' : 'btn-danger'} disabled={busy || (mode === 'problem' && !note.trim())} onClick={submit}>{mode === 'ok' ? 'Yes, printed correctly' : 'Report problem'}</button></>}>
            <p className="mb-3 text-sm text-slate-600">The browser can only start a print job — it cannot tell whether paper came out correctly. Please confirm.</p>
            <div className="grid grid-cols-2 gap-2">
                <button onClick={() => setMode('ok')} className={`rounded-lg border px-3 py-3 text-sm font-medium ${mode === 'ok' ? 'border-emerald-500 bg-emerald-50 text-emerald-800' : 'border-slate-200 hover:bg-slate-50'}`}><Icon name="check" className="mx-auto mb-1 h-5 w-5" />Printed correctly</button>
                <button onClick={() => setMode('problem')} className={`rounded-lg border px-3 py-3 text-sm font-medium ${mode === 'problem' ? 'border-red-500 bg-red-50 text-red-800' : 'border-slate-200 hover:bg-slate-50'}`}><Icon name="alert" className="mx-auto mb-1 h-5 w-5" />There was a problem</button>
            </div>
            {mode === 'problem' && (
                <div className="mt-4">
                    {items.length > 1 && <><p className="label">Which ones had a problem?</p><div className="mb-3 max-h-36 space-y-1 overflow-auto">{items.map((i) => <label key={i.id} className="flex items-center gap-2 text-sm"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={failed.includes(i.id)} onChange={() => setFailed(failed.includes(i.id) ? failed.filter((x) => x !== i.id) : [...failed, i.id])} /> {i.label}</label>)}</div><p className="hint -mt-2 mb-2">Leave all unticked if every page had a problem.</p></>}
                    <label className="label">What went wrong?</label>
                    <textarea className="input" rows={2} placeholder="e.g. Text printed too low, paper jammed" value={note} onChange={(e) => setNote(e.target.value)} autoFocus />
                    {errs.note && <p className="error">{errs.note}</p>}
                </div>
            )}
        </Modal>
    );
}
