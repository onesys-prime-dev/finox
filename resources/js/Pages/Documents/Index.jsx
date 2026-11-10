import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import DocPreview from '@/Components/DocPreview';
import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import { EmptyState, Field, PageHeader, Pagination, useFilters } from '@/Components/ui';
import { bytes, useFmt } from '@/lib/format';

export default function Index({ documents, filters, categories, canUpload, maxMb, retentionYears }) {
    const fmt = useFmt();
    const [f, apply] = useFilters('documents.index', filters);
    const [preview, setPreview] = useState(null);
    const [up, setUp] = useState(false);
    const form = useForm({ file: null, category: 'other', link_type: '', link_id: '' });

    return (
        <AuthenticatedLayout>
            <Head title="Documents" />
            <PageHeader title="Documents" subtitle="Receipts, cheque supporting documents and other files. Access follows your permissions."
                actions={canUpload && <button className="btn-primary" onClick={() => setUp(true)}><Icon name="upload" className="h-4 w-4" /> Upload document</button>} />
            <div className="card">
                <div className="flex flex-wrap items-center gap-2 p-4">
                    <div className="relative min-w-[14rem] flex-1"><Icon name="search" className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" /><input className="input pl-9" placeholder="Search file name…" value={f.search ?? ''} onChange={(e) => apply({ ...f, search: e.target.value })} /></div>
                    <select className="input !w-auto" value={f.category ?? ''} onChange={(e) => apply({ ...f, category: e.target.value }, true)}><option value="">All categories</option>{categories.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}</select>
                    <select className="input !w-auto" value={f.link ?? ''} onChange={(e) => apply({ ...f, link: e.target.value }, true)}><option value="">Linked or not</option><option value="cheque">Linked to a cheque</option><option value="receipt">Linked to a receipt</option><option value="unlinked">Not linked</option></select>
                    <label className="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" checked={!!f.archived} onChange={(e) => apply({ ...f, archived: e.target.checked ? 1 : '' }, true)} /> Include archived (older than {retentionYears} yrs)</label>
                </div>
                <div className="overflow-x-auto">
                    <table className="tbl">
                        <thead><tr><th>File</th><th>Category</th><th>Linked to</th><th>Uploaded</th><th className="w-24" /></tr></thead>
                        <tbody>
                            {documents.data.map((d) => (
                                <tr key={d.id} className="row-link" onClick={() => setPreview(d)}>
                                    <td><div className="flex items-center gap-3"><Icon name="file" className="h-5 w-5 shrink-0 text-slate-400" /><div className="min-w-0"><p className="truncate font-medium">{d.name}</p><p className="text-xs text-slate-500">{bytes(d.size)}{d.version > 1 ? ` · v${d.version}` : ''}</p></div></div></td>
                                    <td className="text-slate-600">{d.category}</td>
                                    <td onClick={(e) => e.stopPropagation()}>{d.linked ? <Link className="text-brand-700 hover:underline" href={d.linked.url}>{d.linked.label}</Link> : <span className="text-slate-400">—</span>}</td>
                                    <td className="whitespace-nowrap text-slate-600">{fmt.date(d.at)} · {d.uploaded_by}</td>
                                    <td onClick={(e) => e.stopPropagation()} className="text-right"><a className="btn-ghost btn-sm" href={route('documents.download', d.id)} aria-label="Download"><Icon name="download" className="h-4 w-4" /></a></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    {documents.data.length === 0 && <EmptyState icon="file" title="No documents found" />}
                </div>
                <Pagination links={documents.links} meta={documents} />
            </div>
            <DocPreview doc={preview} onClose={() => setPreview(null)} />
            <Modal show={up} onClose={() => setUp(false)} title="Upload document" maxWidth="md"
                footer={<><button className="btn-secondary" onClick={() => setUp(false)}>Cancel</button><button className="btn-primary" disabled={form.processing || !form.data.file} onClick={() => form.post(route('documents.store'), { forceFormData: true, onSuccess: () => { form.reset(); setUp(false); } })}>Upload</button></>}>
                <Field label="File" required error={form.errors.file} hint={`PDF, JPG or PNG · up to ${maxMb} MB`}><input type="file" accept=".pdf,.jpg,.jpeg,.png" className="input" onChange={(e) => form.setData('file', e.target.files[0] ?? null)} /></Field>
                <Field label="Category" className="mt-3"><select className="input" value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>{categories.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}</select></Field>
            </Modal>
        </AuthenticatedLayout>
    );
}
