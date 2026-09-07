# Athar Platform — Cron on Hostinger Shared Hosting

> **Authority:** `CONSTITUTION.md` Article 10 (no daemons, one-minute cron, `database` queue),
> Article 11 (server time is the sole reference, BR-07), `PROJECT-CONTRACT.md` §11.
> **Paste-ready lines:** `deploy/cron-setup.txt`.

---

## 1 · What runs, and why exactly two entries

Shared hosting gives no `supervisor`, no long-lived processes and no root. Everything the platform needs
to do on a schedule therefore reaches the server through **two cron entries, both every minute**:

| Entry | Command | Purpose |
|---|---|---|
| 1 | `php artisan schedule:run` | The single entry point for **every** scheduled task defined in `routes/console.php`. Laravel decides internally which of them is due this minute. |
| 2 | `php artisan queue:work --stop-when-empty --max-time=55` | Drains the `database` queue and **exits**. Never a daemon. |

Backups add two more entries — see `deploy/backup.md`.

### 1.1 Why `schedule:run` must run every minute

`schedule:run` is not "the scheduler". It is a one-shot process that asks *"which tasks are due right now?"*,
runs those, and exits. If it runs every five minutes, a task scheduled `->everyMinute()` runs every five
minutes; a task scheduled `->dailyAt('06:00')` may not run at all if 06:00 falls between two invocations.

Two business rules depend directly on this cadence:

- **BR-08** — a participant who did not check in by the end of the session is automatically marked absent.
- **BR-09** — a participant who checked in but never checked out becomes *incomplete attendance*, and the
  trainer is notified.

Both fire from a scheduled sweep. A coarser interval makes them late by up to that interval, and a
participant's attendance record — and therefore their certificate eligibility (BR-26) — is decided by it.
**Confirm the plan's minimum cron interval before go-live** (`deploy/README.md` §13.1). If the floor is above
one minute, that is a blocking constraint to escalate, not something to absorb quietly.

### 1.2 Why the queue worker is `--stop-when-empty`

`queue:work` without a bound runs forever. On shared hosting a forever-process is killed by the host's
process reaper at an arbitrary moment, mid-job, leaving the job reserved and invisible until its timeout.
The flags below make the worker a well-behaved cron task:

| Flag | Effect | Why this value |
|---|---|---|
| `--stop-when-empty` | Exits as soon as the queue is drained | No idle process consuming the account's process allowance |
| `--max-time=55` | Exits after 55 seconds regardless | Bounded to less than the one-minute cron period, so overlapping instances stay rare |
| `--tries=3` | Three attempts, then `failed_jobs` | A failed email retries; it does not vanish |
| `--backoff=30` | 30 s before a retry | Avoids hammering an SMTP host that is rate-limiting us |
| `--timeout=50` | Kills a single job after 50 s | Must stay below `--max-time`; `pcntl` is unavailable, so this is enforced by the worker loop, not by a signal |
| `--sleep=1` | Poll interval while waiting | Only relevant in the moment before `--stop-when-empty` triggers |
| `--memory=180` | Restart threshold in MB | Below the 256 MB PHP limit set in `deploy/README.md` §2.4 |
| `--quiet` | Suppresses per-job output | Cron mails output otherwise; real problems go to `storage/logs/` |

> **`--timeout` note:** Laravel's per-job timeout uses `pcntl_alarm` when available. `pcntl` is absent on
> shared hosting (Article 10), so a genuinely hung job is bounded by `--max-time` instead. Long work
> (PDF generation, bulk certificate issuance, Excel export) must be written to complete well inside
> 50 seconds or be split into smaller jobs. **Do not raise these numbers to make a slow job fit.**

### 1.3 Overlap

Two `queue:work` processes running at once is safe — jobs are reserved atomically in the database, so no
job is processed twice. `--max-time=55` keeps overlap to the rare case where a job runs past the minute
boundary. If the account's process allowance is tight, wrap the entry in `flock` (§4.3).

`schedule:run` overlap is prevented per-task in application code with `->withoutOverlapping()`, which uses
the `cache_locks` table. That is a code concern, not a cron concern.

---

## 2 · Finding the PHP binary path

hPanel cron entries do not inherit your shell's `PATH`, and `php` on a bare cron `PATH` is often an old
default build — not the 8.2/8.3 you selected for the website. **Always use an absolute path.**

Run each of these over SSH and keep the output:

```sh
# 1. What does your interactive shell use?
which php
type -a php
php -v

# 2. CloudLinux alt-php builds (the usual answer on Hostinger)
ls -d /opt/alt/php8* 2>/dev/null
ls -l /opt/alt/php82/usr/bin/php /opt/alt/php83/usr/bin/php 2>/dev/null

# 3. Other common locations
ls -l /usr/local/bin/php /usr/bin/php /usr/bin/php8* /opt/cpanel/ea-php8*/root/usr/bin/php 2>/dev/null

# 4. Whatever `php` resolves to, follow the symlink chain to the real file
readlink -f "$(command -v php)"
```

