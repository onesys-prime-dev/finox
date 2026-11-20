#!/usr/bin/env bash
# FINOX server-side deployment script. Runs on the VPS as the CloudPanel site user (no sudo).
# It is shipped inside the CI release artifact, so the script that runs is the one CI tested.
#
#   deploy-remote.sh deploy   - preflight, unpack, link, cache, backup, migrate, switch, queue:restart
#   deploy-remote.sh verify   - print APP_ENV and scheduled commands of the live release
#   deploy-remote.sh finalize - mark the live release healthy and remove old releases
#   deploy-remote.sh rollback - switch back to the previous release after a failed verification
#
# Inputs (environment variables, set by deploy-reusable.yml):
#   DEPLOY_PATH   e.g. /home/<site-user>/htdocs/<domain>
#   PHP_BIN       e.g. /usr/bin/php8.5
#   TARGET_ENV    staging | production (must equal APP_ENV in shared/.env)
#   RELEASE_ID    <UTC timestamp>-<sha7>           (deploy, rollback)
#   RELEASE_SHA   full commit SHA                  (deploy)
#   RELEASE_VERSION  tag such as v1.2.3, or empty   (deploy)
#   DEPLOY_MODE   deploy | rollback                (deploy; rollback skips the version guard)
#   INCOMING      directory holding release.tar.gz, deploy-remote.sh, SHA256SUMS (deploy)
#   KEEP_RELEASES number of healthy releases to keep (default 5)
#
# Layout under DEPLOY_PATH:
#   shared/.env  shared/storage/  releases/<id>/  current -> releases/<id>  public -> current/public
#   .deploy/  (lock, state, incoming uploads)
set -euo pipefail
# Files must stay readable by Nginx (static assets); secrets are chmod 600 explicitly.
umask 022

REQUIRED_PHP="8.5"
REQUIRED_EXTENSIONS=(ctype curl dom fileinfo filter hash iconv mbstring openssl pdo_mysql session tokenizer xml xmlreader xmlwriter zip)
MIN_FREE_MB=1024

log() { printf '%s %s\n' "$(date -u +%H:%M:%S)" "$*"; }
group() { echo "::group::$*"; }
endgroup() { echo "::endgroup::"; }
fail() { echo "::error::$*"; exit 1; }
warn() { echo "::warning::$*"; }

command="${1:-}"
: "${DEPLOY_PATH:?DEPLOY_PATH is required}"
: "${PHP_BIN:?PHP_BIN is required}"
: "${TARGET_ENV:?TARGET_ENV is required}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"

DEPLOY_PATH="${DEPLOY_PATH%/}"
SHARED="$DEPLOY_PATH/shared"
RELEASES="$DEPLOY_PATH/releases"
STATE_DIR="$DEPLOY_PATH/.deploy"
STATE_FILE="$STATE_DIR/state"

