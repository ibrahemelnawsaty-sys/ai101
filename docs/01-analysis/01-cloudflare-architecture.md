# 01 — Cloudflare Architecture
## Definitive platform mapping for the Athar Training Platform on Cloudflare Workers & Pages

| | |
|---|---|
| **Project** | منصة البرامج التدريبية — مركز أثر للتدريب |
| **Program** | البرنامج التأسيسي في الذكاء الاصطناعي (AI 101) |
| **Platform domain** | `ai.athar-dev.edu.sa` |
| **Sending identity** | `info@athar-dev.edu.sa` (DECISIONS C-03) |
| **Hosting mandate** | **Cloudflare Workers & Pages** (DECISIONS C-01 — non-negotiable) |
| **PRD sections mapped** | §6 المعمارية التقنية · §7 نموذج البيانات · §12 الأمان · §13 الأداء · §15 النشر والتشغيل |
| **Verification date** | **2026-09-04** — every price, limit and version below was fetched live from the vendor's own documentation or the npm registry on this date. |
| **Status** | Analysis. Binding under Constitution Art. 1 level 4, subordinate to `DECISIONS.md`. |

> **Language convention.** This document is written in English because it is engineering reference material for the build team. Arabic user-facing copy is not defined here — see `05-email-and-integrations.md` and `locales/ar.json`.

> **Honesty clause (Constitution Art. 4).** Every non-obvious factual claim carries a source URL. Where Cloudflare's own documentation is ambiguous, stale, or silent, this document says so explicitly instead of guessing. Claims that could not be verified are collected in §13 *Unverified & ambiguous*.

---

> ## ⚠️ تصحيح ملزم بعد قياس مباشر — 2026-09-04
>
> أُجري استعلام DNS فعلي على `athar-dev.edu.sa` بعد إنجاز هذا التقرير. النتيجة تُسقِط أكبر مخاطره:
>
> **NS = `jermaine.ns.cloudflare.com` · `molly.ns.cloudflare.com`** — النطاق **بالفعل منطقة Cloudflare كاملة (Full zone)**.
>
> وعليه يسقط من هذا التقرير:
> - العلم الأحمر #1 (DNS معطّل للإطلاق) — **منتفٍ**
> - خطة Business بـ 200–250$ شهريًا — **غير مطلوبة**
> - تعقيد Cloudflare for SaaS + originless fallback — **غير مطلوب**
> - عدم توفر Cloudflare Email Service — **متوفرة** (شرطها محقّق)
>
> الخيار ① (Full setup على منطقة مجانية) هو **الواقع القائم**، لا خيارًا يُناقش. ربط `ai.athar-dev.edu.sa` يتم بـ **Custom Domain** مباشرة، بلا كلفة إضافية.
>
> **واكتُشف أيضًا:** لا يوجد سجل SPF على النطاق إطلاقًا — ثغرة قائمة في بريد المركز **اليوم**.
>
> التفاصيل وطريقة إعادة التحقق: [`00-verified-infrastructure-facts.md`](./00-verified-infrastructure-facts.md) — **وهي الحاكمة عند أي تعارض**، لأنها قياس لا تقدير.

---

## 0. Executive Verdict

| # | Component | Chosen technology | Confidence | Primary source |
|---|---|---|---|---|
| 1 | **Next.js adapter** | `@opennextjs/cloudflare@1.20.6` + Next.js `16.3.4`, deployed as a **Worker with Static Assets** (not Pages) | **High** — but note Cloudflare officially recommends `vinext` instead; see §1 | https://opennext.js.org/cloudflare · https://registry.npmjs.org/@opennextjs/cloudflare |
| 2 | **Database** | **PostgreSQL on Neon (Launch, `aws-eu-central-1`) via Cloudflare Hyperdrive** | **High** | https://developers.cloudflare.com/hyperdrive/ · https://neon.com/pricing |
| 2b | **ORM** | **Drizzle ORM `0.45.2` + `pg` `8.23.0`** (runner-up: Prisma 7 + `@prisma/adapter-pg`) | **Medium-High** — deviates from PRD §6.1's Prisma proposal; needs approval (new decision **D-19**) | https://developers.cloudflare.com/hyperdrive/examples/connect-to-postgres/postgres-drivers-and-libraries/drizzle-orm/ |
| 3 | **Auth / sessions** | **Better Auth `1.7.2`** with sessions as **Postgres rows** (never KV) | **High** — Auth.js is *architecturally disqualified*, see §3 | https://better-auth.com/docs/concepts/session-management · https://github.com/nextauthjs/next-auth/blob/main/packages/core/src/lib/utils/assert.ts |
| 4 | **Password hashing** | **`node:crypto.scrypt`, N=32768, r=8, p=3, dkLen=64** (argon2id via WASM = runner-up) | **High** | https://developers.cloudflare.com/workers/runtime-apis/nodejs/crypto/ · https://github.com/cloudflare/workerd/blob/main/src/workerd/io/limit-enforcer.h |
| 5 | **File storage** | **Cloudflare R2**, private bucket, **presigned PUT** signed with `aws4fetch` (15-min expiry) + MIME sniffing | **High** | https://developers.cloudflare.com/r2/api/s3/presigned-urls/ |
| 6 | **Email** | **Resend** (primary) → **Cloudflare Email Service** (conditional fallback) | **High** — raw SMTP from a Worker is possible on 465/587 but is the wrong tool | https://developers.cloudflare.com/workers/runtime-apis/tcp-sockets/ · https://developers.cloudflare.com/email-service/platform/pricing/ |
| 7 | **Realtime** | **Durable Objects + WebSocket Hibernation API**, wired through a **custom Worker entrypoint** | **High** on the platform; **Medium** on the OpenNext integration (spike required) | https://developers.cloudflare.com/durable-objects/best-practices/websockets/ · https://opennext.js.org/cloudflare/howtos/custom-worker |
| 8 | **Scheduled jobs** | **Cron Triggers**, `*/15 * * * *`, in the same custom Worker entrypoint | **High** | https://developers.cloudflare.com/workers/configuration/cron-triggers/ |
| 9 | **PDF + PNG generation** | **Cloudflare Browser Rendering ("Browser Run") REST `/pdf` and `/screenshot`** with an embedded Arabic web font | **High** — this is the *only* option that shapes Arabic correctly | https://developers.cloudflare.com/browser-rendering/rest-api/pdf-endpoint/ |
| 9b | **QR generation** | **`uqr@0.1.3`** → SVG, embedded in the HTML before rendering | **High** | https://registry.npmjs.org/uqr |
| 10 | **Rate limiting** | **Three layers**: WAF rule (IP flood) → Workers Rate Limiting binding (cheap per-user) → **Durable Object counters** (accurate, where it matters) | **High** | https://developers.cloudflare.com/workers/runtime-apis/bindings/rate-limit/ |
| 11 | **CAPTCHA** | **Turnstile, Invisible mode** — free, unlimited verifications | **High** | https://developers.cloudflare.com/turnstile/plans/ |
| 12 | **Avatars (256/64px)** | **Cloudflare Images binding** (`env.IMAGES`) reading from R2 | **High** | https://developers.cloudflare.com/images/optimization/binding/ |
| 13 | **Secrets** | **`wrangler secret put`** per environment (Secrets Store is open beta — not yet) | **High** | https://developers.cloudflare.com/secrets-store/ |
| 14 | **Observability** | **Workers Logs** (native) + **Sentry via Cloudflare's OTel export** (no SDK in bundle) | **Medium-High** | https://developers.cloudflare.com/workers/observability/exporting-opentelemetry-data/sentry/ |
| 15 | **CI/CD** | **Workers Builds** (GA) — Git-connected, 3,000 free build-min/mo | **High** | https://developers.cloudflare.com/workers/ci-cd/builds/limits-and-pricing/ |
| 16 | **DNS for `ai.athar-dev.edu.sa`** | **Full setup on Free zone** if NS can move; otherwise **Cloudflare for SaaS custom hostname on a Free zone** | **High** — see §11.7, this is the biggest operational risk in the whole document | https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/plans/ |
| 17 | **Plan** | **Workers Paid ($5/mo) is mandatory.** Free plan is not viable (10 ms CPU, 3 MB bundle). | **Certain** | https://developers.cloudflare.com/workers/platform/limits/ |

**Estimated all-in monthly cost at 60–200 active users: `$30 – $50 USD/month`.** Full breakdown in §12.

---

## 1. Next.js on Cloudflare

### 1.1 The landscape changed in 2026 — read this first

There are now **three** ways to run Next.js on Cloudflare, and Cloudflare's official recommendation is **not** the one most teams expect.

| Adapter | Status today (2026-09-04) | Verdict for this project |
|---|---|---|
| **`vinext`** | Cloudflare's **officially recommended default**. A Vite plugin that *re-implements* the Next.js API from scratch. `vinext@1.0.0-beta.9`, `@vinext/cloudflare@1.0.0-beta.7`. **Beta.** | ❌ **Not for v1** |
| **`@opennextjs/cloudflare`** | `1.20.6`, published 2026-09-02. Actively released (7 publishes since June 2026). Demoted by Cloudflare to "maintain an existing app". | ✅ **CHOSEN** |
| **`@cloudflare/next-on-pages`** | **Archived 2025-09-29. Deprecated. Read-only repo.** | ❌ **Never** |

Cloudflare's Next.js framework guide states verbatim:

> "Cloudflare recommends [vinext](https://vinext.dev/) as the default way to run Next.js applications on Cloudflare Workers."
> "vinext is in beta. Before adopting it for an existing production application, run the compatibility check from your project directory and review the vinext compatibility dashboard."

— https://developers.cloudflare.com/workers/framework-guides/web-apps/nextjs/ (page last updated 2026-08-25)

The OpenNext guide page now carries a demotion banner and even sets `chatbot_deprioritize: true` in its frontmatter. `npm create cloudflare@latest -- --framework=next` scaffolds **vinext**, not OpenNext.

And `next-on-pages`' own README says:

> "The next-on-pages package is deprecated, if you want to deploy a Next.js application on Cloudflare, please use the [OpenNext Cloudflare adapter](https://opennext.js.org/cloudflare) instead."

— https://raw.githubusercontent.com/cloudflare/next-on-pages/main/README.md (repo archived; GitHub API `"archived": true`, last push 2025-09-29)

### 1.2 Recommendation: `@opennextjs/cloudflare@1.20.6` — and why *not* vinext

**I am deliberately recommending against Cloudflare's own recommendation.** The reason is stated by vinext itself:

> "Under active development. vinext supports substantial Next.js applications today, but it is **not yet a drop-in replacement for every application or production workload**." … "Expect compatibility gaps, especially in newer App Router features, and evaluate it against your own application before adopting it."

— https://github.com/cloudflare/vinext (README)

