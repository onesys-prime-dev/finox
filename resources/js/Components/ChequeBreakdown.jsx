import axios from 'axios';
import { useEffect, useState } from 'react';
import Icon from './Icon';
import Modal from './Modal';
import { Spinner } from './ui';
import { parseAmount } from '@/lib/amountInWords';
import { money } from '@/lib/format';

/** Every server error for one row (amount, reference, vendor, particulars, date, receipt). */
function rowErrors(errors, i) {
    const prefix = `lines.${i}.`;
    return Object.entries(errors).filter(([k]) => k.startsWith(prefix)).map(([, v]) => v);
}

const blank = () => ({ line_date: '', reference_no: '', vendor: '', particulars: '', amount: '', receipt_id: null });

/** Sum of valid row amounts in centavos (no float drift on display). */
export function linesTotalCents(lines) {
    return lines.reduce((s, l) => {
        const a = parseAmount(l.amount);
        return s + (a ? Number(a.whole) * 100 + Number(a.cents) : 0);
    }, 0);
}

/**
 * Breakdown rows printed on the Payment Advice: typed by hand or pulled in from receipts.
 * Warn-only: a total that differs from the cheque amount never blocks saving or preparing.
 */
export default function ChequeBreakdown({ lines, setLines, amount, payeeId, payeeName, chequeId, locked, errors = {}, currency = '₱', onUseTotal }) {
    const [picker, setPicker] = useState(false);
    const total = linesTotalCents(lines);
    const a = parseAmount(amount);
    const target = a ? Number(a.whole) * 100 + Number(a.cents) : null;
    const diff = target === null ? null : total - target;
    const fmt = (c) => money((c / 100).toFixed(2), currency);

    const update = (i, patch) => setLines(lines.map((l, k) => (k === i ? { ...l, ...patch } : l)));
    const remove = (i) => setLines(lines.filter((_, k) => k !== i));
    const move = (i, d) => {
        const j = i + d;
        if (j < 0 || j >= lines.length) return;
        const next = [...lines];
        [next[i], next[j]] = [next[j], next[i]];
        setLines(next);
    };

    return (
        <div className="rounded-xl border border-slate-200 p-4">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="text-sm font-semibold">Breakdown <span className="font-normal text-slate-500">(optional)</span></p>
                    <p className="text-xs text-slate-500">Printed on the Payment Advice. Add rows by hand or pull in receipts.</p>
                </div>
                {!locked && (
                    <div className="flex gap-2">
                        <button type="button" className="btn-secondary btn-sm" onClick={() => setPicker(true)}><Icon name="receipt" className="h-3.5 w-3.5" /> Add from receipts</button>
                        <button type="button" className="btn-secondary btn-sm" onClick={() => setLines([...lines, blank()])}><Icon name="plus" className="h-3.5 w-3.5" /> Add row</button>
                    </div>
                )}
            </div>

            {errors.lines && <p className="error mb-2">{errors.lines}</p>}

            {lines.length === 0 ? (
                <p className="rounded-lg bg-slate-50 px-3 py-4 text-center text-sm text-slate-400">No breakdown rows. The advice area will print empty.</p>
            ) : (
                <div className="space-y-2">
                    {lines.map((l, i) => {
                        // Every row carries its own labels, because the fields wrap onto several lines in a narrow form.
                        const lab = (t) => <span className="mb-0.5 block text-[10px] font-semibold uppercase tracking-wide text-slate-400">{t}</span>;
                        return (
                            <div key={i} className="rounded-lg border border-slate-100 bg-slate-50/50 p-2">
                                <div className="mb-1 flex items-center justify-between gap-2">
                                    <span className="text-xs font-medium text-slate-500">Row {i + 1}</span>
                                    <div className="flex items-center gap-0.5">
                                        {l.receipt_id && <a href={l.receipt_url ?? route('receipts.show', l.receipt_id)} target="_blank" className="rounded p-1 text-brand-600 hover:bg-brand-50" title="Linked receipt" aria-label="Open linked receipt"><Icon name="receipt" className="h-4 w-4" /></a>}
                                        {!locked && <>
                                            <button type="button" className="rounded p-1 text-slate-400 hover:bg-slate-100 disabled:opacity-30" onClick={() => move(i, -1)} disabled={i === 0} aria-label="Move up"><Icon name="chevronLeft" className="h-4 w-4 rotate-90" /></button>
                                            <button type="button" className="rounded p-1 text-slate-400 hover:bg-slate-100 disabled:opacity-30" onClick={() => move(i, 1)} disabled={i === lines.length - 1} aria-label="Move down"><Icon name="chevronLeft" className="h-4 w-4 -rotate-90" /></button>
                                            <button type="button" className="rounded p-1 text-slate-400 hover:bg-red-50 hover:text-red-600" onClick={() => remove(i)} aria-label="Remove row"><Icon name="trash" className="h-4 w-4" /></button>
                                        </>}
                                    </div>
                                </div>
                                <div className="grid grid-cols-[repeat(auto-fit,minmax(8.5rem,1fr))] items-end gap-2">
                                    <label className="block">{lab('Date')}<input type="date" className="input !py-1.5 text-sm" aria-label={`Row ${i + 1} date`} disabled={locked} value={l.line_date ?? ''} onChange={(e) => update(i, { line_date: e.target.value })} /></label>
                                    <label className="block">{lab('OR / invoice no.')}<input className="input !py-1.5 font-mono text-sm" aria-label={`Row ${i + 1} OR or invoice no.`} placeholder="OR / INV no." maxLength={60} disabled={locked} value={l.reference_no ?? ''} onChange={(e) => update(i, { reference_no: e.target.value })} /></label>
                                    <label className="block">{lab('Amount')}
                                        <input inputMode="decimal" className={`input !py-1.5 text-right font-mono text-sm ${errors[`lines.${i}.amount`] || (l.amount !== '' && !parseAmount(l.amount)) ? 'input-error' : ''}`} aria-label={`Row ${i + 1} amount`} placeholder="0.00" disabled={locked} value={l.amount ?? ''} onChange={(e) => update(i, { amount: e.target.value })} />
                                    </label>
                                </div>
                                {rowErrors(errors, i).map((m, k) => <p key={k} className="error">{m}</p>)}
                                <div className="mt-2 grid grid-cols-[repeat(auto-fit,minmax(11rem,1fr))] gap-2">
                                    <label className="block">{lab('Vendor')}<input className="input !py-1.5 text-sm" aria-label={`Row ${i + 1} vendor`} placeholder="Vendor" maxLength={255} disabled={locked} value={l.vendor ?? ''} onChange={(e) => update(i, { vendor: e.target.value })} /></label>
                                    <label className="block">{lab('Particulars')}<input className="input !py-1.5 text-sm" aria-label={`Row ${i + 1} particulars`} placeholder="Particulars" maxLength={255} disabled={locked} value={l.particulars ?? ''} onChange={(e) => update(i, { particulars: e.target.value })} /></label>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {lines.length > 0 && (
                <div className={`mt-3 flex flex-wrap items-center justify-between gap-2 rounded-lg px-3 py-2 text-sm ${diff === 0 ? 'bg-emerald-50 text-emerald-900' : 'bg-amber-50 text-amber-900'}`}>
                    <span><b>Breakdown total {fmt(total)}</b>{target !== null && (diff === 0 ? ' — matches the cheque amount.' : ` — ${fmt(Math.abs(diff))} ${diff > 0 ? 'more' : 'less'} than the cheque amount (${fmt(target)}). You can still save and print.`)}</span>
                    {!locked && diff !== 0 && total > 0 && onUseTotal && <button type="button" className="font-semibold underline" onClick={() => onUseTotal((total / 100).toFixed(2))}>Use total as cheque amount</button>}
                </div>
            )}

            <ReceiptPicker show={picker} onClose={() => setPicker(false)} payeeId={payeeId} payeeName={payeeName} chequeId={chequeId}
                taken={lines.map((l) => l.receipt_id).filter(Boolean)}
                onAdd={(rows) => { setLines([...lines.filter((l) => l.receipt_id || l.amount !== '' || l.reference_no || l.particulars || l.vendor), ...rows]); setPicker(false); }} />
        </div>
    );
}

function ReceiptPicker({ show, onClose, onAdd, payeeId, payeeName, chequeId, taken }) {
    const [all, setAll] = useState(false);
    const [q, setQ] = useState('');
    const [rows, setRows] = useState([]);
    const [sel, setSel] = useState([]);
    const [busy, setBusy] = useState(false);

    useEffect(() => { if (show) { setSel([]); setQ(''); setAll(!payeeId); } }, [show]);
    useEffect(() => {
        if (!show) return;
        setBusy(true);
        let stale = false;
        const t = setTimeout(() => axios.get(route('cheques.available-receipts'), { params: { payee_id: all ? '' : payeeId || '', q, ignore: chequeId || '' } })
            .then((r) => { if (!stale) setRows(r.data); }).finally(() => { if (!stale) setBusy(false); }), 200);
        return () => { stale = true; clearTimeout(t); };
    }, [show, all, q, payeeId]);

    // `sel` keeps the whole receipt, so ticks survive a new search or the "all vendors" switch.
    const list = rows.filter((r) => !taken.includes(r.id));
    const picked = (id) => sel.some((s) => s.id === id);
    const toggle = (r) => setSel(picked(r.id) ? sel.filter((s) => s.id !== r.id) : [...sel, r]);
    const add = () => onAdd(sel.filter((r) => !taken.includes(r.id)).map((r) => ({
        line_date: r.receipt_date ?? '', reference_no: r.receipt_number ?? '', vendor: r.vendor ?? '', particulars: r.description ?? '', amount: r.amount, receipt_id: r.id,
    })));

    return (
        <Modal show={show} onClose={onClose} title="Add from receipts" maxWidth="2xl"
            footer={<><button type="button" className="btn-secondary" onClick={onClose}>Cancel</button><button type="button" className="btn-primary" disabled={!sel.length} onClick={add}>Add {sel.length || ''} row(s)</button></>}>
            <div className="mb-3 flex flex-wrap items-center gap-3">
                <input className="input max-w-xs" placeholder="Search receipt no., vendor, description…" value={q} onChange={(e) => setQ(e.target.value)} autoFocus />
                {payeeId && <label className="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={all} onChange={(e) => setAll(e.target.checked)} /> Show all vendors{payeeName ? <span className="text-slate-400">(not just {payeeName})</span> : null}</label>}
                {busy && <Spinner />}
            </div>
            <div className="max-h-80 overflow-auto rounded-lg border border-slate-200">
                {list.length === 0 && !busy ? <p className="p-6 text-center text-sm text-slate-400">No unpaid receipts found{!all && payeeId ? ' for this payee' : ''}. Receipts already on another cheque are hidden.</p> : (
                    <table className="tbl">
                        <thead><tr><th className="w-8" /><th>Receipt</th><th>Date</th><th>Vendor</th><th>Description</th><th className="text-right">Amount</th></tr></thead>
                        <tbody>{list.map((r) => (
                            <tr key={r.id} className="cursor-pointer hover:bg-slate-50" onClick={() => toggle(r)}>
                                <td><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={picked(r.id)} onChange={() => toggle(r)} onClick={(e) => e.stopPropagation()} aria-label={`Select ${r.receipt_number}`} /></td>
                                <td className="font-mono text-[13px]">{r.receipt_number ?? `#${r.id}`}</td><td className="whitespace-nowrap text-slate-600">{r.receipt_date ?? '—'}</td>
                                <td className="max-w-[10rem] truncate">{r.vendor}</td><td className="max-w-[14rem] truncate text-slate-600">{r.description}</td><td className="num">{money(r.amount)}</td>
                            </tr>))}</tbody>
                    </table>
                )}
            </div>
            {sel.length > 0 && <p className="mt-2 text-xs text-slate-500">Selected: {sel.length} receipt(s), total {money((sel.reduce((s, r) => s + Math.round(Number(r.amount) * 100), 0) / 100).toFixed(2))}{sel.some((r) => !list.some((x) => x.id === r.id)) ? ' — includes receipts from an earlier search' : ''}</p>}
        </Modal>
    );
}
