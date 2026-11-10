import { longDate } from '@/lib/format';

/** Resolve the text for one template field from the values map. */
export function fieldText(f, values) {
    if (f.source === 'free_text') return f.text ?? '';
    let v = values[f.source] ?? '';
    if (f.format === 'date_long') v = longDate(v);
    else if (f.format === 'amount_stars' && v) v = `**${v}**`;
    else if (f.source === 'date' && f.format !== 'date_segmented' && v) {
        const [y, m, d] = String(v).split('-');
        v = `${m}/${d}/${y}`;
    }
    v = String(v);
    if (f.label) v = `${f.label}: ${v}`;
    return f.uppercase ? v.toUpperCase() : v;
}

/** MMDDYYYY characters for segmented date boxes. */
export const segmentedDate = (iso) => {
    const [y, m, d] = String(iso || '').split('-');
    return y ? `${m}${d}${y}`.split('') : [];
};

const PT_MM = 0.3528; // 1pt in mm

/**
 * One physical page rendered in millimetres. Used by the preview, the print root and the template editor.
 * `showBackground` is for on-screen preview only — the scan is never printed.
 */
export default function PrintSheet({
    template, values, showBackground = false, guides = false, offset = { x: 0, y: 0 }, scale = 1, selected, onSelect, className = '',
    interactive = false, onFieldMouseDown,
}) {
    const ox = (template.offset_x_mm || 0) + (offset.x || 0);
    const oy = (template.offset_y_mm || 0) + (offset.y || 0);
    const W = template.paper_width_mm, H = template.paper_height_mm;
    const fields = template.layout?.fields ?? [];

    return (
        <div className={`sheet-page ${className}`} style={{
            width: `${W}mm`, height: `${H}mm`, transform: scale !== 1 ? `scale(${scale})` : undefined, transformOrigin: 'top left',
        }}>
            {showBackground && template.background_url && (
                <img src={template.background_url} alt="" className="absolute inset-0 h-full w-full select-none object-fill opacity-70 no-print-bg" draggable={false} />
            )}
            {guides && <Ruler W={W} H={H} />}
            {fields.map((f, i) => {
                const common = {
                    key: i,
                    onMouseDown: interactive ? (e) => onFieldMouseDown?.(e, i) : undefined,
                    onClick: interactive ? () => onSelect?.(i) : undefined,
                };
                const outline = guides || interactive
                    ? { outline: `${selected === i ? 0.4 : 0.15}mm ${selected === i ? 'solid #2551d6' : 'dashed rgba(37,81,214,.6)'}`, background: selected === i ? 'rgba(37,81,214,.08)' : 'transparent', cursor: interactive ? 'move' : 'default' }
                    : {};
                if (f.format === 'date_segmented') {
                    const chars = segmentedDate(values[f.source]);
                    const xs = f.char_x?.length ? f.char_x : [];
                    return (
                        <div {...common} className="absolute" style={{ left: `${f.x + ox}mm`, top: `${f.y + oy}mm`, width: `${f.width}mm`, height: `${f.font_size * PT_MM * 1.3}mm`, ...outline }}>
                            {chars.map((c, k) => (
                                <span key={k} className="sheet-field" style={{
                                    left: `${(xs[k] ?? f.x + k * 5) - f.x}mm`, top: 0, width: '4mm', marginLeft: '-2mm', textAlign: 'center',
                                    fontSize: `${f.font_size}pt`, overflow: 'visible',
                                }}>{c}</span>
                            ))}
                        </div>
                    );
                }
                const lines = f.max_lines || 1;
                return (
                    <div {...common} className="sheet-field" style={{
                        left: `${f.x + ox}mm`, top: `${f.y + oy}mm`, width: `${f.width}mm`, maxHeight: `${lines * f.font_size * PT_MM * 1.2}mm`,
                        fontSize: `${f.font_size}pt`, textAlign: f.align, fontWeight: f.bold ? 700 : 400,
                        whiteSpace: lines > 1 ? 'pre-wrap' : 'nowrap', textOverflow: lines > 1 ? 'clip' : 'ellipsis', ...outline,
                    }}>{fieldText(f, values)}</div>
                );
            })}
        </div>
    );
}

function Ruler({ W, H }) {
    const xs = [], ys = [];
    for (let x = 0; x <= W; x += 10) xs.push(x);
    for (let y = 0; y <= H; y += 10) ys.push(y);
    return (
        <svg className="pointer-events-none absolute inset-0" width={`${W}mm`} height={`${H}mm`} viewBox={`0 0 ${W} ${H}`}>
            {xs.map((x) => <g key={`x${x}`}><line x1={x} y1="0" x2={x} y2={H} stroke="#94a3b8" strokeWidth={x % 50 === 0 ? 0.25 : 0.1} />{x % 20 === 0 && <text x={x + 0.5} y="3.5" fontSize="2.6" fill="#64748b">{x}</text>}</g>)}
            {ys.map((y) => <g key={`y${y}`}><line x1="0" y1={y} x2={W} y2={y} stroke="#94a3b8" strokeWidth={y % 50 === 0 ? 0.25 : 0.1} />{y % 20 === 0 && <text x="0.5" y={y - 0.5} fontSize="2.6" fill="#64748b">{y}</text>}</g>)}
            <rect x="0.2" y="0.2" width={W - 0.4} height={H - 0.4} fill="none" stroke="#475569" strokeWidth="0.3" />
        </svg>
    );
}

/** Scaled on-screen frame for a PrintSheet. */
export function SheetFrame({ template, scale, children, crop }) {
    const mm = 3.7795; // css px per mm
    const h = (crop ? crop.height : template.paper_height_mm) * mm * scale;
    return (
        <div className="relative overflow-hidden rounded-md bg-white shadow-card ring-1 ring-slate-200"
            style={{ width: template.paper_width_mm * mm * scale, height: h }}>
            <div style={{ position: 'absolute', left: 0, top: crop ? -crop.top * mm * scale : 0 }}>{children}</div>
        </div>
    );
}
