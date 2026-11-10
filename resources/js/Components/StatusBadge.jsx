const TONES = {
    grey: 'bg-slate-100 text-slate-700 ring-slate-300',
    blue: 'bg-blue-50 text-blue-700 ring-blue-200',
    purple: 'bg-purple-50 text-purple-700 ring-purple-200',
    amber: 'bg-amber-50 text-amber-800 ring-amber-300',
    green: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    red: 'bg-red-50 text-red-700 ring-red-200',
};

// One colour set used on every screen (Usability Plan §8).
const MAP = {
    draft: ['grey', 'Draft'], prepared: ['blue', 'Prepared'], printed: ['purple', 'Printed'],
    released: ['amber', 'Released'], for_clearing: ['amber', 'For Clearing'], cleared: ['green', 'Cleared'],
    bounced: ['red', 'Bounced'], cancelled: ['red', 'Cancelled'], voided: ['red', 'Voided'], stale: ['grey', 'Stale'],
    recorded: ['blue', 'Recorded'], verified: ['purple', 'Verified'], processed: ['green', 'Processed'], archived: ['grey', 'Archived'],
    initiated: ['amber', 'Awaiting confirmation'], confirmed: ['green', 'Confirmed'], failed: ['red', 'Problem'], partial: ['amber', 'Partly failed'],
};

const HELP = {
    draft: 'Being encoded. Can still be edited freely.',
    prepared: 'Checked and locked (number, payee, amount, date). Ready to print.',
    printed: 'Print was started. Awaiting release.',
    released: 'Handed to the payee (recorded manually).',
    for_clearing: 'Waiting for clearing. Cleared/bounced is entered by hand, not verified with the bank.',
    cleared: 'Marked cleared by a user. Not verified with the bank.',
    bounced: 'Marked bounced by a user.',
    stale: 'Past the stale period.',
    cancelled: 'Cancelled with a reason. Record stays.',
    voided: 'Voided with a reason. Record stays.',
};

export default function StatusBadge({ status, className = '' }) {
    const [tone, label] = MAP[status] ?? ['grey', status];
    return (
        <span className={`badge ${TONES[tone]} ${className}`} title={HELP[status]}>
            <span className="h-1.5 w-1.5 rounded-full bg-current opacity-70" />
            {label}
        </span>
    );
}

export const statusLabel = (s) => MAP[s]?.[1] ?? s;
