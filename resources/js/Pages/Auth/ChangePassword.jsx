import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Banner, Field, Spinner } from '@/Components/ui';

export default function ChangePassword() {
    const { data, setData, post, processing, errors, reset } = useForm({ current_password: '', password: '', password_confirmation: '' });
    const submit = (e) => { e.preventDefault(); post(route('password.change.update'), { onFinish: () => reset() }); };
    return (
        <GuestLayout>
            <Head title="Change password" />
            <h1 className="text-2xl font-semibold">Choose a new password</h1>
            <div className="mt-4"><Banner tone="info">For security you must replace your temporary password before continuing.</Banner></div>
            <form onSubmit={submit} className="mt-6 space-y-4">
                <Field label="Current (temporary) password" error={errors.current_password}><input type="password" autoComplete="current-password" className="input" value={data.current_password} onChange={(e) => setData('current_password', e.target.value)} autoFocus /></Field>
                <Field label="New password" error={errors.password} hint="At least 10 characters with upper- and lower-case letters and a number."><input type="password" autoComplete="new-password" className="input" value={data.password} onChange={(e) => setData('password', e.target.value)} /></Field>
                <Field label="Confirm new password"><input type="password" autoComplete="new-password" className="input" value={data.password_confirmation} onChange={(e) => setData('password_confirmation', e.target.value)} /></Field>
                <button className="btn-primary btn-lg w-full" disabled={processing}>{processing && <Spinner />} Save and continue</button>
            </form>
        </GuestLayout>
    );
}
