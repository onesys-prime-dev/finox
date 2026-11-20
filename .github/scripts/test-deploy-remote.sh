#!/usr/bin/env bash
# Dry-run tests for deploy-remote.sh in a temporary CloudPanel-like directory, using a stub
# `artisan`. Runs in CI (job "deploy-script"); needs Linux, PHP 8.5 and mysqldump on PATH.
#   usage: test-deploy-remote.sh
set -euo pipefail

SCRIPT="$(cd "$(dirname "$0")" && pwd)/deploy-remote.sh"
ROOT="$(mktemp -d)"
trap 'rm -rf "$ROOT"' EXIT
SITE="$ROOT/home/site-user/htdocs/finox.example.test"
WORK="$ROOT/work"
mkdir -p "$SITE" "$WORK"
export DEPLOY_PATH="$SITE" PHP_BIN="${PHP_BIN:-$(command -v php)}" KEEP_RELEASES=5
passed=0

ok() { passed=$((passed + 1)); echo "ok $passed - $*"; }
die() { echo "not ok - $*"; exit 1; }
expect_fail() { # description, pattern, command...
  local desc="$1" pattern="$2" out
  shift 2
  if out="$("$@" 2>&1)"; then echo "$out"; die "$desc: expected failure"; fi
  grep -q -- "$pattern" <<< "$out" || { echo "$out"; die "$desc: output lacks '$pattern'"; }
  ok "$desc"
}
live() { basename "$(readlink "$SITE/current")"; }

write_env() { # APP_ENV APP_DEBUG
  mkdir -p "$SITE/shared"
  cat > "$SITE/shared/.env" <<EOF
APP_NAME=FINOX
APP_ENV=$1
APP_KEY=base64:dGVzdHRlc3R0ZXN0dGVzdHRlc3R0ZXN0dGVzdHRlc3Q=
APP_DEBUG=$2
APP_URL=https://finox.example.test
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=finox
DB_USERNAME=finox
DB_PASSWORD="secret value"
EOF
  chmod 600 "$SITE/shared/.env"
}

# Build a fake release artifact for $1 (7-hex sha) and stage it as an upload in .deploy/incoming/<id>.
stage() {
  local sha7="$1" id
  id="$(date -u +%Y%m%dT%H%M%SZ)-$sha7"
  sleep 1 # release ids are per second
  local app="$WORK/app-$sha7"
  rm -rf "$app"
  mkdir -p "$app/vendor" "$app/public/build" "$app/bootstrap/cache" "$app/storage"
  echo '<?php' > "$app/vendor/autoload.php"
  echo '{}' > "$app/public/build/manifest.json"
  printf '{"sha":"%s"}\n' "${sha7}000000000000000000000000000000000" > "$app/.release.json"
  cat > "$app/artisan" <<'PHP'
<?php
// Stub artisan for deploy-remote.sh tests. Logs every call to ../../calls.log.
$args = array_values(array_filter(array_slice($argv, 1), fn ($a) => $a !== '--no-interaction'));
file_put_contents(__DIR__.'/../../calls.log', basename(__DIR__).' '.implode(' ', $args)."\n", FILE_APPEND);
$env = parse_ini_file(__DIR__.'/.env', false, INI_SCANNER_RAW);
switch ($args[0] ?? '') {
    case '--version': echo "Laravel Framework 12.0.0 (stub)\n"; break;
    case 'optimize': file_put_contents(__DIR__.'/bootstrap/cache/config.php', '<?php return [];'); echo "cached\n"; break;
    case 'finox:backup': @mkdir(__DIR__.'/storage/app/backups', 0777, true); touch(__DIR__.'/storage/app/backups/finox-backup-'.time().'.zip'); echo "backup\n"; break;
    case 'migrate': if (getenv('STUB_FAIL_MIGRATE')) { fwrite(STDERR, "SQLSTATE[42S01]: table exists\n"); exit(1); } echo "migrated\n"; break;
    case 'migrate:status': echo "Ran  2026_11_09_000001_create_access_tables\n"; break;
    case 'queue:restart': echo "Broadcasting queue restart signal.\n"; break;
    case 'about': echo json_encode(['environment' => ['environment' => $env['APP_ENV']]]); break;
    case 'schedule:list': echo "0 1 * * * php artisan finox:flag-stale\n0 2 * * * php artisan finox:backup\n"; break;
    default: fwrite(STDERR, "stub: unknown command\n"); exit(1);
}
PHP
  local in="$SITE/.deploy/incoming/$id"
  mkdir -p "$in"
  tar -czf "$in/release.tar.gz" -C "$app" .
  cp "$SCRIPT" "$in/deploy-remote.sh"
  (cd "$in" && sha256sum release.tar.gz deploy-remote.sh > SHA256SUMS)
  echo "$id"
}

deploy() { # env sha7 [version] [mode]
  local id
  id="$(stage "$2")"
  LAST_ID="$id"
  TARGET_ENV="$1" RELEASE_ID="$id" RELEASE_SHA="${2}000000000000000000000000000000000" RELEASE_VERSION="${3:-}" \
    DEPLOY_MODE="${4:-deploy}" INCOMING="$SITE/.deploy/incoming/$id" bash "$SCRIPT" deploy
}
finalize() { TARGET_ENV="$1" INCOMING="$SITE/.deploy/incoming/$LAST_ID" bash "$SCRIPT" finalize; }