# ----------------------------------------------------------------------------- guards
guard_paths() {
  [[ "$DEPLOY_PATH" == /* ]] || fail "DEPLOY_PATH must be absolute (got '$DEPLOY_PATH')"
  [[ "$DEPLOY_PATH" == */htdocs/* ]] || fail "DEPLOY_PATH must be inside a CloudPanel htdocs directory (got '$DEPLOY_PATH')"
  [[ "$DEPLOY_PATH" != *..* ]] || fail "DEPLOY_PATH must not contain '..'"
  [[ -d "$DEPLOY_PATH" ]] || fail "DEPLOY_PATH '$DEPLOY_PATH' does not exist (create the CloudPanel site first)"
  [[ -O "$DEPLOY_PATH" ]] || fail "DEPLOY_PATH '$DEPLOY_PATH' is not owned by $(id -un) - wrong VPS_USERNAME for this site?"
  [[ "$TARGET_ENV" == staging || "$TARGET_ENV" == production ]] || fail "TARGET_ENV must be staging or production"
  [[ "$KEEP_RELEASES" =~ ^[0-9]+$ && "$KEEP_RELEASES" -ge 2 ]] || fail "KEEP_RELEASES must be a number >= 2"
}

with_lock() {
  mkdir -p "$STATE_DIR"
  exec 9> "$STATE_DIR/lock"
  flock -w 600 9 || fail "Another deployment holds $STATE_DIR/lock (waited 10 minutes)"
}

state_get() { [[ -f "$STATE_FILE" ]] && grep -E "^$1=" "$STATE_FILE" | tail -n1 | cut -d= -f2- || true; }
state_set() {
  local tmp="$STATE_FILE.tmp"
  { [[ -f "$STATE_FILE" ]] && grep -vE "^$1=" "$STATE_FILE" || true; echo "$1=$2"; } > "$tmp"
  mv -f "$tmp" "$STATE_FILE"
}

# Read a key from shared/.env without printing it. Strips surrounding quotes.
env_value() {
  local line
  line="$(grep -E "^[[:space:]]*$1[[:space:]]*=" "$SHARED/.env" | tail -n1 || true)"
  line="${line#*=}"
  line="${line%$'\r'}"
  line="${line#"${line%%[![:space:]]*}"}"
  line="${line%"${line##*[![:space:]]}"}"
  [[ "$line" =~ ^\"(.*)\"$ || "$line" =~ ^\'(.*)\'$ ]] && line="${BASH_REMATCH[1]}"
  printf '%s' "$line"
}

current_release() { [[ -L "$DEPLOY_PATH/current" ]] && basename "$(readlink "$DEPLOY_PATH/current")" || true; }

switch_to() {
  local id="$1"
  [[ -d "$RELEASES/$id" ]] || fail "Release $id does not exist"
  ln -sfn "releases/$id" "$DEPLOY_PATH/.current.tmp"
  mv -Tf "$DEPLOY_PATH/.current.tmp" "$DEPLOY_PATH/current"
  if [[ ! -e "$DEPLOY_PATH/public" && ! -L "$DEPLOY_PATH/public" ]]; then
    ln -s current/public "$DEPLOY_PATH/public"
    log "Created web root symlink public -> current/public"
  fi
  log "current -> releases/$id"
}

artisan() { (cd "$1" && "$PHP_BIN" artisan "${@:2}" --no-interaction); }

# Read one key from a release's .release.json.
release_meta() {
  # shellcheck disable=SC2016  # PHP code
  "$PHP_BIN" -r 'echo json_decode((string) @file_get_contents($argv[1]), true)[$argv[2]] ?? "";' "$1/.release.json" "$2"
}

# Remove the uploaded artifact directory; only ever inside .deploy/incoming/.
remove_incoming() {
  [[ -n "${INCOMING:-}" ]] || return 0
  [[ "$INCOMING" == "$STATE_DIR/incoming/"* && "$INCOMING" != *..* ]] || { warn "Not removing unexpected INCOMING path '$INCOMING'"; return 0; }
  rm -rf "$INCOMING"
}

# ----------------------------------------------------------------------------- preflight
preflight() {
  group "Preflight checks ($TARGET_ENV)"
  guard_paths

  [[ -x "$PHP_BIN" ]] || command -v "$PHP_BIN" > /dev/null || fail "PHP_BIN '$PHP_BIN' is not executable"
  local php_version
  php_version="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  [[ "$php_version" == "$REQUIRED_PHP" ]] || fail "PHP_BIN reports PHP $php_version, expected $REQUIRED_PHP"
  local modules missing=()
  modules="$("$PHP_BIN" -m | tr '[:upper:]' '[:lower:]')"
  for ext in "${REQUIRED_EXTENSIONS[@]}"; do grep -qx "$ext" <<< "$modules" || missing+=("$ext"); done
  ((${#missing[@]} == 0)) || fail "PHP is missing extensions: ${missing[*]}"
  log "PHP $php_version with required extensions"

  [[ -f "$SHARED/.env" ]] || fail "$SHARED/.env is missing - create it by hand (see docs/SERVER-SETUP.md)"
  [[ -r "$SHARED/.env" ]] || fail "$SHARED/.env is not readable by $(id -un)"
  local key
  for key in APP_KEY APP_ENV APP_URL DB_CONNECTION DB_HOST DB_DATABASE DB_USERNAME; do
    [[ -n "$(env_value "$key")" ]] || fail "shared/.env: $key is missing or empty"
  done
  grep -qE '^[[:space:]]*DB_PASSWORD[[:space:]]*=' "$SHARED/.env" || fail "shared/.env: DB_PASSWORD is missing"
  [[ "$(env_value APP_ENV)" == "$TARGET_ENV" ]] || fail "shared/.env: APP_ENV is '$(env_value APP_ENV)', expected '$TARGET_ENV' - wrong .env for this site?"
  [[ "$(env_value DB_CONNECTION)" == mysql ]] || fail "shared/.env: DB_CONNECTION must be mysql"
  local debug
  debug="$(env_value APP_DEBUG | tr '[:upper:]' '[:lower:]')"
  if [[ "$debug" == true || "$debug" == 1 ]]; then
    [[ "$TARGET_ENV" == production ]] && fail "shared/.env: APP_DEBUG=true is not allowed in production"
    warn "shared/.env: APP_DEBUG=true on staging"
  fi
  [[ "$TARGET_ENV" == production && "$(env_value LOG_LEVEL | tr '[:upper:]' '[:lower:]')" == debug ]] \
    && warn "shared/.env: LOG_LEVEL=debug in production (verbose logs may contain sensitive data)"
  [[ "$(stat -c '%a' "$SHARED/.env")" =~ [1-7]$ ]] && warn "shared/.env is readable by other users - run: chmod 600 $SHARED/.env"
  log "shared/.env present with required keys (values not shown)"

  command -v mysqldump > /dev/null || [[ -n "$(env_value MYSQLDUMP_PATH)" ]] \
    || fail "mysqldump not found on PATH and MYSQLDUMP_PATH is not set (needed for the pre-migration backup)"

  mkdir -p "$SHARED/storage"/{app/private,app/public,app/backups,framework/cache/data,framework/sessions,framework/views,logs}
  local probe="$SHARED/storage/.deploy-write-test"
  if ! touch "$probe" 2> /dev/null; then fail "$SHARED/storage is not writable by $(id -un)"; fi
  rm -f "$probe"
  log "shared/storage writable"

  local free_mb
  free_mb="$(df -Pm "$DEPLOY_PATH" | awk 'NR==2 {print $4}')"
  ((free_mb >= MIN_FREE_MB)) || fail "Only ${free_mb} MB free under $DEPLOY_PATH (need ${MIN_FREE_MB} MB)"
  log "${free_mb} MB free"

  if [[ -e "$DEPLOY_PATH/public" && ! -L "$DEPLOY_PATH/public" ]]; then
    fail "$DEPLOY_PATH/public is a real directory. Remove CloudPanel's placeholder by hand (docs/SERVER-SETUP.md); the deploy will not delete it."
  fi
  if [[ -L "$DEPLOY_PATH/public" && "$(readlink "$DEPLOY_PATH/public")" != current/public ]]; then
    fail "$DEPLOY_PATH/public points to '$(readlink "$DEPLOY_PATH/public")', expected 'current/public'"
  fi
  if [[ -e "$DEPLOY_PATH/current" && ! -L "$DEPLOY_PATH/current" ]]; then
    fail "$DEPLOY_PATH/current exists but is not a symlink"
  fi

  # Version guard: production never moves to a lower version except through an explicit rollback.
  local live live_version
  live="$(current_release)"
  if [[ "$TARGET_ENV" == production && "${DEPLOY_MODE:-deploy}" == deploy && -n "${RELEASE_VERSION:-}" && -n "$live" && -f "$RELEASES/$live/.release.json" ]]; then
    live_version="$(release_meta "$RELEASES/$live" version)"
    if [[ -n "$live_version" && "$live_version" != "$RELEASE_VERSION" ]]; then
      local lowest
      lowest="$(printf '%s\n%s\n' "${live_version#v}" "${RELEASE_VERSION#v}" | sort -V | head -n1)"
      [[ "$lowest" == "${live_version#v}" ]] || fail "Production runs $live_version; refusing to deploy older $RELEASE_VERSION (use the rollback dispatch instead)"
    fi
    log "Version guard: live ${live_version:-unknown} -> $RELEASE_VERSION"
  fi
  endgroup
}

# ----------------------------------------------------------------------------- commands
cmd_deploy() {
  : "${RELEASE_ID:?}" "${RELEASE_SHA:?}" "${INCOMING:?}"
  [[ "$INCOMING" == "$STATE_DIR/incoming/"* && "$INCOMING" != *..* ]] || fail "INCOMING must be inside $STATE_DIR/incoming/"
  [[ "$RELEASE_ID" =~ ^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{7}$ ]] || fail "Invalid RELEASE_ID '$RELEASE_ID'"
  with_lock
  preflight

  local dir="$RELEASES/$RELEASE_ID" previous
  previous="$(current_release)"
  [[ ! -e "$dir" ]] || fail "Release directory $dir already exists"

  group "Unpack release $RELEASE_ID"
  (cd "$INCOMING" && sha256sum -c --quiet SHA256SUMS) || fail "Artifact checksum mismatch on the server"
  mkdir -p "$dir"
  state_set pending "$RELEASE_ID"
  tar -xzf "$INCOMING/release.tar.gz" -C "$dir"
  [[ -f "$dir/artisan" && -f "$dir/vendor/autoload.php" ]] || fail "Artifact is incomplete (artisan or vendor/ missing)"
  rm -rf "$dir/storage"
  ln -sfn ../../shared/storage "$dir/storage"
  ln -sfn ../../shared/.env "$dir/.env"
  mkdir -p "$dir/bootstrap/cache"
  # shellcheck disable=SC2016  # PHP code
  "$PHP_BIN" -r '$f=$argv[1]; $m=json_decode(file_get_contents($f), true) ?: [];
    $m += ["sha" => $argv[2]]; $m["version"] = $argv[3]; $m["environment"] = $argv[4]; $m["mode"] = $argv[5];
    $m["deployed_at"] = gmdate("c"); $m["previous_release"] = $argv[6];
    file_put_contents($f, json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");' \
    "$dir/.release.json" "$RELEASE_SHA" "${RELEASE_VERSION:-}" "$TARGET_ENV" "${DEPLOY_MODE:-deploy}" "$previous"
  log "Unpacked to $dir"
  endgroup

  group "Boot check and caches"
  artisan "$dir" --version
  artisan "$dir" optimize
  chmod 600 "$dir/bootstrap/cache/config.php"
  endgroup

  group "Pre-migration backup"
  if [[ -n "$previous" ]]; then
    artisan "$DEPLOY_PATH/current" finox:backup || fail "finox:backup failed - nothing was migrated or switched"
  else
    log "First deployment (no live release): nothing to back up"
  fi
  endgroup

  group "Database migrations"
  artisan "$dir" migrate --force || fail "Migrations failed - the live release was NOT switched. Check the output above; the pre-migration backup is in shared/storage/app/backups."
  artisan "$dir" migrate:status | tail -n 15
  endgroup

  group "Switch release"
  switch_to "$RELEASE_ID"
  state_set previous "$previous"
  state_set current "$RELEASE_ID"
  state_set pending ""
  artisan "$DEPLOY_PATH/current" queue:restart
  log "Queue workers told to restart (Supervisor brings them back on the new release)"
  endgroup

  echo "DEPLOY_RESULT release=$RELEASE_ID previous=${previous:-none}"
}

cmd_verify() {
  guard_paths
  local live="$DEPLOY_PATH/current"
  [[ -L "$live" ]] || fail "No live release"
  local app_env
  app_env="$(artisan "$live" about --only=environment --json | "$PHP_BIN" -r 'echo json_decode(stream_get_contents(STDIN), true)["environment"]["environment"] ?? "";')"
  [[ "$app_env" == "$TARGET_ENV" ]] || fail "Live release reports APP_ENV='$app_env', expected '$TARGET_ENV'"
  log "Live release APP_ENV=$app_env"
  local schedule
  schedule="$(artisan "$live" schedule:list)"
  for cmd in finox:flag-stale finox:backup; do
    grep -q "$cmd" <<< "$schedule" || fail "Scheduled command $cmd missing on the live release"
  done
  log "Scheduler lists finox:flag-stale and finox:backup"
  echo "VERIFY_RESULT ok"
}

cmd_finalize() {
  guard_paths
  with_lock
  local live previous
  live="$(current_release)"
  previous="$(state_get previous)"
  [[ -n "$live" ]] || fail "No live release to finalize"
  touch "$RELEASES/$live/.healthy"
  remove_incoming

  group "Remove old releases (keep $KEEP_RELEASES, always including live and previous)"
  local kept=1 id
  if [[ -n "$previous" && "$previous" != "$live" && -d "$RELEASES/$previous" ]]; then kept=2; fi
  while IFS= read -r id; do
    [[ -n "$id" ]] || continue
    if [[ "$id" == "$live" || "$id" == "$previous" ]]; then continue; fi
    if [[ -f "$RELEASES/$id/.healthy" ]] && ((kept < KEEP_RELEASES)); then
      kept=$((kept + 1))
      continue
    fi
    [[ "$id" =~ ^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{7}$ ]] || { warn "Skipping unexpected directory releases/$id"; continue; }
    rm -rf "${RELEASES:?}/$id"
    log "Removed releases/$id"
  done < <(find "$RELEASES" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort -r)
  endgroup
  echo "FINALIZE_RESULT live=$live"
}

cmd_rollback() {
  : "${RELEASE_ID:?}"
  guard_paths
  with_lock
  local live previous
  live="$(current_release)"
  previous="$(state_get previous)"
  if [[ -d "$RELEASES/$RELEASE_ID" ]]; then touch "$RELEASES/$RELEASE_ID/.failed"; fi
  remove_incoming

  if [[ "$live" != "$RELEASE_ID" ]]; then
    log "Live release is ${live:-none}, not $RELEASE_ID - the failed release was never switched in; nothing to roll back"
    echo "ROLLBACK_RESULT not-needed"
    return 0
  fi
  if [[ -z "$previous" || ! -d "$RELEASES/$previous" ]]; then
    fail "No previous release to roll back to (first deployment?) - $RELEASE_ID stays live; fix forward"
  fi

  group "Roll back to $previous"
  artisan "$RELEASES/$previous" optimize   # rebuild caches from the current shared/.env
  chmod 600 "$RELEASES/$previous/bootstrap/cache/config.php"
  switch_to "$previous"
  state_set current "$previous"
  state_set previous ""
  artisan "$DEPLOY_PATH/current" queue:restart
  endgroup
  warn "Rolled back code to $previous. Database migrations from $RELEASE_ID were NOT reverted."
  echo "ROLLBACK_RESULT release=$previous"
}

case "$command" in
  deploy) cmd_deploy ;;
  verify) cmd_verify ;;
  finalize) cmd_finalize ;;
  rollback) cmd_rollback ;;
  *) fail "Usage: deploy-remote.sh deploy|verify|finalize|rollback" ;;
esac
