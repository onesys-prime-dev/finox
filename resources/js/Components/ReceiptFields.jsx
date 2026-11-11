import axios from 'axios';
import { useEffect, useState } from 'react';
import PayeeSelect from './PayeeSelect';
import { Field } from './ui';
import Icon from './Icon';
import { usePage } from '@inertiajs/react';

/** Shared receipt fields: duplicate check as you type, cheque linking limited to the vendor's cheques. */
export default function ReceiptFields({ data, set, errors, categories, payee, setPayee, ignoreId, chequeLabel, autoFocus }) {
    const { auth, app } = usePage().props;
    const canOverride = auth.user.permissions.includes('receipts.override_duplicate');
    const [dup, setDup] = useState({ exact: null, similar: [] });
    const [cq, setCq] = useState('');
    const [cheques, setCheques] = useState([]);
    const [linkLabel, setLinkLabel] = useState(chequeLabel ?? '');

    useEffect(() => setLinkLabel(chequeLabel ?? ''), [chequeLabel, ignoreId]);

    useEffect(() => {
        if (!data.receipt_number) { setDup({ exact: null, similar: [] }); return; }
        const t = setTimeout(() => axios.get(route('receipts.duplicates'), { params: { number: data.receipt_number, payee_id: data.payee_id || '', ignore: ignoreId } }).then((r) => setDup(r.data)), 250);
        return () => clearTimeout(t);
    }, [data.receipt_number, data.payee_id, ignoreId]);

    useEffect(() => {
        if (cq === '' ) { setCheques([]); return; }
        const t = setTimeout(() => axios.get(route('receipts.cheque-search'), { params: { q: cq, payee_id: data.payee_id || '' } }).then((r) => setCheques(r.data)), 200);
        return () => clearTimeout(t);
    }, [cq, data.payee_id]);

    return (
        <div className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Receipt number" required error={errors.receipt_number}>
                    <input className={`input font-mono ${dup.exact || errors.receipt_number ? 'input-error' : ''}`} value={data.receipt_number ?? ''} onChange={(e) => set('receipt_number', e.target.value)} autoFocus={autoFocus} />
                    {dup.exact && (
                        <div className="mt-1 text-xs font-medium text-red-600">Already recorded for this vendor. <a href={dup.exact.url} target="_blank" className="underline">Open it</a>.
                            {canOverride ? ' You may save anyway with an override reason below.' : ' Ask an administrator if this is a legitimate repeat.'}</div>
                    )}
                    {!dup.exact && dup.similar.length > 0 && <p className="mt-1 text-xs text-amber-700">Same number exists for another vendor: {dup.similar.map((s) => <a key={s.id} href={s.url} target="_blank" className="underline">{s.vendor}</a>).reduce((a, b) => [a, ', ', b])}.</p>}
                </Field>
                <Field label="Receipt date" required error={errors.receipt_date}><input type="date" className="input" value={data.receipt_date ?? ''} onChange={(e) => set('receipt_date', e.target.value)} /></Field>
            </div>
            <Field label="Vendor / payee" required error={errors.payee_id}>
                <PayeeSelect value={payee} error={errors.payee_id} onChange={(p) => { setPayee(p); set('payee_id', p?.id ?? ''); }} />
            </Field>
            <Field label="Description" error={errors.description}><input className="input" value={data.description ?? ''} onChange={(e) => set('description', e.target.value)} /></Field>
            <div className="grid gap-4 sm:grid-cols-3">
                <Field label={`Amount (${app.currency})`} required error={errors.amount}><input inputMode="decimal" className="input text-right font-mono" placeholder="0.00" value={data.amount ?? ''} onChange={(e) => set('amount', e.target.value)} /></Field>
                <Field label={`Tax amount (${app.currency})`} error={errors.tax_amount}><input inputMode="decimal" className="input text-right font-mono" placeholder="0.00" value={data.tax_amount ?? ''} onChange={(e) => set('tax_amount', e.target.value)} /></Field>
                <Field label="Tax rate (%)" error={errors.tax_rate} hint="Optional"><input inputMode="decimal" className="input text-right font-mono" value={data.tax_rate ?? ''} onChange={(e) => set('tax_rate', e.target.value)} /></Field>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <Field label="Category" error={errors.category_id}><select className="input" value={data.category_id ?? ''} onChange={(e) => set('category_id', e.target.value)}><option value="">—</option>{categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select></Field>
                <Field label="Reference no." error={errors.reference_no}><input className="input" value={data.reference_no ?? ''} onChange={(e) => set('reference_no', e.target.value)} /></Field>
            </div>
            <Field label="Linked cheque (optional)" error={errors.cheque_id} hint={data.payee_id ? "Search is limited to this vendor's cheques." : 'Pick a vendor first to narrow the list.'}>
                {data.cheque_id ? (
                    <div className="input flex items-center justify-between"><span className="truncate">{linkLabel || `Cheque #${data.cheque_id}`}</span><button type="button" onClick={() => { set('cheque_id', ''); setLinkLabel(''); }} className="text-slate-400 hover:text-slate-700"><Icon name="x" className="h-4 w-4" /></button></div>
                ) : (
                    <div className="relative">
                        <input className="input" placeholder="Search by cheque number…" value={cq} onChange={(e) => setCq(e.target.value)} onFocus={() => !cq && axios.get(route('receipts.cheque-search'), { params: { payee_id: data.payee_id || '' } }).then((r) => setCheques(r.data))} />
                        {cheques.length > 0 && <ul className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-pop">{cheques.map((c) => <li key={c.id} className="cursor-pointer px-3 py-2 text-sm hover:bg-brand-50" onMouseDown={(e) => { e.preventDefault(); set('cheque_id', c.id); setLinkLabel(c.label); setCq(''); setCheques([]); }}>{c.label}</li>)}</ul>}
                    </div>
                )}
            </Field>
            {dup.exact && canOverride && (
                <Field label="Duplicate override reason" required error={errors.duplicate_override_reason}><input className="input" value={data.duplicate_override_reason ?? ''} onChange={(e) => set('duplicate_override_reason', e.target.value)} /></Field>
            )}
            <Field label="Remarks" error={errors.remarks}><textarea className="input" rows={2} value={data.remarks ?? ''} onChange={(e) => set('remarks', e.target.value)} /></Field>
        </div>
    );
}
