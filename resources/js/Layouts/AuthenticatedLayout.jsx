import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import Icon from '@/Components/Icon';
import Modal from '@/Components/Modal';
import StatusBadge from '@/Components/StatusBadge';

const NAV = [
    { label: 'Home', icon: 'home', route: 'dashboard', match: 'dashboard' },
    { label: 'Cheques', icon: 'cheque', route: 'cheques.index', match: 'cheques*', any: ['cheques.view', 'cheques.view_all'] },
    { label: 'Receipts', icon: 'receipt', route: 'receipts.index', match: 'receipts*', any: ['receipts.view', 'receipts.view_all'] },
    { label: 'Documents', icon: 'file', route: 'documents.index', match: 'documents*', any: ['documents.view', 'documents.upload'] },
    { label: 'Print center', icon: 'printer', route: 'printing.index', match: 'printing*', any: ['print.print'] },
    { label: 'Transactions', icon: 'list', route: 'register.index', match: 'register*', any: ['register.view'] },
    { label: 'Reports', icon: 'chart', route: 'reports.index', match: 'reports*', any: ['reports.view', 'reports.printing'] },
    {
        section: 'Administration', items: [
            { label: 'Payees', icon: 'users', route: 'payees.index', match: 'payees*', any: ['payees.manage'] },
            { label: 'Banks & accounts', icon: 'bank', route: 'admin.banks.index', match: 'admin.banks*', any: ['banks.manage'] },
            { label: 'Print templates', icon: 'layout', route: 'admin.templates.index', match: 'admin.templates*', any: ['templates.manage'] },
            { label: 'Users', icon: 'users', route: 'admin.users.index', match: 'admin.users*', any: ['users.manage'] },
            { label: 'Problem reports', icon: 'help', route: 'admin.problems.index', match: 'admin.problems*', any: ['users.manage'] },
            { label: 'Roles & permissions', icon: 'shield', route: 'admin.roles.index', match: 'admin.roles*', any: ['roles.manage'] },
            { label: 'Activity log', icon: 'activity', route: 'activity.index', match: 'activity*', any: ['activity.view'] },
            { label: 'Settings', icon: 'settings', route: 'admin.settings.edit', match: 'admin.settings*', any: ['settings.manage'] },
        ],
    },
];

