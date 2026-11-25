import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/Components/Icon';
import StatusBadge from '@/Components/StatusBadge';
import { Banner, PageHeader } from '@/Components/ui';
import { timeAgo, useFmt } from '@/lib/format';

const TONE = {
    neutral: 'bg-white', info: 'bg-blue-50/60 border-blue-200', purple: 'bg-purple-50/60 border-purple-200',
    warn: 'bg-amber-50/70 border-amber-200', danger: 'bg-red-50/70 border-red-200',
};
const NUM = { neutral: 'text-slate-900', info: 'text-blue-700', purple: 'text-purple-700', warn: 'text-amber-700', danger: 'text-red-700' };

function Bar({ rows, total }) {
    return (
        <div className="space-y-2">
            {rows.filter((r) => r.count > 0).map((r) => (
                <div key={r.status} className="flex items-center gap-3 text-sm">
                    <div className="w-28 shrink-0"><StatusBadge status={r.status} /></div>
                    <div className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-brand-500" style={{ width: `${(r.count / Math.max(total, 1)) * 100}%` }} /></div>
                    <span className="w-10 text-right tabular-nums text-slate-600">{r.count}</span>
                </div>
            ))}
            {rows.every((r) => r.count === 0) && <p className="py-4 text-center text-sm text-slate-500">Nothing yet.</p>}
        </div>
    );
}

