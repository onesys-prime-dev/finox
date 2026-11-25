import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import Icon from '@/Components/Icon';
import { Field, PageHeader, Toggle } from '@/Components/ui';

const Section = ({ title, desc, children }) => (
    <div className="card"><div className="border-b border-slate-100 px-5 py-3.5"><p className="card-title">{title}</p>{desc && <p className="text-xs text-slate-500">{desc}</p>}</div><div className="grid gap-4 p-5 sm:grid-cols-2">{children}</div></div>
);

export default function Settings({ settings, categories }) {
    const f = useForm({ ...settings });
    const e = f.errors;
    const n = (k, props = {}) => <input className="input" type="number" value={f.data[k]} onChange={(x) => f.setData(k, x.target.value)} {...props} />;
    const t = (k) => <input className="input" value={f.data[k] ?? ''} onChange={(x) => f.setData(k, x.target.value)} />;
    return (
        <AuthenticatedLayout>
            <Head title="Settings" />
            <PageHeader title="System settings" actions={<button className="btn-primary" disabled={f.processing || !f.isDirty} onClick={() => f.put(route('admin.settings.update'), { preserveScroll: true })}>Save settings</button>} />
            <div className="max-w-4xl space-y-5">
                <Section title="Organization & branding" desc="Shown on reports and printed receipts.">
                    <Field label="System name" error={e.system_name}>{t('system_name')}</Field>
                    <Field label="Organization name" error={e.org_name}>{t('org_name')}</Field>
                    <Field label="Address" error={e.org_address} className="sm:col-span-2"><textarea className="input" rows={2} value={f.data.org_address ?? ''} onChange={(x) => f.setData('org_address', x.target.value)} /></Field>
                    <Field label="Contact" error={e.org_contact}>{t('org_contact')}</Field>
                </Section>
                <Section title="Formats">
                    <Field label="Date format"><select className="input" value={f.data.date_format} onChange={(x) => f.setData('date_format', x.target.value)}>{['M d, Y', 'd/m/Y', 'm/d/Y', 'Y-m-d'].map((d) => <option key={d}>{d}</option>)}</select></Field>
                    <Field label="Currency symbol" error={e.currency_symbol} hint="Amounts in words always use PESOS.">{t('currency_symbol')}</Field>
                </Section>
                <Section title="Documents & cheques">
                    <Field label="Upload limit (MB)" error={e.max_upload_mb} hint="Allowed types: PDF, JPG, JPEG, PNG">{n('max_upload_mb')}</Field>
                    <Field label="Stale period (days)" error={e.stale_days} hint="Released cheques older than this are flagged daily for a user to review.">{n('stale_days')}</Field>
                    <Field label="Keep records live for (years)" error={e.retention_years} hint="Older documents and activity-log entries move to Archived: hidden from default lists, never deleted.">{n('retention_years')}</Field>
                </Section>
                <Section title="Printing defaults" desc="Added to every print on top of each template's own offset. Fine-tune per computer in the calibration screen.">
                    <Field label="Default shift X (mm)" error={e.print_offset_x_mm}>{n('print_offset_x_mm', { step: 0.1 })}</Field>
                    <Field label="Default shift Y (mm)" error={e.print_offset_y_mm}>{n('print_offset_y_mm', { step: 0.1 })}</Field>
                </Section>
                <Section title="Security & sessions">
                    <Field label="Idle sign-out (minutes)" error={e.session_minutes}>{n('session_minutes')}</Field>
                    <Field label="Minimum password length" error={e.password_min_length}>{n('password_min_length')}</Field>
                    <Field label="Lock account after failed attempts" error={e.lockout_attempts}>{n('lockout_attempts')}</Field>
                    <Field label="Lock duration (minutes)" error={e.lockout_minutes}>{n('lockout_minutes')}</Field>
                </Section>
                <Section title="Notifications">
                    <div className="flex flex-wrap gap-x-10 gap-y-3 sm:col-span-2"><Toggle checked={!!f.data.notify_failed_prints} onChange={(v) => f.setData('notify_failed_prints', v)} label="Show failed prints on the home screen" />
                        <Toggle checked={!!f.data.notify_stale_cheques} onChange={(v) => f.setData('notify_stale_cheques', v)} label="Show stale-cheque review queue on the home screen" /></div>
                </Section>
                <Categories categories={categories} />
            </div>
        </AuthenticatedLayout>
    );
}

