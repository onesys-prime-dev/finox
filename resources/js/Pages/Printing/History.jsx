import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import StatusBadge from '@/Components/StatusBadge';
import { EmptyState, PageHeader, Pagination, useFilters } from '@/Components/ui';
import { useFmt } from '@/lib/format';

export default function History({ logs, filters }) {
    const fmt = useFmt();
    const [f, apply] = useFilters('printing.history', filters);
    return (
        <AuthenticatedLayout>
            <Head title="Print history" />
            <PageHeader title="Print history" subtitle="Every print attempt, reprint and reported problem. Earlier attempts are never changed." back={{ href: route('printing.index'), label: 'Print center' }} />
            <div className="card">
                <div className="flex flex-wrap items-center gap-3 p-4">
                    <select className="input !w-auto" value={f.status ?? ''} onChange={(e) => apply({ ...f, status: e.target.value }, true)}><option value="">All results</option><option value="initiated">Awaiting confirmation</option><option value="confirmed">Confirmed</option><option value="failed">Problem</option></select>
                    <label className="flex items-center gap-1.5 text-sm text-slate-600"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={!!f.reprint} onChange={(e) => apply({ ...f, reprint: e.target.checked ? 1 : '' }, true)} /> Reprints only</label>
                </div>
                <div className="overflow-x-auto">
                    <table className="tbl">
                        <thead><tr><th>When</th><th>Record</th><th>Job</th><th>By</th><th>Result</th><th>Reason / note</th></tr></thead>
                        <tbody>
                            {logs.data.map((l) => (
                                <tr key={l.id}>
                                    <td className="whitespace-nowrap text-slate-600">{fmt.date(l.at, true)}</td>
                                    <td>{l.url ? <Link href={l.url} className="font-medium text-brand-700 hover:underline">{l.label}</Link> : l.label}{l.is_reprint && <span className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-amber-800">Reprint</span>}</td>
                                    <td className="text-slate-500">#{l.job_id}</td><td>{l.user}</td><td><StatusBadge status={l.status} /></td>
                                    <td className="max-w-xs truncate text-slate-600" title={`${l.reason ?? ''} ${l.note ?? ''}`}>{[l.reason, l.note].filter(Boolean).join(' · ') || '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {logs.data.length === 0 && <EmptyState icon="printer" title="No print activity yet" />}
                </div>
                <Pagination links={logs.links} meta={logs} />
            </div>
        </AuthenticatedLayout>
    );
}
