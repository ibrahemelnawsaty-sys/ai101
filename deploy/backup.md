# Athar Platform — Backup, Retention and Recovery

> **Authority:** PRD §12.7 (daily database backup retained 30 days · weekly file backup · restore test at
> least once per quarter · a documented recovery plan stating maximum data loss and target restore time),
> `CONSTITUTION.md` Article 8 (`audit_logs` immutability), Article 10 (shared hosting), PRD §12.6 (PDPL).
> **Scripts:** `deploy/backup-db.sh`, `deploy/backup-files.sh`. **Cron:** `deploy/cron-setup.txt` entries 3 and 4.

---

## 1 · What is protected, and what is not

| Asset | Where it lives | Protected by | Frequency | Retention |
|---|---|---|---|---|
| All application data — users, enrolments, attendance, submissions, evaluations, certificates, **`audit_logs`** | MySQL | `deploy/backup-db.sh` | Daily | 30 days |
| `audit_logs` immutability triggers (§3.2 of the runbook) | MySQL | Same dump (`--triggers`) | Daily | 30 days |
| Participant uploads, project files, avatars, generated certificate PDFs | `storage/app/` | `deploy/backup-files.sh` | Weekly | 8 weeks |
| Application code | Git repository | Version control + `$HOME/releases/*.tar.gz` | Every deploy | Last 5 releases |
| `.env` and `APP_KEY` | `$APP/.env` | **Password manager, by hand** | On every change | Indefinite |
| Vite build output, `vendor/`, caches, sessions, logs | Regenerable | Not backed up | — | — |

### 1.1 `APP_KEY` is part of the backup, and it is not in either script

Encrypted columns, signed URLs (certificate/card verification, temporary file links) and session payloads
are all derived from `APP_KEY`. **A database dump restored without the matching `APP_KEY` is partly
unreadable.** The key is never written to a backup file on the server, because that would place the
plaintext key beside the data it protects.

Store `APP_KEY`, the database credentials and the mail credentials in the team password manager, and record
the date each was last rotated. Verify at each quarterly drill (§5) that the stored key still matches the
one in production:

```sh
grep '^APP_KEY=' "$HOME/athar-app/.env"
```

### 1.2 Backups contain personal data

Dumps and file archives contain participants' full names, email addresses, phone numbers and submitted
work. Under PRD §12.6 and the Saudi PDPL they are personal data at rest:

- both scripts write with `umask 077` and `chmod 600`;
- `$HOME/backups` is `chmod 700`;
- copies taken off the host must be encrypted in transit **and** at rest;
- backups are **not** shared over email, chat or a public link, ever;
- the retention periods below are the ceiling, not a starting point — they interact with **`D-12` (data
  retention policy)**, which is still `مفتوح`. When `D-12` is approved, re-check that 30 days / 8 weeks
  is still compatible with it.

If you take an off-host copy manually, encrypt it first:

```sh
gpg --symmetric --cipher-algo AES256 athar-db-20261012-001500.sql.gz
# Store the passphrase in the password manager, never with the file.
```

---

## 2 · Daily database backup

**Cron:** entry 3 of `deploy/cron-setup.txt` — `15 0 * * *` (00:15 UTC = **03:15 Asia/Riyadh**).
Riyadh is a constant UTC+3 with no daylight saving, so this schedule never needs a seasonal correction.
**Confirm which timezone cron uses on the account before trusting the hour** (`date; date -u`).

### 2.1 Install

```sh
chmod 700 "$HOME/athar-app/deploy/backup-db.sh"
mkdir -p "$HOME/backups/db" && chmod 700 "$HOME/backups"

# MySQL option file, so the password is never visible in the process list
umask 077
cat > "$HOME/.my.athar.cnf" <<'EOF'
[client]
host=127.0.0.1
user=REPLACE_WITH_DB_USER
password=REPLACE_WITH_DB_PASSWORD
EOF
chmod 600 "$HOME/.my.athar.cnf"

# First run, by hand, watching the output
sh "$HOME/athar-app/deploy/backup-db.sh"
```

Expected output ends with `ok: athar-<db>-<stamp>.sql.gz (<n> bytes)` followed by `done. 1 backup(s) retained.`
Anything else is a failure — read the `FAILED:` line, fix it, and run again. Do not add the cron entry until
a manual run succeeds.

