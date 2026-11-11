import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/Components/Icon';
import StatusBadge from '@/Components/StatusBadge';
import { EmptyState, PageHeader } from '@/Components/ui';
import { useFmt } from '@/lib/format';

export default function Show({ report, title, columns, rows, summary, filters, accounts, users, org, generatedBy, ...rest }) {
    const fmt = useFmt();
    const [f, setF] = useState(filters);
    const go = (n) => { setF(n); router.get(window.location.pathname, Object.fromEntries(Object.entries(n).filter(([, v]) => v)), { preserveState: true, replace: true }); };
    const qs = new URLSearchParams(Object.entries(f).filter(([, v]) => v)).toString();
    const isChequeReport = columns.some((c) => c.key === 'payee');
    const cell = (r, c) => {
        const v = r[c.key];
        if (v === null || v === undefined || v === '') return '—';
        if (c.type === 'money') return fmt.money(v);
        if (c.type === 'date') return fmt.date(v);
        if (c.type === 'status') return ['draft', 'prepared', 'printed', 'released', 'for_clearing', 'cleared', 'bounced', 'cancelled', 'voided', 'stale', 'recorded', 'verified', 'processed', 'archived', 'initiated', 'confirmed', 'failed'].includes(v) ? <StatusBadge status={v} /> : v;
        return v;
    };
    return (
        <AuthenticatedLayout>
            <Head title={title} />
            <PageHeader title={title} back={{ href: route('reports.index'), label: 'All reports' }}
                actions={<><button className="btn-secondary" onClick={() => window.print()}><Icon name="printer" className="h-4 w-4" /> Print / save as PDF</button>
                    <a className="btn-secondary" href={`${route('reports.export', report)}?${qs}&format=pdf`}><Icon name="download" className="h-4 w-4" /> Export PDF</a>
                    <a className="btn-secondary" href={`${route('reports.export', report)}?${qs}&format=xlsx`}><Icon name="download" className="h-4 w-4" /> Export Excel</a>
                    <a className="btn-primary" href={`${route('reports.export', report)}?${qs}`}><Icon name="download" className="h-4 w-4" /> Export CSV</a></>} />

            <div className="card no-print mb-4"><div className="flex flex-wrap items-center gap-2 p-4">
                <span className="text-sm text-slate-500">Period</span>
                <input type="date" className="input !w-auto" value={f.from ?? ''} onChange={(e) => go({ ...f, from: e.target.value })} aria-label="From" />
                <span className="text-slate-400">–</span>
                <input type="date" className="input !w-auto" value={f.to ?? ''} onChange={(e) => go({ ...f, to: e.target.value })} aria-label="To" />
                {report === 'cheque-breakdown' && (
                    <input className="input !w-64" placeholder="OR / invoice no., vendor, particulars…" aria-label="Search" defaultValue={f.q ?? ''}
                        onKeyDown={(e) => e.key === 'Enter' && go({ ...f, q: e.currentTarget.value })} onBlur={(e) => e.target.value !== (f.q ?? '') && go({ ...f, q: e.target.value })} />
                )}
                {isChequeReport && <select className="input !w-auto" value={f.bank_account_id ?? ''} onChange={(e) => go({ ...f, bank_account_id: e.target.value })}><option value="">All bank accounts</option>{accounts.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}</select>}
                <select className="input !w-auto" value={f.user_id ?? ''} onChange={(e) => go({ ...f, user_id: e.target.value })}><option value="">Any user</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select>
                {Object.values(f).some(Boolean) && <button className="btn-ghost btn-sm" onClick={() => go({})}>Clear</button>}
            </div></div>

            <div className="card report-print">
                <div className="border-b border-slate-100 px-5 py-4">
                    <p className="text-xs uppercase tracking-wide text-slate-500">{org}</p>
                    <h2 className="text-lg font-semibold">{title}</h2>
                    <p className="text-xs text-slate-500">{f.from || f.to ? `Period: ${f.from ? fmt.date(f.from) : 'start'} – ${f.to ? fmt.date(f.to) : 'today'} · ` : ''}Generated {new Date().toLocaleString()} by {generatedBy}</p>
                </div>
                {summary.length > 0 && <div className="flex flex-wrap gap-6 border-b border-slate-100 bg-slate-50 px-5 py-3">{summary.map((s) => <div key={s.label}><p className="text-xs text-slate-500">{s.label}</p><p className="text-base font-semibold tabular-nums">{s.value}</p></div>)}</div>}
                <div className="overflow-x-auto">
                    <table className="tbl">
                        <thead><tr>{columns.map((c) => <th key={c.key} className={c.type === 'money' ? 'text-right' : ''}>{c.label}</th>)}</tr></thead>
                        <tbody>{rows.map((r, i) => <tr key={i}>{columns.map((c) => <td key={c.key} className={c.type === 'money' ? 'num' : ''}>{cell(r, c)}</td>)}</tr>)}</tbody>
                    </table>
                    {rows.length === 0 && <EmptyState icon="chart" title="No data for these filters" />}
                    {rest.truncated && (
                        <div className="no-print flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 bg-amber-50 px-5 py-3 text-sm text-amber-900">
                            <span>Showing the first {rows.length} of {rest.totalRows} rows. Totals above include every row.</span>
                            <span className="flex gap-3"><button className="font-semibold underline" onClick={() => go({ ...f, all: 1 })}>Show all rows</button><a className="font-semibold underline" href={`${route('reports.export', report)}?${qs}`}>Export CSV</a></span>
                        </div>
                    )}
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
