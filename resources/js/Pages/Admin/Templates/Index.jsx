import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ConfirmDialog from '@/Components/ConfirmDialog';
import Icon from '@/Components/Icon';
import { EmptyState, PageHeader } from '@/Components/ui';

export default function Index({ templates }) {
    const [del, setDel] = useState(null);
    return (
        <AuthenticatedLayout>
            <Head title="Print templates" />
            <PageHeader title="Print templates" subtitle="Where each piece of text lands on the cheque or receipt, in millimetres. Add a bank by adding a template — no code needed."
                actions={<><Link href={route('admin.templates.create', { kind: 'receipt' })} className="btn-secondary">New receipt template</Link><Link href={route('admin.templates.create')} className="btn-primary"><Icon name="plus" className="h-4 w-4" /> New cheque template</Link></>} />
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {templates.map((t) => (
                    <div key={t.id} className="card card-pad">
                        <div className="mb-3 flex items-start justify-between gap-2">
                            <div><p className="font-semibold">{t.name}</p><p className="text-xs text-slate-500">{t.kind === 'cheque' ? 'Cheque' : 'Receipt'} · {t.size} · v{t.version}</p></div>
                            {!t.is_active && <span className="badge bg-slate-100 text-slate-600 ring-slate-300">Inactive</span>}
                        </div>
                        <p className="mb-4 text-sm text-slate-600">{t.fields} field(s) · used by {t.accounts} bank account(s)</p>
                        <div className="flex flex-wrap gap-2">
                            <Link href={route('admin.templates.edit', t.id)} className="btn-secondary btn-sm"><Icon name="edit" className="h-3.5 w-3.5" /> Edit layout</Link>
                            <Link href={route('printing.calibrate', t.id)} className="btn-secondary btn-sm"><Icon name="ruler" className="h-3.5 w-3.5" /> Calibrate</Link>
                            {!t.in_use && <button className="btn-ghost btn-sm text-red-600" onClick={() => setDel(t)} aria-label={`Delete ${t.name}`} title="Delete template"><Icon name="trash" className="h-3.5 w-3.5" /></button>}
                        </div>
                    </div>))}
            </div>
            {templates.length === 0 && <div className="card"><EmptyState icon="layout" title="No templates yet" /></div>}
            <ConfirmDialog show={!!del} onClose={() => setDel(null)} tone="danger" title={`Delete ${del?.name}?`} confirmLabel="Delete template" message="Only templates that were never used can be deleted." onConfirm={() => router.delete(route('admin.templates.destroy', del.id), { onSuccess: () => setDel(null) })} />
        </AuthenticatedLayout>
    );
}
