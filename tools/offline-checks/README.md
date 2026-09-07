# Offline checks

These run **without `vendor/`, without a database, and without a web server**. They exist
because the machine this project was authored on could not complete `composer install`
(a TLS-inspecting proxy blocks Composer's archive downloads — see `docs/03-decisions/DECISIONS.md`),
so the real suite has never been executed there.

They are a **stopgap, not a substitute**. Once `composer install` succeeds anywhere, the
authority is `composer run gates` — Pest, PHPStan level 8, Pint and `G1`–`G12`. Delete nothing
here, but never report these as evidence that the platform works. They check a handful of
things very precisely; the suite checks everything.

## Running them

Any PHP 8.2+ binary. From the project root:

```
php tools/offline-checks/attendance-boundaries.php
php tools/offline-checks/riyadh-formatting.php
php tools/offline-checks/seed-and-lang-integrity.php
node tools/offline-checks/cross-reference.mjs
node tools/offline-checks/view-model-inventory.mjs
```

Each PHP script exits non-zero on failure, so they can be chained in CI.

`attendance-boundaries.php` and `riyadh-formatting.php` need `vendor/nesbot/carbon` only.
They stand in for the collaborators they do not have — `App\Models\Session` is redeclared as a
plain object exposing `getAttribute()`, `__()` reads the project's own `lang/ar/*.php`, and the
two `ClockInterface`s Carbon type-hints are stubbed. **The classes under test are the project's
own files, unmodified.**

## What each one covers

| script | covers |
|---|---|
| `attendance-boundaries.php` | The full `PROJECT-CONTRACT` §6 table at ±1 second around every edge, plus a cancelled session refusing both actions. `BR-01`–`BR-04`, `BR-07`. |
| `riyadh-formatting.php` | Arabic date wording, 12-hour clock with صباحًا/مساءً including the 12:00 and 00:00 traps, UTC→Riyadh display, and that no Arabic-Indic digit or unresolved lang key ever reaches the screen. Constitution art. 11, art. 15. |
| `seed-and-lang-integrity.php` | Every seeded `unlock_rule` is a value `JourneyEvaluator` actually recognises (`BR-20`, `BR-21`), and every `trans_choice` string in `lang/ar` parses under Laravel's choice syntax. |
| `cross-reference.mjs` | Every `<x-…>` component, `route('…')` name, `__('…')` key and `use App\…` import resolves to something that exists. |
| `view-model-inventory.mjs` | Regenerates `docs/04-design/VIEW-MODEL-INVENTORY.txt` — every `$var->property` the Blade layer dereferences. Run it after changing a view; anything it lists must be published by a presenter. |

## What they deliberately do not cover

Authorization, tenancy scoping, impersonation read-only enforcement, grade recording,
certificate eligibility and the queue and cron paths all need the database and the framework.
`tests/` covers them; these do not.
