#!/usr/bin/env bash
# Performance budget check against a running FINOX server seeded with load-test data.
#   usage: perf-budget.sh <base-url> <email> <password>
# Budgets come from docs/BUILD-PLAN.md: pages < 2 s, search < 1 s.
# Each URL gets one warm-up request, then the median of 5 timed requests is compared.
set -euo pipefail

base="${1:?base url}"
email="${2:?email}"
password="${3:?password}"
jar="$(mktemp)"
trap 'rm -f "$jar"' EXIT

# url|budget-seconds|label
checks=(
  "/dashboard|2.0|Dashboard"
  "/cheques|2.0|Cheque list"
  "/cheques?search=Load+Test|1.0|Cheque search"
  "/receipts|2.0|Receipt list"
  "/transactions|2.0|Transaction register"
  "/transactions?search=LT-R1|1.0|Register search"
  "/search?q=Load+Test|1.0|Global search"
  "/reports/cheque-register|2.0|Cheque register report"
)

# Sign in: Laravel accepts the XSRF-TOKEN cookie value back as the X-XSRF-TOKEN header.
curl -fsS -c "$jar" -b "$jar" -o /dev/null "${base}/login"
xsrf="$(awk '$6 == "XSRF-TOKEN" { print $7 }' "$jar" | python3 -c 'import sys, urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))')"
[[ -n "$xsrf" ]] || { echo "::error::No XSRF-TOKEN cookie from /login"; exit 1; }
location="$(curl -sS -c "$jar" -b "$jar" -o /dev/null -w '%{redirect_url}' \
  -H "X-XSRF-TOKEN: ${xsrf}" -H 'Accept: text/html' \
  --data-urlencode "email=${email}" --data-urlencode "password=${password}" "${base}/login")"
[[ "$location" == *"/dashboard"* ]] || { echo "::error::Sign-in failed (redirected to '${location}')"; exit 1; }

failed=0
{
  echo "### Performance budget"
  echo ""
  echo "| Page | Median | Budget | Result |"
  echo "|---|---|---|---|"
} >> "${GITHUB_STEP_SUMMARY:-/dev/null}"

for c in "${checks[@]}"; do
  IFS='|' read -r path budget label <<< "$c"
  code="$(curl -sS -b "$jar" -o /dev/null -w '%{http_code}' "${base}${path}")"   # warm-up
  if [[ "$code" != "200" ]]; then
    echo "::error::${label} (${path}) returned HTTP ${code}"
    failed=1
    continue
  fi
  times=()
  for _ in 1 2 3 4 5; do
    times+=("$(curl -sS -b "$jar" -o /dev/null -w '%{time_total}' "${base}${path}")")
  done
  median="$(printf '%s\n' "${times[@]}" | sort -n | sed -n 3p)"
  if awk -v m="$median" -v b="$budget" 'BEGIN { exit !(m <= b) }'; then
    result="pass"
  else
    result="FAIL"
    failed=1
    echo "::error::${label} (${path}) median ${median}s exceeds budget ${budget}s"
  fi
  printf '%-26s median %6.3fs  budget %4.1fs  %s   (runs: %s)\n' "$label" "$median" "$budget" "$result" "${times[*]}"
  echo "| ${label} | ${median}s | ${budget}s | ${result} |" >> "${GITHUB_STEP_SUMMARY:-/dev/null}"
done

exit "$failed"
