import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import Icon from './Icon';
import { usePage } from '@inertiajs/react';

/**
 * Search-as-you-type payee picker. A new payee can be added without leaving the form.
 * value: {id, name} | null  — onChange receives the payee object (with default_payee_line) or null.
 */
export default function PayeeSelect({ value, onChange, error, autoFocus, disabled, placeholder = 'Search payee or vendor…' }) {
    const { auth } = usePage().props;
    const canAdd = auth.user.permissions.includes('payees.create') || auth.user.permissions.includes('payees.manage');
    const [q, setQ] = useState('');
    const [open, setOpen] = useState(false);
    const [items, setItems] = useState([]);
    const [hi, setHi] = useState(0);
    const [busy, setBusy] = useState(false);
    const box = useRef();
    const t = useRef();

    useEffect(() => {
        if (!open) return;
        clearTimeout(t.current);
        t.current = setTimeout(() => axios.get(route('payees.search'), { params: { q } }).then((r) => { setItems(r.data); setHi(0); }), 150);
        return () => clearTimeout(t.current);
    }, [q, open]);

    useEffect(() => {
        const h = (e) => box.current && !box.current.contains(e.target) && setOpen(false);
        document.addEventListener('mousedown', h);
        return () => document.removeEventListener('mousedown', h);
    }, []);

    const pick = (p) => { onChange(p); setOpen(false); setQ(''); };
    const exact = items.some((i) => i.name.toLowerCase() === q.trim().toLowerCase());
    const showAdd = canAdd && q.trim().length >= 2 && !exact;

    const add = async () => {
        setBusy(true);
        try { const r = await axios.post(route('payees.quick'), { name: q.trim() }); pick(r.data); } finally { setBusy(false); }
    };

    const onKey = (e) => {
        const total = items.length + (showAdd ? 1 : 0);
        if (e.key === 'ArrowDown') { e.preventDefault(); setHi((h) => Math.min(h + 1, total - 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setHi((h) => Math.max(h - 1, 0)); }
        else if (e.key === 'Enter' && open) {
            e.preventDefault();
            if (hi < items.length) items[hi] && pick(items[hi]); else if (showAdd) add();
        } else if (e.key === 'Escape') setOpen(false);
    };

    return (
        <div className="relative" ref={box}>
            {value && !open ? (
                <div className={`input flex items-center justify-between ${error ? 'input-error' : ''} ${disabled ? 'bg-slate-100' : ''}`}>
                    <span className="truncate">{value.name}</span>
                    {!disabled && <button type="button" className="text-slate-400 hover:text-slate-700" onClick={() => { onChange(null); setOpen(true); }} aria-label="Change payee"><Icon name="x" className="h-4 w-4" /></button>}
                </div>
            ) : (
                <div className="relative">
                    <Icon name="search" className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                    <input className={`input pl-9 ${error ? 'input-error' : ''}`} value={q} placeholder={placeholder} autoFocus={autoFocus} disabled={disabled}
                        onChange={(e) => { setQ(e.target.value); setOpen(true); }} onFocus={() => setOpen(true)} onKeyDown={onKey} role="combobox" aria-expanded={open} />
                </div>
            )}
            {open && (
                <ul className="absolute z-30 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 shadow-pop" role="listbox">
                    {items.map((p, i) => (
                        <li key={p.id} role="option" aria-selected={i === hi} onMouseDown={(e) => { e.preventDefault(); pick(p); }} onMouseEnter={() => setHi(i)}
                            className={`cursor-pointer px-3 py-2 text-sm ${i === hi ? 'bg-brand-50 text-brand-800' : ''}`}>{p.name}</li>
                    ))}
                    {showAdd && (
                        <li onMouseDown={(e) => { e.preventDefault(); add(); }} onMouseEnter={() => setHi(items.length)}
                            className={`flex cursor-pointer items-center gap-2 border-t border-slate-100 px-3 py-2 text-sm font-medium text-brand-700 ${hi === items.length ? 'bg-brand-50' : ''}`}>
                            <Icon name="plus" className="h-4 w-4" /> {busy ? 'Adding…' : `Add "${q.trim()}" as a new payee`}
                        </li>
                    )}
                    {!items.length && !showAdd && <li className="px-3 py-2 text-sm text-slate-500">No matches.</li>}
                </ul>
            )}
        </div>
    );
}