/** Receipt categories: add, search, rename, disable and (when nothing uses it) delete. */
function Categories({ categories }) {
    const [name, setName] = useState('');
    const [q, setQ] = useState('');
    const [show, setShow] = useState('all');
    const [editId, setEditId] = useState(null);
    const [draft, setDraft] = useState('');
    const [errors, setErrors] = useState({});
    const [del, setDel] = useState(null);
    const [busy, setBusy] = useState(false);
    const opt = { preserveScroll: true };

    const active = categories.filter((c) => c.is_active).length;
    const rows = [...categories]
        .sort((a, b) => (Number(b.is_active) - Number(a.is_active)) || a.name.localeCompare(b.name))
        .filter((c) => (show === 'all' || (show === 'active') === c.is_active) && c.name.toLowerCase().includes(q.trim().toLowerCase()));

    const add = () => name.trim() && router.post(route('admin.categories.store'), { name }, { ...opt, onSuccess: () => { setName(''); setErrors({}); }, onError: setErrors });
    const startEdit = (c) => { setEditId(c.id); setDraft(c.name); setErrors({}); };
    const saveEdit = (c) => {
        if (!draft.trim() || draft.trim() === c.name) { setEditId(null); return; }
        router.put(route('admin.categories.update', c.id), { name: draft, is_active: c.is_active }, { ...opt, onSuccess: () => setEditId(null), onError: setErrors });
    };
    const toggle = (c) => router.put(route('admin.categories.update', c.id), { name: c.name, is_active: !c.is_active }, opt);
    const remove = () => router.delete(route('admin.categories.destroy', del.id), { ...opt, onStart: () => setBusy(true), onFinish: () => { setBusy(false); setDel(null); } });

    const tabs = [['all', `All (${categories.length})`], ['active', `Active (${active})`], ['disabled', `Disabled (${categories.length - active})`]];

    return (
        <div className="card">
            <div className="border-b border-slate-100 px-5 py-3.5">
                <p className="card-title">Receipt categories</p>
                <p className="text-xs text-slate-500">Disabled categories disappear from the receipt form but stay on old receipts. A category no receipt uses can be deleted.</p>
            </div>
            <div className="p-5">
                <div className="mb-4">
                    <div className="flex gap-2">
                        <input className={`input max-w-xs ${errors.name && editId === null ? 'input-error' : ''}`} placeholder="New category" value={name} onChange={(x) => { setName(x.target.value); setErrors({}); }} onKeyDown={(x) => x.key === 'Enter' && add()} />
                        <button className="btn-secondary" disabled={!name.trim()} onClick={add}>Add</button>
                    </div>
                    {errors.name && editId === null && <p className="error">{errors.name}</p>}
                </div>

                <div className="mb-3 flex flex-wrap items-center gap-3">
                    <div className="flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-xs font-medium">
                        {tabs.map(([k, label]) => <button key={k} onClick={() => setShow(k)} className={`rounded-md px-3 py-1.5 transition ${show === k ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}>{label}</button>)}
                    </div>
                    <div className="relative ml-auto w-full sm:w-60">
                        <Icon name="search" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input className="input pl-9" placeholder="Search categories…" aria-label="Search categories" value={q} onChange={(x) => setQ(x.target.value)} />
                    </div>
                </div>

                <ul className="scroll-thin max-h-96 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200">
                    {rows.length === 0 && <li className="px-4 py-6 text-center text-sm text-slate-500">{categories.length === 0 ? 'No categories yet. Add the first one above.' : 'No categories match.'}</li>}
                    {rows.map((c) => (
                        <li key={c.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5">
                            {editId === c.id ? (
                                <div className="min-w-0 flex-1">
                                    <input autoFocus className={`input max-w-xs ${errors.name ? 'input-error' : ''}`} value={draft} onChange={(x) => { setDraft(x.target.value); setErrors({}); }}
                                        onKeyDown={(x) => { if (x.key === 'Enter') saveEdit(c); if (x.key === 'Escape') { setEditId(null); setErrors({}); } }} aria-label={`Rename ${c.name}`} />
                                    {errors.name && <p className="error">{errors.name}</p>}
                                </div>
                            ) : (
                                <p className={`min-w-0 flex-1 truncate text-sm ${c.is_active ? 'font-medium text-slate-800' : 'text-slate-400'}`}>{c.name}
                                    {!c.is_active && <span className="badge ml-2 bg-slate-100 text-slate-500 ring-slate-200">Disabled</span>}</p>
                            )}
                            <span className="w-24 text-right text-xs tabular-nums text-slate-500">{c.receipts_count === 0 ? 'Not used' : `${c.receipts_count} receipt${c.receipts_count === 1 ? '' : 's'}`}</span>
                            <div className="flex items-center gap-1">
                                {editId === c.id ? (
                                    <>
                                        <button className="btn-primary btn-sm" onClick={() => saveEdit(c)}>Save</button>
                                        <button className="btn-ghost btn-sm" onClick={() => { setEditId(null); setErrors({}); }}>Cancel</button>
                                    </>
                                ) : (
                                    <>
                                        <button className="btn-ghost btn-sm" onClick={() => startEdit(c)}>Rename</button>
                                        <button className="btn-ghost btn-sm" onClick={() => toggle(c)}>{c.is_active ? 'Disable' : 'Enable'}</button>
                                        <button className="btn-ghost btn-sm text-red-600 hover:bg-red-50 disabled:text-slate-300 disabled:hover:bg-transparent" disabled={c.receipts_count > 0}
                                            title={c.receipts_count > 0 ? 'Used by receipts, so it cannot be deleted. Disable it instead.' : 'Delete this category'} onClick={() => setDel(c)}>Delete</button>
                                    </>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            </div>
            <ConfirmDialog show={!!del} onClose={() => setDel(null)} tone="danger" processing={busy} title={`Delete “${del?.name}”?`} confirmLabel="Delete category" onConfirm={remove}
                message="No receipt uses this category, so it can be removed. This cannot be undone; the deletion stays in the activity log." />
        </div>
    );
}
