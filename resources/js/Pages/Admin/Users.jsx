import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import { EmptyState, Field, PageHeader, Pagination, useFilters } from '@/Components/ui';
import { useFmt } from '@/lib/format';

export default function Users({ users, roles, filters, passwordMin }) {
    const fmt = useFmt();
    const [f, apply] = useFilters('admin.users.index', filters);
    const [edit, setEdit] = useState(null);
    const [confirm, setConfirm] = useState(null); // {user, kind}
    const form = useForm({ name: '', email: '', role_id: roles[0]?.id ?? '', password: '' });
    const open = (u) => { setEdit(u); form.setData({ name: u.name ?? '', email: u.email ?? '', role_id: u.role_id ?? roles[0]?.id ?? '', password: '' }); form.clearErrors(); };
    const save = () => {
        const o = { preserveScroll: true, onSuccess: () => setEdit(null) };
        edit.id ? form.put(route('admin.users.update', edit.id), o) : form.post(route('admin.users.store'), o);
    };
    const gen = () => form.setData('password', Array.from(crypto.getRandomValues(new Uint8Array(12)), (b) => 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'[b % 56]).join('') + 'a1A');

    return (
        <AuthenticatedLayout>
            <Head title="Users" />
            <PageHeader title="Users" subtitle="Create accounts, assign roles and manage access."
                actions={<button className="btn-primary" onClick={() => open({})}><Icon name="plus" className="h-4 w-4" /> Add user</button>} />
            <div className="card">
                <div className="p-4"><div className="relative max-w-md"><Icon name="search" className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" /><input className="input pl-9" placeholder="Search name or email…" value={f.search ?? ''} onChange={(e) => apply({ ...f, search: e.target.value })} /></div></div>
                <div className="overflow-x-auto"><table className="tbl"><thead><tr><th>User</th><th>Role</th><th>Last sign-in</th><th>Status</th><th className="w-48" /></tr></thead>
                    <tbody>{users.data.map((u) => (
                        <tr key={u.id}>
                            <td><Link href={route('admin.users.show', u.id)} className="font-medium hover:text-brand-700">{u.name}</Link><p className="text-xs text-slate-500">{u.email}</p></td>
                            <td>{u.role}</td><td className="text-slate-600">{u.last_login_at ? fmt.date(u.last_login_at, true) : 'Never'}</td>
                            <td>{u.locked ? <span className="badge bg-amber-50 text-amber-800 ring-amber-300">Locked</span> : u.is_active ? <span className="badge bg-emerald-50 text-emerald-700 ring-emerald-200">Active</span> : <span className="badge bg-slate-100 text-slate-600 ring-slate-300">Inactive</span>}</td>
                            <td><div className="flex justify-end gap-1">
                                <button className="btn-ghost btn-sm" title="Edit" onClick={() => open(u)}><Icon name="edit" className="h-4 w-4" /></button>
                                <button className="btn-ghost btn-sm" title="Reset password" onClick={() => setConfirm({ user: u, kind: 'reset' })}><Icon name="key" className="h-4 w-4" /></button>
                                {u.locked && <button className="btn-ghost btn-sm" title="Unlock" onClick={() => router.post(route('admin.users.unlock', u.id), {}, { preserveScroll: true })}><Icon name="unlock" className="h-4 w-4" /></button>}
                                <button className="btn-ghost btn-sm" title={u.is_active ? 'Deactivate' : 'Activate'} onClick={() => setConfirm({ user: u, kind: 'toggle' })}><Icon name={u.is_active ? 'lock' : 'check'} className="h-4 w-4" /></button>
                            </div></td>
                        </tr>))}</tbody></table>
                    {users.data.length === 0 && <EmptyState icon="users" title="No users found" />}</div>
                <Pagination links={users.links} meta={users} />
            </div>

            <Modal show={!!edit} onClose={() => setEdit(null)} title={edit?.id ? 'Edit user' : 'Add user'} maxWidth="lg"
                footer={<><button className="btn-secondary" onClick={() => setEdit(null)}>Cancel</button><button className="btn-primary" disabled={form.processing} onClick={save}>{edit?.id ? 'Save' : 'Create user'}</button></>}>
                <div className="space-y-3">
                    <Field label="Full name" required error={form.errors.name}><input className="input" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} autoFocus /></Field>
                    <Field label="Email" required error={form.errors.email}><input type="email" className="input" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} /></Field>
                    <Field label="Role" required error={form.errors.role_id}><select className="input" value={form.data.role_id} onChange={(e) => form.setData('role_id', Number(e.target.value))}>{roles.map((r) => <option key={r.id} value={r.id}>{r.label}</option>)}</select></Field>
                    {!edit?.id && (
                        <Field label="Temporary password" required error={form.errors.password} hint={`At least ${passwordMin} characters with upper/lower case and a number. The user must change it at first sign-in.`}>
                            <div className="flex gap-2"><input className="input font-mono" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} /><button type="button" className="btn-secondary" onClick={gen}>Generate</button></div>
                        </Field>
                    )}
                </div>
            </Modal>
            <ConfirmDialog show={confirm?.kind === 'toggle'} onClose={() => setConfirm(null)} tone={confirm?.user.is_active ? 'danger' : 'primary'}
                title={confirm?.user.is_active ? `Deactivate ${confirm?.user.name}?` : `Activate ${confirm?.user.name}?`} confirmLabel={confirm?.user.is_active ? 'Deactivate' : 'Activate'}
                message={confirm?.user.is_active ? 'They will be signed out and unable to sign in. Their records and history are kept.' : 'They will be able to sign in again.'}
                onConfirm={() => router.post(route('admin.users.toggle', confirm.user.id), {}, { preserveScroll: true, onSuccess: () => setConfirm(null) })} />
            <ConfirmDialog show={confirm?.kind === 'reset'} onClose={() => setConfirm(null)} title={`Reset password for ${confirm?.user.name}?`} confirmLabel="Reset password"
                message="A temporary password is generated and shown once. They must change it at next sign-in."
                onConfirm={() => router.post(route('admin.users.reset', confirm.user.id), {}, { preserveScroll: true, onSuccess: () => setConfirm(null) })} />
        </AuthenticatedLayout>
    );
}
