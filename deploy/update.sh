#!/usr/bin/env bash
#
# One command for every deployment after the first.
#
# WHY THIS FILE EXISTS
# The application lives OUTSIDE the document root, which is the only safe place
# for it: `.env`, `storage/`, `database/` and `vendor/` must never be reachable
# over HTTP. The consequence is that the built assets exist TWICE — once in
# `$APP/public/build`, which `git pull` updates, and once in `$WEB/build`, which
# it does not.
#
# So a deployment that is only `git pull` updates the code and leaves the browser
# loading yesterday's CSS and JavaScript. It looks like the fix did not work. It
# cost a full round of "still broken" on a defect that had already been fixed,
# measured and pushed — the owner was looking at `app-C86ZY99p.css` while
# `app-C9CjsqND.css` sat unread in the application directory.
#
# `--delete` is not optional: without it the old hashed files pile up in `$WEB`
# forever, and the next person greps the directory and finds two answers.
#
# Nothing here is destructive to data. It touches code, assets and caches only.
#
# @see deploy/README.md §"Option B — the shim in public_html" · D-64

set -euo pipefail

APP="${APP:-$HOME/athar-app}"
WEB="${WEB:-$HOME/domains/wareed.vip/public_html/AI}"

[ -f "$APP/artisan" ] || { echo "No artisan in $APP — set APP=… and run again." >&2; exit 1; }
[ -d "$WEB" ]         || { echo "No document root at $WEB — set WEB=… and run again." >&2; exit 1; }

echo "APP  $APP"
echo "WEB  $WEB"
echo

cd "$APP"

# The server must be on a branch, not a detached HEAD; a detached checkout makes
# `git pull` refuse with "You are not currently on a branch".
git fetch origin
git checkout -B main origin/main
git pull --ff-only

# Schema first: a request that reaches a column the code expects and the table
# does not have is a 500 on every page.
php artisan migrate --force

# Every enrolled account's conversations — the announcement channel, the cohort
# group and the trainer DMs (D-82). Idempotent: on a deploy that adds nobody it
# changes nothing, so it is safe to run every time.
php artisan athar:provision-messages

# THE STEP THAT IS ALWAYS FORGOTTEN.
rsync -a --delete "$APP/public/build/" "$WEB/build/"
rsync -a --delete "$APP/public/fonts/"  "$WEB/fonts/"  2>/dev/null || true
rsync -a --delete "$APP/public/brand/"  "$WEB/brand/"  2>/dev/null || true
rsync -a --delete "$APP/public/images/" "$WEB/images/" 2>/dev/null || true
cp "$APP/public/favicon.ico" "$WEB/" 2>/dev/null || true
cp "$APP/public/robots.txt"  "$WEB/" 2>/dev/null || true

# Compiled Blade holds the OLD markup until it is cleared, so a template change
# — a removed attribute, a renamed variable — keeps behaving the old way.
php artisan optimize:clear

echo
echo "Deployed $(git log --oneline -1)"
echo
echo "One manifest, one answer:"
ls -1 "$WEB"/build/assets/app-*.css