export default function AuthenticatedLayout({ children }) {
    const { auth, app, flash, pendingPrints } = usePage().props;
    const [open, setOpen] = useState(false);
    const [problem, setProblem] = useState(false);
    const can = (list) => !list || list.some((p) => auth.user.permissions.includes(p));

    // Keyboard shortcuts: "/" search, "n" new record, "?" none. Ignored while typing.
    useEffect(() => {
        const h = (e) => {
            const tag = (e.target.tagName || '').toLowerCase();
            if (['input', 'textarea', 'select'].includes(tag) || e.target.isContentEditable || e.ctrlKey || e.metaKey || e.altKey) return;
            if (e.key === '/') { e.preventDefault(); document.getElementById('global-search')?.focus(); }
            if (e.key === 'n') {
                if (route().current('receipts*') && can(['receipts.create'])) router.visit(route('receipts.create'));
                else if (can(['cheques.create'])) router.visit(route('cheques.create'));
            }
        };
        window.addEventListener('keydown', h);
        return () => window.removeEventListener('keydown', h);
    }, []);

    useEffect(() => setOpen(false), [typeof window !== 'undefined' ? window.location.pathname : '']);

    const Item = ({ it }) => {
        const active = route().current(it.match);
        return (
            <Link href={route(it.route)} className={`group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition ${active ? 'bg-white/10 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white'}`}>
                <Icon name={it.icon} className={`h-[18px] w-[18px] ${active ? 'text-brand-300' : 'text-slate-400 group-hover:text-slate-200'}`} />
                {it.label}
            </Link>
        );
    };

    const sidebar = (
        <div className="flex h-full flex-col bg-ink-900">
            <div className="flex h-16 items-center gap-2.5 px-5">
                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-sm font-bold text-white">F</div>
                <div className="leading-tight">
                    <p className="text-sm font-semibold tracking-wide text-white">{app.name}</p>
                    <p className="max-w-[10rem] truncate text-[11px] text-slate-400">{app.org}</p>
                </div>
            </div>
            <nav className="scroll-thin flex-1 space-y-0.5 overflow-y-auto px-3 py-2">
                {NAV.map((n, i) => {
                    if (n.section) {
                        const items = n.items.filter((x) => can(x.any));
                        return items.length ? (
                            <div key={i} className="pt-5">
                                <p className="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500">{n.section}</p>
                                {items.map((x) => <Item key={x.route} it={x} />)}
                            </div>
                        ) : null;
                    }
                    return can(n.any) ? <Item key={n.route} it={n} /> : null; // hidden, not greyed out
                })}
            </nav>
            <div className="border-t border-white/10 p-3">
                <button onClick={() => setProblem(true)} className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-xs font-medium text-slate-400 hover:bg-white/5 hover:text-white">
                    <Icon name="help" className="h-4 w-4" /> Report a problem
                </button>
            </div>
        </div>
    );

    return (
        <div className="min-h-screen">
            <div className="no-print fixed inset-y-0 left-0 z-30 hidden w-64 lg:block">{sidebar}</div>
            {open && (
                <div className="no-print fixed inset-0 z-40 lg:hidden">
                    <div className="absolute inset-0 bg-slate-900/50" onClick={() => setOpen(false)} />
                    <div className="absolute inset-y-0 left-0 w-64 shadow-pop">{sidebar}</div>
                </div>
            )}

            <div className="lg:pl-64">
                <header className="no-print sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6">
                    <button className="btn-ghost -ml-2 p-2 lg:hidden" onClick={() => setOpen(true)} aria-label="Open menu"><Icon name="menu" /></button>
                    <GlobalSearch />
                    <div className="ml-auto flex items-center gap-2">
                        {pendingPrints > 0 && (
                            <Link href={route('printing.index')} className="hidden items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-800 ring-1 ring-amber-300 sm:flex">
                                <Icon name="printer" className="h-3.5 w-3.5" /> {pendingPrints} print{pendingPrints > 1 ? 's' : ''} to confirm
                            </Link>
                        )}
                        <UserMenu user={auth.user} />
                    </div>
                </header>

                <Flash flash={flash} />
                <main className="mx-auto w-full max-w-[1400px] px-4 py-6 sm:px-6">{children}</main>
            </div>
            <ProblemModal show={problem} onClose={() => setProblem(false)} />
        </div>
    );
}

function UserMenu({ user }) {
    const [open, setOpen] = useState(false);
    const ref = useRef();
    useEffect(() => {
        const h = (e) => ref.current && !ref.current.contains(e.target) && setOpen(false);
        document.addEventListener('mousedown', h);
        return () => document.removeEventListener('mousedown', h);
    }, []);
    const initials = user.name.split(' ').map((s) => s[0]).slice(0, 2).join('').toUpperCase();
    return (
        <div className="relative" ref={ref}>
            <button onClick={() => setOpen(!open)} className="flex items-center gap-2 rounded-full py-1 pl-1 pr-2 hover:bg-slate-100">
                <span className="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-800">{initials}</span>
                <span className="hidden text-left leading-tight sm:block"><span className="block text-sm font-medium">{user.name}</span><span className="block text-[11px] text-slate-500">{user.role}</span></span>
                <Icon name="chevronDown" className="h-4 w-4 text-slate-400" />
            </button>
            {open && (
                <div className="absolute right-0 mt-2 w-52 rounded-xl border border-slate-200 bg-white p-1.5 shadow-pop">
                    <Link href={route('profile.edit')} className="flex items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-slate-50"><Icon name="key" className="h-4 w-4 text-slate-400" /> Profile & password</Link>
                    <Link href={route('logout')} method="post" as="button" className="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50"><Icon name="logout" className="h-4 w-4" /> Sign out</Link>
                </div>
            )}
        </div>
    );
}

