# FINOX CI/CD

How code gets from a pull request to staging and production, what every check does, the
GitHub settings it relies on, and what to do when something fails.
Server preparation is in [SERVER-SETUP.md](SERVER-SETUP.md).

## 1. Overview

```
 feature branch ──PR──▶ ci.yml ("CI gate" required) ──squash merge──▶ main
                                                                     │ push
                                                                     ▼
                           deploy-staging.yml:  ci.yml ─▶ deploy-reusable.yml (staging)
                                                                     │ staging OK
                         git tag -a vX.Y.Z  (on main)                ▼
                           deploy-production.yml: validate ─▶ ci.yml ─▶ approval ─▶ deploy-reusable.yml (production) ─▶ GitHub Release
```

| Workflow | Trigger | Does |
|---|---|---|
| `.github/workflows/ci.yml` | PR to `main` (opened, new commits, reopened); called by both deploy workflows | All checks below; builds the release artifact; `CI gate` job is the single required check |
| `.github/workflows/deploy-reusable.yml` | Called only | SSH deployment, verification, automatic rollback |
| `.github/workflows/deploy-staging.yml` | Push to `main` (= merged PR); manual run from `main` | CI on the merge commit, then deploy to `staging` |
| `.github/workflows/deploy-production.yml` | Push of tag `vX.Y.Z`; manual run from a tag (rollback) | Release checks, CI on the tagged commit, approval, deploy to `production`, GitHub Release |

Rules the design guarantees:

* **Nothing deploys without the full CI gate passing on that exact commit in the same run.**
  The deploy job `needs` the CI jobs and downloads the artifact those CI jobs built and tested.
* **Direct pushes to `main` are blocked** (ruleset, section 3). Staging deploys come from merged PRs.
* **Production only gets commits that already ran on staging**, from annotated, increasing tags.
* One deployment per environment at a time (`concurrency: deploy-<env>`, never cancelled).
  On staging, if two merges land close together, the older run skips its deploy when `main`
  has already moved on, so staging never goes backwards.

## 2. CI jobs

All jobs run in parallel except `e2e` and `performance` (they need `build`). Third-party
actions are pinned to commit SHAs (Dependabot opens weekly update PRs).

