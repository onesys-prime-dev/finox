import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { EmptyState, PageHeader, Pagination } from '@/Components/ui';
import { useFmt } from '@/lib/format';

export default function Problems({ reports }) {
    const fmt = useFmt();
    return (
        <AuthenticatedLayout>
            <Head title="Problem reports" />
            <PageHeader title="Problem reports" subtitle="Reported by users through “Report a problem”." />
            <div className="card">
                <ul className="divide-y divide-slate-100">
                    {reports.data.map((p) => (
                        <li key={p.id} className={`flex items-start justify-between gap-4 px-5 py-4 ${p.is_resolved ? 'opacity-60' : ''}`}>
                            <div className="min-w-0"><p className="text-sm">{p.message}</p><p className="mt-1 text-xs text-slate-500">{p.user ?? 'Unknown'} · {fmt.date(p.at, true)} · <span className="font-mono">{p.url}</span></p></div>
                            <button className="btn-secondary btn-sm shrink-0" onClick={() => router.post(route('admin.problems.resolve', p.id), {}, { preserveScroll: true })}>{p.is_resolved ? 'Reopen' : 'Mark resolved'}</button>
                        </li>))}
                </ul>
                {reports.data.length === 0 && <EmptyState icon="help" title="No problem reports" />}
                <Pagination links={reports.links} meta={reports} />
            </div>
        </AuthenticatedLayout>
    );
}
