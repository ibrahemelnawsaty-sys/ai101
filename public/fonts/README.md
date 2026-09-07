# public/fonts — the five font binaries this directory must hold

> **Open decision: `D-28`** in `docs/03-decisions/DECISIONS.md` — who supplies these files,
> at which weights, and with which subsets. The faces themselves were settled by `D-22`.
> Until `D-28` is approved, Constitution art. 19 (self-hosted fonts, `font-display: swap`)
> is **not** claimed as met.

`resources/css/app.css` declares five `@font-face` rules that point here. The
binaries are **not** in this repository (they are third-party artefacts, and the
build must not fetch them from a CDN — Constitution art. 19 requires self-hosted
fonts). Until they are copied in, every rule fails to load and the browser falls
back to the rest of the stack declared in `resources/css/tokens.css`:

```
--font          "IBM Plex Sans Arabic", "Segoe UI", Tahoma, sans-serif
--font-display  "Reem Kufi", "IBM Plex Sans Arabic", "Segoe UI", Tahoma, sans-serif
```

Segoe UI and Tahoma both carry a full Arabic set, so the platform is legible and
correct with this directory empty. What is lost is the approved typographic
identity, not the content. **This is a known open item, not a finished state.**

## The files, byte-for-byte as `app.css` names them

| File name (exact) | Family | Weight | Used by |
|---|---|---|---|
| `ibm-plex-sans-arabic-400.woff2` | IBM Plex Sans Arabic | 400 Regular | body copy everywhere |
| `ibm-plex-sans-arabic-500.woff2` | IBM Plex Sans Arabic | 500 Medium | labels, table headers |
| `ibm-plex-sans-arabic-600.woff2` | IBM Plex Sans Arabic | 600 SemiBold | headings, buttons |
| `ibm-plex-sans-arabic-700.woff2` | IBM Plex Sans Arabic | 700 Bold | figures, emphasis |
| `reem-kufi-variable.woff2` | Reem Kufi | variable 400–700 | marketing headings only (`--font-display`) |

A name that does not match this table exactly will not be found: the URLs are
literal in `app.css`, and a shared host serves this directory as static files
with no rewriting.

## Where to obtain them

Both families are **SIL Open Font License 1.1**, which permits redistribution
inside this repository and on the production host.

* **IBM Plex Sans Arabic** — <https://github.com/IBM/plex> (`IBM-Plex-Sans-Arabic/fonts/complete/woff2/`).
  Also on Google Fonts: <https://fonts.google.com/specimen/IBM+Plex+Sans+Arabic>.
* **Reem Kufi** — <https://github.com/aliftype/reem-kufi> (release assets), or
  <https://fonts.google.com/specimen/Reem+Kufi>.

Download the **Arabic + Latin** subset, not the full family: the pages carry
Arabic prose with Latin numerals and the occasional Latin word (email, GitHub),
so the subset must include `U+0600–06FF`, `U+0750–077F`, `U+FB50–FDFF`,
`U+FE70–FEFF` and Basic Latin. Arabic-Indic digits (`U+0660–0669`) are **not**
needed: the platform prints Latin numerals everywhere (art. 15).

If a download arrives as `.ttf` or `.otf`, convert it before committing:

```bash
pip install fonttools brotli
fonttools ttLib.woff2 compress -o ibm-plex-sans-arabic-400.woff2 IBMPlexSansArabic-Regular.ttf
```

For Reem Kufi keep the **variable** file (one axis, `wght` 400–700) — `app.css`
declares `format("woff2-variations")` and `font-weight: 400 700` for it.

## After the files are in place

1. `npm run build` — Vite fingerprints the stylesheet; the font URLs stay
   absolute (`/fonts/...`) and are served straight from this directory.
2. Load any page and confirm in DevTools → Network that all five requests answer
   `200`, not `404`.
3. Consider adding `size-adjust` / `ascent-override` to each `@font-face` once
   the real metrics can be measured against Segoe UI, to remove the swap-time
   layout shift (Constitution art. 19, CLS < 0.1). Those numbers are deliberately
   **not** guessed in `app.css` today.

## What must never happen here

* No `@import` from Google Fonts or any other CDN — art. 19 and the CSP.
* No yellow or gold anywhere in a colour font or a bitmap glyph — art. 14.
* No font file outside this table; an unused binary is dead weight on a shared
  host.