Typical results:

| Path | Meaning |
|---|---|
| `/usr/bin/php` | The account default — **verify its version, it is often not the one you selected** |
| `/opt/alt/php82/usr/bin/php` | CloudLinux alt-php 8.2 CLI |
| `/opt/alt/php83/usr/bin/php` | CloudLinux alt-php 8.3 CLI |
| `/usr/local/bin/php` | Panel-managed default CLI |
| `/opt/cpanel/ea-php82/root/usr/bin/php` | EasyApache 8.2 (cPanel-style accounts) |

### 2.1 Confirm the binary before trusting it

```sh
/opt/alt/php82/usr/bin/php -v
/opt/alt/php82/usr/bin/php -m | grep -E '^(pdo_mysql|mbstring|openssl|bcmath|fileinfo|gd)$'
/opt/alt/php82/usr/bin/php -r 'echo PHP_VERSION, " ", PHP_SAPI, PHP_EOL;'   # expect: 8.2.x cli
```

The CLI binary must have the **same extension set** as the web SAPI. A CLI build without `pdo_mysql` fails
every cron run silently until you read the log.

### 2.2 Confirm it can actually boot the application

```sh
/opt/alt/php82/usr/bin/php /home/u123456789/athar-app/artisan about
/opt/alt/php82/usr/bin/php /home/u123456789/athar-app/artisan schedule:list
```

If `schedule:list` prints the expected tasks with sensible next-run times, the binary and the paths in your
cron lines are correct. **Do not add the cron entries before this command succeeds.**

### 2.3 hPanel's own hint

hPanel → **Advanced → Cron Jobs** shows the command prefix it expects for the account's PHP version at the
top of the page. If it disagrees with what you found above, prefer hPanel's value and re-verify with §2.1.

---

## 3 · Installing the entries

hPanel → **Advanced → Cron Jobs** → *Create a New Cron Job*:

1. **Common Settings** → choose *Custom* (or *Every Minute* if offered).
2. **Minute** `*`, **Hour** `*`, **Day** `*`, **Month** `*`, **Weekday** `*`.
3. **Command to run** → paste one line from `deploy/cron-setup.txt`, with the placeholders replaced.
4. Save. Repeat for each entry.

Replace in every line:

| Placeholder | Replace with | Find it by |
|---|---|---|
| `/usr/bin/php` | The absolute PHP CLI path from §2 | `readlink -f "$(command -v php)"` |
| `/home/u123456789` | The real account home | `echo $HOME` |

`athar-app` is the application directory name from `deploy/README.md` §4. If you used a different name,
change it consistently in every line.

---

## 4 · The entries, explained line by line

### 4.1 Scheduler

```cron
* * * * * /usr/bin/php /home/u123456789/athar-app/artisan schedule:run >> /home/u123456789/athar-app/storage/logs/cron-schedule.log 2>&1
```

- `>>` appends, so the log survives across runs.
- `2>&1` sends stderr to the same file. Without it, cron emails every warning to the account address and
  the mailbox fills within a day.
- The log is **rotated by `deploy/backup.md`'s housekeeping entry**, not by Laravel. It is a cron log, not
  an application log; application logging goes to `storage/logs/laravel-*.log` via the `daily` channel.

### 4.2 Queue worker

```cron
* * * * * /usr/bin/php /home/u123456789/athar-app/artisan queue:work --stop-when-empty --max-time=55 --tries=3 --backoff=30 --timeout=50 --sleep=1 --memory=180 --quiet >> /home/u123456789/athar-app/storage/logs/cron-queue.log 2>&1
```

The flags are explained in §1.2. Do not remove `--stop-when-empty`.

### 4.3 Optional: prevent overlap with `flock`

Only if the account's process allowance is being hit. Check that `flock` exists first:

```sh
command -v flock
```

If it does:

```cron
* * * * * /usr/bin/flock -n /home/u123456789/athar-app/storage/framework/queue.lock /usr/bin/php /home/u123456789/athar-app/artisan queue:work --stop-when-empty --max-time=55 --tries=3 --backoff=30 --timeout=50 --sleep=1 --memory=180 --quiet >> /home/u123456789/athar-app/storage/logs/cron-queue.log 2>&1
```

`-n` means "if the lock is held, exit immediately" — the next minute's run picks the work up.
If `flock` is not installed, do not emulate it with a lock file written by a shell script; use the
unwrapped line and rely on the database's atomic job reservation instead.

### 4.4 What must **not** be added

- Any `queue:work` **without** `--stop-when-empty` or `--max-time`. That is a daemon (Article 10).
- `queue:listen`. It spawns a child process per job and is slower and heavier than `queue:work`.
- A second `schedule:run` entry "to be safe". One entry, one minute.
- `php artisan schedule:work`. That is a local development helper; it is a foreground daemon.
- Any command that writes to the database with a user other than the runtime user, unless it is a
  deliberate migration step under a deployment window.

