import { useId } from 'react';

/** FINOX brand mark: a ledger-bar "F" with a verified-tick accent on a gradient tile. */
export function LogoMark({ className = 'h-8 w-8' }) {
    const id = useId().replace(/:/g, '');
    return (
        <svg viewBox="0 0 32 32" className={className} role="img" aria-label="FINOX" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <linearGradient id={`g${id}`} x1="3" y1="2" x2="29" y2="30" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stopColor="#4f7cff" />
                    <stop offset="1" stopColor="#1e40af" />
                </linearGradient>
            </defs>
            <rect width="32" height="32" rx="8" fill={`url(#g${id})`} />
            <rect x="0.5" y="0.5" width="31" height="31" rx="7.5" fill="none" stroke="#fff" strokeOpacity=".18" />
            <path d="M10 8h13a1.5 1.5 0 0 1 0 4h-8v3h6.5a1.5 1.5 0 0 1 0 4H15v3.5a2 2 0 0 1-5 0z" fill="#fff" />
            <path d="M19.2 23.2l2 2 4-4.6" fill="none" stroke="#5eead4" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
    );
}

export default function Logo({ name = 'FINOX', sub, size = 'md', dark = true }) {
    const big = size === 'lg';
    return (
        <div className="flex items-center gap-2.5">
            <LogoMark className={big ? 'h-10 w-10' : 'h-8 w-8'} />
            <div className="leading-tight">
                <p className={`${big ? 'text-lg' : 'text-[15px]'} font-bold tracking-[0.14em] ${dark ? 'text-white' : 'text-slate-900'}`}>{name}</p>
                {sub && <p className="max-w-[10rem] truncate text-[11px] text-slate-400">{sub}</p>}
            </div>
        </div>
    );
}
