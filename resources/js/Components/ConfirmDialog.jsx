import { useEffect, useState } from 'react';
import Modal from './Modal';
import Icon from './Icon';

/**
 * Confirmation for sensitive actions (release, void, cancel, reprint).
 * Optional required reason and extra field. onConfirm({reason, extra}) -> may return a promise.
 */
export default function ConfirmDialog({
    show, onClose, onConfirm, title, message, confirmLabel = 'Confirm', tone = 'primary',
    reasonLabel, reasonRequired = true, extraLabel, extraRequired = false, warning, processing = false, errors = {},
}) {
    const [reason, setReason] = useState('');
    const [extra, setExtra] = useState('');
    useEffect(() => { if (show) { setReason(''); setExtra(''); } }, [show]);

    const invalid = (reasonLabel && reasonRequired && reason.trim().length < 3) || (extraLabel && extraRequired && !extra.trim());

    return (
        <Modal show={show} onClose={onClose} title={title} maxWidth="md"
            footer={<>
                <button className="btn-secondary" onClick={onClose} disabled={processing}>Cancel</button>
                <button className={tone === 'danger' ? 'btn-danger' : 'btn-primary'} disabled={invalid || processing}
                    onClick={() => onConfirm({ reason: reason.trim(), extra: extra.trim() })}>{processing ? 'Working…' : confirmLabel}</button>
            </>}>
            {warning && (
                <div className="mb-3 flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                    <Icon name="alert" className="mt-0.5 h-4 w-4 shrink-0" /> <div>{warning}</div>
                </div>
            )}
            {message && <p className="text-sm text-slate-600">{message}</p>}
            {reasonLabel && (
                <div className="mt-3">
                    <label className="label">{reasonLabel}{reasonRequired && <span className="text-red-500"> *</span>}</label>
                    <textarea className={`input ${errors.reason ? 'input-error' : ''}`} rows={2} value={reason} onChange={(e) => setReason(e.target.value)} autoFocus />
                    {errors.reason && <p className="error">{errors.reason}</p>}
                </div>
            )}
            {extraLabel && (
                <div className="mt-3">
                    <label className="label">{extraLabel}{extraRequired && <span className="text-red-500"> *</span>}</label>
                    <textarea className={`input ${errors.stop_payment_note ? 'input-error' : ''}`} rows={2} value={extra} onChange={(e) => setExtra(e.target.value)} />
                    {errors.stop_payment_note && <p className="error">{errors.stop_payment_note}</p>}
                </div>
            )}
        </Modal>
    );
}
