import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ReceiptFields from '@/Components/ReceiptFields';
import { PageHeader, Spinner } from '@/Components/ui';
import { token } from '@/lib/format';

export default function Form({ receipt, categories }) {
    const editing = !!receipt;
    const form = useForm({
        receipt_number: receipt?.receipt_number ?? '', receipt_date: receipt?.receipt_date ?? new Date().toISOString().slice(0, 10), payee_id: receipt?.payee_id ?? '',
        description: receipt?.description ?? '', amount: receipt?.amount ?? '', tax_amount: receipt && receipt.tax_amount !== '0.00' ? receipt.tax_amount : '', tax_rate: receipt?.tax_rate ?? '',
        category_id: receipt?.category_id ?? '', cheque_id: receipt?.cheque_id ?? '', reference_no: receipt?.reference_no ?? '', remarks: receipt?.remarks ?? '',
        duplicate_override_reason: '', file: null, _next: 'record', _submit_token: token(),
    });
    const [payee, setPayee] = useState(receipt?.payee_id ? { id: receipt.payee_id, name: receipt.payee_name } : null);
    const save = (next) => {
        form.transform((d) => ({ ...d, _next: next, amount: String(d.amount ?? '').replace(/,/g, ''), tax_amount: String(d.tax_amount ?? '').replace(/,/g, ''), ...(editing ? { _method: 'put' } : {}) }));
        form.post(editing ? route('receipts.update', receipt.id) : route('receipts.store'), { forceFormData: true, preserveScroll: true });
    };
    return (
        <AuthenticatedLayout>
            <Head title={editing ? 'Edit receipt' : 'New receipt'} />
            <PageHeader title={editing ? `Edit receipt ${receipt.receipt_number ?? '#' + receipt.id}` : 'New receipt'}
                back={{ href: editing ? route('receipts.show', receipt.id) : route('receipts.index'), label: editing ? 'Back to receipt' : 'All receipts' }} />
            <form onSubmit={(e) => { e.preventDefault(); save('record'); }} className="card card-pad mx-auto max-w-3xl">
                <ReceiptFields data={form.data} set={form.setData} errors={form.errors} categories={categories} payee={payee} setPayee={setPayee} ignoreId={receipt?.id} chequeLabel={receipt?.cheque_label} autoFocus />
                {!editing && (
                    <div className="mt-4"><label className="label">Attach a file (optional)</label>
                        <input type="file" accept=".pdf,.jpg,.jpeg,.png" className="input" onChange={(e) => form.setData('file', e.target.files[0] ?? null)} />
                        {form.errors.file && <p className="error">{form.errors.file}</p>}</div>
                )}
                <div className="mt-5 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
                    {(!editing || receipt.status === 'draft') && <button type="button" className="btn-secondary" disabled={form.processing} onClick={() => save('draft')}>Save as draft</button>}
                    <button className="btn-primary ml-auto" disabled={form.processing}>{form.processing && <Spinner />} {editing && receipt.status !== 'draft' ? 'Save changes' : 'Save receipt'}</button>
                </div>
            </form>
        </AuthenticatedLayout>
    );
}
