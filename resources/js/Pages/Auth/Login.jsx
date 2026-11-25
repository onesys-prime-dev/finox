import GuestLayout from '@/Layouts/GuestLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Icon from '@/Components/Icon';
import { Banner, Spinner } from '@/Components/ui';

export default function Login() {
    const { flash } = usePage().props;
    const { data, setData, post, processing, errors, reset } = useForm({ email: '', password: '', remember: false });
    const [show, setShow] = useState(false);
    const submit = (e) => { e.preventDefault(); post(route('login'), { onFinish: () => reset('password') }); };

    return (
        <GuestLayout>
            <Head title="Sign in" />
            <h1 className="text-2xl font-semibold">Sign in</h1>
            <p className="mt-1 text-sm text-slate-500">Use the account an administrator created for you.</p>

            <div className="mt-5 space-y-3">
                {flash.error && <Banner tone="warn">{flash.error}</Banner>}
                {flash.warning && <Banner tone="warn">{flash.warning}</Banner>}
            </div>

            <form onSubmit={submit} className="mt-6 space-y-4">
                <div>
                    <label className="label" htmlFor="email">Email</label>
                    <input id="email" type="email" autoComplete="username" autoFocus className={`input ${errors.email ? 'input-error' : ''}`} value={data.email} onChange={(e) => setData('email', e.target.value)} />
                    {errors.email && <p className="error">{errors.email}</p>}
                </div>
                <div>
                    <label className="label" htmlFor="password">Password</label>
                    <div className="relative">
                        <input id="password" type={show ? 'text' : 'password'} autoComplete="current-password" className="input pr-10" value={data.password} onChange={(e) => setData('password', e.target.value)} />
                        <button type="button" onClick={() => setShow(!show)} className="absolute right-2.5 top-2 text-slate-400 hover:text-slate-600" aria-label={show ? 'Hide password' : 'Show password'}><Icon name="eye" className="h-5 w-5" /></button>
                    </div>
                </div>
                <label className="flex items-center gap-2 text-sm text-slate-600">
                    <input type="checkbox" className="rounded border-slate-300 text-brand-600 focus:ring-brand-500" checked={data.remember} onChange={(e) => setData('remember', e.target.checked)} /> Keep me signed in on this computer
                </label>
                <button className="btn-primary btn-lg w-full" disabled={processing}>{processing ? <Spinner /> : null} Sign in</button>
            </form>
            <p className="mt-6 text-center text-xs text-slate-500">Forgot your password? Ask an administrator to reset it.</p>
        </GuestLayout>
    );
}