### 2.2 What the script guarantees

It refuses to keep a file unless **all four** of these hold:

1. `mysqldump` exited zero;
2. the gzip stream passes `gzip -t`;
3. the decompressed dump ends with mysqldump's `Dump completed` marker — this is what catches a dump that
   was cut short by a disk-full or a killed process while still exiting zero;
4. the dump contains at least one `CREATE TABLE`.

It also refuses to start with less than 200 MB free, and it never prunes below 7 retained backups even if
every file is older than the retention window. A run of failures must not leave the account with nothing.

`--routines --triggers --events` is deliberate: the `audit_logs` `BEFORE UPDATE` / `BEFORE DELETE` triggers
(runbook §3.2, Option B) are the database-level half of Article 8. A dump taken without them restores a
database in which the audit log is silently mutable.

### 2.3 Verifying a dump without restoring it

```sh
LATEST="$(ls -1t "$HOME/backups/db"/athar-*.sql.gz | head -n 1)"

gzip -t "$LATEST" && echo "gzip ok"
gzip -cd "$LATEST" | grep -c 'CREATE TABLE'                    # table count
gzip -cd "$LATEST" | grep -c "INSERT INTO \`audit_logs\`"      # audit rows present
gzip -cd "$LATEST" | grep -c 'CREATE.*TRIGGER'                 # expect >= 2
gzip -cd "$LATEST" | tail -n 3                                 # 'Dump completed' marker
( cd "$HOME/backups/db" && sha256sum -c --ignore-missing SHA256SUMS 2>&1 | tail -n 5 )
```

Run this after any deployment that changed the schema.

---

## 3 · Weekly file backup

**Cron:** entry 4 of `deploy/cron-setup.txt` — `45 0 * * 5` (Friday 00:45 UTC = Friday **03:45 Riyadh**),
the lowest-traffic window of the Saudi week.

```sh
chmod 700 "$HOME/athar-app/deploy/backup-files.sh"
mkdir -p "$HOME/backups/files"
sh "$HOME/athar-app/deploy/backup-files.sh"      # first run by hand
```

Covers `storage/app/` in full — `private/` (participant uploads, never web-served) and `public/`.
Excludes framework caches, sessions and logs: all regenerable, and sessions live in the database.

**Weekly is what PRD §12.7 requires, and it is worth naming what that means:** up to seven days of uploaded
submissions can be lost. During the four weeks a cohort is actually running, that is a real exposure — a
week of a cohort's assignment submissions. Two mitigations, in order:

1. Raise the file backup to daily during a live cohort (change entry 4 to `45 0 * * *`) and drop back
   afterwards. The archive is small while the cohort is small; check the account's disk headroom first.
2. Take a manual archive immediately after each assignment deadline passes:
   `sh "$HOME/athar-app/deploy/backup-files.sh"`.

Option 1 is recommended and costs nothing but disk. It exceeds the PRD minimum; it does not contradict it.

---

## 4 · Off-host copies — the part that makes this a backup

> **A copy stored on the same hosting account is not a backup.** It shares the account's fate: a compromised
> account, an accidental `rm -rf`, a billing suspension, or a host-side storage failure takes both the
> platform and its "backups" at once.

Everything above protects against *application-level* loss — a bad migration, a bad deploy, a deleted row.
It does not protect against loss of the account itself. That requires a copy somewhere else, and the
destination is an open decision — **`D-09`**.

Until `D-09` is approved, the following manual step is performed **weekly** by a named person, and its
absence must be reported honestly rather than glossed over:

```sh
# From an operator machine, not from the server:
rsync -avz --progress -e 'ssh -p <ssh-port>' \
  '<user>@<host>:~/backups/db/'    ./athar-offhost/db/

rsync -avz --progress -e 'ssh -p <ssh-port>' \
  '<user>@<host>:~/backups/files/' ./athar-offhost/files/

# Then verify the copies independently of the server:
( cd ./athar-offhost/db && sha256sum -c --ignore-missing SHA256SUMS )
```

Hostinger's own panel backups (hPanel → Files → Backups) are a useful extra layer but **do not satisfy this
requirement**: they live inside the same account, their retention and frequency are set by the host and can
change without notice, and they cannot be verified by us. Treat them as a convenience, never as the plan.

---

## 5 · Quarterly restore drill

