import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/Components/Icon';
import { Banner, PageHeader } from '@/Components/ui';

export default function Roles({ roles, permissions }) {
    const [activeId, setActiveId] = useState(roles[0].id);
    const role = roles.find((r) => r.id === activeId);
    const [sel, setSel] = useState(() => Object.fromEntries(roles.map((r) => [r.id, r.permissions])));
    const [saving, setSaving] = useState(false);
    const cur = sel[role.id] ?? [];
    const dirty = JSON.stringify([...cur].sort()) !== JSON.stringify([...role.permissions].sort());
    const toggle = (k) => !role.locked && setSel({ ...sel, [role.id]: cur.includes(k) ? cur.filter((x) => x !== k) : [...cur, k] });
    const toggleGroup = (keys, on) => !role.locked && setSel({ ...sel, [role.id]: on ? [...new Set([...cur, ...keys])] : cur.filter((k) => !keys.includes(k)) });

    return (
        <AuthenticatedLayout>
            <Head title="Roles & permissions" />
            <PageHeader title="Roles & permissions" subtitle="Changes apply on the user's next request and are written to the activity log." />
            <div className="grid gap-5 lg:grid-cols-[16rem_minmax(0,1fr)]">
                <div className="card h-fit p-2">
                    {roles.map((r) => (
                        <button key={r.id} onClick={() => setActiveId(r.id)} className={`w-full rounded-lg px-3 py-2.5 text-left transition ${r.id === activeId ? 'bg-brand-50' : 'hover:bg-slate-50'}`}>
                            <p className={`text-sm font-medium ${r.id === activeId ? 'text-brand-800' : ''}`}>{r.label}</p><p className="text-xs text-slate-600">{r.users_count} user(s)</p></button>))}
                </div>
                <div className="card">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                        <div><p className="font-semibold">{role.label}</p><p className="text-sm text-slate-500">{role.description}</p></div>
                        {!role.locked && <button className="btn-primary" disabled={!dirty || saving} onClick={() => { setSaving(true); router.put(route('admin.roles.update', role.id), { permissions: cur }, { preserveScroll: true, onFinish: () => setSaving(false) }); }}>Save permissions</button>}
                    </div>
                    {role.locked && <div className="p-5 pb-0"><Banner tone="info">The Administrator role always has full access and cannot be edited.</Banner></div>}
                    <div className="space-y-6 p-5">
                        {Object.entries(permissions).map(([group, list]) => {
                            const keys = list.map((p) => p.key);
                            const all = keys.every((k) => cur.includes(k));
                            return (
                                <div key={group}>
                                    <div className="mb-2 flex items-center justify-between"><p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{group}</p>
                                        {!role.locked && <button className="text-xs font-medium text-brand-700 hover:underline" onClick={() => toggleGroup(keys, !all)}>{all ? 'Clear all' : 'Select all'}</button>}</div>
                                    <div className="grid gap-1.5 sm:grid-cols-2">
                                        {list.map((p) => (
                                            <label key={p.key} className={`flex items-start gap-2.5 rounded-lg border px-3 py-2 text-sm ${cur.includes(p.key) ? 'border-brand-200 bg-brand-50/50' : 'border-slate-200'} ${role.locked ? 'opacity-70' : 'cursor-pointer hover:border-brand-300'}`}>
                                                <input type="checkbox" disabled={role.locked} className="mt-0.5 rounded border-slate-300 text-brand-600" checked={cur.includes(p.key)} onChange={() => toggle(p.key)} />
                                                <span>{p.label}<span className="block font-mono text-[10px] text-slate-400">{p.key}</span></span>
                                            </label>))}
                                    </div>
                                </div>);
                        })}
                    </div>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
