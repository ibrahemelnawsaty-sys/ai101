# Athar Platform — Hostinger Shared Hosting Deployment Runbook

> **Audience:** the developer performing the deployment. This document is intentionally in English.
> **Authority:** `CONSTITUTION.md` Article 10 (shared-hosting constraints), Article 8 (`audit_logs` immutability),
> Article 12 (environments), Article 24 (security). `PROJECT-CONTRACT.md` §11 (Cloudflare → Laravel migration matrix).
> **Nothing in this file may relax a Constitution article.** Where the host makes an article impossible to satisfy,
> that is an escalation (Article 31), not a permission to skip it — see [§13 Known limits](#13-known-limits-and-escalations).

---

## 0 · Scope and assumptions

| Item | Value |
|---|---|
| Host | Hostinger shared hosting (hPanel, CloudLinux + LiteSpeed, no root, no daemons) |
| Framework | Laravel 12.x (`^12.61.1`), PHP 8.2+ — see `D-27` |
| Database | MySQL 8.0 / MariaDB 10.6+, InnoDB, `utf8mb4_unicode_ci` |
| Production domain | `ai.wareed.vip` — settled by `C-02` / `D-24` |
| Staging domain | `staging.ai.wareed.vip` |
| Queue | `database` driver, drained by cron (`queue:work --stop-when-empty`) |
| Cache / session | `database` driver |
| Uploaded files | Local disk, **outside** the web root, served only through temporary signed URLs (15 minutes) |

Throughout this document:

- `$HOME` is the hosting account home, e.g. `/home/u123456789`. **Run `echo $HOME` on the server and use the real value.**
- `$APP` is the application root: `$HOME/athar-app` — **outside the web root.**
- `$WEB` is the document root that the domain points at. **On this account it is
  `$HOME/domains/wareed.vip/public_html/AI`** — verified on the live server, 10 September 2026.
  It is neither `$HOME/public_html` nor `$HOME/domains/ai.wareed.vip/public_html`: `ai.wareed.vip` is a
  subdomain of `wareed.vip`, and hPanel placed its root in an `AI/` subdirectory of the parent domain.

  **Never assume this path.** The command that finds it without guessing, using the asset hash the live
  page actually loads:

  ```sh
  curl -s https://ai.wareed.vip/ | grep -oE 'build/assets/app-[A-Za-z0-9_-]+\.css'
  grep -rl "<that hash>" "$HOME" --include=manifest.json 2>/dev/null
  ```

  The directory that contains the matching `manifest.json` **is** `$WEB`. This is how it was finally
  located after a day of work in the wrong directory.

> ⚠️ Never place `$APP` inside `$WEB`. `.env`, `storage/`, `database/`, `vendor/` and every uploaded file
> must be unreachable over HTTP. This is Article 10 and Article 24, not a preference.

---

## 1 · Pre-flight checklist

Complete every line before touching the server. Any unchecked line is a stop.

- [ ] SSH access is enabled for the account (hPanel → Advanced → SSH Access). Note host, port, user.
- [ ] The domain / subdomain exists in hPanel and its DNS resolves to the Hostinger IP.
- [ ] An SSL certificate has been issued for the exact hostname (hPanel → Security → SSL).
- [ ] The cron minimum interval on this plan has been confirmed to be **1 minute** — see §13.1.
- [ ] `composer` is available on the server (`composer --version`). If not, see §5.3.
- [ ] Decisions `D-02` (mail provider) and `D-06` (argon2id availability) have been read. `D-02` blocks
      every email-dependent feature; do not claim those features work until it is approved.
- [ ] **The hostname is `ai.wareed.vip`** — settled, see `D-36`, which supersedes `D-24`. The product
      owner's written decision is level 1 in the hierarchy and outranks the contract, so this is not an
      open question and not a stop. What remains operational: the DNS record, the SSL certificate and
      `APP_URL` must all name **`ai.wareed.vip`** exactly (BR-36). A certificate issued for the apex
      `wareed.vip` alone does **not** cover the `ai.` subdomain.
- [ ] You have a `.env` prepared **locally** with real production values, and it has never been committed.

---

## 2 · PHP version and extensions

### 2.1 Select the PHP version

hPanel → **Advanced → PHP Configuration** → *PHP version* tab → select **8.2** or **8.3** → **Save**.

Laravel 11 requires PHP ≥ 8.2. Do not select 8.1 or lower; do not select a version newer than the one the
project's `composer.json` declares support for.

The version chosen here applies to **web requests**. The **CLI** binary used by cron may be a different one —
see `deploy/cron.md` §2. Verify both:

```sh
php -v                          # CLI version on your SSH shell
```

```php
# create $WEB/_phpver.php temporarily, load it in a browser, then DELETE IT
<?php echo PHP_VERSION;
```

> Delete `_phpver.php` immediately after reading it. Leaving diagnostic files in the web root is a finding.

### 2.2 Required extensions

Laravel 11 core requirements plus what this project actually uses:

| Extension | Required for | Blocking |
|---|---|---|
| `ctype`, `filter`, `hash`, `json`, `pcre`, `session`, `tokenizer` | Laravel core | Yes |
| `mbstring` | Arabic string handling everywhere | Yes |
| `openssl` | `APP_KEY` encryption, HTTPS, signed URLs | Yes |
| `pdo`, `pdo_mysql` | MySQL | Yes |
| `dom`, `xml`, `xmlwriter`, `simplexml` | Framework internals, Excel/report export | Yes |
| `fileinfo` | **Real MIME sniffing from file content** (PRD §12.5) | Yes |
| `bcmath` | Decimal grade arithmetic (`decimal(5,2)`, BR-11/BR-12) | Yes |
| `zip` | Composer, resource bundles | Yes |
| `curl` | Outbound SMTP/API calls | Yes |
| `gd` **or** `imagick` | Avatar crop + resize to 256px / 64px (PRD §9.4.1) | Yes |
| `exif` | Reading/stripping image orientation and metadata on upload | Yes |
| `iconv` | Encoding normalisation | Yes |
| `intl` | Locale-aware formatting fallbacks | Recommended |
| `sodium` | Only if `argon2id` is provided through libsodium — see §2.3 | No |

Verify on the server:

```sh
php -m
```

```sh
for e in ctype curl dom exif fileinfo filter gd hash iconv json mbstring openssl \
         pcre pdo pdo_mysql session simplexml tokenizer xml xmlwriter zip bcmath; do
  php -r "exit(extension_loaded('$e') ? 0 : 1);" || echo "MISSING: $e"
done
```

Missing extensions are toggled in hPanel → **PHP Configuration → PHP extensions**. If an extension is not
offered there, you cannot install it (no root, Article 10) — that is an escalation, not a workaround.

### 2.3 Password hashing algorithm (`D-06`)

The Constitution mandates bcrypt cost 12, with `argon2id` **if the host provides it**. Determine which:

```sh
php -r 'print_r(password_algos());'
```

- Output contains `argon2id` → `argon2id` is available. Do **not** switch to it unilaterally; `D-06` is `مفتوح`.
- Output contains only `2y` (bcrypt) → bcrypt cost 12 stands. Record the actual output in `D-06`.

Configure in `.env` (`config/hashing.php` reads these):

```dotenv
HASH_DRIVER=bcrypt
BCRYPT_ROUNDS=12
```

Measure the cost on the target hardware — a shared CPU can make cost 12 slow enough to matter on login:

```sh
php -r '$s=microtime(true); password_hash("benchmark", PASSWORD_BCRYPT, ["cost"=>12]); echo round((microtime(true)-$s)*1000)." ms\n";'
```

Target: 150–400 ms. Above ~800 ms, record the measurement in `D-06` and escalate before lowering the cost.
**Do not lower the cost silently.**

### 2.4 PHP limits

hPanel → **PHP Configuration → PHP options**. Set at least:

| Option | Value | Why |
|---|---|---|
| `memory_limit` | `256M` | PDF/report generation, Excel export |
| `max_execution_time` | `30` | Article 10: no web request exceeds 30 s. Heavy work goes to the queue. |
| `upload_max_filesize` | `25M` | PRD §9.11.2 default per-file ceiling |
| `post_max_size` | `30M` | Must exceed `upload_max_filesize` plus form overhead |
| `max_file_uploads` | `5` | PRD §9.11.2 default file count |
| `max_input_vars` | `3000` | Large admin forms |
| `display_errors` | `Off` | Never leak stack traces |
| `opcache.enable` | `On` | Required for acceptable response times on shared CPU |

CLI runs (cron, queue) are **not** bound by `max_execution_time` in the same way; the queue worker is bounded
explicitly by `--max-time=55` instead (see `deploy/cron.md`).

---

## 3 · MySQL: database, users, and the `audit_logs` grant

### 3.1 Create the database

hPanel → **Databases → Management** → *Create a New MySQL Database*:

- Database name: `<prefix>_athar_prod` (hPanel prefixes with the account id automatically).
- Username: `<prefix>_athar_app`.
- Password: generated, ≥ 32 characters, stored in the team password manager only.

Confirm the character set. Hostinger's default is usually correct, but verify and fix if not:

```sql
ALTER DATABASE `<prefix>_athar_prod`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

```sql
SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
FROM information_schema.SCHEMATA
WHERE SCHEMA_NAME = '<prefix>_athar_prod';
-- expected: utf8mb4 | utf8mb4_unicode_ci
```

Wrong collation on a production database with Arabic data is a data-integrity defect, not a cosmetic one.
Fix it **before** the first migration.

### 3.2 The `audit_logs` constraint — Constitution Article 8

> `audit_logs` **must not be updatable or deletable**, and the restriction must exist **at the database user
> privilege level**, not only in application code.

hPanel creates one database user and grants it `ALL PRIVILEGES` on that database. It does **not** give you
`GRANT OPTION`, so you generally cannot issue `GRANT`/`REVOKE` yourself. And MySQL cannot revoke a
table-level privilege that was granted at the database level — `REVOKE UPDATE ON db.audit_logs`
fails with `ERROR 1147` when the grant is `ON db.*`.

Work the options in order. **Do not stop before you reach one that actually applies.**

#### Option A — two users with explicit grants *(preferred; requires host cooperation)*

Open a support ticket asking Hostinger to apply exactly this, or run it yourself if the plan grants
`GRANT OPTION`. `<T1>…<Tn>` is every table in the schema **except** `audit_logs`.

```sql
-- Migration/deploy user: full DDL. Used ONLY during a deployment window, never at runtime.
CREATE USER '<prefix>_athar_mig'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES
  ON `<prefix>_athar_prod`.* TO '<prefix>_athar_mig'@'localhost';

-- Runtime user: may read everything and append to everything, but may only
-- modify/delete rows in tables other than audit_logs.
CREATE USER '<prefix>_athar_app'@'localhost' IDENTIFIED BY '<strong-password>';
GRANT SELECT, INSERT ON `<prefix>_athar_prod`.* TO '<prefix>_athar_app'@'localhost';
GRANT UPDATE, DELETE ON `<prefix>_athar_prod`.`<T1>` TO '<prefix>_athar_app'@'localhost';
GRANT UPDATE, DELETE ON `<prefix>_athar_prod`.`<T2>` TO '<prefix>_athar_app'@'localhost';
-- … one line per table, audit_logs deliberately absent …
FLUSH PRIVILEGES;
```

With Option A the runtime `.env` uses `<prefix>_athar_app`, and the migration user's credentials are
**not** stored in `.env` — they are supplied only for the `migrate --force` step:

```sh
DB_USERNAME='<prefix>_athar_mig' DB_PASSWORD='<pw>' php artisan migrate --force
```

Because a new table added by a future migration will not automatically be updatable by the runtime user,
**every migration that creates a table must be followed by adding its `GRANT UPDATE, DELETE` line.**
Record this in the deployment log. This is the price of enforcing Article 8 properly.

#### Option B — database triggers *(fallback; works with a single `ALL PRIVILEGES` user)*

If the host will not create a second user, enforce immutability with triggers. This requires the `TRIGGER`
privilege, which a cPanel/hPanel database user normally has on its own schema.

```sql
DELIMITER //

CREATE TRIGGER audit_logs_no_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'audit_logs is append-only (Constitution Article 8)';
END//

CREATE TRIGGER audit_logs_no_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'audit_logs is append-only (Constitution Article 8)';
END//

DELIMITER ;
```

Verify — **both statements must fail**:

```sql
UPDATE audit_logs SET action = 'x' LIMIT 1;   -- expected: ERROR 1644
DELETE FROM audit_logs LIMIT 1;               -- expected: ERROR 1644
```

**Declared residual risk of Option B:** a user holding `ALL PRIVILEGES` can `DROP TRIGGER` and then modify
rows, and `TRUNCATE TABLE` does not fire row triggers. Option B raises the bar; it does not make the table
cryptographically immutable. It must be paired with:

- no application code path that issues `TRUNCATE`, `DROP`, or raw `UPDATE`/`DELETE` on `audit_logs`
  (the `AuditLog` model has neither `update()` nor `delete()` — Article 8, enforced by test);
- the daily database backup (§`deploy/backup.md`), so tampering is detectable by diffing row counts;
- a monthly manual check that both triggers still exist:
  `SHOW TRIGGERS FROM \`<prefix>_athar_prod\` LIKE 'audit_logs';`

#### Option C — neither is possible

Stop. Record it against `D-08` in `docs/03-decisions/DECISIONS.md`, report it, and do not deploy to
production claiming Article 8 compliance. Application-level protection alone does not satisfy Article 8.

### 3.3 Remote access

Leave **Remote MySQL disabled**. The application and the backup script both run on the same host and
connect over `localhost`. If you enabled it for a one-off task, disable it again in the same session.

---

## 4 · Directory layout on the server

```
$HOME/
├── athar-app/                  # $APP — the application. NOT web-accessible.
│   ├── app/  bootstrap/  config/  database/  lang/  resources/  routes/  tests/
│   ├── public/                 # framework's own public dir (source of the assets)
│   ├── storage/
│   │   ├── app/
│   │   │   ├── private/        # participant uploads — never web-served
│   │   │   └── public/         # only genuinely public assets, if any
│   │   ├── framework/{cache,sessions,testing,views}
│   │   └── logs/
│   ├── vendor/
│   ├── artisan
│   └── .env                    # chmod 600
├── backups/
│   ├── db/                     # daily dumps, 30-day retention
│   └── files/                  # weekly file archives, 8-week retention
├── releases/                   # optional: previous release tarballs for rollback
└── public_html/                # $WEB — served over HTTP
    ├── index.php               # either Laravel's own, or deploy/public_html-index.php
    ├── .htaccess               # from deploy/.htaccess
    ├── build/                  # Vite build output
    ├── favicon.ico  robots.txt
    └── (fonts, images, brand assets)
```

Create the scaffolding:

```sh
mkdir -p "$HOME/athar-app" "$HOME/backups/db" "$HOME/backups/files" "$HOME/releases"
chmod 700 "$HOME/backups"
```

---

## 5 · Upload the application

### 5.1 What must NOT be uploaded

`.git/`, `node_modules/`, `tests/` fixtures with real data, any `.env` other than the production one,
`storage/logs/*`, `docs/` (optional — it is Arabic documentation, not runtime code), and every local
development artefact. Build a clean artefact locally:

```sh
# On your machine, from the repository root:
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
npm ci && npm run build          # produces public/build/

tar --exclude-vcs \
    --exclude='node_modules' \
    --exclude='storage/logs/*' \
    --exclude='storage/framework/cache/data/*' \
    --exclude='storage/framework/sessions/*' \
    --exclude='storage/framework/views/*' \
    --exclude='.env' \
    --exclude='.env.*' \
    -czf athar-release.tar.gz .
```

### 5.2 Transfer and extract

```sh
scp -P <ssh-port> athar-release.tar.gz <user>@<host>:$HOME/releases/
ssh -p <ssh-port> <user>@<host>
cd "$HOME/athar-app"
tar -xzf "$HOME/releases/athar-release.tar.gz"
```

If SSH is unavailable, upload the archive with hPanel's File Manager and use its *Extract* action. The
File Manager cannot set `chmod 600`, so §8 must then be done from the File Manager's permissions dialog.

### 5.3 Building `vendor/` on the server instead

Only if you cannot build locally. `composer` may be absent or old on shared hosting:

```sh
cd "$HOME"
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir="$HOME/bin" --filename=composer
rm composer-setup.php

cd "$HOME/athar-app"
COMPOSER_MEMORY_LIMIT=-1 "$HOME/bin/composer" install \
  --no-dev --optimize-autoloader --no-interaction --prefer-dist
```

`--no-dev` is mandatory: dev dependencies (Pest, Larastan, Pint, Faker) must never reach production.
`--optimize-autoloader` is mandatory: without it, class resolution costs a measurable amount on every
request on a shared CPU.

> **Vite assets cannot be built on the server** — there is no Node.js on shared hosting and installing one
> is out of scope for Article 10. `public/build/` must arrive prebuilt in the release archive.

---

## 6 · The `.env` file

### 6.1 Create it

Never `scp` a `.env` into place with world-readable permissions and fix it afterwards; create it restricted
from the first byte:

```sh
cd "$HOME/athar-app"
umask 077
touch .env
chmod 600 .env
nano .env          # paste the production values
```

Verify:

```sh
ls -l .env         # expected: -rw------- 1 <user> <group> ... .env
stat -c '%a %U' .env   # expected: 600 <user>
```

### 6.2 Production values that are not negotiable

```dotenv
APP_NAME="${APP_NAME}"
APP_ENV=production
APP_KEY=                                  # filled by key:generate in §7
APP_DEBUG=false
APP_URL=https://ai.wareed.vip
APP_TIMEZONE=UTC

# Display timezone is Asia/Riyadh and is read from config/athar.php, never from here at runtime.
ATHAR_DISPLAY_TIMEZONE=Asia/Riyadh

LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
# Hostinger names both the database and its user `u<account-id>_<suffix>`, and the
# panel is the only place the pair is created. Take the exact strings from
# hPanel -> Databases -> Management. `127.0.0.1` is correct: the MySQL server is
# on the same host and is not exposed publicly.
DB_DATABASE=u<account-id>_<suffix>
DB_USERNAME=u<account-id>_<suffix>
DB_PASSWORD=<from password manager - never from a chat message, see D-37>

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_ENCRYPT=true

CACHE_STORE=database
QUEUE_CONNECTION=database

FILESYSTEM_DISK=local

HASH_DRIVER=bcrypt
BCRYPT_ROUNDS=12

MAIL_MAILER=smtp
MAIL_HOST=<blocked by D-02>
MAIL_PORT=587
MAIL_USERNAME=<blocked by D-02>
MAIL_PASSWORD=<blocked by D-02>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=<blocked by D-02>
MAIL_FROM_NAME="${APP_NAME}"
```

- `APP_DEBUG=false` is Article 12, **without exception**. A `true` here on production is a security incident.
- `APP_URL` is the single source of every absolute link (BR-36). It must include `https://` and no trailing slash.
- `APP_TIMEZONE=UTC` is Article 11. Display conversion is `Clock::riyadh()`, never a config change.
- `SESSION_SECURE_COOKIE=true` requires §10 (HTTPS) to be working first, or nobody can log in.
- The mail block stays incomplete until `D-02` is approved. **Do not invent an SMTP provider.**

### 6.3 Never read `.env` at runtime

`env()` outside `config/` is blacklisted (Article 13 item 14) precisely because `config:cache` (§7) makes
`env()` return `null` in production. Every value above is consumed through `config('…')`.

---

## 7 · Bootstrap the application

Run in this order, from `$APP`:

```sh
cd "$HOME/athar-app"

# 1. Application key — writes APP_KEY into .env. Run ONCE, on the first deploy only.
php artisan key:generate --force
```

> **Never regenerate `APP_KEY` on an existing production database.** Every encrypted column, every signed
> URL and every active session is derived from it. Regenerating it destroys them irreversibly.
> Back up the key alongside the database credentials.

```sh
# 2. Storage directories (created by the release archive, re-asserted here)
mkdir -p storage/app/private storage/app/public \
         storage/framework/{cache/data,sessions,testing,views} \
         storage/logs bootstrap/cache

# 3. Schema. --force is required because APP_ENV=production prompts otherwise.
php artisan migrate --force

# 4. Reference data only. Never seed demo/participant data into production.
#    ProgramSeeder = the programme, its cohort, the four weeks, the ten journey
#    steps and the landing settings. It is the ONLY seeder that may run against
#    production. DatabaseSeeder also calls the demo seeders - never run it here.
php artisan db:seed --force --class=Database\\Seeders\\ProgramSeeder

# 5. Caches. Order matters: clear first, then build.
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 6. Sanity report
php artisan about
```

`php artisan about` must show: environment `production`, debug mode `OFF`, cache/config/routes/views/events
`CACHED`, database `mysql` connected, cache store `database`, queue `database`, session `database`.

### 7.1 Sessions, cache and jobs tables

Laravel 11 ships the `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches` and `failed_jobs` tables in the
default `0001_01_01_*` migrations. Step 3 creates them. If your `database/migrations` was trimmed, add them
back before deploying — `SESSION_DRIVER=database` and `QUEUE_CONNECTION=database` will fail hard without them.

### 7.2 On every subsequent deploy

```sh
cd "$HOME/athar-app"
php artisan down --render="errors.503" --retry=60       # maintenance page
# `errors.503` — a dot, not `::`.  `errors::503` means "the 503 view of a package
# registered under the `errors` namespace"; this application has no such package,
# and `down` would abort with "No hint path defined for [errors]".
# … replace code, run composer install --no-dev --optimize-autoloader …
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan queue:restart
php artisan up
```

`queue:restart` matters even without a daemon: it makes any in-flight cron-started worker exit at its next
job boundary rather than continue running the previous release's code.

---

## 8 · File permissions

PHP runs as your own account user on Hostinger (LSAPI), so the group/other bits are never needed.
**Do not use 777 anywhere. Do not use 775 unless you have proven the web user differs from your user.**

```sh
cd "$HOME/athar-app"

find . -type d -not -path './vendor/*' -not -path './node_modules/*' -exec chmod 755 {} \;
find . -type f -not -path './vendor/*' -not -path './node_modules/*' -exec chmod 644 {} \;

chmod 755 artisan
chmod -R 755 storage bootstrap/cache
chmod 600 .env

# Backup scripts, if you installed them
chmod 700 "$HOME/backups" "$HOME"/athar-app/deploy/backup-*.sh 2>/dev/null || true
```

Verify nothing is world-writable:

```sh
find "$HOME/athar-app" -perm -o+w -not -path '*/vendor/*' -print
# expected: no output
```

Verify `.env` is not reachable over HTTP — this must return **403 or 404**, never the file:

```sh
curl -sS -o /dev/null -w '%{http_code}\n' https://ai.wareed.vip/.env
curl -sS -o /dev/null -w '%{http_code}\n' https://ai.wareed.vip/storage/logs/athar.log
curl -sS -o /dev/null -w '%{http_code}\n' https://ai.wareed.vip/composer.json
```

---

## 9 · Wiring the web root

Two supported approaches. **Prefer Option A.**

### Option A — point the document root at `$APP/public` *(preferred)*

hPanel → **Websites → Dashboard → Advanced → Website settings** (or *Domains → Manage*), set the
**document root** of `ai.wareed.vip` to:

```
/home/u123456789/athar-app/public
```

Then copy the hardened rules into that directory:

```sh
cp "$HOME/athar-app/deploy/.htaccess" "$HOME/athar-app/public/.htaccess"
```

Laravel's own `public/index.php` is used unchanged. Nothing else is required. This is the cleanest
arrangement: one copy of the assets, no path rewriting, no drift between two directories.

Caveat: some Hostinger plans do not expose a document-root field for the primary domain. If yours does not,
use Option B.

### Option B — the shim in `public_html`

`$WEB` stays where the host insists it is, and `index.php` boots the application from outside it.

```sh
# 1. Install the shim and the hardened rules
cp "$HOME/athar-app/deploy/public_html-index.php" "$WEB/index.php"
cp "$HOME/athar-app/deploy/.htaccess"             "$WEB/.htaccess"

# 2. Adjust the one path constant inside the shim if your app dir is not $HOME/athar-app
nano "$WEB/index.php"      # edit ATHAR_APP_BASE only

# 3. Publish the static assets into the web root
rsync -a --delete "$HOME/athar-app/public/build/" "$WEB/build/"
cp "$HOME/athar-app/public/favicon.ico"  "$WEB/" 2>/dev/null || true
cp "$HOME/athar-app/public/robots.txt"   "$WEB/" 2>/dev/null || true
rsync -a --delete "$HOME/athar-app/public/fonts/"  "$WEB/fonts/"  2>/dev/null || true
rsync -a --delete "$HOME/athar-app/public/brand/"  "$WEB/brand/"  2>/dev/null || true
rsync -a --delete "$HOME/athar-app/public/images/" "$WEB/images/" 2>/dev/null || true

# 4. Remove anything the host pre-installed in the web root
rm -f "$WEB/default.php" "$WEB/index.html" "$WEB/hostinger-*.html"
```

The shim calls `$app->usePublicPath(__DIR__)`, so `public_path()` resolves to `$WEB`. That is why the
Vite manifest must be copied in step 3 — `public/build/manifest.json` is read through `public_path()`.

**Step 3 must be repeated on every deploy that changes front-end assets.** Forgetting it produces a
site styled with the previous release's CSS, which is easy to miss and hard to diagnose.

### 9.1 `storage:link` and its alternatives

`php artisan storage:link` creates `public/storage → storage/app/public`.

**Read this before running it:** under Article 24 and PRD §12.5, participant uploads (submissions, project
files, avatars) are stored **outside the web root** and served **only** through a controller behind an
authorisation check with a temporary signed URL valid 15 minutes. Those files belong in
`storage/app/private/`, which must have **no** public link. `storage:link` is therefore relevant only to
genuinely public, non-personal assets — if the project has none, **do not create the link at all.**

If you do need it and symlinks are unavailable or disabled:

```sh
# Preferred: relative symlink, survives the account being moved between servers
php artisan storage:link --relative

# If symlink() is disabled by the host, verify first:
php -r 'var_export(function_exists("symlink") && !in_array("symlink", explode(",", str_replace(" ", "", (string) ini_get("disable_functions")))));'
```

Fallbacks, in order of preference:

1. **Serve through a route.** A controller action reads the file from `storage/app/public` and streams it
   with the right `Content-Type`, `Content-Disposition: attachment` and `X-Content-Type-Options: nosniff`.
   This is what the private files already do, so there is no new machinery.
2. **Change the disk root.** Point the `public` disk at a directory inside `$WEB` in `config/filesystems.php`
   (a config change, committed and reviewed — not an ad-hoc server edit). Only acceptable for assets that
   are genuinely public.
3. **Copy on deploy.** `rsync -a "$APP/storage/app/public/" "$WEB/storage/"` as a deploy step. Acceptable only
   for content that never changes at runtime; it silently goes stale for anything uploaded by users.

Never `chmod 777` a directory to "make the link work". That is not what a failed symlink means.

---

## 10 · HTTPS and SSL

### 10.1 Issue the certificate

hPanel → **Security → SSL** → select the domain → **Install SSL** (free Let's Encrypt). Wait for status
*Active*. Confirm it covers the exact hostname, including the subdomain — a certificate for
`wareed.vip` alone does not cover `ai.wareed.vip`.

Enable **Force HTTPS** in the same panel if the option is present.

### 10.2 Force it in the application too

Panel settings are not a guarantee, and the redirect must be correct for the app's own generated URLs.
Both layers are used:

1. `deploy/.htaccess` performs the 301 redirect at the server layer (§`deploy/.htaccess`).
2. `APP_URL=https://…` makes every `route()`, `url()` and signed URL absolute and `https`.
3. A `TrustProxies` configuration is required because LiteSpeed terminates TLS and forwards
   `X-Forwarded-Proto`. In `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: '*');
})
```

Without this, Laravel sees the request as `http`, generates `http://` URLs, and `SESSION_SECURE_COOKIE=true`
causes the session cookie never to be sent back — the classic "login redirects to login" loop.

`at: '*'` is safe here only because the application is reachable exclusively through the host's own proxy.
Do not copy that value into an environment where the origin is directly reachable.

### 10.3 Verify

```sh
curl -sS -o /dev/null -w '%{http_code} %{redirect_url}\n' http://ai.wareed.vip/
# expected: 301 https://ai.wareed.vip/

curl -sSI https://ai.wareed.vip/ | grep -Ei 'strict-transport|x-frame|x-content-type|referrer-policy|content-security'
```

Only enable HSTS (`Strict-Transport-Security`) once HTTPS is confirmed working on every hostname the
account serves. It is not reversible within its `max-age` for visitors who have already received it.

---

## 11 · Cron

See **`deploy/cron.md`** for the full explanation and **`deploy/cron-setup.txt`** for the lines to paste
into hPanel. Two entries, both every minute:

- `schedule:run` — every scheduled task in the application runs through this one entry (Article 10).
- `queue:work --stop-when-empty --max-time=55` — drains the `database` queue. **No daemon, ever.**

Backups add two more entries; see `deploy/backup.md`.

---

## 12 · Post-deployment verification

Do not report a successful deployment until every line below has actually been run and observed.
"It should work" is not an answer (Article 30).

| # | Check | Expected |
|---|---|---|
| 1 | `php artisan about` | env `production`, debug `OFF`, all caches `CACHED` |
| 2 | `curl -o /dev/null -w '%{http_code}' https://<host>/` | `200` |
| 3 | `curl -o /dev/null -w '%{http_code}' http://<host>/` | `301` to `https://` |
| 4 | `curl -o /dev/null -w '%{http_code}' https://<host>/.env` | `403` or `404` |
| 5 | `curl -o /dev/null -w '%{http_code}' https://<host>/storage/logs/athar.log` | `403` or `404` |
| 6 | Landing page in a browser | Arabic renders RTL, letters connected, no missing glyphs, no yellow/gold anywhere |
| 7 | Latin digits shown for numbers, dates as «الأحد 12 أكتوبر 2026» | Article 15 |
| 8 | Login → dashboard | Session persists across a page reload (proves §10.2) |
| 9 | `php artisan queue:work --stop-when-empty --once` | Exits cleanly; a test job is processed |
| 10 | `php artisan schedule:list` | Lists the expected tasks with next run times in the expected timezone |
| 11 | Server time | `php -r 'echo (new DateTimeImmutable("now", new DateTimeZone("UTC")))->format("c"), PHP_EOL;'` matches real UTC within a few seconds — BR-07 depends on it |
| 12 | Attendance window boundary | Server-side check honours S−30m / S+30m / E / E+30m; verified against `php artisan tinker` using `Clock::now()` |
| 13 | `UPDATE audit_logs …` and `DELETE FROM audit_logs …` | Both **fail** (§3.2) |
| 14 | Backup script dry run | Produces a `.sql.gz` that `gunzip -t` accepts |
| 15 | `storage/logs/athar-$(date +%F).log` after the smoke test | No `ERROR` or `CRITICAL` lines |

Note on check 11: `new DateTimeImmutable` is used **only** here, in a throwaway shell command outside the
codebase, to prove the host clock is correct. Inside the application the only time source is
`App\Services\Time\Clock` (Article 11, gate G4).

---

## 13 · Known limits and escalations

### 13.1 Cron interval floor

Some Hostinger shared plans enforce a minimum cron interval greater than one minute. Article 10 and the
attendance windows (BR-01…BR-09) assume `schedule:run` executes **every minute**; the automatic transitions
in BR-08 (absent) and BR-09 (incomplete attendance) become inaccurate by up to the interval otherwise.

**Verify the actual floor on this account before go-live.** If it is above one minute, this is a blocking
constraint: record it, report it, and do not silently accept degraded attendance accuracy.

### 13.2 No daemons, no Redis, no root

Consequences already designed for, restated so nobody "optimises" them away:

- No `supervisor`, no `queue:work` without `--stop-when-empty`, no Horizon.
- No Redis for cache, session, queue or locks. `database` only.
- No `pcntl`/`posix`, so no signal handling in the worker; `--max-time` is the only bound that exists.
- No new PHP extensions, no global `php.ini` edits, no `exec()` of system tools.

### 13.3 Execution time

No web request may exceed 30 seconds. Certificate PDF generation, bulk certificate issuance, Excel export
and bulk email all go to the queue and are drained by cron.

### 13.4 Disk and mail quotas

Shared plans cap total disk, inode count and outbound mail per hour. Uploaded submissions
(25 MB × 5 files × cohort size) reach the disk ceiling quickly, and cohort-wide notifications reach the
hourly mail ceiling quickly. Both are open decisions: **`D-05`** (storage ceiling) and **`D-02`**
(mail provider and its rate limits). Neither may be assumed.

### 13.5 Error monitoring

There is no APM agent on shared hosting. Until **`D-04`** is decided, the only signal is
`storage/logs/laravel-*.log`. Read it after every deployment.

---

## 14 · Rollback

```sh
cd "$HOME"
php athar-app/artisan down --retry=60

# 1. Restore code
mv athar-app athar-app.broken
mkdir athar-app && cd athar-app
tar -xzf "$HOME/releases/<previous-release>.tar.gz"
cp "$HOME/athar-app.broken/.env" .env && chmod 600 .env

# 2. Restore the database ONLY if the failed deploy ran a destructive migration
gunzip -c "$HOME/backups/db/<latest-good>.sql.gz" \
  | mysql --defaults-extra-file="$HOME/.my.athar.cnf" "<prefix>_athar_prod"

# 3. Rebuild caches and come back up
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan queue:restart
php artisan up
```

Keep `athar-app.broken` until the incident is written up. Do not delete evidence.

Restoring the database rolls back **all** data written since the dump, including attendance records and
submissions. Article 7 (fail safe) applies: if you are unsure whether the migration was destructive,
restore code only, keep the platform in maintenance mode, and escalate.

---

## 15 · Related documents

| File | Contents |
|---|---|
| `deploy/cron.md` | Cron entries explained, finding the PHP binary, verification |
| `deploy/cron-setup.txt` | The exact lines to paste into hPanel |
| `deploy/public_html-index.php` | The Option B shim |
| `deploy/.htaccess` | HTTPS force, security headers, dotfile blocking, Laravel rewrites |
| `deploy/backup.md` | Daily DB backup (30 days), weekly file backup, quarterly restore drill |
| `deploy/backup-db.sh` · `deploy/backup-files.sh` | The backup scripts referenced by cron |
| `docs/03-decisions/DECISIONS.md` | `D-01` … `D-09` plus `D-24` (hostname), all `مفتوح` and all relevant to this file |
