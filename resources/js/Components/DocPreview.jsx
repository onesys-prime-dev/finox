import Icon from './Icon';
import { SidePanel } from './ui';

/** Inline preview in a side panel (no page reload). Files are streamed through a policy-checked route. */
export default function DocPreview({ doc, onClose }) {
    if (!doc) return null;
    const src = route('documents.show', doc.id);
    const isImg = doc.mime?.startsWith('image/');
    return (
        <SidePanel show onClose={onClose} title={doc.name} width="max-w-3xl">
            <div className="flex h-full flex-col">
                <div className="min-h-0 flex-1 bg-slate-100">
                    {isImg ? <div className="flex h-full items-start justify-center overflow-auto p-4"><img src={src} alt={doc.name} className="max-w-full rounded shadow" /></div>
                        : <iframe src={src} title={doc.name} className="h-full w-full" />}
                </div>
                <div className="flex justify-end gap-2 border-t border-slate-100 p-3">
                    <a className="btn-secondary" href={route('documents.download', doc.id)}><Icon name="download" className="h-4 w-4" /> Download</a>
                </div>
            </div>
        </SidePanel>
    );
}
