import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Field, PageHeader } from '@/Components/ui';

export default function Edit() {
    const { auth } = usePage().props;
    const profile = useForm({ name: auth.user.name, email: auth.user.email });
    const pw = useForm({ current_password: '', password: '', password_confirmation: '' });
    return (
        <AuthenticatedLayout>
            <Head title="Profile" />
            <PageHeader title="Profile" subtitle={`Signed in as ${auth.user.role}`} />
            <div className="grid max-w-4xl gap-6 lg:grid-cols-2">
                <form className="card card-pad space-y-4" onSubmit={(e) => { e.preventDefault(); profile.patch(route('profile.update'), { preserveScroll: true }); }}>
                    <p className="card-title">Your details</p>
                    <Field label="Name" error={profile.errors.name}><input className="input" value={profile.data.name} onChange={(e) => profile.setData('name', e.target.value)} /></Field>
                    <Field label="Email" error={profile.errors.email}><input type="email" className="input" value={profile.data.email} onChange={(e) => profile.setData('email', e.target.value)} /></Field>
                    <button className="btn-primary" disabled={profile.processing}>Save changes</button>
                </form>
                <form className="card card-pad space-y-4" onSubmit={(e) => { e.preventDefault(); pw.put(route('password.update'), { preserveScroll: true, onSuccess: () => pw.reset() }); }}>
                    <p className="card-title">Change password</p>
                    <Field label="Current password" error={pw.errors.current_password}><input type="password" autoComplete="current-password" className="input" value={pw.data.current_password} onChange={(e) => pw.setData('current_password', e.target.value)} /></Field>
                    <Field label="New password" error={pw.errors.password} hint="At least 10 characters with upper- and lower-case letters and a number."><input type="password" autoComplete="new-password" className="input" value={pw.data.password} onChange={(e) => pw.setData('password', e.target.value)} /></Field>
                    <Field label="Confirm new password"><input type="password" autoComplete="new-password" className="input" value={pw.data.password_confirmation} onChange={(e) => pw.setData('password_confirmation', e.target.value)} /></Field>
                    <button className="btn-primary" disabled={pw.processing}>Update password</button>
                </form>
            </div>
        </AuthenticatedLayout>
    );
}
