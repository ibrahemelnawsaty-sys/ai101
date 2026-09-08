# public/fonts — the five font binaries, now vendored

> **`D-28` is closed.** The files that this directory used to only describe are
> now committed here. `git pull` brings them; nothing has to be fetched by hand
> on a deploy any more.

`resources/css/app.css` declares five `@font-face` rules that point at this
directory. Each file below is referenced by that exact name — renaming one
breaks a rule silently, because a missing font produces no error, only a
different-looking page.

## The files and where they came from

| File | Source | Weight |
|---|---|---|
| `ibm-plex-sans-arabic-400.woff2` | `@ibm/plex-sans-arabic@1.1.0` → `fonts/complete/woff2/IBMPlexSansArabic-Regular.woff2` | 400 |
| `ibm-plex-sans-arabic-500.woff2` | same package → `IBMPlexSansArabic-Medium.woff2` | 500 |
| `ibm-plex-sans-arabic-600.woff2` | same package → `IBMPlexSansArabic-SemiBold.woff2` | 600 |
| `ibm-plex-sans-arabic-700.woff2` | same package → `IBMPlexSansArabic-Bold.woff2` | 700 |
| `reem-kufi-variable.woff2` | `@fontsource-variable/reem-kufi@5.3.0` → `files/reem-kufi-arabic-wght-normal.woff2` | variable, 400–700 |

Both families are **SIL Open Font License 1.1**, which permits self-hosting and
redistribution inside a project. That is what settled `D-28`: the open question
was never the choice of face — `D-22` fixed that — but who supplies the binaries
and under what licence.

Total: about 316 KB, served once and cached for a year by the `Cache-Control`
rule in `deploy/.htaccess`.

## Why the `complete` build and not Google Fonts

Google serves these families split by unicode range, and **the Arabic subset
contains no Latin digits**. Article 15 requires Latin numerals throughout the
platform, so a subset build would have dropped every figure on every screen to
the fallback font — visible immediately in tables of grades and attendance.
The `complete` builds carry Arabic and Latin in one file.

`reem-kufi-variable.woff2` is the exception: no `complete` build exists for it.
It is an Arabic display face, and Latin inside a heading falls through to
`IBM Plex Sans Arabic` by the order declared in `tokens.css`. That is a
deliberate, consistent result rather than a gap.

## Two things that will break this silently

1. **`format()` in the `@font-face` rule.** The Reem Kufi rule used
   `format("woff2-variations")`, a hint dropped from the specification; a
   browser that does not know it discards the whole rule and loads nothing.
   It is now plain `format("woff2")`, which serves variable fonts correctly.

2. **The shim deployment.** `deploy/public_html-index.php` calls
   `usePublicPath(__DIR__)`, so `public_path()` is the web root, **not**
   `$APP/public`. Files here are not reachable over HTTP until they are copied
   across. `deploy/RUNBOOK-first-deploy.md` §7 does that; skipping it leaves
   these five files 404 while every other asset works, which is exactly how the
   problem presented the first time.

## Replacing a file

Keep the name. Re-download from the source in the table above, confirm the
result with `file <name>` (it must say *Web Open Font Format (Version 2)*), then
hard-reload with the cache disabled — a year-long `Cache-Control` means a stale
copy will otherwise outlive the fix.
