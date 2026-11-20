import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/Components/Icon';
import { EmptyState, PageHeader, Pagination, SidePanel, useFilters } from '@/Components/ui';
import { useFmt } from '@/lib/format';

const SUBJECT_ROUTE = { cheque: 'cheques.show', receipt: 'receipts.show' };

export default function ActivityLog({ logs, filters, modules, users, retentionYears }) {
    const fmt = useFmt();
    const [f, apply] = useFilters('activity.index', filters);
    const [open, setOpen] = useState(null);
    return (
        <AuthenticatedLayout>
            <Head title="Activity log" />
            <PageHeader title="Activity log" subtitle="A permanent, read-only record of important actions. Nobody — including administrators — can edit or delete entries." />
            <div className="card">
                <div className="flex flex-wrap items-center gap-2 p-4">
                    <div className="relative min-w-[14rem] flex-1"><Icon name="search" className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" /><input className="input pl-9" placeholder="Search description or record…" value={f.search ?? ''} onChange={(e) => apply({ ...f, search: e.target.value })} /></div>
                    <select aria-label="Filter by module" className="input !w-auto" value={f.module ?? ''} onChange={(e) => apply({ ...f, module: e.target.value }, true)}><option value="">All modules</option>{modules.map((m) => <option key={m}>{m}</option>)}</select>
                    <select aria-label="Filter by user" className="input !w-auto" value={f.user_id ?? ''} onChange={(e) => apply({ ...f, user_id: e.target.value }, true)}><option value="">Any user</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select>
                    <input type="date" className="input !w-auto" value={f.from ?? ''} onChange={(e) => apply({ ...f, from: e.target.value }, true)} aria-label="From" />
                    <input type="date" className="input !w-auto" value={f.to ?? ''} onChange={(e) => apply({ ...f, to: e.target.value }, true)} aria-label="To" />
                    <label className="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" checked={!!f.archived} onChange={(e) => apply({ ...f, archived: e.target.checked ? 1 : '' }, true)} /> Include archived (older than {retentionYears} yrs)</label>
                </div>
                <div className="overflow-x-auto"><table className="tbl"><thead><tr><th>When</th><th>User</th><th>Module</th><th>Description</th><th className="w-10" /></tr></thead>
                    <tbody>{logs.data.map((l) => (
                        <tr key={l.id} className="row-link" onClick={() => setOpen(l)}>
                            <td className="whitespace-nowrap text-slate-600">{fmt.date(l.at, true)}</td><td className="whitespace-nowrap font-medium">{l.user}</td>
                            <td><span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{l.module}</span></td>
                            <td className="max-w-xl truncate">{l.description}{l.reason && <span className="ml-2 text-xs text-slate-500">“{l.reason}”</span>}</td>
                            <td><Icon name="chevronRight" className="h-4 w-4 text-slate-300" /></td></tr>))}</tbody></table>
                    {logs.data.length === 0 && <EmptyState icon="activity" title="No entries match" />}</div>
                <Pagination links={logs.links} meta={logs} />
            </div>
            <SidePanel show={!!open} onClose={() => setOpen(null)} title="Activity detail" width="max-w-lg">
                {open && (
                    <div className="space-y-4 p-5 text-sm">
                        <p className="text-base font-medium">{open.description}</p>
                        <dl className="grid grid-cols-3 gap-y-2">
                            {[['When', fmt.date(open.at, true)], ['User', open.user], ['Module', open.module], ['Action', open.action], ['IP', open.ip ?? '—']].map(([k, v]) => <div key={k} className="col-span-3 grid grid-cols-3"><dt className="text-slate-500">{k}</dt><dd className="col-span-2 font-medium">{v}</dd></div>)}
                            {open.subject && <div className="col-span-3 grid grid-cols-3"><dt className="text-slate-500">Record</dt><dd className="col-span-2 font-medium">{SUBJECT_ROUTE[open.subject_type] && open.subject_id ? <Link className="text-brand-700 hover:underline" href={route(SUBJECT_ROUTE[open.subject_type], open.subject_id)}>{open.subject}</Link> : open.subject}</dd></div>}
                            {open.reason && <div className="col-span-3 grid grid-cols-3"><dt className="text-slate-500">Reason</dt><dd className="col-span-2 font-medium">{open.reason}</dd></div>}
                        </dl>
                        {(open.old || open.new) && (
                            <div className="grid grid-cols-2 gap-3">
                                <div><p className="mb-1 text-xs font-semibold uppercase text-slate-500">Previous</p><pre className="overflow-auto rounded-lg bg-red-50 p-3 text-xs text-red-900">{JSON.stringify(open.old ?? {}, null, 2)}</pre></div>
                                <div><p className="mb-1 text-xs font-semibold uppercase text-slate-500">New</p><pre className="overflow-auto rounded-lg bg-emerald-50 p-3 text-xs text-emerald-900">{JSON.stringify(open.new ?? {}, null, 2)}</pre></div>
                            </div>
                        )}
                    </div>
                )}
            </SidePanel>
        </AuthenticatedLayout>
    );
}
