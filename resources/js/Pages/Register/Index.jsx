import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import StatusBadge from '@/Components/StatusBadge';
import { EmptyState, PageHeader, Pagination, useFilters } from '@/Components/ui';
import { SavedFilters, SortHeader } from '@/Components/FilterBar';
import { useFmt } from '@/lib/format';

export default function Index({ rows, totals, filters, accounts, users, savedFilters }) {
    const fmt = useFmt();
    const [f, apply] = useFilters('register.index', filters);
    const qs = new URLSearchParams(Object.entries(f).filter(([, v]) => v)).toString();
    const statuses = ['draft', 'prepared', 'printed', 'released', 'for_clearing', 'cleared', 'bounced', 'stale', 'cancelled', 'voided', 'recorded', 'verified', 'processed', 'archived'];

    return (
        <AuthenticatedLayout>
            <Head title="Transactions" />
            <PageHeader title="Transaction register" subtitle="Cheques and receipts in one searchable list. Read-only — it reads the original records."
                actions={<><button className="btn-secondary" onClick={() => window.print()}><Icon name="printer" className="h-4 w-4" /> Print</button>
                    <a className="btn-secondary" href={`${route('register.export')}?${qs}`}><Icon name="download" className="h-4 w-4" /> Export CSV</a>
                    <a className="btn-secondary" href={`${route('register.export')}?${qs}&format=pdf`}><Icon name="download" className="h-4 w-4" /> Export PDF</a>
                    <a className="btn-secondary" href={`${route('register.export')}?${qs}&format=xlsx`}><Icon name="download" className="h-4 w-4" /> Export Excel</a></>} />

            <div className="mb-4 grid gap-3 sm:grid-cols-2">
                <div className="card card-pad"><p className="text-xs font-medium uppercase tracking-wide text-slate-500">Cheques in view</p><p className="mt-1 text-xl font-semibold tabular-nums">{fmt.money(totals.cheque.amount)}</p><p className="text-xs text-slate-500">{totals.cheque.count} record(s)</p></div>
                <div className="card card-pad"><p className="text-xs font-medium uppercase tracking-wide text-slate-500">Receipts in view</p><p className="mt-1 text-xl font-semibold tabular-nums">{fmt.money(totals.receipt.amount)}</p><p className="text-xs text-slate-500">{totals.receipt.count} record(s) · never added to cheque totals</p></div>
            </div>

            <div className="card">
                <div className="no-print grid gap-2 p-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="relative sm:col-span-2"><Icon name="search" className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" /><input className="input pl-9" placeholder="Reference, payee/vendor or transaction ID…" value={f.search ?? ''} onChange={(e) => apply({ ...f, search: e.target.value })} /></div>
                    <select className="input" value={f.type ?? ''} onChange={(e) => apply({ ...f, type: e.target.value }, true)}><option value="">Cheques & receipts</option><option value="cheque">Cheques only</option><option value="receipt">Receipts only</option></select>
                    <select className="input" value={f.status ?? ''} onChange={(e) => apply({ ...f, status: e.target.value }, true)}><option value="">Any status</option>{statuses.map((s) => <option key={s} value={s}>{s.replace('_', ' ')}</option>)}</select>
                    <input type="date" className="input" value={f.from ?? ''} onChange={(e) => apply({ ...f, from: e.target.value }, true)} aria-label="From" />
                    <input type="date" className="input" value={f.to ?? ''} onChange={(e) => apply({ ...f, to: e.target.value }, true)} aria-label="To" />
                    <div className="flex gap-2"><input inputMode="decimal" className="input" placeholder="Min amount" value={f.min ?? ''} onChange={(e) => apply({ ...f, min: e.target.value })} /><input inputMode="decimal" className="input" placeholder="Max amount" value={f.max ?? ''} onChange={(e) => apply({ ...f, max: e.target.value })} /></div>
                    <select className="input" value={f.bank_account_id ?? ''} onChange={(e) => apply({ ...f, bank_account_id: e.target.value }, true)}><option value="">All bank accounts</option>{accounts.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}</select>
                    <select className="input" value={f.user_id ?? ''} onChange={(e) => apply({ ...f, user_id: e.target.value }, true)}><option value="">Any user</option>{users.map((u) => <option key={u.id} value={u.id}>{u.name}</option>)}</select>
                </div>
                <div className="no-print"><SavedFilters scope="register" saved={savedFilters} filters={f} routeName="register.index" /></div>
                <div className="overflow-x-auto">
                    <table className="tbl">
                        <thead><tr><SortHeader label="ID" field="type" filters={f} apply={apply} /><SortHeader label="Reference" field="reference_number" filters={f} apply={apply} /><SortHeader label="Payee / vendor" field="party" filters={f} apply={apply} />
                            <SortHeader label="Date" field="transaction_date" filters={f} apply={apply} /><SortHeader label="Amount" field="amount" filters={f} apply={apply} className="text-right" /><SortHeader label="Status" field="status" filters={f} apply={apply} /></tr></thead>
                        <tbody>
                            {rows.data.map((t) => (
                                <tr key={t.transaction_id} className="row-link" onClick={() => router.visit(route(t.type === 'cheque' ? 'cheques.show' : 'receipts.show', t.record_id))}>
                                    <td><span className={`rounded px-1.5 py-0.5 font-mono text-[11px] font-semibold ${t.type === 'cheque' ? 'bg-blue-50 text-blue-700' : 'bg-teal-50 text-teal-700'}`}>{t.transaction_id}</span></td>
                                    <td className="font-mono text-[13px]">{t.reference_number ?? '—'}</td><td className="max-w-[18rem] truncate font-medium">{t.party ?? '—'}</td>
                                    <td className="whitespace-nowrap text-slate-600">{fmt.date(t.transaction_date)}</td><td className="num font-medium">{fmt.money(t.amount)}</td><td><StatusBadge status={t.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {rows.data.length === 0 && <EmptyState icon="list" title="No transactions match" />}
                </div>
                <div className="no-print"><Pagination links={rows.links} meta={rows} /></div>
            </div>
        </AuthenticatedLayout>
    );
}
