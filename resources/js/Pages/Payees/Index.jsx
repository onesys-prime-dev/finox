import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import { EmptyState, Field, PageHeader, Pagination, useFilters } from '@/Components/ui';

export default function Index({ payees, filters }) {
    const [f, apply] = useFilters('payees.index', filters);
    const [edit, setEdit] = useState(null); // payee | {} for new
    const form = useForm({ name: '', tin: '', default_payee_line: '', is_active: true });
    const open = (p) => { setEdit(p); form.setData({ name: p.name ?? '', tin: p.tin ?? '', default_payee_line: p.default_payee_line ?? '', is_active: p.is_active ?? true }); form.clearErrors(); };
    const save = () => {
        const o = { preserveScroll: true, onSuccess: () => setEdit(null) };
        edit.id ? form.put(route('payees.update', edit.id), o) : form.post(route('payees.store'), o);
    };
    return (
        <AuthenticatedLayout>
            <Head title="Payees" />
            <PageHeader title="Payees & vendors" subtitle="Reusable names for cheques and receipts. Payees can also be added while entering a record."
                actions={<button className="btn-primary" onClick={() => open({})}><Icon name="plus" className="h-4 w-4" /> Add payee</button>} />
            <div className="card">
                <div className="p-4"><div className="relative max-w-md"><Icon name="search" className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" /><input className="input pl-9" placeholder="Search payees…" value={f.search ?? ''} onChange={(e) => apply({ ...f, search: e.target.value })} /></div></div>
                <div className="overflow-x-auto"><table className="tbl"><thead><tr><th>Name</th><th>TIN</th><th>Default payee line</th><th>Status</th><th className="w-16" /></tr></thead>
                    <tbody>{payees.data.map((p) => (
                        <tr key={p.id}><td className="font-medium">{p.name}</td><td className="text-slate-600">{p.tin ?? '—'}</td><td className="max-w-sm truncate text-slate-500" title={p.default_payee_line}>{p.default_payee_line ?? '—'}</td>
                            <td>{p.is_active ? <span className="badge bg-emerald-50 text-emerald-700 ring-emerald-200">Active</span> : <span className="badge bg-slate-100 text-slate-600 ring-slate-300">Inactive</span>}</td>
                            <td><button className="btn-ghost btn-sm" onClick={() => open(p)} aria-label={`Edit ${p.name}`} title="Edit"><Icon name="edit" className="h-4 w-4" /></button></td></tr>))}</tbody></table>
                    {payees.data.length === 0 && <EmptyState icon="users" title="No payees found" />}</div>
                <Pagination links={payees.links} meta={payees} />
            </div>
            <Modal show={!!edit} onClose={() => setEdit(null)} title={edit?.id ? 'Edit payee' : 'Add payee'} maxWidth="lg"
                footer={<><button className="btn-secondary" onClick={() => setEdit(null)}>Cancel</button><button className="btn-primary" disabled={form.processing || !form.data.name.trim()} onClick={save}>Save</button></>}>
                <div className="space-y-3">
                    <Field label="Name" required error={form.errors.name}><input className="input" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus /></Field>
                    <Field label="TIN" error={form.errors.tin}><input className="input" value={form.data.tin} onChange={(e) => form.setData('tin', e.target.value)} /></Field>
                    <Field label="Default payee line" hint="Printed instead of the name when this payee is chosen (e.g. BIR-style “FAO … IFO … TIN …”)." error={form.errors.default_payee_line}><textarea className="input" rows={2} value={form.data.default_payee_line} onChange={(e) => form.setData('default_payee_line', e.target.value)} /></Field>
                    {edit?.id && <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={form.data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} /> Active</label>}
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