---

## 5 · Timezone

The server clock is UTC and `APP_TIMEZONE=UTC` (Article 11). Cron's `* * * * *` entries are unaffected by
timezone, but **the fixed-time entries in `deploy/backup.md` are not**.

Determine the timezone cron actually uses:

```sh
date
date -u
cat /etc/timezone 2>/dev/null || readlink -f /etc/localtime
```

If cron runs in UTC, a backup wanted at 03:15 **Riyadh** (UTC+3, no DST) must be scheduled at
**00:15 UTC**. Riyadh has no daylight saving, so the offset is a constant +3 and no seasonal adjustment
is ever needed.

Scheduled tasks *inside* the application declare their own timezone in `routes/console.php`
(`->timezone(config('athar.display_timezone'))`), so the application's schedule is unaffected by the
server's cron timezone. Only the raw shell entries in `deploy/cron-setup.txt` need this conversion.

---

## 6 · Verifying that cron actually runs

Cron failing silently is the single most common shared-hosting deployment defect. Verify, do not assume.

### 6.1 Immediately after installing the entries

Wait two minutes, then:

```sh
tail -n 40 /home/u123456789/athar-app/storage/logs/cron-schedule.log
tail -n 40 /home/u123456789/athar-app/storage/logs/cron-queue.log
```

Empty files are the **expected** result for a healthy idle system — `schedule:run` with nothing due and
`queue:work` with an empty queue both print nothing. Empty is therefore not proof that cron ran. Prove it
positively instead:

```sh
ls -l --time-style=full-iso /home/u123456789/athar-app/storage/logs/cron-*.log
# The mtime must be within the last minute or two.
```

If the files do not exist at all, cron has never run: the PHP path is wrong, the artisan path is wrong,
or the entry was not saved.

### 6.2 Positive end-to-end proof

```sh
cd /home/u123456789/athar-app

# 1. Queue a job and confirm cron drains it.
php artisan queue:monitor default          # note the current depth
php artisan tinker --execute="dispatch(new \App\Jobs\NoOpPing());"
# wait up to 60 seconds
php artisan queue:monitor default          # depth must be back to 0

# 2. Confirm the scheduler is being invoked.
php artisan schedule:list                  # shows next run times
sleep 90
php artisan schedule:list                  # next run times must have advanced
```

### 6.3 Ongoing health

```sh
# Failed jobs must be zero. If not, read them before retrying.
php artisan queue:failed

# Application errors from scheduled work
grep -E 'ERROR|CRITICAL|ALERT|EMERGENCY' /home/u123456789/athar-app/storage/logs/laravel-*.log | tail -n 50

# Cron-level failures (wrong path, fatal before Laravel boots)
grep -iE 'command not found|No such file|Fatal error|Allowed memory' \
  /home/u123456789/athar-app/storage/logs/cron-*.log | tail -n 50
```

Until `D-04` (error monitoring) is decided, these three commands are the whole monitoring story and a human
must run them. Say so in the handover; do not imply the platform is monitored.

---

## 7 · Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `cron-*.log` never created | Cron entry not saved, or the PHP path is wrong | Re-check §2, re-save the entry in hPanel |
| `Could not open input file: artisan` | Wrong application path in the entry | `ls /home/<user>/athar-app/artisan` |
| `command not found` | PHP path wrong or not absolute | §2.1 |
| `PDOException: could not find driver` | CLI PHP lacks `pdo_mysql` | §2.1 — pick the alt-php binary that has it |
| Jobs sit in `jobs` forever | Worker entry missing, or a fatal before the loop starts | `php artisan queue:work --once` by hand and read the output |
| Jobs run twice | Two worker entries installed | Delete one; overlap alone does not cause double processing |
| `MaxAttemptsExceededException` | Job exceeded `--timeout` or the worker was killed mid-job | Split the job; do not raise `--timeout` past `--max-time` |
| Emails from cron flooding the mailbox | Missing `2>&1` and `>>` redirection | Add the redirection to every entry |
| Scheduled task never fires | Task's timezone differs from what you assumed | `php artisan schedule:list` prints the resolved next-run time; §5 |
| Everything worked, then stopped | Host reset the PHP version, or the account was migrated to a new server and paths changed | Re-run §2 and §2.2 |

---

## 8 · After changing the cron entries

```sh
cd /home/u123456789/athar-app
php artisan queue:restart      # in-flight workers exit at the next job boundary
php artisan schedule:list      # confirm the schedule is what you expect
```

Record the change — date, entry, reason — in the deployment log. Cron entries are production configuration
and are not in version control; `deploy/cron-setup.txt` is the reference copy that **must** be updated in
the same commit as any change to what the platform schedules.
