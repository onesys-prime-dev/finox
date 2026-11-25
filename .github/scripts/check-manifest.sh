#!/usr/bin/env bash
# Validate the Vite build output that gets deployed.
set -euo pipefail

manifest="public/build/manifest.json"
[[ -f "$manifest" ]] || { echo "::error::${manifest} is missing - vite build did not produce a manifest"; exit 1; }

node --input-type=module - "$manifest" <<'JS'
import fs from 'node:fs';
const file = process.argv[2];
const m = JSON.parse(fs.readFileSync(file, 'utf8'));
const problems = [];
const entry = m['resources/js/app.jsx'];
if (!entry) problems.push('resources/js/app.jsx is not in the manifest');
else if (!entry.isEntry) problems.push('resources/js/app.jsx is not marked as an entry');
// app.blade.php also loads the current page component through @vite, so every page must be in the manifest.
const pages = fs.readdirSync('resources/js/Pages', { recursive: true }).filter((p) => p.endsWith('.jsx'));
for (const p of pages) {
  const key = `resources/js/Pages/${p.replaceAll('\\', '/')}`;
  if (!m[key]) problems.push(`${key} is not in the manifest`);
}
for (const [key, chunk] of Object.entries(m)) {
  for (const f of [chunk.file, ...(chunk.css ?? [])]) {
    if (f && !fs.existsSync(`public/build/${f}`)) problems.push(`${key} -> public/build/${f} does not exist`);
  }
}
if (problems.length) {
  for (const p of problems) console.log(`::error::${p}`);
  process.exit(1);
}
console.log(`Manifest OK: ${Object.keys(m).length} chunks, ${pages.length} pages present.`);
JS
