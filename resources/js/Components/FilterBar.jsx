import { router } from '@inertiajs/react';
import { useState } from 'react';
import Icon from './Icon';

/** Saved-filter chips + "save current" control. Used by every list. */
export function SavedFilters({ scope, saved = [], filters, routeName }) {
    const [naming, setNaming] = useState(false);
    const [name, setName] = useState('');
    const active = Object.fromEntries(Object.entries(filters).filter(([k, v]) => v !== '' && v != null && !['sort', 'dir', 'per_page', 'page'].includes(k)));
    const has = Object.keys(active).length > 0;

    const save = () => {
        if (!name.trim()) return;
        router.post(route('filters.store'), { scope, name: name.trim(), filters: active }, { preserveScroll: true, onSuccess: () => { setNaming(false); setName(''); } });
    };

    if (!saved.length && !has) return null;
    return (
        <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 px-4 py-2.5">
            <Icon name="star" className="h-4 w-4 text-slate-400" />
            {saved.map((s) => (
                <span key={s.id} className="group inline-flex items-center overflow-hidden rounded-full bg-slate-100 text-xs font-medium text-slate-700">
                    <button className="px-2.5 py-1 hover:bg-brand-100 hover:text-brand-800" onClick={() => router.get(route(routeName), s.filters, { preserveState: true })}>{s.name}</button>
                    <button className="px-1.5 py-1 text-slate-400 hover:bg-red-100 hover:text-red-600" onClick={() => router.delete(route('filters.destroy', s.id), { preserveScroll: true })} aria-label={`Delete ${s.name}`}><Icon name="x" className="h-3 w-3" /></button>
                </span>
            ))}
            {has && !naming && <button className="text-xs font-medium text-brand-700 hover:underline" onClick={() => setNaming(true)}>+ Save this filter</button>}
            {naming && (
                <span className="inline-flex items-center gap-1">
                    <input autoFocus className="input !w-48 !py-1 text-xs" placeholder="Name, e.g. Outstanding this month" value={name} onChange={(e) => setName(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') save(); if (e.key === 'Escape') setNaming(false); }} />
                    <button className="btn-primary btn-sm" onClick={save}>Save</button>
                    <button className="btn-ghost btn-sm" onClick={() => setNaming(false)}>Cancel</button>
                </span>
            )}
        </div>
    );
}

export function ColumnChooser({ columns, hidden, onChange }) {
    const [open, setOpen] = useState(false);
    return (
        <div className="relative">
            <button className="btn-secondary btn-sm" onClick={() => setOpen(!open)}><Icon name="columns" className="h-4 w-4" /> Columns</button>
            {open && (
                <>
                    <div className="fixed inset-0 z-20" onClick={() => setOpen(false)} />
                    <div className="absolute right-0 z-30 mt-1 w-48 rounded-lg border border-slate-200 bg-white p-2 shadow-pop">
                        {columns.map((c) => (
                            <label key={c.key} className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-slate-50">
                                <input type="checkbox" className="rounded border-slate-300 text-brand-600" checked={!hidden.includes(c.key)}
                                    onChange={(e) => onChange(e.target.checked ? hidden.filter((h) => h !== c.key) : [...hidden, c.key])} /> {c.label}
                            </label>
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}

export function SortHeader({ label, field, filters, apply, className = '' }) {
    const on = filters.sort === field;
    const next = on && filters.dir !== 'asc' ? 'asc' : 'desc';
    return (
        <th className={className}>
            <button className="inline-flex items-center gap-1 uppercase tracking-wide hover:text-slate-800" onClick={() => apply({ ...filters, sort: field, dir: next }, true)}>
                {label}
                <span className={on ? 'text-brand-600' : 'text-slate-300'}>{on && filters.dir === 'asc' ? '↑' : '↓'}</span>
            </button>
        </th>
    );
}