export default function Dashboard({ queues, stats, recent, filters, accounts, alerts }) {
    const { auth } = usePage().props;
    const fmt = useFmt();
    const [f, setF] = useState(filters);
    const apply = (n) => { setF(n); router.get(route('dashboard'), Object.fromEntries(Object.entries(n).filter(([, v]) => v)), { preserveState: true, preserveScroll: true, replace: true }); };
    const hour = new Date().getHours();
    const greet = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
    const c = stats.cheques, r = stats.receipts;

    return (
        <AuthenticatedLayout>
            <Head title="Home" />
            <PageHeader title={`${greet}, ${auth.user.name.split(' ')[0]}`} subtitle="Here is what needs your attention." />

            {alerts.length > 0 && (
                <div className="mb-5 grid gap-2 sm:grid-cols-2">
                    {alerts.map((a, i) => <Banner key={i} tone={a.tone === 'danger' ? 'bad' : a.tone === 'warn' ? 'warn' : 'info'}>{a.url ? <Link href={a.url} className="font-medium underline-offset-2 hover:underline">{a.text}</Link> : a.text}</Banner>)}
                </div>
            )}

            {queues.length > 0 && (
                <section className="mb-8">
                    <h2 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Work queue</h2>
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
                        {queues.map((q) => (
                            <Link key={q.label} href={q.url} className={`group rounded-xl border p-4 shadow-card transition hover:-translate-y-0.5 hover:shadow-pop ${TONE[q.tone]} `}>
                                <p className={`text-3xl font-semibold tabular-nums ${q.count ? NUM[q.tone] : 'text-slate-500'}`}>{q.count}</p>
                                <p className="mt-1 flex items-center justify-between text-sm font-medium text-slate-700">{q.label}<Icon name="arrowRight" className="h-4 w-4 text-slate-300 transition group-hover:translate-x-0.5 group-hover:text-slate-500" /></p>
                            </Link>
                        ))}
                    </div>
                </section>
            )}

            {(c || r) && (
                <section className="mb-4">
                    <div className="mb-3 flex flex-wrap items-end justify-between gap-3">
                        <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">Overview</h2>
                        <div className="flex flex-wrap items-center gap-2">
                            <input type="date" className="input !w-auto !py-1.5" value={f.from ?? ''} onChange={(e) => apply({ ...f, from: e.target.value })} aria-label="From date" />
                            <span className="text-slate-400">–</span>
                            <input type="date" className="input !w-auto !py-1.5" value={f.to ?? ''} onChange={(e) => apply({ ...f, to: e.target.value })} aria-label="To date" />
                            {c && accounts.length > 1 && (
                                <select className="input !w-auto !py-1.5" value={f.bank_account_id ?? ''} onChange={(e) => apply({ ...f, bank_account_id: e.target.value })}>
                                    <option value="">All bank accounts</option>{accounts.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}
                                </select>
                            )}
                            {(f.from || f.to || f.bank_account_id) && <button className="btn-ghost btn-sm" onClick={() => apply({})}>Clear</button>}
                        </div>
                    </div>
                    <div className="grid gap-4 lg:grid-cols-2">
                        {c && (
                            <div className="card card-pad">
                                <div className="mb-4 flex items-start justify-between">
                                    <div><p className="card-title">Cheques</p><p className="text-xs text-slate-500">{c.total} record(s) · excludes receipts</p></div>
                                    <Link href={route('cheques.index')} className="text-xs font-medium text-brand-700 hover:underline">View all</Link>
                                </div>
                                <div className="mb-5 grid grid-cols-2 gap-3">
                                    <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-500">Outstanding (released / for clearing)</p><p className="mt-0.5 text-lg font-semibold tabular-nums">{fmt.money(c.outstanding_amount)}</p><p className="text-xs text-slate-500">{c.outstanding_count} cheque(s)</p></div>
                                    <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-500">Total issued (excl. cancelled/void)</p><p className="mt-0.5 text-lg font-semibold tabular-nums">{fmt.money(c.total_amount)}</p></div>
                                </div>
                                <Bar rows={c.by_status} total={c.total} />
                            </div>
                        )}
                        {r && (
                            <div className="card card-pad">
                                <div className="mb-4 flex items-start justify-between">
                                    <div><p className="card-title">Receipts</p><p className="text-xs text-slate-500">{r.total} record(s) · excludes cheques</p></div>
                                    <Link href={route('receipts.index')} className="text-xs font-medium text-brand-700 hover:underline">View all</Link>
                                </div>
                                <div className="mb-5 rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-500">Total recorded receipt amount</p><p className="mt-0.5 text-lg font-semibold tabular-nums">{fmt.money(r.total_amount)}</p></div>
                                <Bar rows={r.by_status} total={r.total} />
                            </div>
                        )}
                    </div>
                    <p className="mt-2 text-xs text-slate-500">Cheque and receipt totals are shown separately and never added together.</p>
                </section>
            )}

            {(recent.transactions.length > 0 || recent.activity.length > 0) && (
                <div className="mt-6 grid gap-4 lg:grid-cols-2">
                    {recent.transactions.length > 0 && (
                        <div className="card">
                            <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3"><p className="card-title">Recent transactions</p><Link href={route('register.index')} className="text-xs font-medium text-brand-700 hover:underline">Register</Link></div>
                            <ul className="divide-y divide-slate-100">
                                {recent.transactions.map((t) => (
                                    <li key={t.transaction_id}>
                                        <Link href={route(t.type === 'cheque' ? 'cheques.show' : 'receipts.show', t.record_id)} className="flex items-center justify-between gap-3 px-5 py-3 hover:bg-slate-50">
                                            <div className="min-w-0"><p className="truncate text-sm font-medium">{t.party ?? '—'}</p><p className="text-xs text-slate-500">{t.type === 'cheque' ? 'Cheque' : 'Receipt'} {t.reference_number ?? ''}</p></div>
                                            <div className="text-right"><p className="text-sm font-medium tabular-nums">{fmt.money(t.amount)}</p><StatusBadge status={t.status} /></div>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {recent.activity.length > 0 && (
                        <div className="card">
                            <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3"><p className="card-title">Recent activity</p><Link href={route('activity.index')} className="text-xs font-medium text-brand-700 hover:underline">Activity log</Link></div>
                            <ul className="divide-y divide-slate-100">
                                {recent.activity.map((a) => (
                                    <li key={a.id} className="flex items-start gap-3 px-5 py-3">
                                        <div className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-slate-100 text-slate-500"><Icon name="activity" className="h-3.5 w-3.5" /></div>
                                        <div className="min-w-0"><p className="text-sm">{a.text}</p><p className="text-xs text-slate-500">{a.user ?? 'System'} · {timeAgo(a.at)}</p></div>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            )}

            {queues.length === 0 && !c && !r && (
                <div className="card card-pad text-sm text-slate-600">Your account has no modules assigned yet. Ask an administrator to give your role some permissions.</div>
            )}
        </AuthenticatedLayout>
    );
}