function GlobalSearch() {
    const [q, setQ] = useState('');
    const [res, setRes] = useState([]);
    const [open, setOpen] = useState(false);
    const ref = useRef();
    const t = useRef();
    useEffect(() => {
        clearTimeout(t.current);
        if (q.trim().length < 2) { setRes([]); return; }
        t.current = setTimeout(() => axios.get(route('search'), { params: { q } }).then((r) => setRes(r.data)), 200);
        return () => clearTimeout(t.current);
    }, [q]);
    useEffect(() => {
        const h = (e) => ref.current && !ref.current.contains(e.target) && setOpen(false);
        document.addEventListener('mousedown', h);
        return () => document.removeEventListener('mousedown', h);
    }, []);
    return (
        <div className="relative w-full max-w-md" ref={ref}>
            <Icon name="search" className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
            <input id="global-search" value={q} onChange={(e) => { setQ(e.target.value); setOpen(true); }} onFocus={() => setOpen(true)}
                onKeyDown={(e) => { if (e.key === 'Escape') { setOpen(false); e.target.blur(); } if (e.key === 'Enter' && res[0]) router.visit(res[0].url); }}
                placeholder="Search cheque no., receipt no., payee…" className="input rounded-full border-slate-200 bg-slate-50 pl-9 pr-10" />
            <span className="kbd pointer-events-none absolute right-3 top-2.5 hidden sm:block">/</span>
            {open && q.trim().length >= 2 && (
                <div className="absolute z-40 mt-2 max-h-96 w-full overflow-auto rounded-xl border border-slate-200 bg-white p-1.5 shadow-pop">
                    {res.length === 0 && <p className="px-3 py-3 text-sm text-slate-500">No results for “{q}”.</p>}
                    {res.map((r, i) => (
                        <Link key={i} href={r.url} onClick={() => setOpen(false)} className="flex items-center justify-between gap-3 rounded-lg px-3 py-2 hover:bg-slate-50">
                            <div className="min-w-0">
                                <p className="truncate text-sm font-medium">{r.title} <span className="ml-1 text-[11px] font-normal text-slate-400">{r.type}</span></p>
                                <p className="truncate text-xs text-slate-500">{r.sub}</p>
                            </div>
                            <StatusBadge status={r.status} />
                        </Link>
                    ))}
                </div>
            )}
        </div>
    );
}

function Flash({ flash }) {
    const [items, setItems] = useState([]);
    useEffect(() => {
        const add = ['success', 'error', 'warning'].filter((k) => flash[k]).map((k) => ({ k, text: flash[k], id: Math.random() }));
        if (add.length) {
            setItems((x) => [...x, ...add]);
            const ids = add.map((a) => a.id);
            // errors stay a little longer, and temp-password style messages need to be readable
            setTimeout(() => setItems((x) => x.filter((i) => !ids.includes(i.id))), add.some((a) => a.k !== 'success') ? 9000 : 4500);
        }
    }, [flash.success, flash.error, flash.warning]);
    const tone = { success: 'border-emerald-200 bg-emerald-50 text-emerald-900', error: 'border-red-200 bg-red-50 text-red-900', warning: 'border-amber-200 bg-amber-50 text-amber-900' };
    const icon = { success: 'check', error: 'alert', warning: 'alert' };
    return (
        <div className="no-print pointer-events-none fixed right-4 top-20 z-50 flex w-full max-w-sm flex-col gap-2" role="status" aria-live="polite">
            {items.map((i) => (
                <div key={i.id} className={`pointer-events-auto flex items-start gap-2.5 rounded-xl border p-3 text-sm shadow-pop ${tone[i.k]}`}>
                    <Icon name={icon[i.k]} className="mt-0.5 h-4 w-4 shrink-0" />
                    <p className="min-w-0 flex-1 break-words">{i.text}</p>
                    <button onClick={() => setItems((x) => x.filter((y) => y.id !== i.id))} aria-label="Dismiss"><Icon name="x" className="h-4 w-4 opacity-60" /></button>
                </div>
            ))}
        </div>
    );
}

function ProblemModal({ show, onClose }) {
    const f = useForm({ message: '', url: '' });
    useEffect(() => { if (show) f.setData('url', window.location.pathname + window.location.search); }, [show]);
    return (
        <Modal show={show} onClose={onClose} title="Report a problem" maxWidth="md"
            footer={<><button className="btn-secondary" onClick={onClose}>Cancel</button>
                <button className="btn-primary" disabled={f.processing || f.data.message.trim().length < 5}
                    onClick={() => f.post(route('problems.store'), { preserveScroll: true, onSuccess: () => { f.reset('message'); onClose(); } })}>Send report</button></>}>
            <p className="mb-3 text-sm text-slate-600">Tell us what went wrong. The page you are on is attached automatically.</p>
            <textarea className="input" rows={4} placeholder="What happened? What did you expect?" value={f.data.message} onChange={(e) => f.setData('message', e.target.value)} />
            {f.errors.message && <p className="error">{f.errors.message}</p>}
            <p className="hint">Page: {f.data.url}</p>
        </Modal>
    );
}
