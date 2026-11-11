import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { createPortal } from 'react-dom';
import Icon from '@/Components/Icon';
import PrintSheet, { SheetFrame } from '@/Components/PrintSheet';
import { Banner, PageHeader } from '@/Components/ui';

export default function Calibrate({ template, sample, globalOffset }) {
    const [nudge, setNudge] = useState(() => { try { return JSON.parse(localStorage.getItem('finox.print.nudge')) ?? { x: 0, y: 0 }; } catch { return { x: globalOffset.x, y: globalOffset.y }; } });
    const [guides, setGuides] = useState(true);
    const [bg, setBg] = useState(true);
    const [printing, setPrinting] = useState(false);
    const save = (n) => { setNudge(n); try { localStorage.setItem('finox.print.nudge', JSON.stringify(n)); } catch {} };

    const print = () => {
        setPrinting(true);
        const after = () => { setPrinting(false); window.removeEventListener('afterprint', after); };
        window.addEventListener('afterprint', after);
        setTimeout(() => window.print(), 200);
    };

    return (
        <AuthenticatedLayout>
            <Head title="Calibration" />
            <PageHeader title="Calibration test print" subtitle={template.name} back={{ href: route('printing.index'), label: 'Print center' }}
                actions={<button className="btn-primary" onClick={print}><Icon name="printer" className="h-4 w-4" /> Print on plain paper</button>} />
            <div className="mb-5"><Banner tone="info"><b>Use ordinary paper first.</b> Print this sheet, then hold it against a real cheque (or the scan) in front of a light. If text is off, change Shift X/Y and print again. This sheet is never logged as a real print and never changes any record.</Banner></div>
            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_18rem]">
                <div className="overflow-auto rounded-xl bg-slate-100 p-4">
                    <SheetFrame template={template} scale={0.75}><PrintSheet template={template} values={sample} guides={guides} showBackground={bg} offset={nudge} scale={0.75} /></SheetFrame>
                </div>
                <div className="space-y-4">
                    <div className="card card-pad">
                        <p className="card-title mb-3">Printer offset</p>
                        <div className="grid grid-cols-2 gap-2">
                            <div><label className="label !text-xs">Shift X (mm)</label><input type="number" step="0.1" className="input" value={nudge.x} onChange={(e) => save({ ...nudge, x: Number(e.target.value) })} /></div>
                            <div><label className="label !text-xs">Shift Y (mm)</label><input type="number" step="0.1" className="input" value={nudge.y} onChange={(e) => save({ ...nudge, y: Number(e.target.value) })} /></div>
                        </div>
                        <p className="hint">Positive moves right / down. Saved on this computer and used for real prints.</p>
                    </div>
                    <div className="card card-pad space-y-2">
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={guides} onChange={(e) => setGuides(e.target.checked)} /> Print guide marks and ruler</label>
                        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={bg} onChange={(e) => setBg(e.target.checked)} /> Show cheque scan on screen <span className="text-xs text-slate-400">(never printed)</span></label>
                    </div>
                </div>
            </div>
            {printing && createPortal(
                <div id="print-root" className="print-only">
                    <style>{`@page { size: ${template.paper_width_mm}mm ${template.paper_height_mm}mm; margin: 0; } html, body { margin: 0; padding: 0; }`}</style>
                    <PrintSheet template={template} values={sample} guides={guides} offset={nudge} />
                    <div style={{ position: 'absolute', left: '8mm', top: '4mm', fontSize: '8pt', color: '#444' }}>CALIBRATION — {template.name} — not a real print</div>
                </div>, document.body)}
        </AuthenticatedLayout>
    );
}
