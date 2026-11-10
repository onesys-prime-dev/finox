import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link } from '@inertiajs/react';
import Icon from '@/Components/Icon';
import { PageHeader } from '@/Components/ui';

const ICON = { Cheques: 'cheque', Receipts: 'receipt', System: 'activity' };

export default function Index({ reports }) {
    const groups = reports.reduce((a, r) => ((a[r.group] ||= []).push(r), a), {});
    return (
        <AuthenticatedLayout>
            <Head title="Reports" />
            <PageHeader title="Reports" subtitle="Choose a report, set the filters, then print or export." />
            <div className="grid gap-5 lg:grid-cols-3">
                {Object.entries(groups).map(([g, list]) => (
                    <div key={g} className="card">
                        <div className="flex items-center gap-2.5 border-b border-slate-100 px-5 py-3.5"><span className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-50 text-brand-700"><Icon name={ICON[g]} className="h-4 w-4" /></span><p className="card-title">{g} reports</p></div>
                        <ul className="p-2">{list.map((r) => <li key={r.key}><Link href={route('reports.show', r.key)} className="flex items-center justify-between rounded-lg px-3 py-2.5 text-sm hover:bg-slate-50">{r.title}<Icon name="chevronRight" className="h-4 w-4 text-slate-300" /></Link></li>)}</ul>
                    </div>
                ))}
            </div>
        </AuthenticatedLayout>
    );
}
