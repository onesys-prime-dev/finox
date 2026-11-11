// Browser twin of App\Support\AmountInWords. The server is authoritative and recomputes on save.
const ONES = ['', 'ONE', 'TWO', 'THREE', 'FOUR', 'FIVE', 'SIX', 'SEVEN', 'EIGHT', 'NINE', 'TEN', 'ELEVEN', 'TWELVE',
    'THIRTEEN', 'FOURTEEN', 'FIFTEEN', 'SIXTEEN', 'SEVENTEEN', 'EIGHTEEN', 'NINETEEN'];
const TENS = ['', '', 'TWENTY', 'THIRTY', 'FORTY', 'FIFTY', 'SIXTY', 'SEVENTY', 'EIGHTY', 'NINETY'];
const SCALES = ['', 'THOUSAND', 'MILLION', 'BILLION', 'TRILLION'];

function hundreds(n) {
    const out = [];
    if (n >= 100) { out.push(`${ONES[Math.floor(n / 100)]} HUNDRED`); n %= 100; }
    if (n >= 20) { out.push(TENS[Math.floor(n / 10)]); n %= 10; }
    if (n > 0) out.push(ONES[n]);
    return out.join(' ');
}

export function parseAmount(input) {
    let v = String(input ?? '').replace(/[₱\s]|PHP/gi, '');
    // Commas are only valid as thousands separators; "1,5" is ambiguous and rejected (same rule as the server).
    if (v.includes(',')) {
        if (!/^\d{1,3}(,\d{3})+(\.\d+)?$/.test(v)) return null;
        v = v.replace(/,/g, '');
    }
    if (!/^\d{1,13}(\.\d{1,2})?$/.test(v)) return null;
    const [w, f = ''] = v.split('.');
    return { whole: w.replace(/^0+(?=\d)/, ''), cents: f.padEnd(2, '0') };
}

export function amountInWords(input) {
    const a = parseAmount(input);
    if (!a) return '';
    let s = a.whole, groups = [];
    while (s.length) { groups.push(parseInt(s.slice(-3), 10)); s = s.slice(0, -3); }
    const parts = [];
    for (let i = groups.length - 1; i >= 0; i--) {
        if (groups[i] === 0) continue;
        parts.push(`${hundreds(groups[i])} ${SCALES[i]}`.trim());
    }
    const words = parts.length ? parts.join(' ') : 'ZERO';
    return `${words} AND ${a.cents}/100 PESOS ONLY`;
}
