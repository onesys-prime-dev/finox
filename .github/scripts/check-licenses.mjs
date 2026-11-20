// License compliance gate for Composer (production) and npm dependencies.
//   usage: node check-licenses.mjs <composer-licenses.json> <npm-licenses.json>
// composer: output of `composer licenses --no-dev --format=json`
// npm:      output of `license-checker-rseidelsohn --json --excludePrivatePackages`
// npm devDependencies are included on purpose: React, Inertia, etc. are devDependencies
// here but are bundled into the shipped JavaScript.
import fs from 'node:fs';

// Permissive and weak-copyleft licenses compatible with an internal, closed-source application.
const ALLOWED = new Set([
    'MIT', 'MIT-0', 'ISC', '0BSD', 'BSD-2-Clause', 'BSD-3-Clause', 'Apache-2.0', 'Unlicense', 'CC0-1.0',
    'CC-BY-3.0', 'CC-BY-4.0', 'Python-2.0', 'BlueOak-1.0.0', 'Zlib', 'MPL-2.0',
    'LGPL-2.1', 'LGPL-2.1-only', 'LGPL-2.1-or-later', 'LGPL-3.0', 'LGPL-3.0-only', 'LGPL-3.0-or-later',
]);

// Reviewed per-package exceptions: { 'package-name': 'reason' }.
const EXCEPTIONS = {};

/** SPDX-ish expression check: OR = any alternative allowed, AND = every part allowed. */
function allowed(expr) {
    const clean = String(expr ?? '').replace(/[()*]/g, ' ').trim();
    if (!clean) return false;
    return clean.split(/\s+OR\s+|\s*\|\s*/i).some((alt) => alt.split(/\s+AND\s+/i).every((l) => ALLOWED.has(l.trim())));
}

const [composerFile, npmFile] = process.argv.slice(2);
const violations = [];
let checked = 0;

const composer = JSON.parse(fs.readFileSync(composerFile, 'utf8'));
for (const [name, dep] of Object.entries(composer.dependencies ?? {})) {
    checked++;
    const lic = (dep.license ?? []).join(' OR ');
    if (!allowed(lic) && !EXCEPTIONS[name]) violations.push(`composer ${name}: ${lic || 'NO LICENSE'}`);
}

const npm = JSON.parse(fs.readFileSync(npmFile, 'utf8'));
for (const [id, dep] of Object.entries(npm)) {
    checked++;
    const name = id.replace(/@[^@]+$/, '');
    const lic = Array.isArray(dep.licenses) ? dep.licenses.join(' OR ') : dep.licenses;
    if (!allowed(lic) && !EXCEPTIONS[name]) violations.push(`npm ${id}: ${lic || 'NO LICENSE'}`);
}

if (violations.length) {
    for (const v of violations) console.log(`::error::License not allowed - ${v}`);
    console.log(`\n${violations.length} of ${checked} packages failed. Allowed: ${[...ALLOWED].join(', ')}`);
    console.log('If a package is acceptable after review, add it to EXCEPTIONS in .github/scripts/check-licenses.mjs with a reason.');
    process.exit(1);
}
console.log(`License check OK: ${checked} packages.`);