PRD §12.7 requires a restore test **at least once per quarter**. A backup that has never been restored is
an assumption, not a backup (Article 30: "it should work" is not an answer).

### 5.1 Rules

- The drill restores into the **staging** database, never production.
- The drill uses a real dump taken by cron, chosen at random from the retained set — **not** a fresh dump
  taken for the occasion. The point is to test the pipeline that actually runs.
- The drill is timed. The elapsed time is the measured RTO and it goes in the log.
- The drill is logged in §5.4 **whether it passed or failed**. A failed drill that gets fixed and re-run is
  a healthy record; a missing quarter is not.

### 5.2 Procedure

```sh
# 0. Start the clock.
date -u '+%Y-%m-%dT%H:%M:%SZ'

# 1. Pick a dump at random from the retained set.
LATEST="$(ls -1 "$HOME/backups/db"/athar-*.sql.gz | shuf -n 1)"
echo "drilling with: $LATEST"

# 2. Verify integrity before restoring.
gzip -t "$LATEST" && gzip -cd "$LATEST" | tail -n 3 | grep -q 'Dump completed' && echo "integrity ok"

# 3. Create a scratch database in hPanel, e.g. <prefix>_athar_drill, then:
gzip -cd "$LATEST" | mysql --defaults-extra-file="$HOME/.my.athar.cnf" '<prefix>_athar_drill'

# 4. Restore the matching week's file archive to a scratch directory.
FILES="$(ls -1t "$HOME/backups/files"/athar-files-*.tar.gz | head -n 1)"
mkdir -p "$HOME/drill/storage" && tar -xzf "$FILES" -C "$HOME/drill/storage"

# 5. Point the STAGING .env at the drill database, then:
cd "$HOME/staging-app"
php artisan config:clear && php artisan config:cache
php artisan migrate:status          # every migration must show as Ran
php artisan about

# 6. Stop the clock.
date -u '+%Y-%m-%dT%H:%M:%SZ'
```

### 5.3 Acceptance checklist — every line must pass

- [ ] The dump passed `gzip -t` and carries the `Dump completed` marker.
- [ ] The SHA-256 in `SHA256SUMS` matches the file.
- [ ] Import completed with no errors.
- [ ] `php artisan migrate:status` shows every migration as `Ran` — no pending, no missing.
- [ ] Row counts are plausible: `users`, `enrollments`, `attendances`, `submissions`, `evaluations`,
      `certificates`, `audit_logs` all non-zero and within ~1 day of production's counts.
- [ ] **Arabic text is intact** — `SELECT full_name_ar FROM profiles LIMIT 5;` shows correct connected
      Arabic, not `????` or mojibake. This is what a wrong `--default-character-set` looks like, and it is
      invisible until a restore.
- [ ] The `audit_logs` triggers survived the restore:
      `SHOW TRIGGERS FROM \`<prefix>_athar_drill\` LIKE 'audit_logs';` returns two rows, and
      `UPDATE audit_logs SET action='x' LIMIT 1;` fails with `ERROR 1644`.
- [ ] Staging boots against the restored database, the landing page renders, and a seeded account logs in.
- [ ] A restored certificate PDF opens and its verification code resolves on `/certificate/verify/{code}`.
- [ ] A restored uploaded file downloads through the signed-URL route and is byte-identical to the original.
- [ ] The `APP_KEY` in the password manager still matches production's.
- [ ] Elapsed time recorded, and it is within the target RTO.

### 5.4 Drill log — fill in, do not delete rows

| Date (UTC) | Performed by | Dump used | DB restore ok | Files restore ok | Arabic intact | Triggers intact | Elapsed | Findings |
|---|---|---|---|---|---|---|---|---|
| *(first drill due within one quarter of go-live)* | | | | | | | | |

---

## 6 · Recovery objectives — `D-09`, awaiting approval

PRD §12.7 requires a documented plan that **states** the maximum tolerable data loss and the target restore
time. The PRD does not supply the numbers. Under Article 4 they are not invented silently; the values below
are a **recommendation pending approval**, recorded as `D-09` in `docs/03-decisions/DECISIONS.md`.