Supporting facts:
- vinext's compatibility dashboard reports **95.8% overall pass rate** against Next.js v16.2.6 (https://vinext.dev/compatibility). A 4.2% gap in an unknown place is not an acceptable risk profile for a platform that issues accredited certificates.
- The repo has **476 open issues** roughly six months after its 2026-02-24 launch (https://github.com/cloudflare/vinext).
- The launch blog itself said it was **"not even one week old, and it has not yet been battle-tested with any meaningful traffic at scale"** (https://blog.cloudflare.com/vinext/).
- vinext is still `1.0.0-beta.9` — it has not reached 1.0.

Against that, OpenNext is a known quantity with a documented escape hatch (§7, §8) that this project *structurally requires*.

**Runner-up:** `vinext`. Re-evaluate at 1.0 GA, or immediately if OpenNext blocks on something. vinext *does* support a custom Worker entrypoint — Cloudflare's own `vinext-agents-example` wires WebSockets and Durable Objects exactly the way this document does for OpenNext (`import handler from "vinext/server/app-router-entry"`, https://github.com/cloudflare/vinext-agents-example). So the migration path is real, just not yet safe.

**Note on the "Workers & Pages" mandate.** Cloudflare Pages is frozen: *"Cloudflare Pages will continue to be supported, but, going forward, all of our investment, optimizations, and feature work will be dedicated to improving Workers"* (https://blog.cloudflare.com/full-stack-development-on-cloudflare-workers/). The product surface in the Cloudflare dashboard is literally named **"Workers & Pages"**, so deploying a **Worker with Static Assets** fully satisfies decision C-01. Do **not** deploy to Pages.

### 1.3 Version matrix — verified against the npm registry

`@opennextjs/cloudflare@1.20.6` declares these **peerDependencies** (fetched from `https://registry.npmjs.org/@opennextjs%2Fcloudflare/1.20.6`):

```json
{ "next": ">=15.5.24 <16 || >=16.3.3", "wrangler": "^4.125.0", "rclone.js": "^0.6.6" }
```

Read that carefully:
- **Next.js 14 is excluded entirely.** The OpenNext docs still say *"the latest minors of Next.js 14 and 15 are supported. Next.js 14 support will be dropped Q1 2026"* — **that prose is stale**; the enforced peer range already blocks 14.
- **Next.js 16.0.0 through 16.3.2 are excluded.** Only `>=16.3.3` qualifies.
- **Docs/package.json conflict on wrangler.** The OpenNext get-started page says *"You must use Wrangler version `3.99.0` or later"*; the published peer dep says `^4.125.0`. **Trust the peer dep** — npm enforces it. Current wrangler `latest` is **4.129.0**.

**Pin:** `next@16.3.4`, `wrangler@4.129.0`, `@opennextjs/cloudflare@1.20.6`.

### 1.4 `nodejs_compat` — the flag rules changed on 2026-08-04

| `compatibility_date` | What you must do |
|---|---|
| **≥ `2026-08-04`** | **`nodejs_compat` and `nodejs_compat_v2` are ON BY DEFAULT.** Docs: *"Omit them from new configurations."* |
| `2024-09-23` – `2026-08-03` | Add `nodejs_compat` (implies v2 in this window) |
| ≤ `2024-09-22` | Add `nodejs_compat_v2` alongside `nodejs_compat` |

— https://developers.cloudflare.com/workers/configuration/compatibility-flags/

**Practical guidance for this project:** we still list `"nodejs_compat"` explicitly in `wrangler.jsonc` (it is ignored as redundant on modern dates) because several third-party guides — Sentry, Drizzle/Hyperdrive, Prisma — key their instructions off its presence, and it documents intent.

**Minimum `compatibility_date` for this stack:** `2026-08-16`. Rationale, in ascending order of constraint:
- `2025-04-01` — `process.env` is populated with vars/secrets (OpenNext requires this)
- `2025-05-05` — `FinalizationRegistry` available (OpenNext requires this)
- `2025-08-16` — introduces `https.request`, **required by Sentry's Next.js-on-Cloudflare guide** (https://docs.sentry.io/platforms/javascript/guides/cloudflare/frameworks/nextjs/)
- `2025-09-01` — `node:fs` (virtual FS) available by default

Set it to a recent date, e.g. `"2026-09-01"`.

### 1.5 Which Node.js APIs actually work

Support table from https://developers.cloudflare.com/workers/runtime-apis/nodejs/ (verbatim status column):

- 🟢 **Fully supported**: `assert`, `async_hooks` (AsyncLocalStorage), `buffer`, **`crypto`**, `diagnostics_channel`, `events`, **`fs`**, `http`, `https`, **`net`**, `path`, `process`, `punycode`, `querystring`, **`stream`**, `string_decoder`, `timers`, `url`, `util`, Web Crypto, Web Streams, `zlib`
- 🟡 **Partial**: `console`, `dns`, `module`, `os`, `perf_hooks`, `test`, `tls`

**`node:crypto` — the exact answer.** Verbatim from https://developers.cloudflare.com/workers/runtime-apis/nodejs/crypto/:

> "All `node:crypto` APIs are fully supported in Workers with the following exceptions:
> - The functions **generateKeyPair** and **generateKeyPairSync** do not support **DSA or DH** key pairs.
> - **`argon2` and `argon2Sync` are not supported.**
> - **`ed448` and `x448` curves are not supported.**
> - It is not possible to manually enable or disable **FIPS mode**."

So `createHash`, `createHmac`, `pbkdf2`, **`scrypt`**, `randomBytes`, `randomUUID`, `createCipheriv`, `sign`/`verify`, `X509Certificate`, `timingSafeEqual` and `webcrypto` all work. It is built on **BoringSSL** reusing Node's own `ncrypto` (https://blog.cloudflare.com/nodejs-workers-2025/).

⚠️ **Cloudflare publishes an assertion, not a per-function matrix.** If a specific API is load-bearing, test it in `wrangler dev` *and* on a deployed preview — see the PBKDF2 dev/prod divergence trap in §4.3.

**`node:fs` is a virtual FS, not a disk.** Available by default with `compatibility_date >= 2025-09-01`. Provides `/bundle` (read-only, one file per bundled module), `/tmp` (writable, but *"the contents of `/tmp` are not persistent and are unique to each request"*), and `/dev/*`. Useful for reading bundled templates; useless for persistence. (https://developers.cloudflare.com/workers/runtime-apis/nodejs/fs/)

**`node:net`**: `net.Socket` works (backed by `cloudflare:sockets`), but *"the `net.Server` class is not supported by Workers."*

**Stub modules** (import succeeds, calls throw `[unenv] <method> is not implemented yet!`): `node:child_process`, `node:worker_threads`, `node:cluster`, `node:vm`, `node:inspector`, `node:sqlite`, `node:dgram`, `node:repl`, `node:tty`, `node:v8`, `node:http2`, `node:wasi`, `node:trace_events`, `node:domain`, `node:readline`. (https://developers.cloudflare.com/workers/runtime-apis/nodejs/ — stub-modules partial)

### 1.6 Next.js feature support on OpenNext — what works, what breaks

Cloudflare's own support table (https://developers.cloudflare.com/workers/framework-guides/web-apps/opennext/):

| Feature | Status | Needed by this project? |
|---|---|---|
| App Router | ✅ Supported | ✅ Yes |
| Pages Router | ✅ Supported | ❌ No |
| Route Handlers | ✅ Supported | ✅ Yes (all `/app/api/*`) |
| React Server Components | ✅ Supported | ✅ Yes |
| SSG | ✅ Supported | ✅ Yes (landing page) |
| SSR | ✅ Supported | ✅ Yes (dashboard) |
| **Server Actions** | ✅ Supported | ✅ Yes |
| **Response streaming** | ✅ Supported | ✅ Yes (Skeletons, PRD §2.1) |
| **`next/after`** | ✅ Supported | ✅ Yes (audit-log writes off the hot path) |
| Middleware | ✅ Supported | ✅ Yes (auth gate) |
| Image optimization | ✅ "Supported through Cloudflare Images" | ✅ Yes (avatars) |
| **Partial Prerendering (PPR)** | ⚠️ Listed as supported, **but broken in practice** — see below | ❌ **Do not use** |
| **`"use cache"` / Cache Components** | ⚠️ Listed as supported, **but broken in practice** | ❌ **Do not use** |
| **Node.js Middleware** (Next 15.2+ `proxy.ts`) | Docs say "Not yet supported"; release notes for 1.20.3 say **experimental support shipped** | ❌ Avoid |
| **`export const runtime = "edge"`** | ❌ **Must be removed** before deploying | ❌ Not used |

**The two things you must not turn on**, based on the open issue tracker (https://github.com/opennextjs/opennextjs-cloudflare/issues — 143 open):

- **`cacheComponents: true` is broken.** Issue **#1225** (open, 2026-04-22, 11 comments): *permanent "Connection closed" error with blank static shell served* — only the static shell + Suspense fallbacks render, dynamic content never streams. Issue **#1130** (open, 2026-02-16): production-only `Uncaught SyntaxError: Unexpected identifier '$'` when `cacheComponents` is enabled. The fix, **PR #1318**, is **still open and unmerged**. It is therefore broken in the shipped 1.20.6.
- **PPR + ISR on R2** — issue **#662**, open since 2025-05-12.

**Neither matters for us**: this is an authenticated dashboard with a small marketing page. We use SSG for `/` and dynamic SSR everywhere else. **We do not use ISR, `revalidateTag`, PPR, or Cache Components at all**, which also means we skip the D1/Durable-Object tag-cache machinery and the cost blowup reported in issue **#1103** (~12 million Durable Object requests in one day, >$50/day, from `DOShardedTagCache`).

**Other OpenNext traps that will bite this codebase specifically** (https://opennext.js.org/cloudflare/troubleshooting):
- *"Cannot perform I/O on behalf of a different request"* — **you cannot hold a module-scope database client**. Every request must construct its own `pg` client. This is non-negotiable and is called out explicitly for the `postgres` driver. See §2.6.
- npm packages with multiple export conditions may fail to build → set `WRANGLER_BUILD_CONDITIONS=""` and `WRANGLER_BUILD_PLATFORM="node"`.
- Issue **#139** (`Cannot import .wasm` from `@prisma/client/wasm`) has been **open since 2024-11-21**. Partially relieved in 1.20.3 but not closed. This is a direct argument for Drizzle over Prisma (§2.6).
- Issue **#1284** (open, 27 comments): `Failed to populate remote R2 bucket … after 15 attempts` — an ISR-cache deploy blocker. Avoided by not using ISR.

### 1.7 Bundle size

| Limit | Workers Free | Workers Paid |
|---|---|---|
| Worker size **after gzip** | **3 MB** | **10 MB** |
| Worker size before compression | 64 MB | 64 MB |
| **Worker startup time** | **1 second** | **1 second** |

— https://developers.cloudflare.com/workers/platform/limits/ (last updated 2026-09-03)

⚠️ **Correction to a very common assumption: startup CPU is 1 second, not 400 ms.** It was raised on 2025-10-10 (https://developers.cloudflare.com/changelog/2025-10-10-increased-startup-time/). Exceeding it fails deployment with **error code 10021**; Wrangler auto-generates a CPU profile. Check with `wrangler check startup`.

**Only the gzipped size counts.** OpenNext's docs show `Total Upload: 13833.20 KiB / gzip: 2295.89 KiB` — a 13.5 MB raw bundle is fine at 2.24 MB compressed. Verify with:

```
wrangler deploy --outdir bundled/ --dry-run
```

**A non-trivial App Router app will very likely exceed the 3 MB free ceiling.** OpenNext's troubleshooting page has a dedicated section for *"Your Worker exceeded the size limit of 3 MiB"*. This is one of several independent reasons Workers Paid is mandatory.

> **Do not confuse this with the PRD's `<250 KB` target.** PRD §13.1 requires the **dashboard's initial client JS bundle** to be under 250 KB gzipped. That is a browser-side budget, enforced by route-based code splitting and lazy-loading the calendar/charts/editor (PRD §13.1). It is completely unrelated to the Worker script size limit. Both must be met; they are measured with different tools.

### 1.8 Static assets

| Limit | Free | Paid |
|---|---|---|
| Files per Worker version | 20,000 | **100,000** |
| Individual file size | 25 MiB | 25 MiB |
| `_headers` rules | 100 | 100 |
| `_redirects` static / dynamic | 2,000 / 100 | 2,000 / 100 |

**Requests to static assets are free and unlimited** — they do not invoke the Worker and are not billed (https://developers.cloudflare.com/workers/static-assets/billing-and-limitations/).

Configuration decision: **leave `run_worker_first` unset (default `false`)** so `/_next/static/*` and fonts are served straight from the asset store without invoking the Worker. Consequence: Next.js middleware and `next.config` rewrites/headers do **not** apply to those asset paths — use Workers `_headers` for the security headers on static assets instead (PRD §12.3 requires CSP/HSTS/X-Frame-Options everywhere).

⚠️ Cloudflare documents **no aggregate byte cap** for a static asset set beyond per-file size and per-version file count. Stated explicitly rather than guessed.

---

## 2. Database — the PRD says PostgreSQL; can Cloudflare deliver it?

**This section resolves open decision D-17.**

### 2.1 What the PRD actually demands

From PRD §7 and the Constitution (Art. 9, *"impossibility before prevention"*):

| Requirement | PRD reference |
|---|---|
| ~20 relational tables with real FK relations | §7.1–§7.6 |
| Composite unique constraints on `(session_id, user_id)` and `(cohort_id, user_id)` | §7.7 |
| Unique `email`, `phone`, `serial_number`, `verify_code` | §7.7 |
| **CHECK constraints**: `0 <= score <= max_score`; `end_time > start_time` | §7.7 |
| Indexes on `(cohort_id, date)`, `(user_id, is_read)`, `(assignment_id, user_id)` | §7.7 |
| **Atomic multi-step writes** — every sensitive operation must write an `AuditLog` row *before it counts as done* | Constitution Art. 12, BR-27 |
| **Decimal** scores and attendance rates | §7.2, §7.5 (`final_score Decimal`, `score Decimal`) |
| Soft delete (`deleted_at`) with unique constraints still holding | §7.8 |
| Daily automatic backup, 30-day retention | §12.7 |
| Migrations in the repository, reversible | §6.3, §15 |
| 60–500 users | §19 D-07 (A-01: assume 60) |

### 2.2 D1 — the honest numbers

**Limits** (https://developers.cloudflare.com/d1/platform/limits/):

| Limit | Free | Workers Paid |
|---|---|---|
| Databases per account | 10 | 50,000 |
| **Maximum database size** | **500 MB** | **10 GB** |
| Max storage per account | 5 GB | 1 TB |
| **Queries per Worker invocation** | **50** | **1,000** |
| Max columns per table | 100 | 100 |
| Max rows per table | Unlimited | Unlimited |
| Max string / BLOB / row size | 2,000,000 bytes | 2,000,000 bytes |
| Max SQL statement length | 100,000 bytes | 100,000 bytes |
| **Max bound parameters per query** | **100** | **100** |
| Max SQL query duration | 30 seconds | 30 seconds |
| Time Travel retention | 7 days | **30 days** |

**Pricing** (https://developers.cloudflare.com/d1/platform/pricing/): Paid includes **25 billion rows read/mo** (+$0.001/M), **50 million rows written/mo** (+$1.00/M), **5 GB storage** (+$0.75/GB-mo). Free is 5 M rows read/day, 100 K written/day, 5 GB. Note: **rows read = rows *scanned***, not returned — an unindexed filter on a 5,000-row table bills 5,000 reads.

⚠️ **Free-tier enforcement hardened on 2026-09-01**: *"Beginning September 1, 2026, D1 queries on the Workers Free plan will fail when an account exceeds the daily row read or row write limits."* (https://developers.cloudflare.com/changelog/post/2026-09-01-d1-free-tier-limit-enforcement/) Previously soft, now hard failures.

**Transactions — the decisive fact.** D1 runs in auto-commit. `BEGIN TRANSACTION` / `SAVEPOINT` returns an error. The **only** transaction primitive is `batch()`:

> "Batched statements are SQL transactions. If a statement in the sequence fails, then an error is returned for that specific statement, and it aborts or rolls back the entire sequence."

— https://developers.cloudflare.com/d1/worker-api/d1-database/

That means you can atomically execute a **statically pre-computed array of statements**, but you **cannot** read a value, branch in JavaScript, and write inside the same atomic unit. Cloudflare's architectural reason is sound (SQLite allows one write transaction at a time; allowing `BEGIN` would let any Worker request worldwide block the whole database), but the consequence is real.

**Read replication is still public beta**, 18 months after the 2025-04-10 announcement, with no GA changelog entry. It is opt-in and **free** (*"you don't pay extra storage or compute costs for read replicas"*), and — importantly for auth — *"To use read replication, you must use the D1 Sessions API, otherwise all queries will continue to be executed only by the primary database."* So it cannot silently break session revocation. (https://developers.cloudflare.com/d1/best-practices/read-replication/)

**Time Travel backups** (https://developers.cloudflare.com/d1/reference/time-travel/): restore to **any minute within 30 days** on Paid, automatic, zero config. But it is **destructive and in-place** — *"Queries in flight will be cancelled"* — and there is **no restore-to-a-new-database / clone**. Offsite export via `wrangler d1 export` **blocks other database requests while it runs** and is **not supported for databases containing virtual tables** (i.e. it breaks the moment you add FTS5 for the message search in PRD §9.13.2).

**Schema features:** foreign keys are supported and **on by default**. CHECK constraints and partial indexes work (it is SQLite), but ⚠️ **Cloudflare never explicitly documents CHECK-constraint support** — the only evidence is that `PRAGMA ignore_check_constraints` is listed as supported, which only exists to *disable* enforcement. Flagged as inference, not a documented guarantee.

**Prisma on D1 is disqualified.** Verbatim from Prisma's own docs (https://www.prisma.io/docs/orm/overview/databases/cloudflare-d1):

> "Cloudflare D1 currently does not support transactions… implicit & explicit transactions **will be ignored and run as individual queries**, which breaks the guarantees of the ACID properties of transactions."

`prisma.$transaction([...])` does not throw — it **silently degrades to non-atomic execution**. For a system whose Constitution (Art. 12) requires an audit-log row to be written atomically with every sensitive operation, that is a silent data-integrity failure, which is strictly worse than a loud one. Also: `@prisma/adapter-d1` has been **"in Preview" since Prisma 5.12.0 (2 April 2024) — ~2.5 years** and is the only Preview entry in Prisma's entire database matrix.

**Drizzle on D1 is the honest option**: `db.transaction()` **throws** on D1 (drizzle-orm #2463, #4212) and you must use `db.batch([...])`, which maps to D1's atomic batch. Failing loudly beats degrading silently.

### 2.3 Hyperdrive — free, GA, and the friction is gone

| Limit | Free | Paid |
|---|---|---|
| **Database queries** | 100,000/day | **Unlimited** |
| Hyperdrive configs per account | 10 | 25 |
| Origin DB connections per config | ~20 | ~100 |
| Max query duration | 60 s | 60 s |
| Max cached response | 50 MB | 50 MB |
| Initial connect timeout / idle timeout | 15 s / 10 min | 15 s / 10 min |

— https://developers.cloudflare.com/hyperdrive/platform/limits/

**Pricing:** *"Hyperdrive is included in both the Free and Paid Workers plans."* No per-query charge, no per-GB charge, **no egress charge**. (https://developers.cloudflare.com/hyperdrive/platform/pricing/) It became free-plan-eligible on 2025-04-08. Supports **PostgreSQL and MySQL**, and there is **no limit on concurrent client connections from your Workers**.

⚠️ **Hyperdrive's query cache does NOT invalidate on writes.** Verbatim: *"Hyperdrive does not invalidate cached read query results when your application writes to your database."* Defaults are `max_age` 60 s / `stale_while_revalidate` 15 s. **Mitigation for this project: create two Hyperdrive configs** — one cached (for the public landing page's seat counter and program metadata) and one created with `--caching-disabled` for everything authenticated. Bind both. Route **all** auth, permissions, attendance, grades and read-after-write traffic through the uncached binding. This is mandatory, not optional: PRD §2.2 Moment 2 requires the attendance percentage to update *immediately* after check-in, and PRD §9.9.4 forbids trusting anything but server state.

### 2.4 VERDICT — PostgreSQL on Neon via Hyperdrive

**Chosen: Neon Postgres (Launch plan, region `aws-eu-central-1` / Frankfurt) behind Cloudflare Hyperdrive.**

| Criterion | D1 | **Postgres via Hyperdrive** |
|---|---|---|
| PRD §6.1 asks for PostgreSQL | ❌ SQLite | ✅ |
| **Interactive transactions** (audit log written atomically with the action) | ❌ batch only | ✅ **full ACID** |
| CHECK constraints | works, **undocumented** | ✅ documented, first-class |
| **`DECIMAL` for scores** (PRD §7.5) | ❌ SQLite has no decimal type — scores become IEEE floats unless you hand-roll integer cents | ✅ **`NUMERIC(5,2)`** |
| Native `ENUM`, `UUID`, `timestamptz` | ❌ all emulated as TEXT | ✅ native |
| Partial unique index for soft delete (`UNIQUE(email) WHERE deleted_at IS NULL`) | works, undocumented | ✅ documented |
| 100 bound parameters per query | ❌ **hard limit** — a 4-row insert into a 30-column table already exceeds it | ✅ 65,535 |
| Daily backup, 30-day retention (PRD §12.7) | Time Travel 30-day PITR (free) but **in-place only**, and export **blocks the DB** and **breaks with FTS5** | ⚠️ Neon **Launch** PITR is only **7 days** — see §2.4.1. Satisfied by a daily `pg_dump` to R2 with 30-day retention. |
| Migrations in repo, reversible | `wrangler d1 migrations` (SQL files) | ✅ `drizzle-kit` SQL files, or `prisma migrate deploy` |
| Cost delta | $0 | **+$5–20/month** |

**The `DECIMAL` point is the one that closes the argument.** The Constitution (Art. 8, *"مبدأ الحق المكتسب"*) treats attendance and grades as personal rights, not table rows. Storing a score as an IEEE-754 double because the platform has no decimal type — and then computing a pass/fail threshold at 60.00 against it — is exactly the class of silent error Art. 9 exists to prevent. Postgres `NUMERIC` removes the failure mode structurally.

**Runner-up: D1 + Drizzle + `db.batch()`.** Choose it only if the client refuses any non-Cloudflare vendor. If you do, you must: drop Prisma entirely, restructure every multi-step write as a static batch, store all scores as **integer basis points** with a CHECK constraint, and accept that the "daily backup" requirement is met by Time Travel PITR rather than an offsite dump.

**Why Neon over the alternatives:**

| Provider | Cost | Notes |
|---|---|---|
| **Neon Launch** ✅ | **$0 base**, usage-based: **$0.106/CU-hour** + **$0.35/GB-month** storage + **$0.20/GB-month** PITR. **Realistically $5–20/mo.** | Scale-to-zero, **PITR up to 7 days**, **10 branches included**, 500 GB egress included — database branching maps perfectly onto the staging/preview environments PRD §6.4 requires. |
| Neon Scale | $0 base, **$0.222/CU-hour** (2×), same storage/PITR rates | **PITR up to 30 days**, 25 branches. Only worth it if you want PITR alone to satisfy §12.7 — see §2.4.1. |
| PlanetScale via Cloudflare | **$5/mo** single node, billed on your Cloudflare invoice | Launched April 2026; tightest CF integration and unified billing. Strong runner-up. https://developers.cloudflare.com/hyperdrive/planetscale/ |
| Supabase Pro | **$25/mo** | Daily backups, 7-day retention. ⚠️ Cloudflare's Hyperdrive guide requires the **Direct** (non-pooled) connection string; whether that needs Supabase's paid IPv4 add-on is **not addressed** in current docs — verify before committing. |
| Prisma Postgres Starter | $10/mo + **$8/million operations** | Operations pricing is steep for a chatty dashboard. |

**⚠️ Latency reality check.** Neon has **no Middle East region** (AWS regions offered: N. Virginia, Ohio, Oregon, Frankfurt, London, Singapore, Sydney, São Paulo — https://neon.com/docs/introduction/regions). Neither does D1 (`weur`/`eeur`/`apac`/`oc`/`wnam`/`enam`). **Frankfurt is the closest by network path from Riyadh (~90–110 ms RTT), better than Singapore.** Mitigation is mandatory:

```jsonc
{ "placement": { "mode": "smart" } }
```

**Smart Placement** runs the Worker near the database instead of near the user, collapsing N sequential query round-trips into one user↔Worker round trip. It is **available on all Workers plans** (https://developers.cloudflare.com/workers/configuration/smart-placement/). Note it does **not** apply to static asset serving (always nearest to the user) — which is exactly what you want.

This directly serves PRD §13.1's `<300 ms` server-response target: without Smart Placement, three sequential queries cost ~300 ms in network latency alone.

### 2.4.1 ⚠️ Backup: Neon Launch gives 7-day PITR, the PRD demands 30 days

PRD §12.7 requires *"نسخ احتياطي يومي تلقائي لقاعدة البيانات، يُحتفظ به 30 يومًا"* — a daily automatic backup retained **30 days**, plus a weekly backup of uploaded files and a **quarterly restore test**.

Neon **Launch** caps instant-restore/PITR at **7 days** ($0.20/GB-month); **30 days requires the Scale plan** at $0.222/CU-hour — roughly double the compute rate. (https://neon.com/pricing)

**Chosen resolution — do not upgrade to Scale.** Keep Launch (7-day PITR for fast, granular recovery) and add a **daily logical backup to R2**:

- A second Cron Trigger (`0 1 * * *`, i.e. 04:00 Asia/Riyadh) — but note the 15-minute wall-clock ceiling on cron invocations, and that `pg_dump` is not runnable inside a Worker.
- **Therefore run the dump in Workers Builds / GitHub Actions on a schedule**, not in the Worker: `pg_dump` → gzip → upload to `r2://athar-backups/db/YYYY-MM-DD.sql.gz` via the S3 API.
- Apply an **R2 object lifecycle rule** expiring that prefix at **31 days** (§5.5), which enforces the retention policy structurally rather than by convention (Constitution Art. 9).
- Uploaded files already live in R2, which is replicated by Cloudflare; the PRD's "weekly file backup" is satisfied by an R2 bucket-to-bucket copy job on the same schedule, or accepted as covered by R2's durability with a written note.
- **Schedule the quarterly restore test** into the operations runbook PRD §15 requires — restore the latest dump into a Neon **branch** (free, 10 included) and run the smoke suite against it. Neon branching makes this genuinely cheap, which is the main reason it is the recommended provider.

This is both cheaper than Neon Scale and *better* than PITR alone, because it produces an **offsite, independently restorable artifact** — which PITR, by definition, does not.

### 2.5 ORM verdict — Drizzle (new decision **D-19**)

PRD §6.1 proposes Prisma, but explicitly says the stack is a proposal, not a requirement: *"هذا الستاك مقترح ومبرر، لكنه ليس شرطًا"*.

**Recommended: Drizzle ORM `0.45.2` + `pg@8.23.0`.** Cloudflare documents this combination officially: https://developers.cloudflare.com/hyperdrive/examples/connect-to-postgres/postgres-drivers-and-libraries/drizzle-orm/ — *"You may use node-postgres or Postgres.js when using Drizzle ORM. Both are supported and compatible."*

| | Drizzle | Prisma 7 + `@prisma/adapter-pg` |
|---|---|---|
| Bundle impact on the 10 MB gzip budget | Negligible (pure TS) | Query compiler + `pg`; Prisma's own docs warn about the 3 MB free limit |
| WASM loading on OpenNext | None | **OpenNext issue #139 open since 2024-11-21** |
| Migrations in repo | `drizzle-kit generate` → SQL files in git | `prisma migrate deploy` |
| Type safety | Excellent | Excellent |
| Relation ergonomics | Good | **Better** (`include` depth) |
| `$disconnect()` memory footgun | n/a | Prisma docs: must call it *"or else the worker might run out of memory"* |

**Runner-up: Prisma 7 (`@prisma/adapter-pg@7.10.0`).** Officially documented by Cloudflare for Hyperdrive and fully ACID over Postgres. Choose it if the team's Prisma familiarity outweighs the OpenNext bundling risk. ⚠️ Prisma's Cloudflare docs are **stale** — they still say `node_compat = true`, which is the legacy form; the current requirement is the `nodejs_compat` **compatibility flag**.

### 2.6 Mandatory connection pattern

OpenNext's troubleshooting page documents that a module-scope database client throws *"Cannot perform I/O on behalf of a different request"*. Cloudflare's Hyperdrive guide says *"Create a new client instance for each request."*

```ts
// lib/db/client.ts  — NEVER hoist this to module scope
import { Client } from "pg";
import { drizzle } from "drizzle-orm/node-postgres";
import * as schema from "./schema";

export function getDb(env: CloudflareEnv, ctx: ExecutionContext, opts?: { cached?: boolean }) {
  const client = new Client({
    connectionString: (opts?.cached ? env.HYPERDRIVE_CACHED : env.HYPERDRIVE).connectionString,
  });
  const connected = client.connect();
  // Close on the way out so the connection is returned to Hyperdrive's pool.
  ctx.waitUntil(connected.then(() => {}).finally(() => {}));
  return { db: drizzle(client, { schema }), close: () => ctx.waitUntil(client.end()) };
}
```

Every Route Handler / Server Action must call `close()` (via `ctx.waitUntil`) before returning.

---

## 3. Authentication and sessions

### 3.1 What the PRD demands (§12.1, §9.3, §4.5)

- Sessions stored **server-side**, revocable **instantly** (`يمكن إبطالها فورًا`)
- **24 hours** default, **30 days** with "remember me"
- **Session-id regeneration on login** to prevent session fixation
- **"Logout from all devices"** (§9.3.2)
- **Password change invalidates every active session** (BR-29)
- **Account suspension invalidates sessions immediately** (§4.4)
- **Admin impersonation sessions limited to 30 minutes**, strictly read-only, enforced *at the data-access layer* (§4.5.2, BR-33)
- Cookies `HttpOnly`, `Secure`, `SameSite=Lax`

### 3.2 Auth.js / NextAuth is architecturally disqualified — verified in source

`next-auth` v5 is **still beta after ~3 years**: dist-tags on 2026-09-04 are `latest: 4.24.15`, `beta: 5.0.0-beta.32`. There is no stable 5.x.

**The blocker: the Credentials provider cannot use database sessions.** This is not a docs footnote — it is enforced in three places in the source:

`packages/core/src/lib/utils/assert.ts`:
```js
if (dbStrategy && onlyCredentials) {
  return new UnsupportedStrategy(
    "Signing in with credentials only supported if JWT strategy is enabled"
  )
}
```
— https://github.com/nextauthjs/next-auth/blob/main/packages/core/src/lib/utils/assert.ts

And the credentials sign-in branch in `packages/core/src/lib/actions/callback/index.ts` calls `callbacks.jwt(...)` then `jwt.encode(...)` and sets a JWT cookie — **it never calls `adapter.createSession`**.

Auth.js's own documentation states the consequence plainly:

> "Expiring a JSON Web Token before its encoded expiry is not possible - doing so requires maintaining a server-side blocklist of invalidated tokens… Auth.js will destroy the cookie, but if the user has the JWT saved elsewhere, it will be valid (the server will accept it) until it expires."

— https://authjs.dev/concepts/session-strategies

**Every single one of this project's session requirements lives on the database-session side of a door the Credentials provider cannot walk through.** Worse, the assertion only fires when Credentials is the *only* provider — mixing in an OAuth provider with `strategy: "database"` passes validation silently while still minting JWT cookies that the session endpoint cannot resolve (https://github.com/nextauthjs/next-auth/discussions/12848).

Additional signals: Auth.js has **no Cloudflare Workers or OpenNext deployment documentation at all** (https://authjs.dev/getting-started/deployment mentions Cloudflare only to auto-detect `CF_PAGES` for `AUTH_TRUST_HOST`), and there are open OpenNext issues against it (#435 "Authjs + D1 not working", #686 "NextAuth signOut takes 15 seconds in preview").

### 3.3 VERDICT — Better Auth 1.7.2

**`better-auth@1.7.2`** (published 2026-08-26).

| PRD requirement | Better Auth support |
|---|---|
| Server-stored sessions | ✅ Real DB rows: `id`, `token`, `userId`, `expiresAt`, `ipAddress`, `userAgent` — https://better-auth.com/docs/concepts/session-management |
| Instant invalidation | ✅ `revokeSession()` — a plain `DELETE`. **Do NOT enable `cookieCache`**: its own docs warn *"revoked sessions may remain active on other devices until the cookie cache expires"*. |
| Logout all devices | ✅ `revokeSessions()`, `revokeOtherSessions()` |
| Password change → kill all sessions (BR-29) | ✅ via `revokeSessions()` in an after-hook |
| Admin impersonation | ✅ Admin plugin: `POST /admin/impersonate-user`, `stopImpersonating()`. **`impersonationSessionDuration` defaults to 1 hour — set it to `60 * 30`** for the PRD's 30 minutes. Admins cannot impersonate admins unless explicitly granted, which satisfies BR-35. — https://better-auth.com/docs/plugins/admin |
| Session-id regeneration on login | ⚠️ **Not built in.** Hand-roll: `INSERT` new row → set new cookie → `DELETE` old row, in that order. |
| **24 h / 30 d dual lifetime** | ⚠️ **GAP.** `rememberMe` (default `true`) only controls whether the *cookie* is a browser-session cookie; `expiresIn` is a single global value (default 7 days). **You must set per-row `expiresAt` via a `databaseHook` on session create.** Budget for this — it is a real gap, not a config toggle. |

**Security note:** advisory `GHSA-2vg6-77g8-24mp` (LOW) affects Better Auth **0.3.4 – 1.6.10**: when `secondaryStorage` is configured with `storeSessionInDatabase: false`, three user-deletion endpoints leave valid sessions behind. **Fixed in 1.6.11.** Pin `>=1.7.0` (which is also the version that brings the native `workerd` scrypt path — see §4.4). This advisory is itself direct evidence for the KV conclusion below.

**Runner-up: hand-rolled sessions (~200 lines).** This is now the officially blessed path per Lucia's pivot — **Lucia v3 was deprecated by its maintainer in March 2025** and is now a learning resource, not a library (https://github.com/lucia-auth/lucia/discussions/1707). A hand-rolled implementation gives you the dual lifetimes and id regeneration without fighting a library's defaults: `crypto.getRandomValues(new Uint8Array(32))` for the id, store `SHA-256(id)` not the id, `HttpOnly; Secure; SameSite=Lax`.

### 3.4 Where sessions live: **Postgres. Not KV. Ever.**

| Store | Instant revocation? | Verdict |
|---|---|---|
| **Postgres (via Hyperdrive, uncached binding)** | ✅ `DELETE` is immediately visible | ✅ **CHOSEN** |
| D1 | ✅ (replication is opt-in and requires the Sessions API) | ✅ acceptable if D1 is chosen for the main DB |
| **Workers KV** | ❌ | ❌ **DISQUALIFIED** |
| Durable Object (one per user) | ✅ strongly consistent, single-writer | ⚠️ Overkill; adds a cross-region round trip |

**Why KV is disqualified — verbatim from https://developers.cloudflare.com/kv/concepts/how-kv-works/:**

> "KV achieves high performance by being eventually-consistent."
> **"Changes may take up to 60 seconds or more to be visible in other global network locations as their cached versions of the data time out."**
> **"Negative lookups indicating that the key does not exist are also cached, so the same delay exists noticing a value is created as when a value is changed."**
> "At the Cloudflare global network location at which changes are made, these changes are usually immediately visible. However, this is not guaranteed and therefore it is not advised to rely on this behaviour."

Deleting a session key in one PoP does **not** evict the cached positive value in other PoPs. `cacheTtl` has a **minimum of 30 seconds**, so you cannot even shrink the window below that. A user whose account is suspended (PRD §4.4: *"تُبطل جلساته فورًا"*) could stay logged in for a minute or more. **This directly violates PRD §12.1 and is non-negotiable.**

KV remains useful in this project for: the landing-page settings cache, i18n bundles, and feature flags — never for anything that must be revoked.

**Hyperdrive caveat for auth:** all session reads must go through the **`--caching-disabled`** Hyperdrive binding (§2.3). Hyperdrive's 60-second read cache would reintroduce exactly the KV problem.

**⚠️ Read-replication caveat if D1 is chosen instead:** leave read replication **off**, or never wrap session lookups in `withSession()`. Unwrapped D1 queries always hit the primary.

### 3.5 Admin impersonation ("معاينة الحسابات", §4.5)

The PRD's strongest privilege. Constitution Art. 5 and PRD §4.5.2 require read-only enforcement **at the data-access layer, not the endpoint layer**.

Implementation on this stack:
1. Impersonation mints a **separate session row** with `impersonatedBy = adminId` and `expiresAt = now + 30 min`.
2. `getDb()` (§2.6) inspects the session and, when `impersonatedBy` is set, returns a **write-blocked Drizzle instance** — a thin proxy that throws on `insert`/`update`/`delete`. This is the single choke point required by Art. 6 (*مبدأ المصدر الواحد*).
3. The exceptions to (2) are the audit-log writes themselves, which use a separate privileged handle.
4. Every tab navigation during impersonation writes an `AuditLog` row (§4.5.3) — use `next/after` so it does not block the response.
5. Ending impersonation deletes the impersonation session row; the admin's original session row was never touched, so they return without re-authenticating (§4.5.2).

---

## 4. Password hashing

### 4.1 What the PRD demands

PRD §12.1 / §6.1: *"argon2id أو bcrypt بمعامل كلفة 12 فأعلى"* — argon2id, or bcrypt at cost factor ≥ 12.

### 4.2 The CPU budget

| Limit | Workers Free | Workers Paid |
|---|---|---|
| **CPU time per HTTP request** | **10 ms** | **30 s default, configurable to 5 min** |
| Max configurable `limits.cpu_ms` | — | **300,000** |
| Memory per isolate | 128 MB | 128 MB |

— https://developers.cloudflare.com/workers/platform/limits/

**The Free plan's 10 ms CPU limit makes every OWASP-strength password hash impossible.** This alone forces Workers Paid. It is not a preference.

### 4.3 ⚠️ PBKDF2 is capped at 100,000 iterations — and it fails ONLY in production

This is the single nastiest trap on the platform, and **Cloudflare does not document it anywhere.**

Verified directly in `workerd` source, `src/workerd/io/limit-enforcer.h`:
```cpp
static constexpr size_t DEFAULT_MAX_PBKDF2_ITERATIONS = 100'000;
static constexpr uint64_t DEFAULT_MAX_SCRYPT_COST = 1u << 20;
...
// By default, historically we've limited this to 100,000 iterations max. ...
// Note, this current default limit is *WAY* below the recommended
// minimum iterations for pbkdf2.
```
— https://github.com/cloudflare/workerd/blob/main/src/workerd/io/limit-enforcer.h

The error is `NotSupportedError: Pbkdf2 failed: iteration counts above 100000 are not supported (requested 600000).` **The cap applies to `node:crypto.pbkdf2` too** (`checkPbkdfLimits` in `src/workerd/api/node/crypto.c++`).

**And the standalone `workerd` server overrides the enforcer to remove the limit:**
```cpp
kj::Maybe<size_t> checkPbkdfIterations(jsg::Lock& lock, size_t iterations) const override {
    // No limit on the number of iterations in workerd
    return kj::none;
}
```
— https://github.com/cloudflare/workerd/blob/main/src/workerd/server/server.c++

**Consequence: PBKDF2 at 600,000 iterations works perfectly in `wrangler dev` and throws in production.** Tracking issue https://github.com/cloudflare/workerd/issues/1346 is **still open with no Cloudflare response**.

OWASP recommends 600,000 iterations for PBKDF2-HMAC-SHA256. **100,000 is a 6× shortfall. PBKDF2 is not a compliant option on Workers.**

### 4.4 VERDICT — `node:crypto.scrypt`, N=32768, r=8, p=3

```ts
import { scrypt, randomBytes, timingSafeEqual } from "node:crypto";

const PARAMS = { N: 32768, r: 8, p: 3, maxmem: 128 * 32768 * 8 * 3 }; // ≈100 MiB ceiling
const DKLEN = 64;
```

Why these exact numbers:

| Constraint | Value | Our config |
|---|---|---|
| workerd scrypt cost cap: `N·r·p ≤ 1,048,576` | 1,048,576 | **786,432** ✅ |
| Working memory `128·N·r` vs 128 MB isolate | 128 MB | **32 MiB** ✅ |
| OWASP-equivalent strength | N=2^17,r=8,p=1 baseline; *"N=2^16 at p=2 through N=2^13 at p=10"* listed as equivalent | **N=2^15, r=8, p=3** ✅ |

**OWASP's headline scrypt config (N=2^17, r=8, p=1) is NOT viable on Workers** — it needs `128 × 131072 × 8` = **128 MiB** of working memory, i.e. the entire isolate budget. This is a memory constraint, not a cost-cap constraint. `N=2^15, r=8, p=3` clears both.

Why scrypt over the alternatives:
- **Native BoringSSL**, not JavaScript. No WASM loader hacks, no bundler export-condition gambles, no unmaintained dependency.
- **No dev/prod divergence** — unlike PBKDF2, the scrypt cost check is enforced identically in local `workerd` and in production.
- Set `maxmem` **explicitly**: workerd accepts `maxmem: 0` where Node rejects it (workerd issue #6639, open), so do not rely on defaults.
- Compare with **`timingSafeEqual` on raw buffers**, not `===` on hex strings.

**Better Auth's built-in is already correct on Workers.** `better-auth@1.7.2` pins `@better-auth/utils@0.4.2`, whose `./password` export map added a **`workerd` condition in 0.4.1 (2026-05-27)** routing to the native `node:crypto.scrypt` build (`N: 16384, r: 16, p: 1, dkLen: 64`; cost 262,144 ✅; 32 MiB ✅) rather than the pure-JS `@noble/hashes` fallback. That fix resolves better-auth issue **#8860** (*"email/password sign-up exceeds CPU time limit on Cloudflare Workers"*).

> ⚠️ **Verify this in your build output.** If the bundler silently falls through to the `import` condition instead of `workerd`, you get pure-JS noble scrypt and CPU-limit errors in production. Add a build-time assertion.

**Runner-up: argon2id via WASM.** Package: **`argon2id@1.0.1`** (openpgpjs) — RFC 9106, <7 KB gzipped, and critically it exposes `setupWasm()` so you can supply your own `WebAssembly.instantiate`, which is exactly what Workers needs. Parameters: **m=19456 KiB, t=2, p=1** (OWASP). Memory is comfortable (19 MiB of 128 MB). Choose it only if argon2id is a hard compliance requirement, and:
- **Instantiate the WASM lazily inside the request handler, never at module top level** — base64-decoding an inlined wasm blob at global scope is a documented cause of `Script startup exceeded CPU time limit [code: 10021]` against the 1-second startup budget.
- ⚠️ No published Workers benchmark exists at OWASP argon2id parameters. You will be the first measurement.

**Explicitly rejected:**

| Option | Why not |
|---|---|
| `crypto.argon2` in `node:crypto` | **Explicitly unsupported** — Cloudflare's docs name it as an exception |
| PBKDF2 (WebCrypto or node) | Hard-capped at 100k; passes in dev, throws in prod |
| `bcryptjs@3.0.3` | Pure-JS KDF on a CPU-metered platform. Loads and runs, but burns far more CPU-ms than native scrypt for weaker memory-hardness. Also silently truncates passwords at 72 bytes. **No measured cost-12 timing on Workers exists.** |
| `@node-rs/argon2@2.2.0` | napi-rs **native addon** — per-platform `.node` binaries, no wasm32 target. **Cannot load.** |
| `hash-wasm@4.12.0` | `WebAssembly.compile(): Wasm code generation disallowed by embedder`. Maintainer confirms the loader is incompatible; package stale since 2024-11. |
| `@noble/hashes@2.4.0` argon2id | Works, but its own README says: *"Argon2 can't be fast in JS… It is suggested to use Scrypt instead."* |

**Compliance note against PRD §12.1.** The PRD asks for "argon2id or bcrypt cost ≥ 12". scrypt is neither. It is, however, one of the three OWASP-approved password KDFs, memory-hard like argon2id, and the only one that runs natively on this platform. **This requires a written decision (new decision D-20) under Constitution Art. 4 — it must not be implemented as a silent substitution.**

---

## 5. File storage — R2

### 5.1 Limits and pricing

| Limit | Value |
|---|---|
| Max object size | 5 TiB |
| **Max single-part upload** | **5 GiB** |
| Max multipart upload | 4.995 TiB |
| **Multipart: min part / max parts** | **5 MiB / 10,000** (all parts but the last must be the same size) |
| Object key length | 1,024 bytes |
| Concurrent writes to the same key | 1/second |

— https://developers.cloudflare.com/r2/platform/limits/ · https://developers.cloudflare.com/r2/objects/multipart-objects/

| Pricing | Standard |
|---|---|
| Storage | **$0.015 / GB-month** |
| Class A ops (Put, CreateMultipartUpload, UploadPart, List) | **$4.50 / million** |
| Class B ops (Get, Head) | **$0.36 / million** |
| **Egress** | **Free** |
| Free tier | 10 GB-mo storage, 1 M Class A, 10 M Class B |

— https://developers.cloudflare.com/r2/pricing/

### 5.2 Presigned URLs — what R2 does and does not support

— https://developers.cloudflare.com/r2/api/s3/presigned-urls/

- ✅ **Presigned `PUT`** for direct browser upload. Also GET, HEAD, DELETE.
- ❌ **Presigned POST policy is NOT supported.** Verbatim: *"`POST` (multipart form uploads via HTML forms) is not currently supported."* Confirmed by the S3 compatibility matrix — `PostObject` is not implemented.
- **Expiry: 1 second to 7 days.** The PRD's **15-minute** requirement (§4.3, §12.5, §9.12) is comfortably inside this.
- ⚠️ **Presigned multipart is undocumented but works.** The presigned-URLs page lists only GET/HEAD/PUT/DELETE, yet the S3 API page marks `CreateMultipartUpload`/`UploadPart`/`CompleteMultipartUpload` as supported, and there is an open docs issue about exactly this gap (https://github.com/cloudflare/cloudflare-docs/issues/19190). **Treat presigned `UploadPart` as working-but-unofficial — spike it before depending on it.**

**There is no method on the R2 Workers binding to mint a presigned URL.** The binding exposes only `head/get/put/delete/list/createMultipartUpload/resumeMultipartUpload`. To presign you must use the S3 API with an R2 Access Key ID + Secret scoped to the bucket. **Use `aws4fetch@1.0.20`** — it is built on `fetch` + WebCrypto with zero Node dependencies. (The AWS SDK v3 pulls `DOMParser` and other DOM/Node APIs and is problematic in Workers.) ⚠️ There is **no single officially-recommended signing library** — Cloudflare's docs demonstrate the AWS SDKs; `aws4fetch` is the practical community standard and appears in several Cloudflare templates.

**Known gotcha:** do **not** sign `Content-Type` into a query-signed URL. `signQuery: true` in `aws4fetch` signs only the `host` header, so browser uploads fail while `curl` succeeds.

**CORS is required even with presigned URLs.** Verbatim: *"Without a CORS policy, browser-based uploads and downloads using presigned URLs will fail, even though the presigned URL itself is valid."* For resumable multipart you must also add `ExposeHeaders: ["ETag"]` or JavaScript cannot read the per-part ETag. (https://developers.cloudflare.com/r2/buckets/cors/)

### 5.3 Request body limits through a Worker

| Cloudflare **account plan** | Max request body |
|---|---|
| **Free** | **100 MB** |
| **Pro** | **100 MB** |
| **Business** | **200 MB** |
| Enterprise | up to 5 GB |

Verbatim: *"Request body size limits depend on your Cloudflare account plan, not your Workers plan."* This is a **proxy-level** limit. It does **not** apply to the R2 S3 endpoint (`<accountid>.r2.cloudflarestorage.com`), which is not an orange-clouded zone.

**For the PRD's 25 MB assignment uploads (§9.11.2) this is a non-issue** — 25 MB is far under 100 MB on the Free zone plan. **The binding constraint is memory, not body size:** the isolate has 128 MB, so you must **stream** to R2 and never `await request.arrayBuffer()` on a 25 MB body.

### 5.4 MIME sniffing — R2 does not do it for you

**Confirmed absent.** R2 stores `Content-Type` as system metadata and returns it on GET, but performs **no verification that the bytes match**. PRD §12.5 requires *"فحص نوع MIME الحقيقي من محتوى الملف لا من امتداده"* — real MIME detection from content, not extension. That is entirely your responsibility.

Use **`file-type@22.0.2`** — it operates on a `Uint8Array` and needs only the first ~4 KB, no filesystem, Workers-compatible.

**⚠️ Architectural consequence: presigned direct-to-R2 upload bypasses your Worker, so you cannot sniff at upload time.** Two options:

| Option | How | Trade-off |
|---|---|---|
| **A — Proxy through the Worker** ✅ **CHOSEN** | Read the first chunk of the request stream, run `file-type`, reject or continue, then `env.BUCKET.put()` the rest | Synchronous rejection with a clear Arabic error (PRD §11); costs Worker CPU; safe at 25 MB (under the 100 MB cap) |
| B — Presign + async validation | Upload to a `quarantine/` prefix → R2 event notification → Queue → consumer does a **ranged GET of the first 4 KB** (cheap Class B op) → copy or delete | Scales better, but the client must poll, and the PRD's "confirmation card with filename, size, exact submission time" (§9.11.2) would show before validation completes |

**Option A is chosen** because PRD §9.11.2 and §14.1 require the executable-content rejection to be immediate and explicit (*"رفع ملف بامتداد مسموح لكن محتواه تنفيذي"* is a mandatory test case).

Also resolve **open decision D-18** here: PRD §9.11.2 says "any format" while §12.5 requires rejecting executables. Constitution Art. 9 requires an **allowlist, not a denylist**. Define it in `lib/storage/allowed-types.ts`.

### 5.5 Lifecycle and cleanup

PRD §7.8: *"الملفات المرفوعة: تُحذف من التخزين بعد 90 يومًا من حذف السجل المرتبط بها"* — files deleted 90 days after their record is soft-deleted.

R2 **object lifecycle rules** (max 1,000 per bucket, https://developers.cloudflare.com/r2/buckets/object-lifecycles/) support expiration by age and abort-incomplete-multipart-uploads (a default 7-day rule ships with every bucket). But "90 days after the *database record* was soft-deleted" is application state, not object age — so implement it in the 15-minute cron sweep (§8), moving objects to a `trash/` prefix on soft delete and letting a 90-day lifecycle rule on that prefix do the deletion.

---

## 6. Email — the most-changed answer in this document

### 6.1 Can a Worker do outbound raw SMTP?

**Partially — and you should not.**

Verbatim from https://developers.cloudflare.com/workers/runtime-apis/tcp-sockets/:

> "By default, Workers cannot create outbound TCP connections on port **`25`** to send email to SMTP mail servers."

**Only port 25 is documented as blocked.** Ports **465 and 587 are not blocked and work in practice** — `worker-mailer` (https://github.com/zou-yu/worker-mailer) is a maintained SMTP client for Workers whose README states: *"Cloudflare Workers cannot make outbound connections on port 25… but common ports like 587 and 465 are supported."*

`startTls()` **is** supported (`secureTransport: "starttls"` then `socket.startTls()`), but has a history of bugs — https://github.com/cloudflare/workerd/issues/2712 documents STARTTLS failures against `smtp.protonmail.ch:587`. **Implicit TLS on 465 is the more reliable path.** Separately, `connect()` is blocked to Cloudflare's own IP ranges (https://github.com/cloudflare/cloudflare-docs/issues/21888).

> ⚠️ **Discrepancy with the existing `05-email-and-integrations.md`.** That document states Cloudflare blocks ports 25/465/587 and that SMTP from a Worker is *"مستحيل تقنيًا"* (technically impossible). **Cloudflare's documentation blocks only port 25**, and a maintained library demonstrates 465/587 working. The *practical* conclusion is identical — do not use raw SMTP — but the *reason* is different, and the factual claim in `05-email` should be corrected so nobody later "discovers" that 587 works and treats the recommendation as invalid.

**Why not, regardless:** raw SMTP burns a TCP connection and several round trips against your CPU-time budget, gives you no retry/bounce/suppression handling, and — decisively — gives you none of the sending-IP reputation that determines whether a Saudi institutional recipient's Gmail/Outlook inbox accepts the message. PRD §18 lists spam-foldering as an identified risk.

### 6.2 Cloudflare Email Service — a genuinely new first-party option

**This is the biggest change since the previous analysis.** Cloudflare's Email Service entered **public beta on 2026-04-16** (https://developers.cloudflare.com/changelog/post/2026-04-16-email-sending-public-beta/), adding real outbound transactional sending via a Workers binding, a REST API, and authenticated SMTP submission.

| | Value |
|---|---|
| Status | **Email Sending: public beta.** Email Routing (inbound): GA |
| Plan | **Sending to arbitrary recipients requires Workers Paid** |
| **Pricing** | **3,000 emails/month included, then $0.35 per 1,000** |
| Free | Sends to **verified destination addresses** are free on all plans and don't count toward quota |
| Message size | 5 MiB (25 MiB to verified destinations only) |
| Recipients | 50 per email (to+cc+bcc) |
| Verified destination addresses | 200 per account |
| Domains per zone | 30 |
| SMTP endpoint | `smtp.mx.cloudflare.net:465`, **implicit TLS only**. *"Plaintext SMTP, opportunistic STARTTLS on port 587, and unauthenticated relay on port 25 are not supported for outbound submission."* |

— https://developers.cloudflare.com/email-service/platform/pricing/ · https://developers.cloudflare.com/email-service/platform/limits/

**Two things disqualify it as the primary for launch:**

1. **`"You must be using Cloudflare DNS to use Email Service."`** — verbatim, https://developers.cloudflare.com/email-service/get-started/send-emails/. This is full-zone Cloudflare DNS, because onboarding writes MX records for bounce handling on a `cf-bounce.` subdomain plus SPF (`v=spf1 include:_spf.mx.cloudflare.net ~all`), DKIM (`cf-bounce._domainkey`), and DMARC. For a SaudiNIC-administered `.edu.sa` institutional domain that is a real organisational obstacle (see §11.7).
2. **Undisclosed ramping quota:** *"New accounts start with a conservative daily quota and scale up over time based on your sending behavior, deliverability rates, and account standing."* No published starting number. **For account-verification and password-reset traffic on launch day, an unknown daily cap is unacceptable risk.**

Also note an observability wart: emails sent from a Worker via `send_email` show as **"dropped"** in the Email Routing summary even when delivered — use the Email Sending metrics instead.

### 6.3 Provider comparison

| Provider | Free tier | Cheapest paid | Custom domain, records-only? | Workers support |
|---|---|---|---|---|
| **Resend** ✅ | 3,000/mo, **100/day**, 3 domains | **$20/mo → 50,000/mo**, no daily cap, $0.90/1k overage | ✅ Yes | **Official Workers guide** |
| **Cloudflare Email Service** | none on Workers Free | Workers Paid: 3,000/mo, then **$0.35/1k** | ❌ **Requires Cloudflare DNS** | Native binding, no API key |
| **ZeptoMail** | 1 free credit = 10,000 emails | **~$2.50 / 10,000**, credits valid 6 months | ✅ Yes | REST via `fetch` |
| Brevo | 300/day | ~$9/mo → 5k/mo | ✅ Yes | REST via `fetch` |
| AWS SES | **No standing free tier** (replaced by $200 new-account credits) | **$0.10 / 1,000**, no minimum | ✅ Yes | Yes, via `aws4fetch` SigV4 |
| Postmark | 100/mo | $15/mo → 10,000/mo | ✅ Yes | REST via `fetch` |
| SendGrid | **Free tier discontinued** (60-day trial) | $19.95/mo | ✅ Yes | REST via `fetch` |
| **MailChannels** | ❌ **Free Workers tier ended 2024-08-31** | ~100/day dev plan, card required | ✅ | **Do not build on this** |

⚠️ Brevo, SendGrid and Mailgun figures came from third-party aggregators rather than the vendors' own pages — **re-verify before contracting.** ZeptoMail's own page lists the credit structure but not the currency amount, and its pricing changed for new sign-ups from 2026-07-01.

### 6.4 VERDICT

**PRIMARY: Resend.** Reasons, in order of weight:

1. **No nameserver delegation required.** This is decisive. `athar-dev.edu.sa` is SaudiNIC-administered; getting three DNS records added is realistic, getting full NS delegation to Cloudflare often is not.
2. **SPF lives on a `send.` subdomain**, so you cannot break the institution's existing apex SPF or trip RFC 7208's 10-DNS-lookup limit. The `info@` mailbox is on Google Workspace (per `05-email-and-integrations.md` §0), which means the apex already carries a Microsoft/Google SPF `include:` — appending another one is a live risk of `permerror`, which would silently break **all** institutional mail.
3. **Officially documented Cloudflare Workers support** (https://resend.com/docs/send-with-cloudflare-workers) — works via the `resend@6.26.0` SDK or a bare `fetch` POST to `https://api.resend.com/emails`.
4. **Production-grade, not beta**, with per-message logs and bounce/complaint webhooks — which you need on an unproven `.edu.sa` sending reputation.
5. Idempotency-Key support, which matters for the cron-driven reminder jobs (§8).

**Budget: `$20/month` from launch.** The free tier's **100/day** cap is the binding constraint, and the PRD's own notification matrix (§9.16.1) guarantees you will exceed it on at least two days — registration opening (60 verification + 60 welcome emails) and certificate issuance day (60 issuance emails plus the grade notifications that precede them). Do not plan for $0.

**FALLBACK: Cloudflare Email Service — conditional on DNS control.** If `athar-dev.edu.sa` does end up on Cloudflare nameservers (which §11.7 may force anyway), this becomes very attractive: a native binding with **no API key to store or rotate**, automatic SPF/DKIM/DMARC provisioning, and $0.35/1,000 vs Resend's $0.90/1,000 overage. Re-evaluate after it leaves beta.

**Budget alternative: ZeptoMail** at roughly one-eighth Resend's marginal rate, records-only verification, plain REST from Workers.

**INBOUND: Cloudflare Email Routing — free and unlimited on all plans.** Use it to route `contact@athar-dev.edu.sa` (the address the PRD displays publicly, §1.2) and to catch bounces/replies. This does not require the sending-side DNS commitment.

### 6.5 DNS records and the `.edu.sa` reality

Resend on Cloudflare-hosted DNS requires (https://resend.com/docs/dashboard/domains/cloudflare):

| Type | Name | Value |
|---|---|---|
| MX | `send` | feedback-smtp region host, priority 10 |
| TXT | `send` | `v=spf1 include:amazonses.com ~all` |
| TXT | `resend._domainkey` | DKIM public key |
| TXT | `_dmarc` | `v=DMARC1; p=none; rua=mailto:...` → tighten to `p=quarantine` then `p=reject` |

Practical risks to plan for, all of which map to PRD §18 and Appendix B:

1. **You probably don't control the zone.** Budget lead time for a SaudiNIC/institution ticket.
2. **SPF 10-lookup limit** — using a provider that scopes SPF to a subdomain sidesteps it entirely.
3. **Existing DMARC policy** — if `_dmarc.athar-dev.edu.sa` is already `p=reject`, mail fails until DKIM aligns. Start at `p=none` and monitor.
4. **Regional inbound filters** are aggressive for educational/government domains. Warm up gradually.

**Arabic RTL email templates** (PRD §9.16): set `Content-Type: text/html; charset=UTF-8`; put `dir="rtl" lang="ar"` on `<html>` **and on every table and cell** (`<td dir="rtl" align="right">`) because Outlook's Word engine ignores inherited `direction`; set `text-align: right` explicitly; use table layout, not flexbox; **always send a `text` alternative part** (it improves spam scoring and renders correctly everywhere); do not embed Arabic in images. PRD §9.16 already forbids base64-inlined images — host them externally on R2.

---

## 7. Realtime — chat, notifications, live attendance roster

### 7.1 What the PRD demands

- 1:1 trainer DM, group chat (20–60 people), announcement channel (§9.13)
- **"تحديث لحظي للرسائل دون إعادة تحميل الصفحة"** — live message updates (§9.13.2)
- Unread counters, read receipts
- **Live attendance roster**: *"قائمة بكل المتدربين وحالتهم لحظيًا مع تحديث تلقائي"* (§9.9.7)
- Instant attendance-percentage update after check-in, no page reload (§2.2 Moment 2)

### 7.2 VERDICT — Durable Objects + WebSocket Hibernation

| Limit | Value |
|---|---|
| **Max WebSocket connections per DO** | **32,768** (Hibernation API) |
| WebSocket message size | **32 MiB** received |
| SQLite storage per DO | **10 GB** |
| Requests per object | soft ~1,000 req/s |
| DO classes per account | 500 Paid / 100 Free |
| `serializeAttachment` max | **16,384 bytes** |

— https://developers.cloudflare.com/durable-objects/platform/limits/ · https://developers.cloudflare.com/durable-objects/api/state/

**SQLite-backed Durable Objects are now available on the Free plan** and are the recommended default; KV-backed DOs are legacy.

**Hibernation is what makes this nearly free.** Verbatim from https://developers.cloudflare.com/durable-objects/platform/pricing/:

> "Durable Objects are billed for compute duration (wall-clock time) while the Durable Object is actively running or is idle in memory but unable to hibernate."
> **"Durable Objects that are idle and eligible for hibernation are not billed for duration, even before the runtime has hibernated them."**

And on request billing:

> "There is no charge for outgoing WebSocket messages, nor for incoming WebSocket protocol pings. For compute requests billing-only, a **20:1 ratio** is applied to incoming WebSocket messages… For example, 100 WebSocket incoming messages would be charged as 5 requests."

**You must use `state.acceptWebSocket(ws)`, not `server.accept()`.** With `server.accept()` the DO stays resident and accrues duration charges for the entire connection lifetime — the single most common way to blow up a Durable Objects bill.

Handlers become `webSocketMessage(ws, msg)`, `webSocketClose(ws, code, reason, wasClean)`, `webSocketError(ws, err)`. Per-connection state (`{userId, role, cohortId}`) goes in `ws.serializeAttachment(...)` (≤16 KB). Use `state.setWebSocketAutoResponse()` to answer app-level pings without waking the object.

**Sharding plan for this project:**

| DO class | Instance key | Purpose |
|---|---|---|
| `ThreadRoom` | `thread:{threadId}` | 1:1 DM, group chat, announcements |
| `SessionRoster` | `session:{sessionId}` | Live attendance roster + presence during a training session |
| `UserInbox` | `user:{userId}` | Notification bell fan-out, unread counts |

**Cost math at this scale:** 200 users connected ~8 h/day, mostly idle → hibernated → **≈0 GB-s duration**. 5,000 inbound messages/day → 250 billed requests/day at the 20:1 ratio, plus ~200 connection-establishment requests. That stays inside the **free** 100,000 requests/day allowance with enormous margin. **Realtime is effectively free on this platform.**

### 7.3 ⚠️ The integration risk: WebSockets and Next.js

**You cannot perform a WebSocket upgrade inside a Next.js Route Handler.**

- Next.js has no WebSocket API. The RFC "WebSocket Upgrades in Route Handlers" (https://github.com/vercel/next.js/discussions/95514) is **status: Draft, target: Experimental** as of 2026-07-06, and lists edge-runtime support as a *non-goal*.
- Mechanically, a Cloudflare upgrade requires `new Response(null, { status: 101, webSocket: client })`. The standard Web `Response` constructor rejects status 101, and Next.js's response pipeline does not pass through Cloudflare's `webSocket` init property.
- Neither the OpenNext supported-features list nor its known-issues page mentions WebSockets.

**The solution is the documented custom Worker entrypoint** (https://opennext.js.org/cloudflare/howtos/custom-worker). Because you own the `fetch` function, you intercept the upgrade before Next.js ever sees it:

```ts
// worker/index.ts
import { default as handler } from "../.open-next/worker.js";

export default {
  async fetch(request: Request, env: CloudflareEnv, ctx: ExecutionContext) {
    const url = new URL(request.url);
    if (url.pathname.startsWith("/ws/") && request.headers.get("Upgrade") === "websocket") {
      return routeToDurableObject(request, env);
    }
    return handler.fetch(request, env, ctx);
  },
  async scheduled(controller, env, ctx) { /* §8 */ },
} satisfies ExportedHandler<CloudflareEnv>;

export { ThreadRoom, SessionRoster, UserInbox } from "./durable-objects";
export { DOQueueHandler, DOShardedTagCache } from "../.open-next/worker.js";
```

Then point `wrangler.jsonc` `"main"` at this file. Durable Objects in the **same** Worker are supported — opennextjs-cloudflare issue **#207** ("[FEATURE] Support DurableObjects") is **closed/done**. The older "put DOs in a separate Worker" advice is superseded.

> ⚠️ **Spike this first.** No first-party document explicitly confirms the WebSocket-intercept pattern working with OpenNext specifically. It follows necessarily from (a) you own the fetch handler and (b) Workers supports 101 upgrades natively — but **build a 20-line proof before committing the architecture.** This is the single highest-uncertainty item in the document.

> ⚠️ **Side effect: Preview URLs will not work.** Cloudflare does **not** generate Preview URLs for Workers that implement a Durable Object (https://developers.cloudflare.com/workers/versions-and-deployments/preview-urls/). See §11.2 for the mitigation.

### 7.4 Alternatives considered and rejected

| Option | Why rejected |
|---|---|
| **SSE (Server-Sent Events)** | Works on Workers (`ReadableStream` + `text/event-stream`, no duration limit while the client is connected), but is one-directional and — decisively — **a DO holding an SSE stream cannot hibernate**, so it accrues GB-s continuously. Worse economics than WebSockets. |
| **Polling** | 200 users × 12 polls/min = ~3.5 M requests/day. Blows the free DO request allowance immediately, and PRD §2.1 forbids "ambiguous waiting". Acceptable only as a degraded fallback for the roster. |
| **Pusher Channels** | Sandbox free tier caps at **100 concurrent connections** — below the 200-user ceiling. Startup is **$49/mo**. |
| **Ably** | Free: 200 concurrent connections — *exactly* the ceiling with zero headroom. Standard $29/mo. |
| **Supabase Realtime** | Free: 200 concurrent, 2 M messages/mo. ⚠️ **Fan-out multiplier**: every subscribed client counts as a message, so one message in a 60-person group chat bills 60. |
| **Cloudflare Realtime / RealtimeKit** | **Not relevant.** It is WebRTC audio/video (SFU, STUN/TURN) — https://blog.cloudflare.com/introducing-cloudflare-realtime-and-realtimekit/. Only becomes interesting if the platform later hosts live video sessions itself instead of linking to Zoom. |
| **PartyKit** | Acquired by Cloudflare April 2024; folded into the platform, not a separately-marketed product to build on. |

---

## 8. Scheduled jobs — Cron Triggers

### 8.1 What the PRD demands (§9.9.5)

A job **every 15 minutes** that:
- converts non-checked-in trainees in a finished session to `absent` (BR-08)
- converts checked-in-but-not-checked-out to `incomplete` and notifies the trainer (BR-09)
- recomputes each trainee's attendance rate

Plus, from §9.16.1, time-based notifications: session reminders at T-24h and T-1h, assignment reminders at T-48h and T-6h.

### 8.2 Facts

| Item | Value |
|---|---|
| Syntax | Five-field cron + Quartz-like extensions. **`*/15 * * * *` is valid.** |
| Minimum granularity | **1 minute** |
| Cron Triggers per **account** | **5 Free / 250 Paid** |
| Timezone | **UTC only** |
| **CPU per cron invocation** | Free **10 ms** · Paid **30 s** (intervals <1 h) · 15 min (intervals ≥1 h) |
| Wall time | **15 minutes** |
| `ctx.waitUntil` | ✅ available in `scheduled()` |
| Local test | `curl "http://localhost:8787/cdn-cgi/handler/scheduled?format=json"` |

— https://developers.cloudflare.com/workers/configuration/cron-triggers/ · https://developers.cloudflare.com/workers/platform/limits/

Handler signature: `async scheduled(controller, env, ctx)`, with `controller.cron` (the matched expression — use it in a `switch` for multiple schedules) and `controller.scheduledTime`.

⚠️ **The Free plan's 10 ms cron CPU makes this job impossible.** Another independent reason Workers Paid is mandatory.

### 8.3 ⚠️ Cloudflare publishes NO reliability guarantee for Cron Triggers

I could find **no official statement** about execution guarantees, delays, or missed-run behaviour on either the cron-triggers page or the scheduled-handler page — no SLA, no "best effort", no "may be delayed" language. **This is an absence of documentation, not a claim of unreliability.**

By contrast, **Durable Object Alarms explicitly document at-least-once execution** with automatic retry: *"exponential backoff starting at a 2 second delay from the first failure with up to 6 retries"* (https://developers.cloudflare.com/durable-objects/api/alarms-in-durable-objects/).

**Mandatory design consequence — this is a correctness requirement, not an optimisation.** PRD §9.9 governs attendance, which the Constitution (Art. 8) treats as a personal right. The sweep must be **idempotent and watermark-based**: process *"every session whose check-out window closed since the last successful watermark"*, **not** *"everything in the last 15 minutes"*. A skipped run then self-heals on the next tick. Store the watermark in the database, advance it only after a successful pass.

If a hard guarantee is later required, back the sweep with a self-re-arming **Durable Object Alarm** (which does document at-least-once) and let cron act only as a watchdog.

### 8.4 Wiring it into OpenNext

The generated `.open-next/worker.js` exports only a `fetch` handler. The **custom Worker entrypoint** from §7.3 is where `scheduled()` goes — the same file that intercepts WebSockets and exports the Durable Object classes. One entrypoint solves both platform needs.

```jsonc
{ "main": "./worker/index.ts", "triggers": { "crons": ["*/15 * * * *"] } }
```

### 8.5 Queues and Workflows — available, but not needed

**Cloudflare Workflows is GA** (since 2025-04-07) on Free and Paid. Paid: 10 M requests/mo, 30 M CPU-ms/mo, **500,000 steps/mo** then $0.80/100k. Step and storage billing began 2026-08-10.

**Cloudflare Queues:** Free 10,000 ops/day; Paid 1 M ops/mo then $0.40/M. One operation = each 64 KB written, read, or deleted; typical delivery ≈ 3 operations.

**Verdict: both are overkill for a 15-minute sweep over 60–200 users.** A plain `scheduled()` handler is sufficient and stays well inside the 30 s CPU budget. Adopt Queues **only if** the sweep later needs to fan out per-trainee work that exceeds 30 s CPU — then cron enqueues and consumers each get an independent budget. Adopt Workflows only if the sweep needs durable multi-step retries with human-in-the-loop (e.g. mark absent → notify trainer → wait 24 h for an excused-absence override → recompute).

> One Queue **is** worth adopting immediately if you choose R2 upload option B in §5.4 — R2 event notifications are delivered to Queues.

---

## 9. PDF, PNG, and QR generation — including Arabic shaping

**This is the hardest section in the document. Read the Arabic subsection before choosing anything.**

### 9.1 What the PRD demands

| Artifact | Requirement |
|---|---|
| Certificate PDF (§9.17) | Athar branding, **four-part Arabic name + English name**, program name, hours, completion date, serial `ATHAR-AI101-2026-0001`, QR verification code |
| Attendance / schedule / grade reports (§9.9.6, §9.9.7) | Arabic tables, exportable to PDF and Excel |
| Digital card PNG (§9.6.3) | *"صورة PNG بدقة عالية (ضعف الأبعاد المعروضة على الأقل)"* — at least 2× displayed dimensions |
| Digital card PDF (§9.6.3) | Standard printable card size |
| QR code (§9.6.2) | Generated **server-side**, ≥120 px, scannable from 20 cm with a normal phone camera |

### 9.2 ⚠️ Why Arabic makes this hard

Arabic requires two things that Latin text does not:

1. **Contextual shaping** — each letter has up to four positional forms (isolated, initial, medial, final) selected by OpenType GSUB rules, plus mandatory ligatures (lam-alef). Rendering the raw Unicode code points produces **disconnected letters**.
2. **Bidirectional reordering** — the UAX#9 bidi algorithm, needed the moment Arabic text contains Latin-script names, GitHub URLs, or the Latin digits the PRD mandates (§5.4: *"الأرقام اللاتينية (1234) هي التوصية"*).

A PDF library that only embeds a font and draws glyphs in logical order gets **both** wrong.

### 9.3 What does NOT work — verified

| Approach | Verdict |
|---|---|
| **`pdf-lib@1.17.1`** | ❌ **Does not shape Arabic.** Standard fonts throw `WinAnsi cannot encode` on Arabic (issue #435). With a custom font it draws unshaped, unjoined glyphs. Bidi is broken — issue **#1450** *"Arabic text with numbers, numbers gets reversed"* is **open since 2023-04-29**. Issue #1697 shows corrupted Arabic extraction. `@pdf-lib/fontkit@1.1.1` provides **embedding and subsetting, not GSUB layout**. |
| **`satori@0.33.4`** | ❌ **Does not shape Arabic today.** Issues **#420 "Not supporting RTL"** and **#421 "Satori crashes if the text only consists of Arabic letters"** (both 2023, closed). The fix — **PR #745 "feat: RTL (Arabic/Hebrew) text rendering and layout" — is still OPEN** (created 2026-04-03) and references an upstream OpenType.js problem with Arabic font features. https://github.com/vercel/satori |
| `@react-pdf/renderer` | ❌ Same fontkit-based layout limitations; not verified working on workerd. |
| `pdfkit` | ❌ Node-stream dependent. |
| `arabic-reshaper` + `bidi-js` "reshape then reverse" hack | ⚠️ Produces *readable* output for simple strings but fails on kashida, optional ligatures, nested bidi runs, and mixed Latin/Arabic/digits — **exactly the certificate's content** (Arabic name + Latin name + `ATHAR-AI101-2026-0001`). **Not acceptable for a document a trainee will show an employer.** |

### 9.4 ✅ VERDICT — Cloudflare Browser Rendering ("Browser Run")

**Render HTML in real headless Chrome and let Chrome's own HarfBuzz do the shaping.** This is the only approach that is *correct by construction* rather than approximately correct.

Two REST "Quick Actions" cover everything:

**`POST /pdf`** — https://developers.cloudflare.com/browser-rendering/rest-api/pdf-endpoint/
- Accepts `"html"` directly (no public URL needed) or `"url"`
- `"addStyleTag"` — inject CSS `content` or an external stylesheet `url`
- `"gotoOptions.waitUntil": "networkidle0"`
- `"pdfOptions"` — `format`, `printBackground`, `margin`
- `"setExtraHTTPHeaders"`
- Accepts request bodies up to **50 MB**

**`POST /screenshot`** — https://developers.cloudflare.com/browser-rendering/rest-api/screenshot-endpoint/
- `"html"`, `"viewport": { "width", "height", "deviceScaleFactor" }` ← **`deviceScaleFactor: 2` satisfies the PRD's "at least 2× displayed dimensions"**
- `"screenshotOptions": { "type": "png", "omitBackground", "fullPage", "clip" }`
- `"selector"` — clip to the card element with a CSS selector
- `"addStyleTag"`, `"addScriptTag"`

**On Arabic fonts — the key question, answered.** Verbatim from the `/pdf` docs:

> "If your PDF requires a font that is not pre-installed in the Browser Run environment, you can load custom fonts using the `addStyleTag` parameter."

So: ship `IBM Plex Sans Arabic` (PRD §5.4) as a `@font-face` in the injected style tag. Two delivery options:
- **`addStyleTag: [{ url: "https://ai.athar-dev.edu.sa/fonts/athar-fonts.css" }]`** — the font files are already in Workers Static Assets for the web app, cost nothing extra, and are served from your own domain. **Recommended.**
- Or inline a base64 WOFF2 subset in `addStyleTag.content` for full hermeticity.

Either way, wait for `document.fonts.ready` (or `waitUntil: "networkidle0"`) before capture so text is not rendered in a fallback face.

⚠️ **I could not find a documented list of fonts pre-installed in the Browser Run environment.** Do not assume any Arabic font is present — **always inject your own.** This is stated as an unknown, not a guess.

**Limits and cost** — https://developers.cloudflare.com/browser-rendering/platform/limits/ · `.../pricing/`

| | Workers Free | Workers Paid |
|---|---|---|
| Browser hours | **10 minutes/day** | **10 hours/month, then $0.09/hour** |
| Concurrent browsers | 3/account | 10 monthly-average, then $2.00/browser; hard cap 200 |
| New browser instances | 1 per 20 s | 3/second |
| Quick Actions rate | 1 per 10 s | **30/second** |
| Browser idle timeout | 60 s (extendable to 10 min via `keep_alive`) | same |

**Quick Actions (`/pdf`, `/screenshot`) bill browser hours only — not concurrent-browser count.** That is exactly the cheap path. Realistic usage for this project: 60 certificates × ~4 s ≈ 4 minutes; 200 card downloads × ~3 s ≈ 10 minutes; report exports maybe 30 minutes/month. **Total well under 1 hour/month against a 10-hour included allowance. Effectively free.**

⚠️ **Free plan is unusable here**: 10 minutes/day of browser time and 1 Quick Action per 10 seconds. Yet another reason Workers Paid is mandatory.

**Implementation notes:**
- Prefer the **REST Quick Actions** over the Puppeteer binding (`@cloudflare/puppeteer@1.4.0`): Quick Actions do not bill concurrency, add no bundle weight, and need no session lifecycle management. If you ever use the binding, **always `browser.close()` in a `finally`** — unclosed sessions burn browser-hours until timeout.
- Generate certificates **asynchronously**: the admin's bulk-issue action enqueues, and a background pass renders and stores to R2. Do not block an HTTP response on 60 renders.
- Store the rendered PDF in R2 and serve it via a 15-minute presigned URL (§5.2), satisfying PRD §12.5.
- The certificate HTML is a normal RTL page built from the same Design Tokens as the app (Constitution Art. 6) — which is a real benefit: **the certificate and the UI cannot drift apart.**

**Runner-up: `harfbuzzjs` (HarfBuzz compiled to WASM) + `pdf-lib`.** Shape the text yourself with HarfBuzz, then draw the resulting glyph IDs with `pdf-lib`'s low-level glyph API. This is genuinely correct and removes the Browser Rendering dependency, but it means owning bidi reordering, line breaking, and justification yourself — days of work plus a permanent maintenance burden — to save a service that costs ~$0. **Only pursue this if Browser Rendering is unavailable in the account.**

### 9.5 QR codes

**Chosen: `uqr@0.1.3`** (published 2026-04-03) — *"Generate QR Code universally, in any runtime, to ANSI, Unicode or SVG."* **Zero dependencies**, single export, pure JS. Generate SVG server-side and embed it directly in the certificate/card HTML before rendering. SVG keeps the code crisp at any DPI, which is what makes PRD §9.6's *"قابل للمسح فعليًا من مسافة 20 سم"* achievable.

**Runner-up: `@paulmillr/qr@0.3.0`** — also zero-dependency, supports SVG and GIF, useful if you ever need a raster QR without a browser.

❌ **Do not use `qrcode@1.5.4`** — it depends on `pngjs`, `yargs` and `dijkstrajs` and is built around Node canvas.

QR content per PRD §9.6.2: `https://ai.athar-dev.edu.sa/verify/{token}` where the token is long, random and signed — **never the user id**. Build the absolute URL from the single `APP_BASE_URL` environment variable (PRD §1.2 / BR-36).

### 9.6 Excel export

PRD §9.9.7 and §9.18 require Excel export. **Generate CSV with a UTF-8 BOM (`﻿`) rather than XLSX** — Excel on Windows will otherwise mis-decode Arabic. If true `.xlsx` is required, use a pure-JS writer with no Node dependencies and verify it bundles under the 10 MB gzip limit; this should be validated during the build, not assumed.

---

## 10. Rate limiting

### 10.1 What the PRD demands (§12.4)

| Operation | Limit | Key |
|---|---|---|
| Login | 5 attempts / 15 min | **per account** |
| Registration | 5 attempts / hour | per IP |
| Password reset | 3 requests / hour | **per email** |
| Check-in / check-out | 10 requests / min | **per user** |
| File upload | 20 operations / hour | **per user** |
| Send message | 30 messages / min | **per user** |
| Public read APIs | 100 requests / min | per IP |

**Five of the seven are keyed on identity, not IP.** That is the decisive constraint.

### 10.2 The four options, honestly compared

**(a) Cloudflare WAF Rate Limiting Rules** — https://developers.cloudflare.com/waf/rate-limiting-rules/

| | Free | Pro | Business | Enterprise + App Sec |
|---|---|---|---|---|
| Number of rules | **1** | 2 | 5 | 100 |
| Counting characteristics | **IP only** | **IP only** | IP, IP w/ NAT | IP, NAT, JA3/JA4, headers, cookies, JSON fields, body |
| Counting period | **10 s only** | up to 1 min | up to 10 min | up to 65,535 s |
| Mitigation timeout | **10 s** | up to 1 h | up to 1 day | up to 1 day |

Caveat: *"There may be a delay of up to a few seconds between detecting a request and updating rate counters."*

**Verdict: cannot do per-user limiting below Enterprise.** But the single Free rule is genuinely useful as an outer perimeter — it runs at the edge **before your Worker is invoked**, so it protects your request quota for free.

**(b) Workers Rate Limiting binding** — https://developers.cloudflare.com/workers/runtime-apis/bindings/rate-limit/

**GA since 2025-09-19** (requires Wrangler ≥ 4.36.0).

```jsonc
{ "ratelimits": [{ "name": "RL_LOGIN", "namespace_id": "1001", "simple": { "limit": 5, "period": 60 } }] }
```
```ts
const { success } = await env.RL_LOGIN.limit({ key: `login:${email}` });
```

- **`simple.period` must be either `10` or `60` seconds.** No other values. **This alone cannot express "5 per 15 minutes" or "3 per hour".**
- ⚠️ **It is per-colo, and Cloudflare says so explicitly:**
  > **"For each unique key you pass to your rate limiting binding, there is a unique limit per Cloudflare location."**
  > **"The Rate Limiting API is permissive, eventually consistent, and intentionally designed to not be used as an accurate accounting system."**

  A limit of 100/min is really *100/min per PoP*. A user reaching three PoPs gets 300/min.
- Counters are cached on the same machine as the Worker → **no added latency**.
- Sharing a `namespace_id` across Workers shares state. Not visible in the dashboard — observe via Workers Logs.

**(c) Durable Objects** — exact, globally authoritative, arbitrary windows.

⚠️ **Cloudflare explicitly warns against the naive version.** From https://developers.cloudflare.com/durable-objects/best-practices/rules-of-durable-objects/: *"Do not create a single 'global' Durable Object that handles all requests"* — calling out **"🔴 Bad: Global rate limiter — ALL requests go through one instance."** The correct pattern is **one DO per rate-limit key** (`idFromName(\`login:${email}\`)`), which shards naturally.

Cost: every check is a DO request. Keep the counter **in memory** inside the DO and persist only periodically, so `rows written` stays near zero.

**(d) Workers KV** — ❌ **Disqualified.** Eventually consistent (up to 60 s+), no atomic increment, and concurrent increments lose updates through read-modify-write races. A KV rate limiter systematically under-counts under exactly the burst conditions it exists to catch. (Reasoned from https://developers.cloudflare.com/kv/concepts/how-kv-works/ — Cloudflare does not publish a "don't use KV for counters" statement, so this is inference from the documented consistency model, stated as such.)

### 10.3 VERDICT — three layers

| Layer | Tool | Covers |
|---|---|---|
| **1. Edge perimeter** | WAF rate limiting rule (Free zone: 1 rule, IP, 10 s) on `/api/*` | Crude floods, before the Worker runs. Free. |
| **2. Cheap per-user** | Workers Rate Limiting binding, keyed on `user:{id}` | **Check-in (10/min)**, **messages (30/min)**, **public reads (100/min per IP)** — all naturally expressible in a 60 s window. Set the configured limit **conservatively** to absorb per-colo slop: for a true 30/min global, configure ~12/min. |
| **3. Accurate, security-critical** | **Durable Object counters, one DO per key** | **Login (5/15 min per account)**, **password reset (3/h per email)**, **registration (5/h per IP)**, **uploads (20/h per user)** — none of which the binding's 10/60 s windows can express, and all of which are security controls where an inaccurate count is a vulnerability. |

**Note:** PRD §9.3.1 also requires a **15-minute account lockout after 5 failed logins**, with `failed_login_count` and `locked_until` columns already in the data model (§7.1). That is a **database-level** control and is the authoritative one; the DO rate limiter is defence-in-depth in front of it, protecting the database and the password-hash CPU cost from being consumed by a brute-force attempt.

---

## 11. Platform operations

### 11.1 Secrets

| | `wrangler secret put` | Secrets Store |
|---|---|---|
| Status | GA | **Open beta** |
| Scope | Per Worker | Account-level, **one store per account** |
| Count | **64 Free / 128 Paid** per Worker | **100 per account** |
| Value size | **5 KB** | **1024 bytes** |
| Access | `env.MY_SECRET` (sync) | `await env.MY_SECRET.get()` (async) |
| Local dev | works | **Production secrets not readable locally** |
| Pricing | included | **undocumented** |

— https://developers.cloudflare.com/workers/configuration/secrets/ · https://developers.cloudflare.com/secrets-store/

**Chosen: `wrangler secret put` per environment.** Secrets Store is beta, capped at 1024-byte values, requires an `await` on every read, and has no published pricing. Revisit at GA.

Secrets required (mapping PRD §6.4 to this stack):

```
DATABASE_URL              # only for migrations from CI; runtime uses the Hyperdrive binding
AUTH_SECRET               # Better Auth signing key
RESEND_API_KEY
R2_ACCESS_KEY_ID
R2_SECRET_ACCESS_KEY
TURNSTILE_SECRET_KEY
CF_ACCOUNT_ID             # Browser Rendering REST
CF_BROWSER_RENDERING_TOKEN
SENTRY_DSN                # only if using the SDK; not needed for the OTel path
```

`APP_BASE_URL` and `APP_TIMEZONE` are **plain `vars`, not secrets** — and per BR-36 all absolute URLs in emails, QR codes and verification pages must be built from `APP_BASE_URL` alone.

> 🔴 **Carry over DECISIONS D-15**: the `info@athar-dev.edu.sa` mailbox password was transmitted in plaintext and **must be rotated**. On this architecture it is never needed — Resend uses an API key, and mailbox credentials are never stored.

### 11.2 Environments and previews

**Environments** use the `env` key. An environment deploys a **separate Worker** named `<name>-<env>`. Select with `--env` / `-e`, or `CLOUDFLARE_ENV`.

⚠️ **Bindings and `vars` are non-inheritable** — *"Non-inheritable keys are configurable at the top-level, but cannot be inherited by environments and must be specified for each environment."* **You must repeat the entire binding block for every environment.** Inheritable: `name`, `main`, `compatibility_date`, `compatibility_flags`, `routes`, `triggers`, `preview_urls`.

This maps cleanly onto PRD §6.4's three environments, each with its own database:

| PRD environment | Worker | Database |
|---|---|---|
| Development | `wrangler dev` | Neon **branch** `dev` |
| Staging (`staging.ai.athar-dev.edu.sa`) | `athar-platform-staging` | Neon **branch** `staging` |
| Production (`ai.athar-dev.edu.sa`) | `athar-platform` | Neon `main` |

**Neon's 10 included branches make this nearly free** and give staging a real copy-on-write clone of production structure — a materially better story than provisioning three separate D1 databases.

⚠️ **Preview URLs will NOT work for this Worker.** Verbatim: Preview URLs are **not generated for Workers that implement a Durable Object** (https://developers.cloudflare.com/workers/versions-and-deployments/preview-urls/). Since §7 requires Durable Objects in the main Worker, per-PR preview deployments are unavailable. Also note *"You cannot view logs for Preview URLs — this includes Workers Logs, `wrangler tail`, and Logpush."*

**Mitigation:** use the **staging environment** as the review environment (`wrangler deploy --env staging` from CI on every merge to `develop`), and gate it behind **Cloudflare Access**. Accept that per-PR ephemeral previews are not available on this architecture. This is a real, permanent limitation to communicate to the team.

**Workers Builds** (Cloudflare's Git-connected CI) is **GA**: 3,000 build-minutes/month Free, 6,000 Paid then $0.005/min; 20-minute build timeout; Node 24.18.0 default. https://developers.cloudflare.com/workers/ci-cd/builds/limits-and-pricing/

### 11.3 Observability

**Workers Logs — GA, included on both plans.**

| | Free | Paid |
|---|---|---|
| Log events | 200,000/day | **20 million/month, then $0.60/million** |
| **Retention** | **3 days** | **7 days** |

Hard caps: max retention **7 days**; single log max **256 KB**; 5 billion logs/account/day, after which a 1% head sample applies.

```jsonc
{ "observability": { "enabled": true, "head_sampling_rate": 1 } }
```

⚠️ **7-day retention is short** for a platform that must produce audit evidence. **PRD §12 and BR-27 require an immutable `AuditLog` — that lives in the database, not in Workers Logs.** Workers Logs is for operational debugging only. Do not conflate the two. Note also PRD §15's requirement of *"سجلات منظمة للعمليات الحساسة، دون تسجيل أي بيانات شخصية"* — structured logs with **no personal data**: enforce a redaction helper in `lib/log`.

**`wrangler tail`**: max **10 concurrent clients** per Worker; enters sampling mode under high traffic (no documented threshold).

**Sentry — three routes, and the third is the one to take:**

| Route | Assessment |
|---|---|
| `@sentry/cloudflare@10.73.0` SDK | Works; requires `nodejs_compat` (needs `AsyncLocalStorage`) |
| Sentry's Next.js-on-Cloudflare guide | **Officially documented**; requires `compatibility_date >= "2025-08-16"` (introduces `https.request`). ⚠️ Open issues report `AsyncLocalStorage` errors in `captureRequestError` / `onRequestError` and silent server-side init failures (getsentry/sentry-javascript #18842, #14931). Client-side and source-map upload work fine. |
| ✅ **Cloudflare native OTel export to Sentry** | **Recommended.** No SDK in your bundle, no `nodejs_compat` dependency, no `AsyncLocalStorage` risk. Configure a destination in the dashboard, then declare it in `wrangler.jsonc`. Traces + logs only — **Worker metrics are not yet supported**. https://developers.cloudflare.com/workers/observability/exporting-opentelemetry-data/sentry/ |

```jsonc
{
  "observability": {
    "enabled": true,
    "head_sampling_rate": 1,
    "traces": { "enabled": true, "destinations": ["sentry-traces"], "head_sampling_rate": 0.2 },
    "logs":   { "enabled": true, "destinations": ["sentry-logs"] }
  }
}
```

Add the **client-side** Sentry SDK separately in the browser bundle for front-end error tracking (PRD §15) — it does not touch the Worker.

### 11.4 Turnstile — the invisible CAPTCHA (PRD §9.2.2)

**Free, with "Unlimited challenges (traffic or verification requests)."** 20 widgets per account, 10 hostnames per widget, 7-day analytics. https://developers.cloudflare.com/turnstile/plans/

Three modes: **Managed**, **Non-Interactive**, and **Invisible** (widget completely hidden). PRD §9.2.2 asks for *"CAPTCHA غير مرئي"*.

⚠️ **Invisible mode carries a legal obligation**: *"As a condition of enabling invisible mode, you must reference Cloudflare's Turnstile Privacy Addendum in your own privacy policy."* PRD §12.6 already requires a privacy policy — **add the Turnstile addendum reference to it.** This is a launch-checklist item.

Server-side validation: `POST https://challenges.cloudflare.com/turnstile/v0/siteverify` with `secret` + `response` (+ optional `remoteip`, `idempotency_key`). **Token is valid 5 minutes and is single-use** — replay returns `timeout-or-duplicate`.

**CSP note:** Turnstile is hosted at `challenges.cloudflare.com` — it must be allowed in the CSP that PRD §12.3 mandates.

**React component:** no official Cloudflare package. Cloudflare explicitly recommends the community one: *"Cloudflare recommends **@marsidev/react-turnstile** when rendering Turnstile. We have deployed an implementation of the library and can confirm that it is safe to use and works as expected."* — `@marsidev/react-turnstile@1.6.1`.

### 11.5 Avatars — Cloudflare Images binding

PRD §7.1 stores `avatar_url`; avatars appear in the header, digital card and chat (§2.4) at effectively 256 px and 64 px.

**Chosen: the Images binding (`env.IMAGES`), reading bytes straight from R2.** This is the cleanest path because **it requires no zone configuration at all** — unlike `/cdn-cgi/image/` URL transformations, which require a zone on Cloudflare with transformations enabled and the R2 domain added to the allowed sources list.

```jsonc
{ "images": { "binding": "IMAGES" } }
```
```ts
const out = await env.IMAGES.input(object.body).transform({ width: 256 }).output({ format: "image/webp" });
```

**Pricing:** Free plan includes **5,000 unique transformations/month**; beyond that **$0.50 / 1,000**. A "unique transformation" = one source image + one parameter set, billed **once per calendar month** regardless of repeat requests. `format=auto` counts as one even if served as both AVIF and WebP. For 60–200 avatars at two sizes, this is **comfortably free**.

⚠️ **Binding responses are NOT cached automatically.** Every uncached call fully decodes and re-encodes, burning Worker CPU. **Enable Workers Cache and set `Cache-Control`.** Also note `.input()` is capped at **20 MB**.

Limits: 100 MB remote file, 100 MP area, 12,000 px max dimension (1,200 px for AVIF). Input formats include PNG, JPEG, GIF, WebP, SVG, HEIC — **HEIC matters**, since iPhone users will upload HEIC avatars.

This also backs `next/image` under OpenNext, which uses the same binding. ⚠️ Note open issue **#1125** *"Cloudflare images binding takes excessive CPU time"* — another reason to cache aggressively.

**Alternative considered:** `@cf-wasm/photon@0.4.0` — avoids per-transformation cost but burns Worker CPU and adds significant WASM weight against the 10 MB gzip budget. Not worth it when the free tier covers the workload.

### 11.6 Security headers, CSP and CSRF (PRD §12.3)

- Set CSP, HSTS, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` in Next.js middleware for Worker-served routes **and** in a Workers `_headers` file for static assets (because with `run_worker_first: false`, middleware does not run for assets — §1.8).
- CSP must allow `challenges.cloudflare.com` (Turnstile) and your R2/Images delivery host.
- **CSRF:** Better Auth issues `SameSite=Lax` cookies; add an origin check on every state-changing Route Handler and Server Action. Note that `SameSite=Lax` alone does **not** protect top-level `POST` navigations in all browsers.

### 11.7 ~~🔴 DNS — the biggest operational risk~~ ✅ **RESOLVED BY MEASUREMENT 2026-09-04**

> **This entire section is superseded.** `athar-dev.edu.sa` is already a **full Cloudflare zone** (NS = `jermaine`/`molly.ns.cloudflare.com`, verified by live DNS query). Option ① below is the existing reality. Ignore the cost and complexity analysis of options ②–④. See `00-verified-infrastructure-facts.md`.

The original analysis is retained below for the record.

**To attach a Worker to a custom domain, you need an *active Cloudflare zone that you own*** (https://developers.cloudflare.com/workers/configuration/routing/custom-domains/). Verified plan availability:

| Zone setup | Free | Pro | Business | Enterprise |
|---|---|---|---|---|
| **Primary (Full)** — move nameservers to Cloudflare | ✅ | ✅ | ✅ | ✅ |
| **CNAME (Partial)** — keep your DNS provider | ❌ | ❌ | ✅ | ✅ |
| **Subdomain setup** — a zone for `ai.` only | ❌ | ❌ | ❌ | ✅ |

Verbatim: *"A CNAME setup (partial) is only available to customers on a Business or Enterprise plan."* (https://developers.cloudflare.com/dns/zone-setups/partial-setup/) · *"Subdomain setup is only available for Enterprise accounts."* (https://developers.cloudflare.com/dns/zone-setups/subdomain-setup/setup/)

**There is no Cloudflare-side restriction on `.edu.sa` as a TLD.** The constraint is purely whether nameservers can be changed at SaudiNIC.

**Four options, ranked:**

| # | Option | Cost | Requires |
|---|---|---|---|
| **①** | **Full setup on a Free zone.** Move `athar-dev.edu.sa` nameservers to Cloudflare; `ai.` becomes a Worker Custom Domain. | **$0** | SaudiNIC NS change for the **whole institutional domain**. **All existing records — including the Google Workspace MX for `info@` — must be recreated in Cloudflare DNS first.** |
| **②** ⭐ | **Cloudflare for SaaS custom hostname.** You own any zone on Cloudflare (e.g. a project-owned domain) on **any plan**; the institution adds **one CNAME** for `ai.athar-dev.edu.sa` at their existing DNS host; Cloudflare issues the cert via DCV. | **$0** — **available on the Free plan with 100 hostnames included**, $0.10 each thereafter | Only a **single CNAME record** from the institution. Nameservers never move. https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/plans/ |
| ③ | Partial (CNAME) setup on `athar-dev.edu.sa` | **$200/mo annual / $250/mo monthly (Business)** | Business plan |
| ④ | Ship on `*.workers.dev` | $0 | Nothing — but violates PRD §1.2's mandated domain |

**Option ② is the answer to "the institution won't move nameservers."** Wiring it to a Worker: create an originless fallback origin (e.g. `service.example.com AAAA 100::`) and add a Worker route `*/*` on the SaaS zone — https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/start/advanced-settings/worker-as-origin/. Constraint: *"Do not configure a custom hostname which matches the zone name."*

**Do NOT budget $200–250/month for a Business plan until option ② has been ruled out for a policy reason.**

⚠️ **Interaction with §6.2:** Cloudflare Email Service requires **full** Cloudflare DNS. Under option ②, Email Service is unavailable for `athar-dev.edu.sa` and Resend is not merely preferred, it is **required**. Under option ①, Email Service becomes available.

⚠️ **Flagged as not fully verified:** Cloudflare's plans table shows Cloudflare for SaaS availability as Yes on Free, and the worker-as-origin guide states no plan requirement — but that guide does **not explicitly confirm Free-plan support**. **Test this in the dashboard before architecting around it.**

---

## 12. Cost estimate — 60–200 active users

Assumptions: 60 trainees + 3 trainers + 2 admins in cohort 1 (A-01), scaling to 200; ~4-week program with 12 sessions; ~500 K Worker requests/month; ~2 GB database; ~20 GB uploaded files; ~4,000 emails/month at peak.

| Service | Plan / usage | Monthly cost |
|---|---|---|
| **Workers Paid** | $5 base; 10 M requests + 30 M CPU-ms included. Our ~500 K requests and hashing CPU are far inside. | **$5.00** |
| **Cloudflare zone** | **Free** (see §11.7 — do not buy Pro/Business) | **$0.00** |
| **Hyperdrive** | Included on Workers Paid; unlimited queries; no egress charge | **$0.00** |
| **Neon Postgres (Launch)** | $0 base + ~0.25 CU avg with scale-to-zero (~$10 at $0.106/CU-hour) + 2 GB storage ($0.70) + 7-day PITR (~$0.40). **Not Scale** — 30-day retention comes from the R2 dumps instead (§2.4.1). | **$5 – $20** |
| **R2** | 20 GB uploads + ~31 daily SQL dumps (~1 GB) = 21 GB ($0.17 after the 10 GB free tier). Well under 1 M Class A / 10 M Class B free ops. **Egress free.** | **$0.00 – $0.20** |
| **Durable Objects** | Hibernated WebSockets ⇒ ~0 GB-s. ~250 billed req/day (20:1 ratio) + rate-limiter DOs. Inside the 1 M req/mo included. | **$0.00** |
| **Workers KV** | Landing settings + i18n cache. Inside 10 M reads / 1 M writes included. | **$0.00** |
| **Browser Rendering** | ~45 min/month of the 10 browser-hours included on Paid | **$0.00** |
| **Cloudflare Images** | ~400 unique transformations of the 5,000 free | **$0.00** |
| **Turnstile** | Free, unlimited verifications | **$0.00** |
| **Workers Logs** | ~1.5 M events of the 20 M included | **$0.00** |
| **Workers Builds** | ~400 build-min of the 6,000 included | **$0.00** |
| **Email Routing (inbound)** | Free, unlimited | **$0.00** |
| **Resend** | **Pro $20/mo** (50,000/mo, no daily cap). The free tier's **100/day** cap breaks on registration-open and certificate-issuance days. | **$20.00** |
| **Sentry** | Developer/Team tier | **$0 – $26** |
| | | |
| **Baseline (realistic)** | | **≈ $30 / month** |
| **With Sentry Team + upper Neon usage** | | **≈ $50 / month** |
| **Worst case if Business zone is forced (§11.7 option ③)** | | **≈ $250 / month** |

**Cost-control measures to implement on day one:**
1. **Set `limits.cpu_ms`** in `wrangler.jsonc` to cap denial-of-wallet exposure from the password-hash endpoint.
2. **Enable Neon's spend limit** (on by default on paid plans).
3. **Never use `server.accept()`** on WebSockets — always `state.acceptWebSocket()` (§7.2). This is the single largest cost-blowup risk on the platform.
4. **Do not enable ISR / `revalidateTag` / `DOShardedTagCache`** — OpenNext issue #1103 documents >$50/day from that path.
5. **Cache Images-binding output** — uncached calls re-encode every time.

---

## 13. 🔴 RED FLAGS — every PRD requirement that is hard or impossible on Cloudflare

Ordered by severity. Each row states the concrete workaround.

| # | PRD requirement | Problem on Cloudflare | Concrete workaround | Severity |
|---|---|---|---|---|
| **R-01** | §1.2, §6.4 — the platform must live at `ai.athar-dev.edu.sa` | A Worker Custom Domain needs an **active Cloudflare zone you own**. Partial/CNAME setup is **Business-only ($200+/mo)**; subdomain-only zones are **Enterprise-only**. `athar-dev.edu.sa` is SaudiNIC-registered and probably not yours to delegate. | **Cloudflare for SaaS custom hostname — free on the Free plan, 100 hostnames included.** The institution adds one CNAME; nameservers never move. Fallback: full-zone migration (also free) if the institution agrees, but **all existing records including the Google Workspace MX must be recreated first**. **Verify in the dashboard before building.** | 🔴 **Blocker** |
| **R-02** | §12.1 — argon2id **or bcrypt cost ≥ 12** | `crypto.argon2` is **explicitly unsupported** in Workers' `node:crypto`. All argon2 WASM builds are unmaintained, incompatible loaders, or unbenchmarked. `bcryptjs` is pure JS on a CPU-metered platform. **PBKDF2 is hard-capped at 100,000 iterations — and passes in `wrangler dev` while throwing in production.** | **Use `node:crypto.scrypt` with N=32768, r=8, p=3, dkLen=64** — OWASP-equivalent, native BoringSSL, clears both the cost cap (786,432 ≤ 1,048,576) and the memory limit (32 MiB of 128 MB). **Requires a written deviation from §12.1 (new decision D-20).** | 🔴 **Blocker** |
| **R-03** | §12.1 — sessions revocable **instantly**; §4.4 suspension kills sessions immediately | **Workers KV takes "up to 60 seconds or more"** to propagate, caches negative lookups, and has a 30-second minimum `cacheTtl`. Any KV-backed session store violates this. | **Store sessions in Postgres** and read them through the **`--caching-disabled` Hyperdrive binding**. Never KV. Never enable Better Auth's `cookieCache`. If D1 is chosen instead, leave read replication off. | 🔴 **Blocker** |
| **R-04** | §6.1 — Auth.js (NextAuth) with server sessions | **Auth.js's Credentials provider cannot use database sessions** — enforced in `assert.ts` and in the sign-in code path, which never calls `adapter.createSession`. `next-auth` v5 is still `5.0.0-beta.32` after ~3 years. Auth.js has **no Cloudflare/OpenNext documentation at all**. | **Better Auth 1.7.2** (pin ≥1.7.0 for GHSA-2vg6-77g8-24mp and the native workerd scrypt path). **Deviation from §6.1 — needs a written decision.** | 🔴 **Blocker** |
| **R-05** | §7 — PostgreSQL with transactions, CHECK constraints, **Decimal** scores | **D1 has no interactive transactions** (`batch()` only), **no decimal type**, a **100 bound-parameter** limit, and undocumented CHECK support. **Prisma's D1 adapter silently degrades `$transaction` to non-atomic queries** — a data-integrity failure that does not throw. | **Postgres on Neon via Hyperdrive** + **Smart Placement**. Gives real ACID, `NUMERIC(5,2)` scores, native enums, and `prisma migrate`/`drizzle-kit` migrations in the repo. **+$5–20/mo.** | 🔴 **Blocker** |
| **R-06** | §9.13.2, §9.9.7 — live chat and live attendance roster | **Next.js Route Handlers cannot perform a WebSocket upgrade.** The RFC is a draft; Cloudflare's 101-upgrade requires a non-standard `Response` init that Next.js does not pass through. | **Custom Worker entrypoint** that intercepts `Upgrade: websocket` before delegating to `handler.fetch`, routes to a Durable Object, and exports the DO classes from the same file. **⚠️ Spike this before committing — no first-party doc confirms it with OpenNext.** | 🟠 **High** |
| **R-07** | §9.17, §9.6 — Arabic PDF certificates and PNG cards | **No JS PDF library shapes Arabic correctly.** `pdf-lib` renders unjoined letters and reverses numbers (issue #1450, open since 2023). **satori's RTL PR #745 is still open.** The reshape-and-reverse hack fails on mixed Arabic/Latin/digits — exactly the certificate's content. | **Cloudflare Browser Rendering `/pdf` and `/screenshot`** with `addStyleTag` injecting IBM Plex Sans Arabic. Real Chrome ⇒ real HarfBuzz shaping and UAX#9 bidi. ~free within the 10 browser-hours included on Paid. **Never assume a font is preinstalled — always inject.** | 🟠 **High** |
| **R-08** | §6.4, §15 — three environments, preview deployments, automated rollback | **Cloudflare does not generate Preview URLs for Workers that implement a Durable Object** — and this Worker must (R-06). Also: **logs are unavailable on Preview URLs entirely.** | Use the **staging environment** as the review environment (`wrangler deploy --env staging` on merge to `develop`), gated behind Cloudflare Access. **Accept that per-PR ephemeral previews are unavailable.** Rollback via `wrangler rollback` / dashboard version pinning. | 🟠 **High** |
| **R-09** | §12.4 — rate limits keyed per account / per email / per user, over 15-minute and 1-hour windows | **WAF rate limiting is IP-only below Enterprise** (1 rule on Free, 10-second window). The **Workers Rate Limiting binding is per-colo** — Cloudflare says it is *"intentionally designed to not be used as an accurate accounting system"* — and its period **must be 10 or 60 seconds**, so it cannot express "5 per 15 minutes". | **Three layers**: WAF rule (free IP shield) → Rate Limiting binding for 60-second per-user limits, configured conservatively to absorb per-colo slop → **Durable Object counters, one DO per key**, for login/reset/registration/upload. Plus the DB-level `locked_until` lockout from §9.3.1. | 🟠 **High** |
| **R-10** | §12.7 — automatic daily backup, **30-day** retention, quarterly restore test | On **D1**: Time Travel is 30-day PITR but **destructive and in-place** with no clone; `wrangler d1 export` **blocks the database** and **fails outright if you ever add FTS5** (which §9.13.2's message search invites). On **Neon Launch**: PITR is only **7 days** — 30 days needs the Scale plan at 2× compute rate. And **`pg_dump` cannot run inside a Worker**. | **Neon Launch (7-day PITR) + a daily `pg_dump` to R2 run from CI**, not from the Worker, with an **R2 lifecycle rule expiring the prefix at 31 days**. This is cheaper than Neon Scale *and* produces an offsite, independently restorable artifact that PITR by definition does not. Restore-test quarterly into a free Neon branch. **§2.4.1.** If D1 is chosen instead, PITR meets the letter of §12.7 but you have **no offsite copy and no non-destructive restore** — say so explicitly to the client. | 🟠 **High** |
| **R-11** | §6.1 — SMTP provider; C-03 — send from `info@athar-dev.edu.sa` | **Port 25 is blocked outbound.** 465/587 work but give you no reputation, retries, bounce handling, or suppression. Cloudflare's own Email Service **requires full Cloudflare DNS** — incompatible with R-01 option ②. | **Resend** (records-only verification, SPF scoped to a `send.` subdomain so the institution's apex SPF cannot break). **$20/mo** — the free tier's 100/day cap fails on registration-open and certificate day. Cloudflare Email Service is the fallback *only if* full DNS migration happens. | 🟠 **High** |
| **R-12** | §13.1 — server response `< 300 ms` for common queries | **Neither Neon nor D1 has a Middle East region.** Frankfurt is ~90–110 ms RTT from Riyadh. Three sequential queries = 300 ms of pure network latency. | **`"placement": { "mode": "smart" }`** — runs the Worker next to the database, collapsing N round-trips into one. Plus: batch queries, avoid N+1, index per §7.7, and paginate at 50 rows (§13.1). **Measure on the real domain from Riyadh before sign-off.** | 🟠 **High** |
| **R-13** | Free-plan viability | **Not viable.** 10 ms CPU (kills password hashing *and* the cron sweep), 3 MB gzip bundle (an App Router app will exceed it), 10 min/day Browser Rendering, 50 subrequests, no outbound email. | **Workers Paid ($5/mo) is mandatory.** Not a preference. | 🟠 **High** |
| **R-14** | §6.1 — Prisma ORM | `@prisma/adapter-d1` has been **Preview for ~2.5 years**. On Postgres, Prisma works but **OpenNext issue #139 (`.wasm` import from `@prisma/client/wasm`) has been open since 2024-11-21**, and Prisma's Cloudflare docs are stale (`node_compat = true`). | **Drizzle `0.45.2` + `pg 8.23.0`** — pure TS, no WASM, officially documented with Hyperdrive, SQL migrations in git. **Deviation from §6.1 — new decision D-19.** Prisma 7 + `@prisma/adapter-pg` remains a viable runner-up. | 🟡 **Medium** |
| **R-15** | §9.11.2 — 25 MB uploads with a **real** progress bar, resumable on mobile | Presigned direct-to-R2 **bypasses your Worker**, so you cannot MIME-sniff at upload time (§12.5 requires content-based detection). **Presigned POST is not supported** by R2 at all. Presigned multipart works but is **undocumented**. | **Proxy the upload through the Worker** (25 MB is far under the 100 MB body cap) and **stream** it — never `arrayBuffer()` against the 128 MB isolate. Sniff the first 4 KB with `file-type@22.0.2`. Use `XMLHttpRequest`/`fetch` upload progress events for the real progress bar. For resumability, spike presigned `UploadPart` first. | 🟡 **Medium** |
| **R-16** | §13.1 — dashboard initial bundle `< 250 KB` gzipped | Unrelated to the Worker size limit but easy to conflate. React 19 + Tailwind 4 + a calendar + charts + a rich-text editor will blow it if everything is eagerly imported. | Route-based code splitting; `next/dynamic` for the calendar view, charts and editor (PRD §13.1 already mandates this); self-host only the four IBM Plex Sans Arabic weights actually used, subset to Arabic + Latin ranges. **Enforce with a CI bundle-size budget**, per Constitution's automation principle. | 🟡 **Medium** |
| **R-17** | §15 — production error tracking with immediate alerting | Sentry's Next.js-on-Cloudflare SDK path has **open `AsyncLocalStorage` issues** in `onRequestError`. Workers Logs retention is only **7 days**. | Use **Cloudflare's native OTel export to Sentry** (no SDK in the Worker bundle) for server traces/logs, plus the **client-side** Sentry SDK for browser errors. Keep the **immutable `AuditLog` in the database** — Workers Logs is not an audit trail. | 🟡 **Medium** |
| **R-18** | §9.16 — "email if the user is offline" | Requires knowing presence. Presence lives in Durable Object memory, which **hibernates**. | Track `last_seen_at` in the database, updated on WebSocket connect/disconnect via the DO. Treat "offline" as `last_seen_at > 5 minutes ago`. Do not rely on live DO memory. | 🟡 **Medium** |
| **R-19** | §9.13.2 — full-text search inside conversations | Postgres full-text search is fine. **But if D1 is chosen, FTS5 permanently breaks `wrangler d1 export`** — your only offsite backup mechanism. | On Postgres: `tsvector` + GIN index. On D1: choose between FTS5 and offsite export — **you cannot have both.** | 🟡 **Medium** |
| **R-20** | Cloudflare's own recommendation is `vinext`, not OpenNext | Building on the adapter Cloudflare has **demoted** carries long-term maintenance risk. `next-on-pages` was archived after a similar demotion. | Accept it for v1 — vinext's own README says it is *"not yet a drop-in replacement for… production workload"*. **Re-evaluate at vinext 1.0 GA.** The custom-worker pattern this architecture depends on exists in vinext too (`vinext/server/app-router-entry`), so the migration path is real. | 🟡 **Medium** |
| **R-21** | §2.2, §9.9 — instant UI update after check-in | **Hyperdrive's query cache does not invalidate on writes** (60 s default `max_age`). A cached read after check-in would show stale attendance. | **Bind two Hyperdrive configs**: one cached (public landing data), one `--caching-disabled` (everything authenticated). Route all read-after-write through the uncached one. **Mandatory.** | 🟡 **Medium** |
| **R-22** | §9.2.2 — invisible CAPTCHA | Turnstile Invisible mode imposes a legal condition: you **must** reference Cloudflare's Turnstile Privacy Addendum in your privacy policy. | Add it to the §12.6 privacy policy. Launch-checklist item (Appendix B). | 🟢 **Low** |
| **R-23** | §5.4 / D-13 — self-hosted IBM Plex Sans Arabic | Static assets cap at 25 MiB per file (fine) but every font weight costs bundle and LCP. Browser Rendering also needs the font. | Subset to Arabic + Latin + digits, WOFF2, four weights (400/500/600/700), `font-display: swap`. **Serve the same CSS URL to Browser Rendering via `addStyleTag`** so certificates and UI use identical glyphs. | 🟢 **Low** |
| **R-24** | Session/CPU denial-of-wallet | An attacker hammering `/api/auth/sign-in` forces a 200–400 ms scrypt hash per request, billed at $0.02/million CPU-ms. | Set `limits.cpu_ms`; run the **DO-backed** login rate limiter (R-09) **before** hashing; Turnstile on the login form; the DB-level `locked_until` lockout. | 🟢 **Low** |

### 13.1 Unverified & ambiguous — stated honestly (Constitution Art. 4)

These are **not** claims. They are known gaps.

1. **No first-party confirmation** that the WebSocket-intercept-in-custom-worker pattern has been exercised with OpenNext specifically. It follows necessarily from the platform primitives, but **spike it**.
2. **Cloudflare publishes no reliability guarantee for Cron Triggers** — no SLA, no "best effort" language, nothing. This is an absence of documentation. Design the sweep to be idempotent regardless.
3. **The PBKDF2 100,000-iteration cap is undocumented by Cloudflare.** Verified only from `workerd` C++ source and an open issue with no staff response.
4. **No measured CPU-ms figure exists for native `node:crypto.scrypt` on Workers** at any parameters. The 200–400 ms estimate is extrapolation from reference hardware. **Benchmark on a deployed Worker, not `wrangler dev`.**
5. **No argon2id benchmark on Workers at OWASP parameters** exists. The only published Workers argon2 benchmark used 16 KiB of memory — 1,216× below OWASP — and does not extrapolate.
6. **Cloudflare never explicitly documents CHECK-constraint or partial-index support in D1.** Inferred from `PRAGMA ignore_check_constraints` being listed.
7. **D1 publishes no limit on rows returned per query or response size.** The practical ceiling is the 128 MB isolate.
8. **D1 read replication has no GA changelog entry** 18 months after the beta announcement, yet the doc page no longer shows a beta banner. Treat as beta.
9. **Presigned multipart on R2 is undocumented** but reported working; there is an open docs issue about the gap.
10. **The list of fonts preinstalled in the Browser Rendering environment is not published.** Always inject your own.
11. **Cloudflare for SaaS + Workers routing on the Free plan** is implied by the plans table but the worker-as-origin guide does not explicitly confirm Free-plan support. **Test before architecting around it.**
12. **Secrets Store pricing is entirely undocumented.** Presumably free during open beta; cannot be confirmed.
13. **Turnstile siteverify rate limits are not documented** — "unlimited" per the plans page, unconfirmed at extreme scale.
14. **Whether OpenNext's bundler reliably resolves the `workerd` export condition** for `@better-auth/utils` was not verified by building a bundle. **Assert it in CI.**
15. **Whether Supabase's Direct (non-pooled) connection requires their paid IPv4 add-on** is not addressed in Cloudflare's Hyperdrive guide. Only relevant if Supabase is chosen over Neon.
16. **Brevo, SendGrid and Mailgun pricing** came from third-party aggregators, not vendor pages.
17. **`05-email-and-integrations.md` states that ports 25/465/587 are all blocked.** Cloudflare documents **only port 25**. That document should be corrected.

---

## 14. `wrangler.jsonc` skeleton

Every key below was checked against https://developers.cloudflare.com/workers/wrangler/configuration/. Placeholder IDs are marked `<...>`.

```jsonc
{
  "$schema": "node_modules/wrangler/config-schema.json",

  // ── Identity ────────────────────────────────────────────────────────────
  "name": "athar-platform",
  "main": "./worker/index.ts",          // custom entrypoint: fetch + scheduled + DO exports (§7.3, §8.4)
  "compatibility_date": "2026-09-01",   // ≥2025-08-16 required by Sentry's Next.js guide
  "compatibility_flags": ["nodejs_compat"], // redundant on dates ≥2026-08-04, kept for clarity (§1.4)

  // ── Placement: run the Worker next to the database (§2.4, R-12) ─────────
  "placement": { "mode": "smart" },

  // ── Cost guard against denial-of-wallet on the hashing endpoint (R-24) ──
  "limits": { "cpu_ms": 30000 },

  // ── Static assets (§1.8) ────────────────────────────────────────────────
  "assets": {
    "directory": ".open-next/assets",
    "binding": "ASSETS"
    // run_worker_first intentionally omitted (default false) so /_next/static
    // and fonts are served without invoking the Worker. Security headers for
    // these paths come from a Workers `_headers` file, not Next.js middleware.
  },

  // ── Observability: Workers Logs + native OTel export to Sentry (§11.3) ──
  "observability": {
    "enabled": true,
    "head_sampling_rate": 1,
    "traces": { "enabled": true, "destinations": ["sentry-traces"], "head_sampling_rate": 0.2 },
    "logs":   { "enabled": true, "destinations": ["sentry-logs"] }
  },

  // ── Cron: the 15-minute attendance sweep (§8, BR-08/BR-09) ──────────────
  "triggers": { "crons": ["*/15 * * * *"] },

  // ── Non-secret configuration. BR-36: all absolute URLs derive from this. ─
  "vars": {
    "APP_BASE_URL": "https://ai.athar-dev.edu.sa",
    "APP_TIMEZONE": "Asia/Riyadh",
    "APP_ENV": "production"
  },

  // ── Database: TWO Hyperdrive configs. See R-21 — this is mandatory. ─────
  "hyperdrive": [
    { "binding": "HYPERDRIVE",        "id": "<UNCACHED_CONFIG_ID>" }, // auth, attendance, grades, all read-after-write
    { "binding": "HYPERDRIVE_CACHED", "id": "<CACHED_CONFIG_ID>"   }  // public landing data only
  ],

  // ── Object storage (§5) ─────────────────────────────────────────────────
  "r2_buckets": [
    { "binding": "UPLOADS",      "bucket_name": "athar-uploads" },
    { "binding": "CERTIFICATES", "bucket_name": "athar-certificates" }
  ],

  // ── KV: caches only. NEVER sessions (§3.4, R-03). ───────────────────────
  "kv_namespaces": [
    { "binding": "SETTINGS_CACHE", "id": "<KV_NAMESPACE_ID>" }
  ],

  // ── Realtime + accurate rate limiting (§7.2, §10.3) ─────────────────────
  "durable_objects": {
    "bindings": [
      { "name": "THREAD_ROOM",    "class_name": "ThreadRoom" },
      { "name": "SESSION_ROSTER", "class_name": "SessionRoster" },
      { "name": "USER_INBOX",     "class_name": "UserInbox" },
      { "name": "RATE_LIMITER",   "class_name": "RateLimiterDO" }
    ]
  },
  "migrations": [
    {
      "tag": "v1",
      "new_sqlite_classes": ["ThreadRoom", "SessionRoster", "UserInbox", "RateLimiterDO"]
    }
  ],

  // ── Cheap per-colo rate limiting. period MUST be 10 or 60 (§10.2b). ─────
  "ratelimits": [
    { "name": "RL_CHECKIN",  "namespace_id": "1001", "simple": { "limit": 10, "period": 60 } },
    { "name": "RL_MESSAGES", "namespace_id": "1002", "simple": { "limit": 12, "period": 60 } },
    { "name": "RL_PUBLIC",   "namespace_id": "1003", "simple": { "limit": 40, "period": 60 } }
  ],

  // ── Avatar pipeline, 256/64px (§11.5). Also backs next/image. ───────────
  "images": { "binding": "IMAGES" },

  // ── Certificate PDFs and digital-card PNGs (§9.4) ───────────────────────
  "browser": { "binding": "BROWSER" },

  // ── Production route (§11.7) ────────────────────────────────────────────
  "routes": [
    { "pattern": "ai.athar-dev.edu.sa", "custom_domain": true }
  ],
  "workers_dev": false,
  "preview_urls": false, // not generated for Workers with Durable Objects anyway (R-08)

  // ── Environments. Bindings are NON-INHERITABLE — repeat every block. ────
  "env": {
    "staging": {
      "name": "athar-platform-staging",
      "vars": {
        "APP_BASE_URL": "https://staging.ai.athar-dev.edu.sa",
        "APP_TIMEZONE": "Asia/Riyadh",
        "APP_ENV": "staging"
      },
      "hyperdrive": [
        { "binding": "HYPERDRIVE",        "id": "<STAGING_UNCACHED_ID>" },
        { "binding": "HYPERDRIVE_CACHED", "id": "<STAGING_CACHED_ID>"   }
      ],
      "r2_buckets": [
        { "binding": "UPLOADS",      "bucket_name": "athar-uploads-staging" },
        { "binding": "CERTIFICATES", "bucket_name": "athar-certificates-staging" }
      ],
      "kv_namespaces": [
        { "binding": "SETTINGS_CACHE", "id": "<STAGING_KV_ID>" }
      ],
      "durable_objects": {
        "bindings": [
          { "name": "THREAD_ROOM",    "class_name": "ThreadRoom" },
          { "name": "SESSION_ROSTER", "class_name": "SessionRoster" },
          { "name": "USER_INBOX",     "class_name": "UserInbox" },
          { "name": "RATE_LIMITER",   "class_name": "RateLimiterDO" }
        ]
      },
      "ratelimits": [
        { "name": "RL_CHECKIN",  "namespace_id": "2001", "simple": { "limit": 10, "period": 60 } },
        { "name": "RL_MESSAGES", "namespace_id": "2002", "simple": { "limit": 12, "period": 60 } },
        { "name": "RL_PUBLIC",   "namespace_id": "2003", "simple": { "limit": 40, "period": 60 } }
      ],
      "images":  { "binding": "IMAGES" },
      "browser": { "binding": "BROWSER" },
      "routes": [
        { "pattern": "staging.ai.athar-dev.edu.sa", "custom_domain": true }
      ]
    }
  }
}
```

**Secrets are NOT in this file.** Set per environment:

```bash
wrangler secret put AUTH_SECRET                     # and: --env staging
wrangler secret put RESEND_API_KEY
wrangler secret put R2_ACCESS_KEY_ID
wrangler secret put R2_SECRET_ACCESS_KEY
wrangler secret put TURNSTILE_SECRET_KEY
wrangler secret put CF_ACCOUNT_ID
wrangler secret put CF_BROWSER_RENDERING_TOKEN
```

Pre-flight checks before every deploy:
```bash
wrangler deploy --outdir bundled/ --dry-run   # confirm gzip size < 10 MB (§1.7)
wrangler check startup                        # confirm startup CPU < 1 s
```

---

## 15. `package.json` — dependencies with verified versions

**Every version below was fetched from `registry.npmjs.org` on 2026-09-04 and exists.**

```jsonc
{
  "name": "athar-platform",
  "private": true,
  "type": "module",
  "engines": { "node": ">=22" },

  "scripts": {
    "dev": "next dev",
    "build": "next build",
    "preview": "opennextjs-cloudflare build && opennextjs-cloudflare preview",
    "deploy": "opennextjs-cloudflare build && opennextjs-cloudflare deploy",
    "deploy:staging": "opennextjs-cloudflare build && CLOUDFLARE_ENV=staging opennextjs-cloudflare deploy --env staging",
    "cf-typegen": "wrangler types --env-interface CloudflareEnv",
    "db:generate": "drizzle-kit generate",
    "db:migrate": "drizzle-kit migrate",
    "db:studio": "drizzle-kit studio",
    "size:check": "wrangler deploy --outdir bundled/ --dry-run && wrangler check startup",
    "test": "vitest run",
    "test:e2e": "playwright test",
    "lint": "eslint .",
    "typecheck": "tsc --noEmit"
  },

  "dependencies": {
    // ── Framework ────────────────────────────────────────────────────────
    "next": "16.3.4",                    // in OpenNext peer range ">=15.5.24 <16 || >=16.3.3"
    "react": "19.2.8",
    "react-dom": "19.2.8",

    // ── Database (§2) ────────────────────────────────────────────────────
    "drizzle-orm": "0.45.2",
    "pg": "8.23.0",                      // Cloudflare requires >=8.13.0 for Hyperdrive

    // ── Auth + hashing (§3, §4) ──────────────────────────────────────────
    "better-auth": "1.7.2",              // >=1.6.11 for GHSA-2vg6-77g8-24mp; >=1.7.0 for native workerd scrypt

    // ── Validation (PRD §6.3 — one schema, server + client) ──────────────
    "zod": "4.5.4",

    // ── Storage (§5) ─────────────────────────────────────────────────────
    "aws4fetch": "1.0.20",               // presigned R2 URLs; fetch + WebCrypto, zero Node deps
    "file-type": "22.0.2",               // real MIME sniffing (PRD §12.5)

    // ── Email (§6) ───────────────────────────────────────────────────────
    "resend": "6.26.0",                  // or drop this and POST to https://api.resend.com/emails

    // ── QR (§9.5) ────────────────────────────────────────────────────────
    "uqr": "0.1.3",                      // zero-dep, SVG output, any runtime

    // ── UI ───────────────────────────────────────────────────────────────
    "@marsidev/react-turnstile": "1.6.1", // explicitly recommended by Cloudflare
    "lucide-react": "1.41.0",            // PRD §5.7 — single icon set, 1.5px stroke

    // ── Time (PRD §7, Constitution Art. 7 — Asia/Riyadh display, UTC storage)
    "date-fns": "4.4.0",
    "@date-fns/tz": "1.5.0"
  },

  "devDependencies": {
    "@opennextjs/cloudflare": "1.20.6",
    "wrangler": "4.129.0",               // peer dep requires ^4.125.0 (docs saying 3.99.0 are stale)
    "@cloudflare/workers-types": "5.20260904.1",

    "drizzle-kit": "0.31.10",
    "@types/pg": "8.23.1",

    "typescript": "7.0.2",
    "tailwindcss": "4.3.3",

    "vitest": "5.0.0",
    "@playwright/test": "1.62.1",
    "eslint": "10.10.0",
    "prettier": "3.9.6"
  }
}
```

**Deliberately NOT included, and why:**

| Package | Reason |
|---|---|
| `@cloudflare/next-on-pages` | Archived 2025-09-29, deprecated |
| `next-auth` / `@auth/*` | Credentials provider cannot use database sessions (§3.2) |
| `@prisma/client`, `prisma` | See §2.5 / R-14. Add `prisma@7.10.0` + `@prisma/adapter-pg@7.10.0` only if decision D-19 goes the other way |
| `bcryptjs`, `@node-rs/argon2`, `hash-wasm` | See §4.4 |
| `pdf-lib`, `@pdf-lib/fontkit`, `satori`, `@resvg/resvg-wasm` | None shape Arabic (§9.3) |
| `qrcode` | Depends on `pngjs`, `yargs`, `dijkstrajs` |
| `@sentry/cloudflare` | Using Cloudflare's native OTel export instead (§11.3). Add the **client-side** Sentry SDK separately for browser errors. |
| `@cloudflare/puppeteer` | REST Quick Actions do not bill concurrency and add no bundle weight (§9.4) |

**Build-time assertions to add to CI** (Constitution: enforce, don't promise):
1. `wrangler deploy --dry-run` gzip output **< 10 MB**.
2. `wrangler check startup` passes (< 1 s).
3. Next.js dashboard route first-load JS **< 250 KB gzipped** (PRD §13.1).
4. Assert `@better-auth/utils` resolved via the **`workerd`** export condition (§4.4) — otherwise password hashing silently falls back to pure JS and will exceed CPU limits in production.
5. Assert no `export const runtime = "edge"` exists anywhere (OpenNext requires its removal).

---

## 16. Decisions this document raises for `DECISIONS.md`

Per Constitution Art. 4 and Art. 31, these must be recorded and approved — **none may be implemented as a silent assumption.**

| # | Decision | Status | Recommendation |
|---|---|---|---|
| **D-17** | Platform database (already open) | **Recommendation ready** | **PostgreSQL on Neon (Launch, Frankfurt) via Cloudflare Hyperdrive**, with Smart Placement and two Hyperdrive configs. Runner-up: D1 + Drizzle + `batch()`. §2.4 |
| **D-19** *(new)* | ORM: Drizzle instead of the PRD's Prisma | `موصى به` | **Drizzle 0.45.2 + pg 8.23.0** — smaller bundle, no WASM, officially documented with Hyperdrive. PRD §6.1 explicitly permits stack substitution. §2.5 |
| **D-20** *(new)* | Password hashing: scrypt instead of argon2id/bcrypt | `موصى به` — **deviates from §12.1** | **`node:crypto.scrypt` N=32768, r=8, p=3, dkLen=64.** argon2id is unsupported natively and every WASM route is unmaintained or unbenchmarked; bcrypt is pure JS on a CPU-metered platform. §4.4 |
| **D-21** *(new)* | Auth library: Better Auth instead of Auth.js | `موصى به` — **deviates from §6.1** | **Better Auth 1.7.2.** Auth.js's Credentials provider structurally cannot use revocable database sessions. §3.2 |
| **D-22** *(new)* | 🔴 **DNS strategy for `ai.athar-dev.edu.sa`** | 🛑 **Client/institution decision required** | **Cloudflare for SaaS custom hostname** (one CNAME, free) vs **full nameserver migration** to Cloudflare (also free, but moves the whole institutional domain including Google Workspace MX). **This blocks production launch and must be resolved before Phase 1.** §11.7 / R-01 |
| **D-23** *(new)* | No per-PR preview deployments | `افتراض مؤقت` | Cloudflare does not generate Preview URLs for Workers with Durable Objects. Staging environment behind Cloudflare Access replaces them. §11.2 / R-08 |
| **D-24** *(new)* | Certificate/PDF rendering via Browser Rendering | `موصى به` | The only approach that shapes Arabic correctly. Adds a Cloudflare service dependency but stays within the included allowance. §9.4 |
| **D-14** | Sending identity `info@` + `Reply-To: contact@` (already open) | unchanged | Unaffected by this analysis; Resend supports both headers. |
| **D-18** | Upload allowlist (already open) | unchanged | Define in `lib/storage/allowed-types.ts`; enforce with `file-type@22.0.2` after MIME sniffing. §5.4 |
| **D-25** *(new)* | Correct the SMTP claim in `05-email-and-integrations.md` | `إجراء تحريري` | That document states ports 25/465/587 are all blocked. Cloudflare documents **only port 25**. The recommendation (do not use raw SMTP) is unchanged; the stated reason must be corrected. §6.1 / §13.1 item 17 |

---

## 17. Source index

All URLs verified 2026-09-04.

**Next.js on Cloudflare**
https://developers.cloudflare.com/workers/framework-guides/web-apps/nextjs/ · https://developers.cloudflare.com/workers/framework-guides/web-apps/opennext/ · https://opennext.js.org/cloudflare · https://opennext.js.org/cloudflare/caching · https://opennext.js.org/cloudflare/howtos/custom-worker · https://opennext.js.org/cloudflare/troubleshooting · https://github.com/opennextjs/opennextjs-cloudflare/issues · https://github.com/cloudflare/vinext · https://vinext.dev/compatibility · https://blog.cloudflare.com/vinext/ · https://github.com/cloudflare/next-on-pages · https://blog.cloudflare.com/full-stack-development-on-cloudflare-workers/

**Platform limits & pricing**
https://developers.cloudflare.com/workers/platform/limits/ · https://developers.cloudflare.com/workers/platform/pricing/ · https://developers.cloudflare.com/workers/configuration/compatibility-flags/ · https://developers.cloudflare.com/workers/runtime-apis/nodejs/ · https://developers.cloudflare.com/workers/runtime-apis/nodejs/crypto/ · https://developers.cloudflare.com/workers/runtime-apis/web-crypto/ · https://developers.cloudflare.com/workers/configuration/smart-placement/ · https://developers.cloudflare.com/workers/wrangler/configuration/ · https://blog.cloudflare.com/nodejs-workers-2025/ · https://github.com/cloudflare/workerd/blob/main/src/workerd/io/limit-enforcer.h · https://github.com/cloudflare/workerd/issues/1346

**Database**
https://developers.cloudflare.com/d1/platform/limits/ · https://developers.cloudflare.com/d1/platform/pricing/ · https://developers.cloudflare.com/d1/worker-api/d1-database/ · https://developers.cloudflare.com/d1/best-practices/read-replication/ · https://developers.cloudflare.com/d1/reference/time-travel/ · https://developers.cloudflare.com/d1/best-practices/import-export-data/ · https://developers.cloudflare.com/d1/sql-api/foreign-keys/ · https://developers.cloudflare.com/changelog/post/2026-09-01-d1-free-tier-limit-enforcement/ · https://developers.cloudflare.com/hyperdrive/ · https://developers.cloudflare.com/hyperdrive/platform/limits/ · https://developers.cloudflare.com/hyperdrive/platform/pricing/ · https://developers.cloudflare.com/hyperdrive/configuration/query-caching/ · https://developers.cloudflare.com/hyperdrive/examples/connect-to-postgres/postgres-drivers-and-libraries/drizzle-orm/ · https://developers.cloudflare.com/hyperdrive/planetscale/ · https://www.prisma.io/docs/orm/overview/databases/cloudflare-d1 · https://neon.com/pricing · https://neon.com/docs/introduction/regions

**Auth & hashing**
https://authjs.dev/concepts/session-strategies · https://github.com/nextauthjs/next-auth/blob/main/packages/core/src/lib/utils/assert.ts · https://better-auth.com/docs/concepts/session-management · https://better-auth.com/docs/plugins/admin · https://github.com/lucia-auth/lucia/discussions/1707 · https://developers.cloudflare.com/kv/concepts/how-kv-works/ · https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html

**Storage**
https://developers.cloudflare.com/r2/platform/limits/ · https://developers.cloudflare.com/r2/pricing/ · https://developers.cloudflare.com/r2/api/s3/presigned-urls/ · https://developers.cloudflare.com/r2/buckets/cors/ · https://developers.cloudflare.com/r2/buckets/object-lifecycles/ · https://developers.cloudflare.com/r2/buckets/event-notifications/

**Email**
https://developers.cloudflare.com/workers/runtime-apis/tcp-sockets/ · https://developers.cloudflare.com/email-service/ · https://developers.cloudflare.com/email-service/get-started/send-emails/ · https://developers.cloudflare.com/email-service/platform/pricing/ · https://developers.cloudflare.com/email-service/platform/limits/ · https://developers.cloudflare.com/email-service/configuration/domains/ · https://developers.cloudflare.com/changelog/post/2026-04-16-email-sending-public-beta/ · https://resend.com/pricing · https://resend.com/docs/send-with-cloudflare-workers · https://github.com/zou-yu/worker-mailer

**Realtime, cron, rate limiting**
https://developers.cloudflare.com/durable-objects/platform/limits/ · https://developers.cloudflare.com/durable-objects/platform/pricing/ · https://developers.cloudflare.com/durable-objects/best-practices/websockets/ · https://developers.cloudflare.com/durable-objects/best-practices/rules-of-durable-objects/ · https://developers.cloudflare.com/durable-objects/api/alarms-in-durable-objects/ · https://github.com/vercel/next.js/discussions/95514 · https://developers.cloudflare.com/workers/configuration/cron-triggers/ · https://developers.cloudflare.com/workers/runtime-apis/handlers/scheduled/ · https://developers.cloudflare.com/waf/rate-limiting-rules/ · https://developers.cloudflare.com/workers/runtime-apis/bindings/rate-limit/ · https://developers.cloudflare.com/changelog/post/2025-09-19-ratelimit-workers-ga/

**PDF / images / QR**
https://developers.cloudflare.com/browser-rendering/ · https://developers.cloudflare.com/browser-rendering/rest-api/pdf-endpoint/ · https://developers.cloudflare.com/browser-rendering/rest-api/screenshot-endpoint/ · https://developers.cloudflare.com/browser-rendering/platform/limits/ · https://developers.cloudflare.com/browser-rendering/platform/pricing/ · https://github.com/Hopding/pdf-lib/issues/1450 · https://github.com/vercel/satori/pull/745 · https://developers.cloudflare.com/images/pricing/ · https://developers.cloudflare.com/images/optimization/binding/

**Operations**
https://developers.cloudflare.com/workers/configuration/secrets/ · https://developers.cloudflare.com/secrets-store/ · https://developers.cloudflare.com/workers/wrangler/environments/ · https://developers.cloudflare.com/workers/versions-and-deployments/preview-urls/ · https://developers.cloudflare.com/workers/ci-cd/builds/limits-and-pricing/ · https://developers.cloudflare.com/workers/observability/logs/workers-logs/ · https://developers.cloudflare.com/workers/observability/exporting-opentelemetry-data/sentry/ · https://docs.sentry.io/platforms/javascript/guides/cloudflare/frameworks/nextjs/ · https://developers.cloudflare.com/turnstile/plans/ · https://developers.cloudflare.com/turnstile/get-started/server-side-validation/ · https://developers.cloudflare.com/dns/zone-setups/full-setup/ · https://developers.cloudflare.com/dns/zone-setups/partial-setup/ · https://developers.cloudflare.com/dns/zone-setups/subdomain-setup/setup/ · https://developers.cloudflare.com/workers/configuration/routing/custom-domains/ · https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/plans/ · https://developers.cloudflare.com/cloudflare-for-platforms/cloudflare-for-saas/start/advanced-settings/worker-as-origin/

---

*End of document. Every price, limit and version herein was verified on 2026-09-04. Re-verify before contracting or before any architectural commitment made more than 60 days after that date — this platform moves fast, and three of the most important answers in this document (vinext, Cloudflare Email Service, and Hyperdrive's free tier) did not exist in their current form twelve months ago.*
