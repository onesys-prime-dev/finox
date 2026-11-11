import { Link, router } from '@inertiajs/react';
import { Children, cloneElement, isValidElement, useEffect, useId, useRef, useState } from 'react';
import Icon from './Icon';

export function PageHeader({ title, subtitle, actions, back }) {
    return (
        <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
                {back && <Link href={back.href} className="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-brand-700"><Icon name="chevronLeft" className="h-3.5 w-3.5" />{back.label}</Link>}
                <h1 className="truncate text-xl font-semibold tracking-tight sm:text-2xl">{title}</h1>
                {subtitle && <p className="mt-1 text-sm text-slate-500">{subtitle}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}

const NATIVE_CONTROLS = ['input', 'select', 'textarea'];

/** Labels are tied to their control (htmlFor/id) so screen readers announce them and clicking a label focuses the field. */
export function Field({ label, error, hint, required, children, className = '' }) {
    const auto = useId();
    let id = null;
    let body = children;
    const kids = Children.toArray(children);
    const first = kids[0];
    if (isValidElement(first) && NATIVE_CONTROLS.includes(first.type) && first.props.type !== 'hidden') {
        id = first.props.id ?? auto;
        kids[0] = first.props.id ? first : cloneElement(first, { id });
        body = kids;
    }
    return (
        <div className={className}>
            {label && <label className="label" htmlFor={id ?? undefined}>{label}{required && <span className="text-red-500"> *</span>}</label>}
            {body}
            {error ? <p className="error">{error}</p> : hint ? <p className="hint">{hint}</p> : null}
        </div>
    );
}

export function EmptyState({ icon = 'file', title, text, action }) {
    return (
        <div className="flex flex-col items-center justify-center px-6 py-14 text-center">
            <div className="mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-400"><Icon name={icon} className="h-6 w-6" /></div>
            <p className="font-medium text-slate-800">{title}</p>
            {text && <p className="mt-1 max-w-sm text-sm text-slate-500">{text}</p>}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}

export function Pagination({ links, meta }) {
    if (!links || links.length <= 3) return meta ? <div className="px-4 py-3 text-xs text-slate-500">{meta.total} result(s)</div> : null;
    return (
        <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 px-4 py-3">
            <p className="text-xs text-slate-500">{meta ? `Showing ${meta.from ?? 0}–${meta.to ?? 0} of ${meta.total}` : ''}</p>
            <div className="flex flex-wrap gap-1">
                {links.map((l, i) => (
                    <button key={i} disabled={!l.url} onClick={() => l.url && router.get(l.url, {}, { preserveScroll: true, preserveState: true })}
                        className={`min-w-[2rem] rounded-md px-2.5 py-1 text-xs font-medium ${l.active ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100 disabled:opacity-40 disabled:hover:bg-transparent'}`}
                        dangerouslySetInnerHTML={{ __html: l.label.replace('&laquo; Previous', '‹').replace('Next &raquo;', '›') }} />
                ))}
            </div>
        </div>
    );
}

export function Stat({ label, value, sub, tone = 'default', href }) {
    const tones = { default: 'text-slate-900', good: 'text-emerald-700', warn: 'text-amber-700', bad: 'text-red-700' };
    const inner = (
        <div className="card card-pad h-full transition hover:border-brand-300">
            <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
            <p className={`mt-1 text-2xl font-semibold tabular-nums ${tones[tone]}`}>{value}</p>
            {sub && <p className="mt-0.5 text-xs text-slate-500">{sub}</p>}
        </div>
    );
    return href ? <Link href={href}>{inner}</Link> : inner;
}

/** Debounced filter bar state synced to the URL via Inertia. */
export function useFilters(routeName, initial, { only } = {}) {
    const [f, setF] = useState(initial);
    const first = useRef(true);
    const timer = useRef();
    const apply = (next, immediate = false) => {
        setF(next);
        clearTimeout(timer.current);
        const go = () => router.get(route(routeName), Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '' && v != null && v !== false)),
            { preserveState: true, preserveScroll: true, replace: true, only });
        immediate ? go() : (timer.current = setTimeout(go, 300));
    };
    useEffect(() => { first.current = false; }, []);
    // Re-sync when the server hands back different filters (saved filter applied, back/forward, etc.).
    const key = JSON.stringify(initial);
    useEffect(() => { if (!first.current) setF(initial); }, [key]);
    return [f, apply];
}

export function Spinner({ className = 'h-4 w-4' }) {
    return <svg className={`${className} animate-spin`} viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity=".25" strokeWidth="3" /><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="3" strokeLinecap="round" /></svg>;
}

export function Banner({ tone = 'info', children, icon }) {
    const t = {
        info: 'border-blue-200 bg-blue-50 text-blue-900', warn: 'border-amber-200 bg-amber-50 text-amber-900',
        bad: 'border-red-200 bg-red-50 text-red-900', good: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    }[tone];
    return <div className={`flex gap-2.5 rounded-lg border p-3 text-sm ${t}`}><Icon name={icon ?? (tone === 'bad' || tone === 'warn' ? 'alert' : 'info')} className="mt-0.5 h-4 w-4 shrink-0" /><div className="min-w-0">{children}</div></div>;
}

export function Tabs({ tabs, value, onChange }) {
    return (
        <div className="flex gap-1 rounded-lg bg-slate-100 p-1">
            {tabs.map((t) => (
                <button key={t.value} onClick={() => onChange(t.value)}
                    className={`rounded-md px-3 py-1.5 text-sm font-medium transition ${value === t.value ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-800'}`}>{t.label}</button>
            ))}
        </div>
    );
}

export function Toggle({ checked, onChange, label, disabled }) {
    return (
        <label className={`inline-flex items-center gap-2 text-sm ${disabled ? 'opacity-50' : 'cursor-pointer'}`}>
            <button type="button" role="switch" aria-checked={checked} disabled={disabled} onClick={() => onChange(!checked)}
                className={`relative h-5 w-9 rounded-full transition ${checked ? 'bg-brand-600' : 'bg-slate-300'}`}>
                <span className={`absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition ${checked ? 'left-[18px]' : 'left-0.5'}`} />
            </button>
            {label}
        </label>
    );
}

/** Side panel (slides from right) used for quick detail / preview without page reload. */
export function SidePanel({ show, onClose, title, children, width = 'max-w-xl' }) {
    useEffect(() => {
        if (!show) return;
        const h = (e) => e.key === 'Escape' && onClose();
        window.addEventListener('keydown', h);
        return () => window.removeEventListener('keydown', h);
    }, [show]);
    if (!show) return null;
    return (
        <div className="fixed inset-0 z-40 no-print">
            <div className="absolute inset-0 bg-slate-900/30" onClick={onClose} />
            <aside className={`absolute right-0 top-0 flex h-full w-full ${width} flex-col bg-white shadow-pop`}>
                <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3.5">
                    <h3 className="font-semibold">{title}</h3>
                    <button onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100" aria-label="Close panel"><Icon name="x" /></button>
                </div>
                <div className="scroll-thin min-h-0 flex-1 overflow-y-auto">{children}</div>
            </aside>
        </div>
    );
}
