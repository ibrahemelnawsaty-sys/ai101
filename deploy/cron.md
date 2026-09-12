# Athar Platform — Cron on Hostinger Shared Hosting

> **Authority:** `CONSTITUTION.md` Article 10 (no daemons, one-minute cron, `database` queue),
> Article 11 (server time is the sole reference, BR-07), `PROJECT-CONTRACT.md` §11.
> **Paste-ready lines:** `deploy/cron-setup.txt`.

---

## 1 · What runs, and why exactly one application entry

Shared hosting gives no `supervisor`, no long-lived processes and no root. Everything the platform needs
to do on a schedule therefore reaches the server through **one cron entry, every minute**:

| Entry | Command | Purpose |
|---|---|---|
| 1 | `php artisan schedule:run` | The single entry point for **every** scheduled task — `routes/console.php` and `bootstrap/app.php`. Laravel decides internally which of them is due this minute. |

**The queue worker is one of those scheduled tasks**, not a cron entry of its own:
`bootstrap/app.php` schedules `queue:work --stop-when-empty …` `->everyMinute()->withoutOverlapping(5)`.
Earlier revisions of this document asked for a second, separate `queue:work` entry. The code never needed
it, and the account runs without one (D-66). Its flags live in `bootstrap/app.php` and are changed there,
in a commit — never in hPanel.

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
The flags below — exactly as `bootstrap/app.php` passes them — make the worker a well-behaved scheduled task:

| Flag | Effect | Why this value |
|---|---|---|
| `--stop-when-empty` | Exits as soon as the queue is drained | No idle process consuming the account's process allowance |
| `--max-time=50` | Exits after 50 seconds regardless | Bounded to less than the one-minute cron period, so the next minute's run finds the lock free |
| `--tries=3` | Three attempts, then `failed_jobs` | A failed email retries; it does not vanish |
| `--backoff=30` | 30 s before a retry | Avoids hammering an SMTP host that is rate-limiting us |
| `--timeout=45` | Kills a single job after 45 s | Must stay below `--max-time`; `pcntl` is unavailable, so this is enforced by the worker loop, not by a signal |
| `--sleep=0` | No pause between polls | `--stop-when-empty` exits on the first empty poll anyway |
| `--memory=180` | Restart threshold in MB | Below the 256 MB PHP limit set in `deploy/README.md` §2.4 |

> **`--timeout` note:** Laravel's per-job timeout uses `pcntl_alarm` when available. `pcntl` is absent on
> shared hosting (Article 10), so a genuinely hung job is bounded by `--max-time` instead. Long work
> (PDF generation, bulk certificate issuance, Excel export) must be written to complete well inside
> 45 seconds or be split into smaller jobs. **Do not raise these numbers to make a slow job fit.**

### 1.3 Overlap

The scheduled worker carries `->withoutOverlapping(5)`: while one worker holds the lock, the next
minute's `schedule:run` skips it rather than starting a second. The lock expires after five minutes, so a
worker killed mid-job cannot block the queue for longer than that.

Were two workers ever to run at once, it would still be safe — jobs are reserved atomically in the
database, so no job is processed twice. It would only waste a process from the account's allowance, which
is why a separate `queue:work` cron entry must not be added (§4.3).

Every other scheduled task is guarded the same way in application code, with the lock in the
`cache_locks` table. That is a code concern, not a cron concern.

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

### 4.2 Queue worker — no entry of its own

There is nothing to paste. The worker runs inside 4.1 (§1, §1.3). To confirm it is scheduled:

```sh
php artisan schedule:list | grep queue:work
```

### 4.3 What must **not** be added

- **A separate `queue:work` entry.** The scheduler already starts one every minute behind a lock; a cron
  entry beside it runs a second, unlocked worker in parallel. Safe, because reservation is atomic — and a
  second process spent every minute for nothing.

- Any `queue:work` **without** `--stop-when-empty` or `--max-time`, anywhere. That is a daemon (Article 10).
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
```

The worker writes to this same log, because it runs inside `schedule:run`. A healthy idle system prints
little or nothing here, so an empty file is not proof that cron ran. Prove it positively instead:

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
| Jobs sit in `jobs` forever | The `schedule:run` entry is missing, or a fatal before the worker loop starts | `php artisan schedule:list` must show `queue:work`; then `php artisan queue:work --once` by hand and read the output |
| Jobs sit for five minutes, then drain | A worker died holding the `withoutOverlapping(5)` lock | Expected recovery; if it recurs, read `laravel-*.log` for the fatal |
| Two workers in the process list | A separate `queue:work` cron entry left from an earlier revision of this document | Delete that entry (§4.3); the scheduled worker is enough |
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

---

## 9 · After a period without cron — backfill attendance once

The scheduled `attendance:reconcile` (every fifteen minutes) revisits only the last
`athar.attendance.reconcile_lookback_days` days — **3** by default. That bound keeps each pass short. It
also means **a session that ended more than three days before cron started working is never revisited**:
its no-shows have no `absent` row (BR-08), its check-ins without check-out never become `incomplete`
(BR-09), and every affected attendance rate — and so certificate eligibility — is computed from a record
with holes in it.

So whenever cron has been off, missing or broken — the first deployment, a host migration, a wrong PHP
path discovered late — run one pass by hand, far enough back to reach the first affected session:

```sh
cd /home/u123456789/athar-app
php artisan attendance:reconcile --days=30
```

It prints one line, for example:

```
Reconciled 3 session(s): 3 absent, 0 incomplete, 1 cohort(s), 0 enrolment(s) updated.
```

The command is idempotent: a second run over the same period converts nothing, because nothing is left to
convert. Running it with a generous `--days` is therefore safe; running it too short is the only mistake.
