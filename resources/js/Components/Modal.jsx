import { Dialog, DialogPanel, Transition, TransitionChild } from '@headlessui/react';
import Icon from './Icon';

export default function Modal({ show = false, onClose = () => {}, title, children, maxWidth = 'lg', footer, closeable = true }) {
    const w = { sm: 'max-w-sm', md: 'max-w-md', lg: 'max-w-lg', xl: 'max-w-xl', '2xl': 'max-w-2xl', '4xl': 'max-w-4xl', '6xl': 'max-w-6xl' }[maxWidth];
    return (
        <Transition show={show} leave="duration-150">
            <Dialog as="div" className="relative z-50" onClose={() => closeable && onClose()}>
                <TransitionChild enter="ease-out duration-150" enterFrom="opacity-0" enterTo="opacity-100" leave="ease-in duration-100" leaveFrom="opacity-100" leaveTo="opacity-0">
                    <div className="fixed inset-0 bg-slate-900/50 backdrop-blur-[1px]" />
                </TransitionChild>
                <div className="fixed inset-0 overflow-y-auto">
                    <div className="flex min-h-full items-center justify-center p-4">
                        <TransitionChild enter="ease-out duration-150" enterFrom="opacity-0 translate-y-2 scale-95" enterTo="opacity-100 translate-y-0 scale-100"
                            leave="ease-in duration-100" leaveFrom="opacity-100 scale-100" leaveTo="opacity-0 scale-95">
                            <DialogPanel className={`w-full ${w} rounded-2xl bg-white shadow-pop`}>
                                {title && (
                                    <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                                        <h3 className="text-base font-semibold">{title}</h3>
                                        {closeable && <button onClick={onClose} className="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close"><Icon name="x" className="h-5 w-5" /></button>}
                                    </div>
                                )}
                                <div className="px-5 py-4">{children}</div>
                                {footer && <div className="flex justify-end gap-2 rounded-b-2xl border-t border-slate-100 bg-slate-50 px-5 py-3">{footer}</div>}
                            </DialogPanel>
                        </TransitionChild>
                    </div>
                </div>
            </Dialog>
        </Transition>
    );
}
