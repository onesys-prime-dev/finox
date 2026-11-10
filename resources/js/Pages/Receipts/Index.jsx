import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import StatusBadge from '@/Components/StatusBadge';
import { EmptyState, PageHeader, Pagination, useFilters } from '@/Components/ui';
import { SavedFilters, SortHeader } from '@/Components/FilterBar';
import { useFmt } from '@/lib/format';

export default function Index({ receipts, filters, statuses, categories, savedFilters }) {
    const { auth } = usePage().props;
    const can = (p) => auth.user.permissions.includes(p);
    const fmt = useFmt();
    const [f, apply] = useFilters('receipts.index', filters);
    const rows = receipts.data;
    const quick = [{ v: '', l: 'All' }, { v: 'draft', l: 'Drafts' }, { v: 'recorded', l: 'To verify' }, { v: 'verified', l: 'Verified' }, { v: 'processed', l: 'Processed' }, { v: 'archived', l: 'Archived' }];

    return (
        <AuthenticatedLayout>
            <Head title="Receipts" />
            <PageHeader title="Receipts" subtitle="Encode receipts, attach files and track verification."
                actions={can('receipts.create') && <>
                    <Link href={route('receipts.new')} className="btn-secondary"><Icon name="edit" className="h-4 w-4" /> Enter manually</Link>
                    <Link href={route('receipts.create')} className="btn-primary"><Icon name="upload" className="h-4 w-4" /> Upload files</Link>
                </>} />
            <div className="card">
                <div className="flex flex-wrap items-center gap-1.5 border-b border-slate-100 px-4 pt-3">
                    {quick.map((q) => (
                        <button key={q.v} onClick={() => apply({ ...f, status: q.v }, true)}
                            className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium transition ${(f.status ?? '') === q.v ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800'}`}>{q.l}</button>
                    ))}
                </div>
                <div className="flex flex-wrap items-center gap-2 p-4">
                    <div className="relative min-w-[14rem] flex-1"><Icon name="search" className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                        <input className="input pl-9" placeholder="Receipt no., vendor, description, reference…" value={f.search ?? ''} onChange={(e) => apply({ ...f, search: e.target.value })} /></div>
                    <select className="input !w-auto" value={f.category_id ?? ''} onChange={(e) => apply({ ...f, category_id: e.target.value }, true)}><option value="">All categories</option>{categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>
                    <input type="date" className="input !w-auto" value={f.from ?? ''} onChange={(e) => apply({ ...f, from: e.target.value }, true)} aria-label="From" />
                    <input type="date" className="input !w-auto" value={f.to ?? ''} onChange={(e) => apply({ ...f, to: e.target.value }, true)} aria-label="To" />
                    <label className="flex items-center gap-1.5 text-sm text-slate-600"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={!!f.mine} onChange={(e) => apply({ ...f, mine: e.target.checked ? 1 : '' }, true)} /> Mine</label>
                </div>
                <SavedFilters scope="receipts" saved={savedFilters} filters={f} routeName="receipts.index" />
                <div className="overflow-x-auto">
                    <table className="tbl">
                        <thead><tr>
                            <SortHeader label="Receipt no." field="receipt_number" filters={f} apply={apply} />
                            <SortHeader label="Date" field="receipt_date" filters={f} apply={apply} />
                            <th>Vendor</th><th>Category</th>
                            <SortHeader label="Amount" field="amount" filters={f} apply={apply} className="text-right" />
                            <th className="w-10" /><SortHeader label="Status" field="status" filters={f} apply={apply} />
                        </tr></thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr key={r.id} className="row-link" onClick={() => router.visit(route('receipts.show', r.id))}>
                                    <td className="font-mono text-[13px] font-medium">{r.receipt_number ?? <span className="font-sans italic text-slate-400">#{r.id} (no number yet)</span>}</td>
                                    <td className="whitespace-nowrap text-slate-600">{fmt.date(r.receipt_date)}</td>
                                    <td className="max-w-[16rem] truncate font-medium">{r.payee ?? '—'}</td>
                                    <td className="text-slate-600">{r.category ?? '—'}</td>
                                    <td className="num font-medium">{fmt.money(r.amount)}</td>
                                    <td title={r.has_file ? 'Has a file' : 'No file attached'}>{r.has_file ? <Icon name="file" className="h-4 w-4 text-slate-400" /> : <span className="text-slate-300">—</span>}</td>
                                    <td><StatusBadge status={r.status} /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {rows.length === 0 && <EmptyState icon="receipt" title="No receipts match" text="Upload files or enter a receipt by hand." />}
                </div>
                <Pagination links={receipts.links} meta={receipts} />
            </div>
        </AuthenticatedLayout>
    );
}