# --- guards --------------------------------------------------------------------------------
write_env staging false
mkdir -p "$SITE/public" && echo placeholder > "$SITE/public/index.html"
expect_fail "refuses a real public/ directory" "is a real directory" deploy staging aaaaaa1
[[ -f "$SITE/public/index.html" ]] || die "placeholder public/ was touched"
rm -rf "$SITE/public"
expect_fail "refuses DEPLOY_PATH outside htdocs" "inside a CloudPanel htdocs" env DEPLOY_PATH="$ROOT/work" TARGET_ENV=staging bash "$SCRIPT" verify
expect_fail "refuses APP_ENV mismatch" "APP_ENV is 'staging', expected 'production'" deploy production aaaaaa2
write_env production true
expect_fail "refuses APP_DEBUG=true in production" "APP_DEBUG=true is not allowed in production" deploy production aaaaaa3
write_env staging false
sed -i '/^APP_KEY=/d' "$SITE/shared/.env"
expect_fail "refuses a .env without APP_KEY" "APP_KEY is missing" deploy staging aaaaaa4
write_env staging false

# --- first deployment ------------------------------------------------------------------------
deploy staging 1111111 > "$WORK/out.txt"
out="$(cat "$WORK/out.txt")"
first="$LAST_ID"
[[ "$(live)" == "$first" ]] || die "current does not point at first release"
[[ "$(readlink "$SITE/public")" == current/public ]] || die "public symlink not created"
[[ "$(readlink "$SITE/releases/$first/.env")" == ../../shared/.env ]] || die ".env not linked to shared"
[[ "$(readlink "$SITE/releases/$first/storage")" == ../../shared/storage ]] || die "storage not linked to shared"
[[ "$(stat -c %a "$SITE/releases/$first/bootstrap/cache/config.php")" == 600 ]] || die "config cache is not 600"
grep -q "First deployment" <<< "$out" || die "first deploy should skip the backup"
! grep -q "secret value" <<< "$out" || die ".env value leaked to the log"
ok "first deployment switches, links shared files, protects config cache, prints no secrets"

TARGET_ENV=staging bash "$SCRIPT" verify | grep -q "VERIFY_RESULT ok" || die "verify failed"
ok "verify reads APP_ENV and the schedule from the live release"
finalize staging > /dev/null
[[ -f "$SITE/releases/$first/.healthy" && ! -d "$SITE/.deploy/incoming/$first" ]] || die "finalize did not mark healthy / clean upload"
ok "finalize marks the release healthy and removes the upload"

# --- second deployment and rollback -----------------------------------------------------------
: > "$SITE/calls.log"
deploy staging 2222222 > /dev/null
second="$LAST_ID"
[[ "$(live)" == "$second" ]] || die "second deploy did not switch"
grep -q "^$first finox:backup" "$SITE/calls.log" || die "backup did not run from the previous live release"
backup_line="$(grep -n "finox:backup" "$SITE/calls.log" | cut -d: -f1)"
migrate_line="$(grep -n " migrate --force" "$SITE/calls.log" | cut -d: -f1)"
((backup_line < migrate_line)) || die "backup must run before migrations"
ok "second deployment backs up (old release) before migrating (new release)"

out="$(TARGET_ENV=staging RELEASE_ID="$second" bash "$SCRIPT" rollback)"
[[ "$(live)" == "$first" && -f "$SITE/releases/$second/.failed" ]] || die "rollback did not restore first release"
grep -q "were NOT reverted" <<< "$out" || die "rollback must state migrations were not reverted"
ok "rollback switches back to the previous release and marks the failed one"

# --- failed migration leaves the live release alone ------------------------------------------
export STUB_FAIL_MIGRATE=1
expect_fail "failed migration aborts the deployment" "Migrations failed" deploy staging 3333333
unset STUB_FAIL_MIGRATE
[[ "$(live)" == "$first" ]] || die "live release changed after a failed migration"
ok "live release unchanged after a failed migration"

# --- production version guard ----------------------------------------------------------------
rm -rf "$SITE/releases" "$SITE/current" "$SITE/public" "$SITE/.deploy"
write_env production false
deploy production 4444444 v1.2.0 > /dev/null && finalize production > /dev/null
expect_fail "refuses a lower production version" "refusing to deploy older v1.1.9" deploy production 5555555 v1.1.9
deploy production 6666666 v1.1.9 rollback > /dev/null
ok "explicit rollback mode may deploy a lower version"
deploy production 7777777 v1.10.0 > /dev/null
ok "version comparison is numeric (v1.10.0 > v1.1.9)"

# --- retention -------------------------------------------------------------------------------
for s in 8888881 8888882 8888883 8888884 8888885 8888886; do deploy production "$s" v2.0.0 > /dev/null && finalize production > /dev/null; done
count="$(find "$SITE/releases" -mindepth 1 -maxdepth 1 -type d | wc -l)"
[[ "$count" -eq 5 ]] || { ls "$SITE/releases"; die "expected 5 releases kept, found $count"; }
[[ -d "$SITE/releases/$(live)" ]] || die "live release was removed"
ok "keeps exactly 5 releases including the live one"

echo "All $passed deploy-remote.sh checks passed."
