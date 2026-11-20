# FINOX Server Setup (CloudPanel, one-time)

How to prepare the VPS so the GitHub Actions pipeline ([CI-CD.md](CI-CD.md)) can deploy to it.
Do this once per environment. Nothing here is automated by the pipeline, on purpose: the
deploy user has no sudo and the pipeline never creates sites, databases or services.

**Target:** Ubuntu 24.04, CloudPanel, Nginx, PHP 8.5 (FPM), MySQL 8, Supervisor, cron.
Staging and production are **two CloudPanel sites on the same VPS**, each with its own site
user, database and queue worker.

| | Staging | Production |
|---|---|---|
| CloudPanel site user (`VPS_USERNAME`) | e.g. `finox-staging` | e.g. `finox` |
| `DEPLOY_PATH` | `/home/<staging-user>/htdocs/<staging-domain>` | `/home/<prod-user>/htdocs/<prod-domain>` |
| Database / DB user | `finox_staging` / `finox_staging` | `finox_production` / `finox_production` |
| Supervisor program | `finox-staging-worker` | `finox-production-worker` |
| `APP_ENV` in `.env` | `staging` | `production` |

## 1. Create the two sites

In CloudPanel: **Sites → Add Site → Create a PHP Site**, application **Laravel**, PHP **8.5**.
CloudPanel creates the site user and `/home/<user>/htdocs/<domain>`.

Then, for each site:

1. **Root directory.** Leave CloudPanel's setting as it is (`<domain>/public`). The deployment
   replaces `<domain>/public` with a symlink to `current/public`.
2. **Remove the placeholder.** CloudPanel puts a sample `public/` (and maybe other files) in the
   site directory. Remove it once, as the site user, while the directory is still empty of real data:
   ```bash
   cd /home/<user>/htdocs/<domain>
   ls -la                # check there is nothing you need
   rm -rf public         # the deploy refuses to replace a real public/ directory
   ```
3. **Check the vhost uses `$realpath_root`.** *Sites → <site> → Vhost*. The PHP location must
   pass the resolved path to PHP-FPM:
   ```nginx
   fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
   fastcgi_param DOCUMENT_ROOT $realpath_root;
   ```
   If it uses `$document_root`, change those two lines to `$realpath_root` and save.
   Otherwise OPcache can keep serving the previous release after a deploy, because the
   symlinked path does not change.
4. **SSL.** Issue a Let's Encrypt certificate (*SSL/TLS*). Until it exists, set the
   environment variable `TLS_INSECURE=true` for that environment; set it back to `false` after.

## 2. Databases

*Databases → Add Database* for each site: `finox_staging` and `finox_production`, each with
its own user that only has rights on its own database (CloudPanel does this by default).

`finox:backup` (run before every migration) uses `mysqldump`. With MySQL 8 a non-root user
also needs the global `PROCESS` privilege, or `mysqldump` fails with
*"Access denied; you need (at least one of) the PROCESS privilege(s)"*. As MySQL root:

```sql
GRANT PROCESS ON *.* TO 'finox_staging'@'localhost';
GRANT PROCESS ON *.* TO 'finox_production'@'localhost';
```

(Use the host part CloudPanel created, e.g. `'127.0.0.1'` or `'%'`; check with
`SELECT user, host FROM mysql.user;`.) `PROCESS` lets the user see server process info; it
gives no access to other databases.

Check `mysqldump` is on the site user's `PATH` (`which mysqldump`). If not, set
`MYSQLDUMP_PATH` in the `.env`.

## 3. Directory layout and `.env`

Create the shared directory and the hand-managed `.env` as the **site user**:

```bash
cd /home/<user>/htdocs/<domain>
mkdir -p shared/storage
nano shared/.env
chmod 600 shared/.env
```

After the first deployment the directory looks like this; only `shared/` holds data:

```
<domain>/                      DEPLOY_PATH
  shared/
    .env                       hand-managed, never in git or GitHub
    storage/                   uploads (app/private), backups (app/backups), logs, sessions
  releases/<UTC time>-<sha>/   one per deployment, 5 kept; .env and storage are symlinks to shared/
  current -> releases/<id>     the live release
  public  -> current/public    web root served by Nginx
  .deploy/                     lock file, state, temporary uploads
```

`shared/.env` template (adjust every value; the deploy checks the marked keys):

```dotenv
APP_NAME=FINOX
APP_ENV=production             # REQUIRED: "staging" on the staging site, "production" on production
APP_KEY=                       # REQUIRED: generate once, see below; never change it afterwards
APP_DEBUG=false                # REQUIRED false in production (the deploy refuses true)
APP_URL=https://finox.example.com   # REQUIRED, same as the SITE_URL variable
APP_TIMEZONE=Asia/Manila

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning              # the deploy warns about "debug" in production

DB_CONNECTION=mysql            # REQUIRED
DB_HOST=127.0.0.1              # REQUIRED
DB_PORT=3306
DB_DATABASE=finox_production   # REQUIRED
DB_USERNAME=finox_production   # REQUIRED
DB_PASSWORD=...                # REQUIRED key (may not be empty in practice)

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true     # served over HTTPS
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
MAIL_MAILER=log

# MYSQLDUMP_PATH=/usr/bin/mysqldump   # only if mysqldump is not on PATH
```

