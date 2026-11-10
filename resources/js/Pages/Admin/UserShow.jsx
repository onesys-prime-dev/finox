import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head } from '@inertiajs/react';
import { PageHeader } from '@/Components/ui';
import { useFmt } from '@/lib/format';

const EV = { success: ['Signed in', 'text-emerald-700'], failed: ['Failed attempt', 'text-red-700'], locked: ['Blocked (locked)', 'text-amber-700'], logout: ['Signed out', 'text-slate-600'] };

export default function UserShow({ user, logins, activity }) {
    const fmt = useFmt();
    return (
        <AuthenticatedLayout>
            <Head title={user.name} />
            <PageHeader title={user.name} subtitle={`${user.email} · ${user.role} · ${user.is_active ? 'Active' : 'Inactive'}`} back={{ href: route('admin.users.index'), label: 'All users' }} />
            <div className="grid gap-6 lg:grid-cols-2">
                <div className="card"><div className="border-b border-slate-100 px-5 py-3"><p className="card-title">Login history</p></div>
                    <ul className="divide-y divide-slate-100">{logins.map((l, i) => <li key={i} className="flex items-center justify-between px-5 py-2.5 text-sm"><span className={`font-medium ${EV[l.event]?.[1]}`}>{EV[l.event]?.[0] ?? l.event}</span><span className="text-xs text-slate-500">{l.ip} · {fmt.date(l.created_at, true)}</span></li>)}
                        {logins.length === 0 && <li className="px-5 py-6 text-center text-sm text-slate-400">No logins recorded.</li>}</ul></div>
                <div className="card"><div className="border-b border-slate-100 px-5 py-3"><p className="card-title">Recent activity</p></div>
                    <ul className="divide-y divide-slate-100">{activity.map((a) => <li key={a.id} className="px-5 py-2.5 text-sm"><p>{a.description ?? a.action}</p><p className="text-xs text-slate-500">{a.module} · {fmt.date(a.created_at, true)}</p></li>)}
                        {activity.length === 0 && <li className="px-5 py-6 text-center text-sm text-slate-400">No activity yet.</li>}</ul></div>
            </div>
        </AuthenticatedLayout>
    );
}
