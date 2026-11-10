import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import { EmptyState, Field, PageHeader } from '@/Components/ui';

export default function Banks({ banks, templates }) {
    const [bankModal, setBankModal] = useState(null);
    const [acctModal, setAcctModal] = useState(null); // {bank, account?}
    const bf = useForm({ name: '', remarks: '', is_active: true });
    const af = useForm({ branch: '', account_name: '', account_number: '', account_reference: '', cheque_format: '', print_template_id: '', starting_number: '', is_active: true, remarks: '' });

    const openBank = (b) => { setBankModal(b); bf.setData({ name: b.name ?? '', remarks: b.remarks ?? '', is_active: b.is_active ?? true }); bf.clearErrors(); };
    const openAcct = (bank, a = {}) => { setAcctModal({ bank, account: a }); af.setData({ branch: a.branch ?? '', account_name: a.account_name ?? '', account_number: a.account_number ?? '', account_reference: a.account_reference ?? '', cheque_format: a.cheque_format ?? '', print_template_id: a.print_template_id ?? '', starting_number: a.starting_number ?? '', is_active: a.is_active ?? true, remarks: a.remarks ?? '' }); af.clearErrors(); };
    const saveBank = () => { const o = { preserveScroll: true, onSuccess: () => setBankModal(null) }; bankModal.id ? bf.put(route('admin.banks.update', bankModal.id), o) : bf.post(route('admin.banks.store'), o); };
    const saveAcct = () => { const o = { preserveScroll: true, onSuccess: () => setAcctModal(null) }; acctModal.account.id ? af.put(route('admin.accounts.update', acctModal.account.id), o) : af.post(route('admin.accounts.store', acctModal.bank.id), o); };

    return (
        <AuthenticatedLayout>
            <Head title="Banks & accounts" />
            <PageHeader title="Banks & accounts" subtitle="Reference data for preparing cheques. FINOX has no connection to any bank."
                actions={<button className="btn-primary" onClick={() => openBank({})}><Icon name="plus" className="h-4 w-4" /> Add bank</button>} />
            {banks.length === 0 && <div className="card"><EmptyState icon="bank" title="No banks yet" /></div>}
            <div className="space-y-5">
                {banks.map((b) => (
                    <div key={b.id} className="card">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-3.5">
                            <div className="flex items-center gap-3"><span className="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-50 text-brand-700"><Icon name="bank" className="h-5 w-5" /></span>
                                <div><p className="font-semibold">{b.name} {!b.is_active && <span className="badge ml-1 bg-slate-100 text-slate-600 ring-slate-300">Inactive</span>}</p>{b.remarks && <p className="text-xs text-slate-500">{b.remarks}</p>}</div></div>
                            <div className="flex gap-2"><button className="btn-secondary btn-sm" onClick={() => openBank(b)}><Icon name="edit" className="h-3.5 w-3.5" /> Edit bank</button><button className="btn-primary btn-sm" onClick={() => openAcct(b)}><Icon name="plus" className="h-3.5 w-3.5" /> Add account</button></div>
                        </div>
                        {b.accounts.length === 0 ? <p className="px-5 py-6 text-center text-sm text-slate-400">No accounts for this bank.</p> : (
                            <div className="overflow-x-auto"><table className="tbl"><thead><tr><th>Account</th><th>Branch / reference</th><th>Print template</th><th>Starting no.</th><th>Cheques</th><th>Status</th><th className="w-12" /></tr></thead>
                                <tbody>{b.accounts.map((a) => (
                                    <tr key={a.id}><td><p className="font-medium">{a.account_name}</p><p className="font-mono text-xs text-slate-500">{a.account_number}</p></td>
                                        <td className="text-slate-600">{a.branch ?? '—'}<p className="text-xs text-slate-400">{a.account_reference}</p></td>
                                        <td>{a.template ?? <span className="text-xs font-medium text-amber-700">⚠ None assigned</span>}</td><td className="font-mono text-xs">{a.starting_number ?? '—'}</td><td>{a.cheques_count}</td>
                                        <td>{a.is_active ? <span className="badge bg-emerald-50 text-emerald-700 ring-emerald-200">Active</span> : <span className="badge bg-slate-100 text-slate-600 ring-slate-300">Inactive</span>}</td>
                                        <td><button className="btn-ghost btn-sm" onClick={() => openAcct(b, a)}><Icon name="edit" className="h-4 w-4" /></button></td></tr>))}</tbody></table></div>)}
                    </div>))}
            </div>

            <Modal show={!!bankModal} onClose={() => setBankModal(null)} title={bankModal?.id ? 'Edit bank' : 'Add bank'} maxWidth="md"
                footer={<><button className="btn-secondary" onClick={() => setBankModal(null)}>Cancel</button><button className="btn-primary" disabled={bf.processing || !bf.data.name.trim()} onClick={saveBank}>Save</button></>}>
                <div className="space-y-3"><Field label="Bank name" required error={bf.errors.name}><input className="input" value={bf.data.name} onChange={(e) => bf.setData('name', e.target.value)} autoFocus /></Field>
                    <Field label="Remarks"><input className="input" value={bf.data.remarks} onChange={(e) => bf.setData('remarks', e.target.value)} /></Field>
                    {bankModal?.id && <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={bf.data.is_active} onChange={(e) => bf.setData('is_active', e.target.checked)} /> Active</label>}</div>
            </Modal>
            <Modal show={!!acctModal} onClose={() => setAcctModal(null)} title={acctModal?.account?.id ? 'Edit account' : `Add account · ${acctModal?.bank.name}`} maxWidth="lg"
                footer={<><button className="btn-secondary" onClick={() => setAcctModal(null)}>Cancel</button><button className="btn-primary" disabled={af.processing} onClick={saveAcct}>Save account</button></>}>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Account name" required error={af.errors.account_name}><input className="input" value={af.data.account_name} onChange={(e) => af.setData('account_name', e.target.value)} autoFocus /></Field>
                    <Field label="Account number" required error={af.errors.account_number}><input className="input font-mono" value={af.data.account_number} onChange={(e) => af.setData('account_number', e.target.value)} /></Field>
                    <Field label="Branch"><input className="input" value={af.data.branch} onChange={(e) => af.setData('branch', e.target.value)} /></Field>
                    <Field label="Account reference (e.g. BRSTN)"><input className="input" value={af.data.account_reference} onChange={(e) => af.setData('account_reference', e.target.value)} /></Field>
                    <Field label="Cheque format"><input className="input" value={af.data.cheque_format} onChange={(e) => af.setData('cheque_format', e.target.value)} /></Field>
                    <Field label="Print template" error={af.errors.print_template_id}><select className="input" value={af.data.print_template_id} onChange={(e) => af.setData('print_template_id', e.target.value)}><option value="">— none —</option>{templates.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select></Field>
                    <Field label="Starting cheque number" hint="Used to suggest the first number."><input className="input font-mono" value={af.data.starting_number} onChange={(e) => af.setData('starting_number', e.target.value)} /></Field>
                    <Field label="Status"><label className="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={af.data.is_active} onChange={(e) => af.setData('is_active', e.target.checked)} /> Active (can be chosen for new cheques)</label></Field>
                </div>
                <Field label="Remarks" className="mt-3"><textarea className="input" rows={2} value={af.data.remarks} onChange={(e) => af.setData('remarks', e.target.value)} /></Field>
            </Modal>
        </AuthenticatedLayout>
    );
}
