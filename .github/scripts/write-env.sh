#!/usr/bin/env bash
# Create a throwaway CI .env file from .env.example with overrides.
#   usage: write-env.sh <target-file> KEY=VALUE [KEY=VALUE ...]
# Existing keys are replaced, missing keys are appended. Used only on CI runners.
set -euo pipefail

target="${1:?target file required}"
shift
cp .env.example "$target"

for pair in "$@"; do
  key="${pair%%=*}"
  value="${pair#*=}"
  if grep -qE "^#?\s*${key}=" "$target"; then
    # Replace the first (possibly commented) occurrence; '|' delimiter, values must not contain '|'.
    sed -i -E "0,/^#?\s*${key}=.*/s||${key}=${value}|" "$target"
  else
    printf '%s=%s\n' "$key" "$value" >> "$target"
  fi
done

echo "Wrote ${target} ($(grep -c '=' "$target") settings)"
