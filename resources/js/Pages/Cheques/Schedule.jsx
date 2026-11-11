import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import Icon from '@/Components/Icon';
import { Banner, PageHeader } from '@/Components/ui';
import { useFmt } from '@/lib/format';

/** Plain-A4 schedule of every breakdown row. Never uses cheque stock. */
export default function Schedule({ cheque: c, org, by }) {
    const fmt = useFmt();
    const [printing, setPrinting] = useState(false);

    useEffect(() => {
        if (!printing) return;
        const after = () => setPrinting(false);
        window.addEventListener('afterprint', after, { once: true });
        const t = setTimeout(() => { window.print(); axios.post(route('cheques.schedule.printed', c.id)).catch(() => {}); }, 200);
        return () => { clearTimeout(t); window.removeEventListener('afterprint', after); };
    }, [printing]);

    const body = (
        <div style={{ fontFamily: 'Arial, Helvetica, sans-serif', color: '#000', fontSize: '10pt', padding: '14mm 14mm' }}>
            <p style={{ fontSize: '9pt', letterSpacing: '0.08em', textTransform: 'uppercase', color: '#444' }}>{org}</p>
            <h1 style={{ fontSize: '15pt', fontWeight: 700, margin: '2mm 0 4mm' }}>Breakdown schedule — Cheque {c.cheque_number}</h1>
            <table style={{ width: '100%', fontSize: '9.5pt', marginBottom: '5mm' }}><tbody>
                {[['Bank account', c.bank_account], ['Payee', c.payee], ['Cheque date', fmt.date(c.cheque_date)], ['Cheque amount', fmt.money(c.amount)], ['Purpose', c.purpose ?? '—'], ['Reference', c.reference_no ?? '—']].map(([k, v]) => (
                    <tr key={k}><td style={{ width: '32mm', color: '#555', padding: '0.6mm 0' }}>{k}</td><td style={{ fontWeight: 600 }}>{v}</td></tr>
                ))}
            </tbody></table>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: '9.5pt' }}>
                <thead><tr style={{ borderTop: '0.3mm solid #000', borderBottom: '0.3mm solid #000' }}>
                    {['#', 'Date', 'OR / invoice no.', 'Vendor', 'Particulars'].map((h) => <th key={h} style={{ textAlign: 'left', padding: '1.2mm 1mm' }}>{h}</th>)}<th style={{ textAlign: 'right', padding: '1.2mm 1mm' }}>Amount</th>
                </tr></thead>
                <tbody>{c.lines.map((l, i) => (
                    <tr key={l.id} style={{ borderBottom: '0.1mm solid #bbb', pageBreakInside: 'avoid' }}>
                        <td style={{ padding: '1.1mm 1mm', color: '#666' }}>{i + 1}</td><td style={{ padding: '1.1mm 1mm', whiteSpace: 'nowrap' }}>{l.line_date ? fmt.date(l.line_date) : ''}</td>
                        <td style={{ padding: '1.1mm 1mm' }}>{l.reference_no}</td><td style={{ padding: '1.1mm 1mm' }}>{l.vendor}</td><td style={{ padding: '1.1mm 1mm' }}>{l.particulars}</td>
                        <td style={{ padding: '1.1mm 1mm', textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{fmt.money(l.amount)}</td>
                    </tr>))}</tbody>
                <tfoot><tr style={{ borderTop: '0.3mm solid #000' }}><td colSpan={5} style={{ textAlign: 'right', fontWeight: 700, padding: '1.5mm 1mm' }}>TOTAL</td><td style={{ textAlign: 'right', fontWeight: 700, padding: '1.5mm 1mm' }}>{fmt.money(c.lines_total)}</td></tr></tfoot>
            </table>
            <table style={{ width: '100%', marginTop: '16mm', fontSize: '9pt' }}><tbody><tr>
                <td>Prepared by: <b>{c.prepared_by}</b></td><td>Checked by: ______________</td><td>Approved by: ______________</td></tr></tbody></table>
            <p style={{ marginTop: '8mm', fontSize: '8pt', color: '#777' }}>Printed by {by} from FINOX · {new Date().toLocaleString()}</p>
        </div>
    );

    return (
        <AuthenticatedLayout>
            <Head title={`Schedule · ${c.cheque_number}`} />
            <PageHeader title="Breakdown schedule" subtitle={`Cheque ${c.cheque_number} · every row on plain A4 paper`} back={{ href: route('cheques.show', c.id), label: 'Back to cheque' }}
                actions={<button className="btn-primary" disabled={!c.lines.length} onClick={() => setPrinting(true)}><Icon name="printer" className="h-4 w-4" /> Print on A4</button>} />
            <div className="mb-4"><Banner tone="info">Prints on ordinary A4 paper — never on cheque stock. Choose <b>Margins: Default</b> and <b>Scale: 100%</b> in the print dialog.</Banner></div>
            {c.lines.length === 0 ? <div className="card card-pad text-center text-sm text-slate-500">This cheque has no breakdown rows.</div> : (
                <div className="card mx-auto max-w-[210mm] overflow-auto bg-white shadow-card">{body}</div>
            )}
            {printing && createPortal(
                <div id="print-root" className="print-only">
                    <style>{'@page { size: A4 portrait; margin: 0; } html, body { margin: 0; padding: 0; }'}</style>
                    {body}
                </div>, document.body)}
        </AuthenticatedLayout>
    );
}