| Job | Checks | Run locally |
|---|---|---|
| PHP style (Pint) and composer.json | `composer validate --strict`; Pint with `pint.json` (Laravel preset) | `vendor/bin/pint --test` (fix: `vendor/bin/pint`) |
| Static analysis (PHPStan) | Larastan level 5; **code complexity** (cognitive complexity ≤ 15 per method, ≤ 80 per class); **architecture** rules in `tests/Architecture/LayerTest.php` (phpat). Existing findings are in `phpstan-baseline.neon` | `vendor/bin/phpstan analyse --memory-limit=2G` |
| JavaScript lint (ESLint) | React, hooks, jsx-a11y correctness rules (`eslint.config.js`); existing violations in `eslint-suppressions.json`; `exhaustive-deps` is a warning | `npm run lint` |
| Dependency audit | `composer audit` and `npm audit --audit-level=high` | same commands |
| Secret scan (gitleaks) | `gitleaks-action` on PR/push commits; full-history gitleaks CLI scan on every deployment run (the action finds no commits on tag pushes) | `gitleaks git --redact .` |
| Security static analysis (Semgrep) | Rulesets `p/php`, `p/phpcs-security-audit`, `p/security-audit`, `p/javascript`, `p/react`; fails on ERROR severity; JSON report artifact | `docker run --rm -v "$PWD:/src" -w /src semgrep/semgrep semgrep scan --config p/php …` |
| License compliance | Composer (no-dev) and npm packages against the allowlist in `.github/scripts/check-licenses.mjs` (permissive + LGPL/MPL); GPL/AGPL/unknown fail | see the job's `run:` block |
| Workflow and script lint | actionlint (with shellcheck of every `run:` block) and shellcheck of `.github/scripts/*.sh` | `actionlint`, `shellcheck .github/scripts/*.sh` |
| Deploy script dry-run tests | `.github/scripts/test-deploy-remote.sh` runs `deploy-remote.sh` against a fake CloudPanel site: guards, first deploy, backup-before-migrate, rollback, failed migration, version guard, retention | Linux only: `bash .github/scripts/test-deploy-remote.sh` |
| Tests (PHP 8.3, SQLite) | Full PHPUnit suite on the minimum supported PHP (`composer.json`: `^8.3`), in-memory SQLite (`phpunit.xml`) | `php artisan test` |
| Tests and coverage (PHP 8.5, MySQL) | Full PHPUnit suite on production's PHP and MySQL 8 (incl. the MySQL `transaction_register` view and the route protection test), PCOV coverage with a floor (`COVERAGE_MIN` in `ci.yml`); coverage HTML/Clover artifact | `DB_CONNECTION=mysql DB_DATABASE=finox_test php artisan test --coverage` |
| Migrations, seeders, backup, scheduler and queue | `migrate:fresh --seed`; seeding twice changes no row counts; `migrate:reset` leaves no tables/views (every `down()` works), then `migrate` again with nothing pending; `DemoSeeder`; `schedule:list` has `finox:flag-stale` and `finox:backup`; both commands run; the backup zip restores into a fresh database with the same tables; `queue:work --once` boots | see the job |
| Config, route, view and event caching | With `APP_ENV=production`, `APP_DEBUG=false` and no dev packages: no `env()` outside `config/`; `php artisan optimize`; the cached app boots, lists routes and the schedule | `php artisan optimize && php artisan optimize:clear` |
| Build release artifact | `npm ci && vite build`; manifest contains `app.jsx` and every page; `composer install --no-dev -o`; `check-platform-reqs` on PHP 8.5; tarball + `deploy-remote.sh` + SHA256SUMS (artifact `release-<sha>`, 14 days) | `npm run build` |
| Browser tests and accessibility (Playwright) | All `e2e/*.spec.js` on Chromium against MySQL with `DemoSeeder` and `E2eUserSeeder`, including `a11y.spec.js` (axe-core, fails on critical/serious WCAG 2.1 A/AA issues). Report, traces and logs uploaded on failure (7 days) | [OPERATIONS.md](OPERATIONS.md#automated-tests) |
| Performance budget | `finox:seed-loadtest 20000`, then median of 5 requests per page: pages < 2 s, searches < 1 s (budgets from BUILD-PLAN.md) | `.github/scripts/perf-budget.sh <url> <email> <password>` |
| **CI gate** | Fails unless every job above succeeded. **This is the only required status check.** | — |

### Checks that are deliberately not run

| Check | Why |
|---|---|
| API tests | There is no `routes/api.php`; all endpoints are Inertia web routes covered by the Feature and E2E tests |
| Load testing | Concurrency numbers from a shared CI runner are meaningless; manual procedure in [SERVER-SETUP.md §8](SERVER-SETUP.md#8-load-testing-manual-staging-only) |
| Docker build / container security | The app is not containerised; it deploys to CloudPanel sites |
| Notifications, translations | No Notification/Mail classes and no `lang/` directory yet. Add checks when they appear |
| Prettier | JS formatting is not enforced (decided; ESLint covers correctness) |

### Baselines and floors (tighten over time)

* **PHPStan:** fix some baselined errors, then `vendor/bin/phpstan analyse --generate-baseline phpstan-baseline.neon`.
* **ESLint:** fix some suppressed violations, then `npx eslint . --prune-suppressions`. ESLint fails when the suppressions file lists violations that no longer exist, so prune after fixing.
* **Coverage:** raise `COVERAGE_MIN` in `ci.yml` (multiples of 5) when coverage grows.
* **axe / licenses:** reviewed exceptions go into `ALLOWED` in `e2e/a11y.spec.js` / `EXCEPTIONS` in `check-licenses.mjs`, each with a reason.

## 3. Required GitHub settings

Configure these once (*repository Settings*). The pipeline assumes them.

**Secrets and variables**

* Repository secret **`GITLEAKS_LICENSE`** (*Settings → Secrets and variables → Actions → Repository secrets*). Required by PR CI. The copies inside the `staging`/`production` environments are not used and can stay.
* Environment secrets/variables: section 4.

**Actions** (*Settings → Actions → General*)

* Workflow permissions: **Read repository contents and packages permissions** (read-only default). Jobs request more only where needed.
* "Allow GitHub Actions to create and approve pull requests": off.
* If actions are restricted to an allowlist, allow: `actions/checkout`, `actions/setup-node`, `actions/cache`, `actions/upload-artifact`, `actions/download-artifact`, `shivammathur/setup-php`, `gitleaks/gitleaks-action`.

**General → Pull Requests**

* Allow **squash merging** only (untick merge commits and rebase merging); default commit message: *Pull request title*.
* "Automatically delete head branches": recommended.

**Rulesets** (*Settings → Rules → Rulesets*)

`main` (target: default branch), enforcement *Active*, **bypass list empty**:

* Restrict deletions
* Require a pull request before merging — required approvals **0** (raise to 1 when a second maintainer joins); allowed merge method **Squash**
* Require status checks to pass — **`CI gate`**; *Require branches to be up to date before merging*
* Block force pushes

Release tags (target: tags matching `v*`), enforcement *Active*:

* Restrict creations — bypass: *Repository admin* / maintainers only
* Restrict updates (a tag can never be moved to another commit)
* Restrict deletions

**Environments** (*Settings → Environments*)

| Setting | `staging` | `production` |
|---|---|---|
| Deployment branches and tags | Selected: branch `main` | Selected: tag pattern `v*.*.*` |
| Required reviewers | none | **1** (you) |
| Prevent self-review | — | **off** (single maintainer) |
| Wait timer | none | none |
| Allow administrators to bypass | — | off |

## 4. Environment configuration

Each environment already holds these; the workflows read them and nothing is hardcoded.

| Name | Kind | Used for |
|---|---|---|
| `VPS_HOST` | secret | SSH host |
| `VPS_USERNAME` | secret | CloudPanel **site user** of that environment (no sudo) |
| `VPS_PRIVATE_KEY` | secret | Deploy key (ed25519) for that site user |
| `VPS_KNOWN_HOSTS` | secret | Server host key; strict host checking, never `ssh-keyscan` at deploy time |
| `GITLEAKS_LICENSE` | secret | Not used at environment level (the repository secret is used) |
| `DEPLOY_PATH` | variable | `/home/<site-user>/htdocs/<domain>`; must be absolute and inside `htdocs` |
| `PHP_BIN` | variable | e.g. `/usr/bin/php8.5`; the deploy checks it reports PHP 8.5 and has the required extensions |
| `SITE_URL` | variable | e.g. `https://staging.finox.example`; health checks and the environment link |
| `SSH_PORT` | variable | SSH port (empty = 22) |
| `TLS_INSECURE` | variable | `true` = health checks skip TLS certificate verification (`curl -k`), e.g. before the certificate exists. Use `false` on production |

The server-side `.env` lives in `DEPLOY_PATH/shared/.env` and is managed by hand
([SERVER-SETUP.md §3](SERVER-SETUP.md#3-directory-layout-and-env)). Its `APP_ENV` must be
`staging` / `production`; the deploy stops if it does not match the environment.

## 5. Deployment lifecycle

`deploy-reusable.yml`, job *Deploy to &lt;env&gt;* (runs as the site user over SSH):

1. **Configuration check** — all secrets/variables present and well-formed (values never printed).
2. **Artifact** — downloads `release-<sha>` from this run and verifies SHA256SUMS (again on the server).
3. **SSH** — key and known_hosts written with mode 600; connection test. Removed at the end of the job.
4. **Upload** to `DEPLOY_PATH/.deploy/incoming/<release-id>/`.
5. **`deploy-remote.sh deploy`** (under a `flock` lock):
   * preflight: path and ownership, PHP 8.5 + extensions, `mysqldump`, `shared/.env` keys and `APP_ENV`, `APP_DEBUG` not true in production, `shared/storage` writable, ≥ 1 GB free, `public` is a symlink (or absent), production version not lower than the live one;
   * unpack to `releases/<UTC time>-<sha7>/`, link `.env` and `storage` to `shared/`, write `.release.json`;
   * `artisan --version` (catches missing PHP extensions), `artisan optimize`, config cache chmod 600;
   * **`finox:backup`** from the *live* release (skipped on the very first deploy) — a failed backup stops here;
   * **`migrate --force`** — a failed migration stops here; the live release was not touched;
   * **atomic switch** of `current`, create `public -> current/public` if missing, `queue:restart`.
6. **Verification** from the runner: `SITE_URL/up` = 200 (retried ~60 s); `/login` = 200 and contains `FINOX`; the JS asset it references = 200; on the server `APP_ENV` of the live release matches and `schedule:list` has both FINOX commands.
7. **On any failure after the upload: automatic rollback** — `current` goes back to the previous release (caches rebuilt from the current `.env`), `queue:restart`, the failed release is marked `.failed`, the job fails. **Migrations are not rolled back.**
8. **Finalize** — the release is marked `.healthy`; old releases are removed, keeping **5** (always the live and the previous one).
9. **Job summary** — environment, commit, version, release id, step outcomes, migration output.

### Migrations must be backward-compatible

Because code can roll back while the schema stays, every migration must work with the
**previous** release too ("expand, then contract"):

* add columns/tables as nullable or with defaults; do not rename or drop in the same release that stops using them;
* drop or rename in a **later** release, once no deployed code uses the old shape;
* data migrations must be re-runnable or guarded.

Every `down()` must still work (CI runs `migrate:reset`), but production rollbacks use a
backup restore, not `migrate:rollback` ([OPERATIONS.md §3](OPERATIONS.md#3-backups)).

## 6. Releasing to production

1. Make sure the commit is merged and **staging deployed it successfully** (*Actions → Deploy staging*).
2. Tag it with an **annotated** SemVer tag, higher than every existing one, and push:
   ```bash
   git switch main && git pull
   git tag -a v1.4.0 -m "Release v1.4.0"
   git push origin v1.4.0
   ```
3. *Actions → Deploy production* runs **Validate release**:
   1. tag is `vMAJOR.MINOR.PATCH` (no leading zeros or suffix; `v1.4.0-rc.1` does not even trigger),
   2. the commit is on `main`,
   3. the version is higher than every other `v*` tag,
   4. the tag is annotated,
   5. *(next job)* the full CI gate passes on that commit,
   6. that commit has a successful `staging` deployment.
4. The run pauses at **Production → Deploy to production**: *Review deployments → production → Approve*.
5. After the deploy, the **GitHub Release** is created with generated notes.

Versioning: MAJOR for breaking changes (e.g. a data migration requiring a procedure), MINOR for features, PATCH for fixes.

## 7. Rollback

* **Automatic:** a failed verification rolls the code back immediately (section 5.7).
* **Production, manual:** *Actions → Deploy production → Run workflow*, **Use workflow from: Tags → the older tag** (e.g. `v1.3.2`), type `v1.3.2` in *confirm_tag*. Checks 3 and the server's version guard are skipped; CI and approval still apply. Migrations stay as they are — the older code must be compatible (section 5). For a schema problem, restore the pre-deployment backup (`shared/storage/app/backups/`) following OPERATIONS.md §3.
* **Staging, manual:** *Actions → Deploy staging → Run workflow* from `main` with *sha* = an older commit of `main`. Usually it is better to revert the PR and let the merge deploy.
* **Emergency, on the server** (no CI, use only if GitHub is unavailable): as the site user,
  `ln -sfn releases/<previous-id> current.tmp && mv -Tf current.tmp current && php8.5 current/artisan queue:restart`.

## 8. Troubleshooting

Open the failed run, expand the red step; errors are printed as annotations (`::error::`).
Artifacts (bottom of the run page) hold reports.

| Failing job / step | Usual cause | Fix |
|---|---|---|
| PHP style | Unformatted PHP | `vendor/bin/pint`, commit |
| PHPStan | New type error, method too complex, or a layer rule broken (`phpat.test…` identifier) | Fix the code. Complexity: split the method. Architecture: move the dependency to the right layer. Do not regenerate the baseline to hide new errors |
| ESLint | New lint error; or "unused suppressions" after fixing old ones | Fix it; or `npx eslint . --prune-suppressions` |
| Dependency audit | A new advisory for a dependency | `composer update <pkg>` / `npm update <pkg>`; if no fix exists, assess and document |
| Secret scan | A secret in a commit | Rotate the secret first, then remove it from history; a false positive can be allowlisted in a `.gitleaks.toml` |
| Semgrep | ERROR-level finding | See the `semgrep` artifact; fix, or add `// nosemgrep: <rule-id>` with a justification comment after review |
| License compliance | New dependency with a non-allowed license | Choose another package, or add a reviewed entry to `EXCEPTIONS` |
| Workflow lint | Invalid workflow syntax/expression or a shellcheck warning | Run `actionlint` locally |
| Deploy script dry-run | `deploy-remote.sh` change broke a scenario | Run the test script on Linux/WSL; the failing `not ok` line names the scenario |
| Tests (8.3) | Code uses a PHP > 8.3 feature, or a test fails on SQLite | Keep PHP 8.3 syntax (composer allows `^8.3`) |
| Tests (8.5, MySQL) | MySQL-specific behaviour, or coverage below `COVERAGE_MIN` | Run tests against a local MySQL `finox_test`; add tests |
| Migrations… | A `down()` leaves tables, seeder not idempotent, backup not restorable, schedule entry removed | Fix the migration/seeder; the step name says which |
| Config caching | `env()` outside `config/`, a closure route, a Blade error | Move `env()` into a config file; use controller routes |
| Build | Vite error, page missing from manifest, extension missing on 8.5 | `npm run build` locally; check `composer check-platform-reqs` |
| E2E | Page error, flow regression, accessibility violation | Download the `e2e-report` artifact, open `playwright-report/index.html` and the traces (`npx playwright show-trace`) |
| Performance | A page above its budget | Look for missing eager loading / indexes in the slow page; a single noisy run can be re-run |
| CI gate | One of the above | Fix that job |
| Deploy: *Check environment configuration* | Missing/invalid secret or variable | Set it in *Settings → Environments* |
| Deploy: *Set up SSH* | Wrong host/port/user/key, or host key mismatch | Re-check section 4; never disable strict host checking |
| Deploy: *Deploy on the server* — preflight | `.env` missing/wrong `APP_ENV`, `APP_DEBUG=true` in production, real `public/` directory, disk full, wrong PHP | The message says which; fix on the server ([SERVER-SETUP.md](SERVER-SETUP.md)) and re-run the job |
| Deploy — `finox:backup failed` | `mysqldump` missing or the DB user lacks `PROCESS` | SERVER-SETUP.md §2 |
| Deploy — `Migrations failed` | Migration error on real data | Nothing was switched. Fix the migration in a new PR; check the DB state (partial MySQL DDL is not transactional) |
| Deploy — *Verify the live site* | App error after the switch (500 on `/up` or `/login`), assets not served, wrong `.env` | Rolled back automatically. Check `shared/storage/logs/laravel-*.log` on the server, Nginx logs in CloudPanel |
| Deploy — "Another deployment holds … lock" | A previous deployment is still running or was killed | Wait; if none is running, remove `DEPLOY_PATH/.deploy/lock` |
| Validate release — check 2/3/4/6 | Tag not on main / not higher / lightweight / commit never deployed to staging | Delete the tag (`git push --delete origin vX.Y.Z`, needs the tag ruleset bypass) and create a correct one |
| Production stuck "Waiting" | Approval pending | *Review deployments* on the run page |
| Staging run says "skipping deployment" | A newer commit reached `main` | Expected; the newer run deploys |

Re-running: *Re-run failed jobs* re-runs CI and, if it passes, the deploy for the same commit.
A deploy re-run creates a new release directory; the earlier failed one is cleaned up later.
