import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Field, PageHeader, Toggle } from '@/Components/ui';

const Section = ({ title, desc, children }) => (
    <div className="card"><div className="border-b border-slate-100 px-5 py-3.5"><p className="card-title">{title}</p>{desc && <p className="text-xs text-slate-500">{desc}</p>}</div><div className="grid gap-4 p-5 sm:grid-cols-2">{children}</div></div>
);

export default function Settings({ settings, categories }) {
    const f = useForm({ ...settings });
    const [cat, setCat] = useState('');
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
                    <div className="space-y-3 sm:col-span-2"><Toggle checked={!!f.data.notify_failed_prints} onChange={(v) => f.setData('notify_failed_prints', v)} label="Show failed prints on the home screen" />
                        <Toggle checked={!!f.data.notify_stale_cheques} onChange={(v) => f.setData('notify_stale_cheques', v)} label="Show stale-cheque review queue on the home screen" /></div>
                </Section>
                <div className="card">
                    <div className="border-b border-slate-100 px-5 py-3.5"><p className="card-title">Receipt categories</p></div>
                    <div className="p-5">
                        <div className="mb-4 flex gap-2"><input className="input max-w-xs" placeholder="New category" value={cat} onChange={(x) => setCat(x.target.value)} onKeyDown={(x) => x.key === 'Enter' && cat.trim() && router.post(route('admin.categories.store'), { name: cat }, { preserveScroll: true, onSuccess: () => setCat('') })} />
                            <button className="btn-secondary" disabled={!cat.trim()} onClick={() => router.post(route('admin.categories.store'), { name: cat }, { preserveScroll: true, onSuccess: () => setCat('') })}>Add</button></div>
                        <div className="flex flex-wrap gap-2">{categories.map((c) => (
                            <span key={c.id} className={`inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm ${c.is_active ? 'border-slate-200 bg-white' : 'border-dashed bg-slate-50 text-slate-400'}`}>{c.name}
                                <button className="text-xs text-slate-400 hover:text-slate-700" onClick={() => router.put(route('admin.categories.update', c.id), { name: c.name, is_active: !c.is_active }, { preserveScroll: true })}>{c.is_active ? 'Disable' : 'Enable'}</button></span>))}</div>
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
