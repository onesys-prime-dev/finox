import { usePage } from '@inertiajs/react';
import Logo from '@/Components/Logo';

export default function GuestLayout({ children }) {
    const { app } = usePage().props;
    return (
        <div className="grid min-h-screen lg:grid-cols-2">
            <div className="relative hidden flex-col justify-between overflow-hidden bg-ink-900 p-12 text-white lg:flex">
                <div className="absolute -right-24 -top-24 h-80 w-80 rounded-full bg-brand-600/30 blur-3xl" />
                <div className="absolute -bottom-32 -left-16 h-96 w-96 rounded-full bg-brand-500/20 blur-3xl" />
                <div className="relative">
                    <Logo name={app?.name ?? 'FINOX'} size="lg" />
                </div>
                <div className="relative max-w-md">
                    <h2 className="text-3xl font-semibold leading-tight text-white">Cheques, receipts and records — in one calm place.</h2>
                    <ul className="mt-6 space-y-3 text-sm text-slate-300">
                        <li>• Prepare and print cheques with live preview</li>
                        <li>• Every change recorded in the activity log</li>
                        <li>• Release and clearing tracked manually, clearly labelled</li>
                    </ul>
                </div>
                <p className="relative text-xs text-slate-400">Internal system · authorised users only</p>
            </div>
            <div className="flex items-center justify-center p-6 sm:p-12">
                <div className="w-full max-w-sm">{children}</div>
            </div>
        </div>
    );
}