| Objective | Proposed | Follows from | Consequence if accepted |
|---|---|---|---|
| **RPO — database** | **24 hours** | One dump per day (PRD §12.7 minimum) | Up to a day of attendance records, submissions and evaluations lost. During a live session this is the difference between a participant holding a certificate and not. |
| **RPO — uploaded files** | **7 days**, reduced to **24 hours** while a cohort is running (§3) | Weekly archive (PRD §12.7 minimum) | Up to a week of submitted work lost outside a live cohort |
| **RTO — full platform** | **4 hours** from decision-to-restore to service restored | Manual restore on shared hosting: no automation, no standby environment | Participants see the maintenance page for up to 4 hours |
| **RTO — read-only status page** | **30 minutes** | A static maintenance page can be published quickly | Participants are informed, not left guessing |
| **Maximum tolerable outage** | **24 hours** | Programme is four weeks long; a full day is one session | Beyond this, sessions must be rescheduled and participants notified |

**If a 24-hour RPO is not acceptable for attendance data**, the options, in increasing cost, are: a second
daily dump at midday (RPO 12 h, one extra cron line, no other change); hourly dumps of the attendance and
evaluation tables only (RPO 1 h, a new script, more disk); or MySQL binary-log point-in-time recovery
(RPO minutes — **not available on shared hosting**, requires moving off it entirely).

The middle option is achievable within Article 10 and is the recommended answer if the 24-hour figure is
rejected. **Do not implement it before `D-09` is approved.**

---

## 7 · Recovery runbooks

### 7.1 Single accidental deletion (most common)

Do **not** restore the whole database. Restore the affected table into a scratch database and copy the rows
back deliberately.

```sh
gzip -cd "$HOME/backups/db/<dump>.sql.gz" \
  | sed -n '/^-- Table structure for table `submissions`/,/^-- Table structure for table `/p' \
  > /tmp/submissions.sql
# Review /tmp/submissions.sql, import into a scratch DB, then copy the specific rows across.
```

Never `mysql production < full-dump.sql` to recover one row. That silently reverts every other table.

### 7.2 Bad migration

See `deploy/README.md` §14 (Rollback). Restore code first; restore the database **only** if the migration
was destructive. If you are not certain it was destructive, keep the platform in maintenance mode and
escalate — Article 7, fail safe.

### 7.3 Total loss of the hosting account

1. Provision a new account, complete `deploy/README.md` §§1–11 from scratch.
2. Restore the newest verified off-host database dump (§4).
3. Restore the newest off-host file archive into `storage/app/`.
4. Put `APP_KEY` and the credentials back from the password manager **before** first boot.
5. Re-point DNS, re-issue SSL, re-create the cron entries.
6. Run the §5.3 acceptance checklist in full before letting participants back in.
7. Write the incident up: what was lost, over what window, and who must be told. Under PDPL, a personal-data
   loss may carry a notification duty — that is a decision for the centre and its counsel, not for the
   deploying developer to judge alone.

### 7.4 Suspected tampering with `audit_logs`

Article 8 makes the audit log the record of last resort, so a suspicion here is an incident, not a task.

```sh
# Compare row counts across retained dumps: the count must only ever increase.
for f in "$HOME/backups/db"/athar-*.sql.gz; do
  printf '%s %s\n' "$(basename "$f")" "$(gzip -cd "$f" | grep -c "INSERT INTO \`audit_logs\`")"
done
```

A decrease between two consecutive daily dumps means rows were removed. Preserve every dump involved, do
not run any further writes against production, and escalate immediately.

---

## 8 · Monthly operator checklist

Ten minutes, once a month. Until `D-04` (error monitoring) is decided, this is the only routine health
check that exists — say so plainly in any handover rather than implying the platform is monitored.

- [ ] `ls -lt "$HOME/backups/db" | head` — a dump exists for **every** day of the last 30.
- [ ] `ls -lt "$HOME/backups/files" | head` — an archive exists for every week of the last 8.
- [ ] `grep -c FAILED "$HOME/backups/backup-db.log"` — zero since the last check.
- [ ] `df -h "$HOME"` — the account is not approaching its disk or inode ceiling (see **`D-05`**).
- [ ] Off-host copies (§4) are current.
- [ ] `SHOW TRIGGERS … LIKE 'audit_logs';` still returns two rows on production.
- [ ] `php artisan queue:failed` is empty.
- [ ] The `SHA256SUMS` file verifies for the newest three dumps.
- [ ] The quarterly drill (§5) is not overdue.
