import { usePage } from '@inertiajs/react';

export function money(v, symbol = '₱') {
    if (v === null || v === undefined || v === '') return '—';
    const n = Number(v);
    if (Number.isNaN(n)) return '—';
    return symbol + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const MONTHS_LONG = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

/** Format "2026-11-09" or "2026-11-09 14:03:00" using the system date format setting. */
export function fmtDate(v, fmt = 'M d, Y', withTime = false) {
    if (!v) return '—';
    const s = String(v).replace('T', ' ');
    const [d, t] = s.split(' ');
    const [y, m, day] = d.split('-');
    if (!y || !m || !day) return v;
    const map = {
        'M d, Y': `${MONTHS[+m - 1]} ${day}, ${y}`,
        'd/m/Y': `${day}/${m}/${y}`,
        'm/d/Y': `${m}/${day}/${y}`,
        'Y-m-d': `${y}-${m}-${day}`,
    };
    const out = map[fmt] ?? map['M d, Y'];
    return withTime && t ? `${out} ${t.slice(0, 5)}` : out;
}

export function longDate(v) {
    if (!v) return '';
    const [y, m, d] = String(v).slice(0, 10).split('-');
    return `${MONTHS_LONG[+m - 1]} ${+d}, ${y}`;
}

export function useFmt() {
    const { app } = usePage().props;
    return {
        money: (v) => money(v, app.currency),
        date: (v, time = false) => fmtDate(v, app.date_format, time),
    };
}

export function timeAgo(iso) {
    const s = Math.max(1, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));
    if (s < 60) return 'just now';
    if (s < 3600) return `${Math.floor(s / 60)} min ago`;
    if (s < 86400) return `${Math.floor(s / 3600)} h ago`;
    return `${Math.floor(s / 86400)} d ago`;
}

export function bytes(n) {
    if (n < 1024) return `${n} B`;
    if (n < 1048576) return `${Math.round(n / 1024)} KB`;
    return `${(n / 1048576).toFixed(1)} MB`;
}

export const token = () => (crypto.randomUUID ? crypto.randomUUID() : String(Math.random()).slice(2));
