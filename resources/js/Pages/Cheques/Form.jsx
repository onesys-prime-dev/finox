import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useMemo, useRef, useState } from 'react';
import Icon from '@/Components/Icon';
import ChequeBreakdown, { linesTotalCents } from '@/Components/ChequeBreakdown';
import PayeeSelect from '@/Components/PayeeSelect';
import PrintSheet, { SheetFrame } from '@/Components/PrintSheet';
import { Banner, Field, PageHeader, Spinner } from '@/Components/ui';
import { amountInWords, parseAmount } from '@/lib/amountInWords';
import { today, token } from '@/lib/format';

export default function Form({ cheque, accounts, locked = false, duplicateOf }) {
    const { auth, app } = usePage().props;
    const can = (p) => auth.user.permissions.includes(p);
    const editing = !!cheque?.id;
    const params = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
    const defaultAccount = cheque?.bank_account_id ?? (params.get('bank_account_id') ? Number(params.get('bank_account_id')) : accounts[0]?.id);

    const form = useForm({
        bank_account_id: defaultAccount ?? '', cheque_number: cheque?.cheque_number ?? '', cheque_date: cheque?.cheque_date ?? today(),
        payee_id: cheque?.payee_id ?? '', payee_line: cheque?.payee_line ?? '', is_cash: cheque?.is_cash ?? false, amount: cheque?.amount ?? '',
        purpose: cheque?.purpose ?? '', reference_no: cheque?.reference_no ?? '', remarks: cheque?.remarks ?? '', _next: 'draft', _submit_token: token(),
        lines: (cheque?.lines ?? []).map((l) => ({ line_date: l.line_date ?? '', reference_no: l.reference_no ?? '', vendor: l.vendor ?? '', particulars: l.particulars ?? '', amount: l.amount ?? '', receipt_id: l.receipt_id ?? null, receipt_url: l.receipt_url ?? null })),
    });
    const { data, setData, errors } = form;
    const [payee, setPayee] = useState(cheque?.payee_id ? { id: cheque.payee_id, name: cheque.payee_name } : null);
    const [dup, setDup] = useState(null);
    const [lineOpen, setLineOpen] = useState(!!cheque?.payee_line);
    const [view, setView] = useState('cheque'); // cheque | advice | full
    const [dirty, setDirty] = useState(false);
    const formRef = useRef();

    const account = accounts.find((a) => a.id === Number(data.bank_account_id));
    const words = useMemo(() => amountInWords(data.amount), [data.amount]);
    const amountOk = parseAmount(data.amount);
    const amountInvalid = data.amount !== '' && !amountOk;

    // Prefill the next cheque number for the chosen account (new cheques only).
    useEffect(() => {
        if (editing || !data.bank_account_id || data.cheque_number) return;
        axios.get(route('cheques.check'), { params: { bank_account_id: data.bank_account_id, want_next: 1 } }).then((r) => r.data.next_number && setData('cheque_number', r.data.next_number));
    }, [data.bank_account_id]);

    // Live duplicate check.
    useEffect(() => {
        if (locked || !data.cheque_number || !data.bank_account_id) { setDup(null); return; }
        let stale = false;
        const t = setTimeout(() => axios.get(route('cheques.check'), { params: { bank_account_id: data.bank_account_id, cheque_number: data.cheque_number, ignore: cheque?.id } }).then((r) => { if (!stale) setDup(r.data.duplicate); }), 250);
        return () => { stale = true; clearTimeout(t); };
    }, [data.cheque_number, data.bank_account_id]);

    // Warn on leaving with unsaved changes.
    useEffect(() => {
        const h = (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } };
        window.addEventListener('beforeunload', h);
        return () => window.removeEventListener('beforeunload', h);
    }, [dirty]);

    const set = (k, v) => { setDirty(true); setData(k, v); };

    const submit = (next) => {
        if (dup || amountInvalid) return;
        form.transform((d) => ({ ...d, _next: next }));
        setDirty(false);
        const opts = { preserveScroll: true, onError: () => setDirty(true) };
        editing ? form.put(route('cheques.update', cheque.id), opts) : form.post(route('cheques.store'), opts);
    };

    // Ctrl+S saves the draft; Enter moves to the next field.
    useEffect(() => {
        const h = (e) => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); submit('draft'); } };
        window.addEventListener('keydown', h);
        return () => window.removeEventListener('keydown', h);
    });
    const onEnter = (e) => {
        if (e.key !== 'Enter' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'BUTTON' || e.target.getAttribute('role') === 'combobox') return;
        e.preventDefault();
        const els = [...formRef.current.querySelectorAll('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled])')];
        const i = els.indexOf(e.target);
        els[i + 1]?.focus();
    };

    const values = {
        payee_line: data.is_cash ? 'CASH' : (data.payee_line || payee?.name || ''),
        amount_number: amountOk ? Number(data.amount.replace(/,/g, '')).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '',
        breakdown: (() => {
            const rows = data.lines.filter((l) => parseAmount(l.amount)).map((l) => ({ line_date: l.line_date, reference_no: l.reference_no, vendor: l.vendor, particulars: l.particulars,
                amount: Number(String(l.amount).replace(/,/g, '')).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }));
            return { rows, count: rows.length, total: rows.length ? (linesTotalCents(data.lines) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '' };
        })(),
        amount_words: words, date: data.cheque_date, check_no: data.cheque_number, purpose: data.purpose, reference: data.reference_no, prepared_by: auth.user.name,
    };
    const tpl = account?.template;
    const scale = 0.55;

    return (
        <AuthenticatedLayout>
            <Head title={editing ? `Edit cheque ${cheque.cheque_number}` : duplicateOf ? 'Duplicate cheque' : 'New cheque'} />
            <PageHeader title={editing ? `Edit cheque ${cheque.cheque_number}` : duplicateOf ? 'New cheque from existing' : 'New cheque'}
                subtitle={locked ? 'This cheque is locked. Only purpose, reference and remarks can change.' : 'Fill in the details. The preview updates as you type.'}
                back={{ href: editing ? route('cheques.show', cheque.id) : route('cheques.index'), label: editing ? 'Back to cheque' : 'All cheques' }} />

            {duplicateOf && <div className="mb-4"><Banner tone="info">Details copied from an existing cheque. Enter a new cheque number from the physical stock.</Banner></div>}

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,28rem)]">
                <form ref={formRef} onKeyDown={onEnter} onSubmit={(e) => { e.preventDefault(); submit('draft'); }} className="card card-pad space-y-5">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Bank account" required error={errors.bank_account_id}>
                            <select className="input" value={data.bank_account_id} disabled={locked} onChange={(e) => { set('bank_account_id', Number(e.target.value)); if (!editing) setData('cheque_number', ''); }}>
                                {accounts.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}
                            </select>
                            {account && !account.has_template && <p className="mt-1 text-xs font-medium text-amber-700">No print template is assigned to this account yet — it can be saved but not printed.</p>}
                        </Field>
                        <Field label="Cheque number" required error={errors.cheque_number} hint="Copy from the physical cheque stock. The next number is suggested for you.">
                            <input className={`input font-mono ${dup || errors.cheque_number ? 'input-error' : ''}`} value={data.cheque_number} disabled={locked} onChange={(e) => set('cheque_number', e.target.value)} autoFocus={!editing} />
                            {dup && <p className="error">Already used for this account. <a className="underline" href={dup.url} target="_blank">Open that cheque ({dup.status})</a></p>}
                        </Field>
                        <Field label="Cheque date" required error={errors.cheque_date}>
                            <input type="date" className="input" value={data.cheque_date} disabled={locked} onChange={(e) => set('cheque_date', e.target.value)} />
                        </Field>
                        <Field label={`Amount (${app.currency})`} required error={errors.amount ?? (amountInvalid ? 'Enter digits only, up to 2 decimals.' : null)}>
                            <input inputMode="decimal" className={`input text-right font-mono text-base ${amountInvalid || errors.amount ? 'input-error' : ''}`} placeholder="0.00" value={data.amount} disabled={locked} onChange={(e) => set('amount', e.target.value)} />
                        </Field>
                    </div>

                    <Field label="Amount in words" hint="Generated automatically and cannot be edited.">
                        <div className="min-h-[2.5rem] rounded-lg border border-dashed border-slate-300 bg-slate-50 px-3 py-2 font-mono text-[13px] leading-snug text-slate-700">{words || <span className="text-slate-500">Enter an amount…</span>}</div>
                    </Field>

                    <div>
                        <div className="mb-1 flex items-center justify-between">
                            <label className="label !mb-0">Payee <span className="text-red-500">*</span></label>
                            <label className="flex items-center gap-1.5 text-xs text-slate-600"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={data.is_cash} disabled={locked} onChange={(e) => set('is_cash', e.target.checked)} /> Pay to CASH</label>
                        </div>
                        {data.is_cash ? (
                            <div className="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-600">The payee line will print <b>CASH</b>. A payee record is still kept for reporting.</div>
                        ) : null}
                        <div className={data.is_cash ? 'mt-2' : ''}>
                            <PayeeSelect value={payee} disabled={locked} error={errors.payee_id} onChange={(p) => { setPayee(p); set('payee_id', p?.id ?? ''); if (p?.default_payee_line && !data.payee_line) { set('payee_line', p.default_payee_line); setLineOpen(true); } }} />
                        </div>
                        {errors.payee_id && <p className="error">{errors.payee_id}</p>}
                        {!lineOpen && !locked && !data.is_cash && <button type="button" className="mt-1.5 text-xs font-medium text-brand-700 hover:underline" onClick={() => setLineOpen(true)}>Print different text on the payee line…</button>}
                        {lineOpen && !data.is_cash && (
                            <div className="mt-2">
                                <textarea className="input" rows={2} disabled={locked} placeholder="e.g. BPI Ayala II Branch FAO Bureau of Internal Revenue IFO … TIN …" value={data.payee_line} onChange={(e) => set('payee_line', e.target.value)} />
                                <p className="hint">Overrides the name printed on the cheque (used for BIR-style payee lines). Leave empty to print the payee name.</p>
                            </div>
                        )}
                    </div>

                    <ChequeBreakdown lines={data.lines} setLines={(v) => set('lines', v)} amount={data.amount} payeeId={data.payee_id} payeeName={payee?.name}
                        chequeId={cheque?.id} locked={locked} errors={errors} currency={app.currency} onUseTotal={(t) => set('amount', t)} />

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field label="Purpose" error={errors.purpose}><input className="input" value={data.purpose} onChange={(e) => set('purpose', e.target.value)} /></Field>
                        <Field label="Reference no." error={errors.reference_no}><input className="input" value={data.reference_no} onChange={(e) => set('reference_no', e.target.value)} /></Field>
                    </div>
                    <Field label="Remarks (internal)" error={errors.remarks}><textarea className="input" rows={2} value={data.remarks} onChange={(e) => set('remarks', e.target.value)} /></Field>

                    <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
                        <button type="button" className="btn-secondary" disabled={form.processing || !!dup} onClick={() => submit('draft')}>
                            {form.processing ? <Spinner /> : <Icon name="save" className="h-4 w-4" />} {editing ? 'Save changes' : 'Save draft'} <span className="kbd">Ctrl S</span>
                        </button>
                        {!editing && <button type="button" className="btn-secondary" disabled={form.processing || !!dup} onClick={() => submit('another')}>Save and add another</button>}
                        {can('cheques.prepare') && !locked && (
                            <button type="button" className="btn-primary ml-auto" disabled={form.processing || !!dup} onClick={() => submit('prepare')}><Icon name="check" className="h-4 w-4" /> {editing ? 'Save and prepare' : 'Save and prepare'}</button>
                        )}
                    </div>
                </form>

                <aside className="xl:sticky xl:top-24 xl:self-start">
                    <div className="card card-pad">
                        <div className="mb-3 flex items-center justify-between">
                            <p className="card-title">Live preview</p>
                            {tpl && (
                                <div className="flex gap-1 rounded-lg bg-slate-100 p-0.5 text-xs font-medium">
                                    {[['cheque', 'Cheque'], ['advice', 'Advice'], ['full', 'Full sheet']].map(([v, l]) => (
                                        <button key={v} type="button" onClick={() => setView(v)} className={`rounded-md px-2 py-1 ${view === v ? 'bg-white shadow-sm' : 'text-slate-600'}`}>{l}</button>
                                    ))}
                                </div>
                            )}
                        </div>
                        {tpl ? (
                            <div className="overflow-x-auto">
                                <SheetFrame template={tpl} scale={scale} crop={view === 'cheque' ? tpl.layout?.preview_crop : view === 'advice' ? (tpl.layout?.advice_crop ?? null) : null}>
                                    <PrintSheet template={tpl} values={values} showBackground scale={scale} />
                                </SheetFrame>
                            </div>
                        ) : (
                            <div className="rounded-lg border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No print template for this bank account.<br />An administrator can assign one under Print templates.</div>
                        )}
                        <p className="mt-3 text-xs text-slate-500">Cheque number, account details and the MICR line are pre-printed on the stock — FINOX never prints them.</p>
                    </div>
                </aside>
            </div>
        </AuthenticatedLayout>
    );
}
