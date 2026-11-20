import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import Icon from '@/Components/Icon';
import StatusBadge from '@/Components/StatusBadge';
import { EmptyState, PageHeader, Pagination, SidePanel, useFilters } from '@/Components/ui';
import { ColumnChooser, SavedFilters, SortHeader } from '@/Components/FilterBar';
import { useFmt } from '@/lib/format';

const COLS = [
    { key: 'cheque_number', label: 'Cheque no.' }, { key: 'cheque_date', label: 'Date' }, { key: 'payee', label: 'Payee' },
    { key: 'bank', label: 'Bank' }, { key: 'amount', label: 'Amount' }, { key: 'status', label: 'Status' }, { key: 'reference_no', label: 'Reference' },
];

export default function Index({ cheques, filters, statuses, accounts, savedFilters }) {
    const { auth } = usePage().props;
    const can = (p) => auth.user.permissions.includes(p);
    const fmt = useFmt();
    const [f, apply] = useFilters('cheques.index', filters);
    const [sel, setSel] = useState([]);
    const [panel, setPanel] = useState(null);
    const [hidden, setHidden] = useState(() => { try { return JSON.parse(localStorage.getItem('finox.cols.cheques') || '["reference_no"]'); } catch { return ['reference_no']; } });
    useEffect(() => { try { localStorage.setItem('finox.cols.cheques', JSON.stringify(hidden)); } catch {} }, [hidden]);
    useEffect(() => setSel([]), [cheques.current_page, filters.status]);
    const show = (k) => !hidden.includes(k);
    const rows = cheques.data;
    const allOn = rows.length > 0 && rows.every((r) => sel.includes(r.id));
    const selRows = rows.filter((r) => sel.includes(r.id));
    const quick = [{ v: '', l: 'All' }, { v: 'draft', l: 'Drafts' }, { v: 'prepared', l: 'Prepared' }, { v: 'printed', l: 'Printed' }, { v: 'outstanding', l: 'Outstanding' }, { v: 'cleared', l: 'Cleared' }];

    return (
        <AuthenticatedLayout>
            <Head title="Cheques" />
            <PageHeader title="Cheques" subtitle="Prepare, print, release and track cheques."
                actions={can('cheques.create') && <Link href={route('cheques.create')} className="btn-primary"><Icon name="plus" className="h-4 w-4" /> New cheque <span className="kbd ml-1 !border-brand-400 !bg-brand-700 !text-brand-100">N</span></Link>} />

            <div className="card">
                <div className="flex flex-wrap items-center gap-1.5 border-b border-slate-100 px-4 pt-3">
                    {quick.map((q) => (
                        <button key={q.v} onClick={() => apply({ ...f, status: q.v, flagged: '' }, true)}
                            className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium transition ${(f.status ?? '') === q.v && !f.flagged ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800'}`}>{q.l}</button>
                    ))}
                    {f.flagged && <span className="mb-1 ml-2 rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700 ring-1 ring-red-200">Flagged as stale</span>}
                </div>
                <div className="flex flex-wrap items-center gap-2 p-4">
                    <div className="relative min-w-[14rem] flex-1">
                        <Icon name="search" className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                        <input className="input pl-9" placeholder="Cheque no., payee, purpose, reference…" value={f.search ?? ''} onChange={(e) => apply({ ...f, search: e.target.value })} />
                    </div>
                    <select aria-label="Filter by bank account" className="input !w-auto" value={f.bank_account_id ?? ''} onChange={(e) => apply({ ...f, bank_account_id: e.target.value }, true)}>
                        <option value="">All accounts</option>{accounts.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}
                    </select>
                    <input type="date" className="input !w-auto" value={f.from ?? ''} onChange={(e) => apply({ ...f, from: e.target.value }, true)} aria-label="From" />
                    <input type="date" className="input !w-auto" value={f.to ?? ''} onChange={(e) => apply({ ...f, to: e.target.value }, true)} aria-label="To" />
                    <label className="flex items-center gap-1.5 text-sm text-slate-600"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={!!f.mine} onChange={(e) => apply({ ...f, mine: e.target.checked ? 1 : '' }, true)} /> Mine</label>
                    <ColumnChooser columns={COLS} hidden={hidden} onChange={setHidden} />
                    <select className="input !w-auto" value={f.per_page ?? 25} onChange={(e) => apply({ ...f, per_page: e.target.value }, true)} aria-label="Page size">{[25, 50, 100].map((n) => <option key={n} value={n}>{n} / page</option>)}</select>
                </div>
                <SavedFilters scope="cheques" saved={savedFilters} filters={f} routeName="cheques.index" />

                {sel.length > 0 && (
                    <div className="flex flex-wrap items-center gap-3 border-t border-brand-200 bg-brand-50 px-4 py-2.5 text-sm">
                        <span className="font-medium text-brand-900">{sel.length} selected</span>
                        {can('cheques.prepare') && selRows.some((r) => r.status === 'draft') && (
                            <button className="btn-secondary btn-sm" onClick={() => router.post(route('cheques.bulk-prepare'), { ids: selRows.filter((r) => r.status === 'draft').map((r) => r.id) }, { preserveScroll: true, onSuccess: () => setSel([]) })}>Prepare drafts</button>
                        )}
                        {can('print.print') && selRows.some((r) => r.status === 'prepared') && (
                            <Link className="btn-secondary btn-sm" href={route('printing.index', { select: selRows.filter((r) => r.status === 'prepared').map((r) => r.id).join(',') })}><Icon name="printer" className="h-3.5 w-3.5" /> Print selected</Link>
                        )}
                        <button className="ml-auto text-xs text-slate-500 hover:underline" onClick={() => setSel([])}>Clear</button>
                    </div>
                )}

                <div className="overflow-x-auto">
                    <table className="tbl">
                        <thead>
                            <tr>
                                <th className="w-10 !pr-0"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={allOn} onChange={() => setSel(allOn ? [] : rows.map((r) => r.id))} aria-label="Select all" /></th>
                                {show('cheque_number') && <SortHeader label="Cheque no." field="cheque_number" filters={f} apply={apply} />}
                                {show('cheque_date') && <SortHeader label="Date" field="cheque_date" filters={f} apply={apply} />}
                                {show('payee') && <th>Payee</th>}
                                {show('bank') && <th>Bank</th>}
                                {show('reference_no') && <th>Reference</th>}
                                {show('amount') && <SortHeader label="Amount" field="amount" filters={f} apply={apply} className="text-right" />}
                                {show('status') && <SortHeader label="Status" field="status" filters={f} apply={apply} />}
                                <th className="w-10" />
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((c) => (
                                <tr key={c.id} className="row-link" onClick={() => router.visit(route('cheques.show', c.id))}>
                                    <td className="!pr-0" onClick={(e) => e.stopPropagation()}><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={sel.includes(c.id)} onChange={() => setSel(sel.includes(c.id) ? sel.filter((x) => x !== c.id) : [...sel, c.id])} aria-label={`Select ${c.cheque_number}`} /></td>
                                    {show('cheque_number') && <td className="font-mono text-[13px] font-medium">{c.cheque_number}{c.flagged && <span title="Past the stale period" className="ml-1.5 text-red-500"><Icon name="flag" className="inline h-3.5 w-3.5" /></span>}</td>}
                                    {show('cheque_date') && <td className="whitespace-nowrap text-slate-600">{fmt.date(c.cheque_date)}</td>}
                                    {show('payee') && <td className="max-w-[18rem] truncate font-medium">{c.is_cash ? <span className="rounded bg-slate-100 px-1.5 py-0.5 text-xs">CASH</span> : c.payee}</td>}
                                    {show('bank') && <td className="text-slate-600">{c.bank}</td>}
                                    {show('reference_no') && <td className="text-slate-600">{c.reference_no ?? '—'}</td>}
                                    {show('amount') && <td className="num font-medium">{fmt.money(c.amount)}</td>}
                                    {show('status') && <td><StatusBadge status={c.status} /></td>}
                                    <td onClick={(e) => e.stopPropagation()}><button className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" onClick={() => setPanel(c)} aria-label="Quick view"><Icon name="eye" className="h-4 w-4" /></button></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {rows.length === 0 && <EmptyState icon="cheque" title="No cheques match" text="Try clearing a filter, or create a new cheque." action={can('cheques.create') && <Link href={route('cheques.create')} className="btn-primary">New cheque</Link>} />}
                </div>
                <Pagination links={cheques.links} meta={cheques} />
            </div>

            <SidePanel show={!!panel} onClose={() => setPanel(null)} title={panel ? `Cheque ${panel.cheque_number}` : ''} width="max-w-md">
                {panel && (
                    <div className="space-y-5 p-5">
                        <div className="flex items-center justify-between"><StatusBadge status={panel.status} />{panel.print_count > 0 && <span className="text-xs text-slate-500">Printed {panel.print_count}×</span>}</div>
                        <div className="rounded-xl bg-slate-50 p-4"><p className="text-xs text-slate-500">Amount</p><p className="text-2xl font-semibold tabular-nums">{fmt.money(panel.amount)}</p></div>
                        <dl className="grid grid-cols-3 gap-y-3 text-sm">
                            {[['Payee', panel.is_cash ? 'CASH' : panel.payee], ['Date', fmt.date(panel.cheque_date)], ['Bank', panel.bank], ['Purpose', panel.purpose ?? '—'], ['Reference', panel.reference_no ?? '—']].map(([k, v]) => (
                                <div key={k} className="col-span-3 grid grid-cols-3"><dt className="text-slate-500">{k}</dt><dd className="col-span-2 font-medium">{v}</dd></div>
                            ))}
                        </dl>
                        <Link href={route('cheques.show', panel.id)} className="btn-primary w-full">Open full record <Icon name="arrowRight" className="h-4 w-4" /></Link>
                    </div>
                )}
            </SidePanel>
        </AuthenticatedLayout>
    );
}