Generate `APP_KEY` once (any machine with PHP):
`php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`.
**Keep a copy of each `.env` somewhere safe outside the server.** Losing `APP_KEY` makes
encrypted data and sessions unreadable.

To change configuration later: edit `shared/.env`, then either redeploy or run
`cd current && php8.5 artisan config:cache` (config is cached during deploys).

## 4. SSH access for GitHub Actions

Create one key pair **per environment** on your own computer (not on the server):

```bash
ssh-keygen -t ed25519 -N '' -C 'github-actions finox staging' -f finox-staging-deploy
ssh-keygen -t ed25519 -N '' -C 'github-actions finox production' -f finox-production-deploy
```

1. In CloudPanel, *Site → SSH/FTP → SSH Keys* (or the site user's `~/.ssh/authorized_keys`),
   add the `.pub` key to the matching site user.
2. Collect the server host key and **check its fingerprint** against the server console
   (`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub` on the VPS):
   ```bash
   ssh-keyscan -p <port> -t ed25519 <host> > known_hosts
   ssh-keygen -lf known_hosts
   ```
3. Put the values into the GitHub environment (*Settings → Environments → staging/production*):
   `VPS_PRIVATE_KEY` = private key file contents, `VPS_KNOWN_HOSTS` = the `known_hosts` line,
   `VPS_HOST`, `VPS_USERNAME` = that site's user, plus the variables in [CI-CD.md](CI-CD.md#4-environment-configuration).
4. Delete the private key files from your computer once they are stored in GitHub.

Test from your computer: `ssh -i finox-staging-deploy -p <port> <staging-user>@<host> 'php8.5 -v'`.

## 5. Queue workers (Supervisor, as root)

`/etc/supervisor/conf.d/finox-staging-worker.conf` (and the same for production with its
own user, path and name):

```ini
[program:finox-staging-worker]
command=/usr/bin/php8.5 /home/<staging-user>/htdocs/<staging-domain>/current/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
directory=/home/<staging-user>/htdocs/<staging-domain>/current
user=<staging-user>
numprocs=1
autostart=true
autorestart=true
startsecs=5
stopasgroup=true
killasgroup=true
stopwaitsecs=60
redirect_stderr=true
stdout_logfile=/home/<staging-user>/htdocs/<staging-domain>/shared/storage/logs/worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
```

```bash
supervisorctl reread && supervisorctl update
supervisorctl status          # finox-staging-worker RUNNING
```

The deployment runs `php artisan queue:restart`; each worker finishes its current job and
exits, and Supervisor starts it again through `current/` — i.e. on the new release. No sudo is
needed. Before the first deployment `current/` does not exist yet, so the worker shows
`FATAL`/`BACKOFF`; run `supervisorctl start finox-staging-worker` after the first deploy.

## 6. Scheduler (cron, as the site user)

CloudPanel: *Site → Cron Jobs → Add Cron Job*, every minute:

```
* * * * * /usr/bin/php8.5 /home/<user>/htdocs/<domain>/current/artisan schedule:run >> /dev/null 2>&1
```

It runs `finox:flag-stale` (01:00) and `finox:backup` (02:00). Check with
`cd current && php8.5 artisan schedule:list`.

## 7. First deployment

1. Complete sections 1–6 for the environment and fill its GitHub secrets and variables.
2. Deploy: staging by merging a PR into `main` (or *Actions → Deploy staging → Run workflow*);
   production by pushing a release tag (see [CI-CD.md](CI-CD.md#6-releasing-to-production)).
3. **Seed once by hand** — the pipeline never seeds, because `DatabaseSeeder` recreates the
   `admin@finox.test` account with its published default password if it is missing:
   ```bash
   cd /home/<user>/htdocs/<domain>/current
   php8.5 artisan db:seed --force
   ```
4. Sign in as `admin@finox.test` / `ChangeMe#2026`, change the password (forced), create real
   users, then deactivate or rename the seed account ([OPERATIONS.md](OPERATIONS.md)).
5. `supervisorctl start finox-<env>-worker` (root) if it was not running yet.

## 8. Load testing (manual, staging only)

Not part of CI: a shared CI runner cannot produce meaningful concurrency numbers. To measure
staging under load, from your computer, with [k6](https://k6.io) or similar, against a
signed-in session cookie, e.g. 10 virtual users for 2 minutes on `/dashboard`, `/cheques`
and `/transactions`. Keep it to staging, outside office hours. For bulk data use
`php8.5 artisan finox:seed-loadtest 50000` **on staging only** (it refuses production without
`--force`; never use `--force`).

## Checklist

- [ ] Two CloudPanel PHP 8.5 sites, placeholder `public/` removed, vhost uses `$realpath_root`
- [ ] SSL issued (or `TLS_INSECURE=true` until it is)
- [ ] Databases and users, `GRANT PROCESS`, `mysqldump` on PATH
- [ ] `shared/.env` (chmod 600) with the correct `APP_ENV` per site; copies stored safely
- [ ] Deploy key per site user; `VPS_*` secrets and variables set in each GitHub environment
- [ ] Supervisor programs `finox-staging-worker` / `finox-production-worker`
- [ ] Cron `schedule:run` every minute for each site user
- [ ] After the first deploy: `db:seed --force` once, admin password changed, worker running
