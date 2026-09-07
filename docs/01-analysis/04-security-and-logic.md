# 04 — Security Architecture & Domain-Logic Specification
## منصة البرامج التدريبية — مركز أثر (AI 101) · `ai.athar-dev.edu.sa`

| | |
|---|---|
| **Document** | 04 — Security & Logic (adversarial review of PRD v1.0) |
| **Source of truth** | `docs/00-source/PRD-AR-source.md` |
| **Runtime target** | Cloudflare Workers + Pages (edge, V8 isolates, no long-lived Node process) |
| **Data classification** | Saudi trainee PII + attendance records that gate certificates + grades |
| **Reviewer posture** | Adversarial. Every "should" in the PRD is treated as unproven until it is a constraint, a query predicate, or a test. |

---

> ### 📌 كيف تُقرأ هذه الوثيقة
> هذه وثيقة مراجعة أمنية ومنطقية للـ PRD، وليست بديلًا عنه. تحتوي على:
> 1. **مواصفة رسمية لمحرك نوافذ الحضور** — أدق منطق في المنصة، بدوال قابلة للاختبار وجدول حدود كامل.
> 2. **نموذج صلاحيات رسمي** قابل للتحويل إلى كود مباشرة.
> 3. **نمذجة تهديدات لميزة «معاينة الحسابات»** — أخطر صلاحية في النظام.
> 4. **تقوية نموذج البيانات** بالقيود التي يفترضها الـ PRD ولا ينص عليها.
> 5. **ضوابط أمنية بصيغة تنفيذية على Cloudflare Workers**.
> 6. **حالات الإساءة والحالات الحدّية** — ما ورد في §14.1 وما نقصه.
> 7. **حزمة الاختبارات الإلزامية** مربوطة بأرقام قواعد العمل BR-01..BR-36.
>
> **⚠️ تحذير للعميل:** رصدت المراجعة **تعارضات داخلية في الـ PRD** تمسّ حقوق المتدربين مباشرة (نسبة الحضور والشهادات). هذه التعارضات مجمّعة في **الملحق أ — سجل التعارضات** ويجب حسمها مع صاحب المنتج **قبل بدء المرحلة 4** (نظام الحضور)، عملًا بنص §1.1: «أي غموض أو تعارض يجب رفعه إلى صاحب المنتج قبل التنفيذ، لا تجاوزه بافتراض».

### Legend used throughout

| Marker | Meaning |
|---|---|
| 🔴 **BLOCKER** | Must be resolved with the product owner before the affected phase starts. Implementing an assumption here damages trainee rights. |
| 🟠 **GAP** | The PRD is silent; this document proposes a normative rule. Needs sign-off but is safe to implement as proposed. |
| 🟡 **HARDENING** | Beyond the PRD. Recommended, not contractual. |
| ✅ **CONFIRMS PRD** | Restates a PRD rule in enforceable form. |

---
---

# 1. Attendance Time-Window Engine — Formal Specification

> ### 📌 ملخص القسم بالعربية
> نظام الحضور هو أخطر منطق في المنصة لأنه يحدد من يستحق الشهادة. هذا القسم يحوّل قواعد §9.9 إلى **دوال رياضية صافية (pure functions)** بمتباينات محددة بدقة (هل الحد `≤` أم `<`)، مع **جدول اختبار حدود كامل** لجلسة 6:00–9:00 مساءً عند كل حد ± ثانية واحدة.
>
> **النتائج الحرجة:**
> 1. توقيت `Asia/Riyadh` **لا يحتوي على توقيت صيفي إطلاقًا** — تم التحقق من قاعدة بيانات المناطق الزمنية IANA للأعوام 1970–2035: الإزاحة ثابتة `UTC+03:00`. ومع ذلك **يبقى تخزين UTC إلزاميًا** لأسباب مشروحة في §1.6.
> 2. **تعارض 🔴:** جدول المثال في §9.9.3 يقول إن آخر لحظة لتسجيل الانصراف هي **9:29 م**، بينما القاعدة BR-04 و§14.1 تقولان إن النافذة تمتد حتى **9:30 م بالضبط**. الفرق دقيقة كاملة تضيع على المتدرب.
> 3. **تعارض 🔴:** §7.7 تفرض قيدًا `end_time > start_time` وهو ما **يمنع أي جلسة تعبر منتصف الليل**، بينما §14.1 تُلزم باختبار جلسة تعبر منتصف الليل. الحل: تخزين لحظتين مطلقتين بدل (تاريخ + وقت).
> 4. **ثغرة 🔴:** الـ PRD **لا يعرّف معادلة نسبة الحضور إطلاقًا** رغم أنها تحكم إصدار الشهادة (BR-26). هذا القسم يقترح معادلة صريحة يجب اعتمادها.
> 5. **قرار معماري:** على Cloudflare Workers **لا يُوثق بساعة الـ Worker**. اللحظة المرجعية `now` تُقرأ من **ساعة قاعدة البيانات داخل نفس عبارة SQL** التي تنفّذ الكتابة — وهذا يلغي انحراف الساعات بين مراكز البيانات ويلغي فجوة (فحص ثم كتابة) في آن واحد.

---

## 1.1 Domain constants and types

All PRD attendance rules reduce to four durations. They are configuration, not literals — §3.3/BR-31 requires everything changeable to be admin-managed — but they have hard-coded safe defaults and are validated on read.

```ts
// /lib/time/attendance.constants.ts
/** All durations in milliseconds. Never express these as "minutes" in call sites. */
export const MINUTE = 60_000 as const;

export interface AttendancePolicy {
  /** BR-01: check-in opens this long before S. */
  readonly checkInLeadMs: number;      // default 30 * MINUTE
  /** BR-02/BR-03: check-in at or before S + this is `present`, after is `late`. */
  readonly presentGraceMs: number;     // default 30 * MINUTE
  /** BR-04: check-out opens this long before E. */
  readonly checkOutLeadMs: number;     // default 30 * MINUTE
  /** BR-04: check-out closes this long after E. */
  readonly checkOutLagMs: number;      // default 30 * MINUTE
}

export const DEFAULT_ATTENDANCE_POLICY: AttendancePolicy = {
  checkInLeadMs:  30 * MINUTE,
  presentGraceMs: 30 * MINUTE,
  checkOutLeadMs: 30 * MINUTE,
  checkOutLagMs:  30 * MINUTE,
};
```

### 1.1.1 The instant type — the single most important decision in this module

```ts
/**
 * An absolute point on the timeline, epoch milliseconds UTC.
 * NOT a Date. NOT a string. NOT a "local time".
 *
 * Branding prevents `Instant` being confused with any other number
 * (a duration, a minute count, a session id) at compile time.
 */
export type Instant = number & { readonly __brand: 'Instant' };

export const asInstant = (ms: number): Instant => ms as Instant;
```

**Why a branded number and not `Date`:**
- `Date` carries an implicit "local time" API surface (`getHours`, `toString`, `setMinutes`) that silently reads the ambient `TZ`. On Cloudflare Workers `TZ` is UTC; on a developer's laptop in Riyadh it is `+03:00`. Identical code, three-hour divergence, zero errors raised.
- Arithmetic on `number` is total and testable. `S + 30 * MINUTE` cannot throw, cannot mutate, and serialises identically everywhere.
- `Date` equality (`d1 === d2`) is reference equality — a bug magnet in boundary tests. Numbers compare correctly.

**Enforcement (§6.3 already demands centralised time functions — this makes it checkable):**

```jsonc
// .eslintrc — /lib/time/** is the ONLY place allowed to construct Date
"no-restricted-globals": [
  "error",
  { "name": "Date", "message": "Use /lib/time. Date arithmetic is forbidden in domain code (BR-07)." }
],
"no-restricted-properties": [
  "error",
  { "object": "Date", "property": "now", "message": "Server time comes from the DB clock. See §1.7." }
]
```

---

## 1.2 `canCheckIn` — exact specification

> **PRD sources:** §9.9.2 row 1, §9.9.3 rows at 5:00/5:30/9:00, §9.9.4 bullet 6 (cancelled sessions), BR-01.

### Normative rule

Let `S` = session start instant, `E` = session end instant, `now` = authoritative server instant.

```
canCheckIn(now, S, E) ⟺  (S − 30m) ≤ now  ∧  now ≤ E
```

**Both bounds are inclusive (closed interval `[S−30m, E]`).**

Justification for each inequality, traced to the PRD:

| Bound | Inequality | PRD evidence |
|---|---|---|
| Lower `S − 30m` | **`≤`** (inclusive) | §9.9.3 row `5:30 م` → «مفعّل — **بداية النافذة**». The instant is described as the window's start, i.e. the button is already enabled at 5:30:00.000. |
| Upper `E` | **`≤`** (inclusive) | §9.9.3 row `9:00 م` → «**آخر لحظة**، ثم يُغلق». "Last moment, then it closes" ⇒ 9:00:00.000 is accepted, 9:00:00.001 is not. Also BR-01: «تنتهي **بانتهاء الجلسة**». |

### Reference implementation

```ts
// /lib/time/attendance.ts
export type CheckInDenial =
  | 'session_cancelled'
  | 'window_not_open_yet'
  | 'window_closed'
  | 'already_checked_in';

export interface CheckInWindow {
  readonly opensAt: Instant;
  readonly closesAt: Instant;
  /** The last instant at which a check-in is still classified `present`. */
  readonly presentUntil: Instant;
}

export function checkInWindow(
  S: Instant,
  E: Instant,
  policy: AttendancePolicy = DEFAULT_ATTENDANCE_POLICY,
): CheckInWindow {
  return {
    opensAt:      asInstant(S - policy.checkInLeadMs),
    closesAt:     E,
    presentUntil: asInstant(S + policy.presentGraceMs),
  };
}

/**
 * BR-01. Pure. No I/O, no Date, no timezone.
 * `now` MUST be the authoritative server instant (see §1.7) — never a client value (BR-07).
 */
export function canCheckIn(
  now: Instant,
  S: Instant,
  E: Instant,
  sessionStatus: SessionStatus,
  policy: AttendancePolicy = DEFAULT_ATTENDANCE_POLICY,
): { ok: true } | { ok: false; reason: CheckInDenial } {
  // §9.9.4: «يُمنع تسجيل الحضور للجلسات الملغاة»
  if (sessionStatus === 'cancelled') return { ok: false, reason: 'session_cancelled' };

  const w = checkInWindow(S, E, policy);
  if (now < w.opensAt)  return { ok: false, reason: 'window_not_open_yet' };
  if (now > w.closesAt) return { ok: false, reason: 'window_closed' };
  return { ok: true };
}
```

### 🟠 GAP-A1 — `canCheckIn` upper bound of `E` permits a zero-duration attendance

A trainee who calls the endpoint at exactly `E` is recorded `late`, and the check-out window `[E−30m, E+30m]` is *also* open at `E`. Two API calls one millisecond apart therefore produce a complete, "attended" record for a session the person was never in. The current PRD text mandates this behaviour (BR-01 + BR-04 read literally).

**Options for the product owner (do not implement silently — §1.1 of the PRD forbids assuming):**

| Option | Rule | Effect |
|---|---|---|
| **A (PRD-literal, default)** | Keep `[S−30m, E]` | Ship as specified. Accept the loophole; it is detectable in reports. |
| **B** | Close check-in at `min(E, S + 50% × duration)` | For 6–9pm this closes check-in at 7:30pm. Matches the intent of "attendance", still generous. **Changes BR-01.** |
| **C (compensating control, no rule change)** | Keep A, but compute `presence_ms = check_out_at − check_in_at` and surface any record below a threshold in the trainer's report as «حضور صوري يحتاج مراجعة» | No right is removed automatically; the trainer decides. |

**Recommendation: A + C.** It keeps the contract exactly as written (no trainee loses a right because of a rule we invented), while making abuse visible to the human who is already authorised to correct it under §4.2 («التعديل اليدوي على سجل الحضور — نعم مع تسجيل السبب»).

---

## 1.3 `classify` — present vs late

> **PRD sources:** §9.9.2 rows 2–3, §9.9.5, §14.1 bullets 2–3, BR-02, BR-03.

### Normative rule

```
classify(checkInAt, S) = 'present'  ⟺  checkInAt ≤ S + 30m
classify(checkInAt, S) = 'late'     ⟺  checkInAt >  S + 30m
```

The boundary at `S + 30m` is **inclusive of `present`**. This is stated three independent times and is unambiguous:
- §9.9.2: «وقت التسجيل **≤** S زائد 30 دقيقة» — the PRD itself writes the `≤`.
- §9.9.3: «6:30 م — آخر لحظة تُحتسب حاضرًا».
- §14.1: «تسجيل حضور عند الدقيقة 30 بالضبط من بداية الجلسة (**يجب أن يُحتسب حاضرًا لا متأخرًا**)».

### 🔴 BLOCKER-A2 — sub-minute ambiguity at `S + 30m` is unresolved by the PRD

§14.1 says «الدقيقة 30 **بالضبط**» (minute 30 exactly) and §9.9.3 jumps from `6:30 م` to `6:31 م`. Neither text says what happens at **6:30:37 pm**. There are two mutually exclusive readings:

| Reading | Rule | `18:30:00.000` | `18:30:00.001` | `18:30:59.999` | `18:31:00.000` |
|---|---|---|---|---|---|
| **R1 — instant** (recommended) | `checkInAt ≤ S + 1_800_000 ms` | `present` | `late` | `late` | `late` |
| **R2 — minute bucket** | `floorToMinute(checkInAt) ≤ floorToMinute(S) + 30` | `present` | `present` | `present` | `late` |

R2 grants 59.999 extra seconds of grace to every trainee, in every session. Over a 14-session programme that is a materially different attendance record for anyone who habitually arrives at the boundary.

**Recommendation: R1 (instant comparison).** Reasons:
1. It is the only reading that is exact, stateless, and reproducible across timezones and clock resolutions.
2. §9.9.2 literally writes the inequality with `≤` applied to an *instant* («وقت التسجيل»), not to a minute number.
3. R2 requires a truncation function, and truncation is where timezone bugs hide (truncating in local time versus UTC differs whenever the offset is not a whole number of minutes — not an issue for Riyadh today, but a latent trap the moment the platform serves a second country).

**Required PRD amendment (to be signed off):** change §9.9.2 row 2 to read
«وقت التسجيل ≤ S + 00:30:00.000 بدقة المللي ثانية» and add a §9.9.3 row `6:30:01 م → متأخر`.

### Reference implementation

```ts
export type CheckInClass = 'present' | 'late';

/**
 * BR-02 / BR-03. Total function — every instant maps to exactly one class.
 * Deliberately does NOT take `E`: classification is independent of the session end.
 */
export function classify(
  checkInAt: Instant,
  S: Instant,
  policy: AttendancePolicy = DEFAULT_ATTENDANCE_POLICY,
): CheckInClass {
  return checkInAt <= (S + policy.presentGraceMs) ? 'present' : 'late';
}
```

### 🔴 BLOCKER-A3 — `late` is not "a different kind of attendance", and the PRD's single enum destroys it

§9.9.2 says a late check-in «يُقبل حضوره و**يُحتسب حاضرًا** لكن يُسجَّل متأخرًا» — it counts as attended. §9.7.1 journey step 2 accepts `present` **or** `late`. But §9.9.5 puts `present`, `late`, `incomplete` and `absent` in **one enum column** (`Attendance.status`), which means the sweep job's `incomplete` transition (§1.5) **overwrites and destroys** the present/late fact.

Consequence: a trainee who arrived on time but forgot to check out becomes `incomplete`, which is neither `present` nor `late`, and therefore **fails journey step 2** and — under any naive attendance-rate formula — **loses attendance credit and possibly their certificate**.

**Fix (mandatory, structural):** split the single overloaded enum into orthogonal facts.

```sql
ALTER TABLE attendance
  -- Set once at check-in. NEVER mutated by the sweep. NULL ⇒ never checked in.
  ADD COLUMN checkin_class  TEXT NULL CHECK (checkin_class IN ('present','late')),
  -- Set by the sweep or by check-out. Orthogonal to checkin_class.
  ADD COLUMN completion     TEXT NOT NULL DEFAULT 'pending'
      CHECK (completion IN ('pending','complete','incomplete','not_attended')),
  -- Administrative override, orthogonal to both. §9.9.5 `excused`.
  ADD COLUMN excused_at     TIMESTAMPTZ NULL,
  ADD COLUMN excused_by     UUID NULL REFERENCES users(id),
  ADD COLUMN excuse_reason  TEXT NULL;
```

`status` becomes a **derived** value for display only, computed by a pure function, so the UI contract in §9.9.5/§9.9.6 is preserved exactly:

```ts
export type DisplayStatus = 'present' | 'late' | 'absent' | 'excused' | 'incomplete';

export function displayStatus(r: AttendanceRow): DisplayStatus {
  if (r.excusedAt !== null)          return 'excused';    // administrative decision wins
  if (r.checkinClass === null)       return 'absent';
  if (r.completion === 'incomplete') return 'incomplete';
  return r.checkinClass;                                   // 'present' | 'late'
}
```

**The rule that follows:** everything that decides **rights** (journey steps, attendance rate, certificate eligibility) reads `checkin_class` and `excused_at`. Everything that renders a badge reads `displayStatus`. These two must never be swapped, and a test asserts it (see §7).

---

## 1.4 `canCheckOut` — exact specification

> **PRD sources:** §9.9.2 rows 4–5, §9.9.3 rows 8:29/8:30/9:29/9:31, §14.1 bullets 4–5, BR-04, BR-05.

### Normative rule

```
canCheckOut(now, S, E, hasCheckIn) ⟺
      hasCheckIn = true
   ∧  (E − 30m) ≤ now
   ∧  now ≤ (E + 30m)
```

**Both bounds inclusive (closed interval `[E−30m, E+30m]`).**

| Bound | Inequality | Evidence |
|---|---|---|
| Lower `E − 30m` | **`≤`** | §9.9.3 row `8:30 م` → «مفعّل — **بداية نافذة الانصراف**». |
| Upper `E + 30m` | **`≤`** | BR-04: «تنتهي **بعد نهايتها بـ 30 دقيقة**». §14.1: «تسجيل انصراف عند **الحد الأعلى للنافذة بالضبط** وبعده بدقيقة» — the exact upper bound is an explicitly mandated *passing* test, therefore that instant must be accepted. |

`hasCheckIn` is not a boolean the client supplies; it is the existence of a row with `check_in_at IS NOT NULL` for `(session_id, user_id)`, and it is evaluated **inside the same SQL statement as the write** (§1.8) so it cannot be raced.

### Reference implementation

```ts
export type CheckOutDenial =
  | 'session_cancelled'
  | 'no_check_in'
  | 'window_not_open_yet'
  | 'window_closed'
  | 'already_checked_out';

export interface CheckOutWindow { readonly opensAt: Instant; readonly closesAt: Instant; }

export function checkOutWindow(
  S: Instant, E: Instant, policy: AttendancePolicy = DEFAULT_ATTENDANCE_POLICY,
): CheckOutWindow {
  return {
    // Clamp: a session shorter than the lead time must not open check-out before it starts.
    // See GAP-A4.
    opensAt:  asInstant(Math.max(E - policy.checkOutLeadMs, S)),
    closesAt: asInstant(E + policy.checkOutLagMs),
  };
}

/** BR-04 + BR-05. Pure. */
export function canCheckOut(
  now: Instant, S: Instant, E: Instant,
  hasCheckIn: boolean,
  sessionStatus: SessionStatus,
  policy: AttendancePolicy = DEFAULT_ATTENDANCE_POLICY,
): { ok: true } | { ok: false; reason: CheckOutDenial } {
  if (sessionStatus === 'cancelled') return { ok: false, reason: 'session_cancelled' };
  // BR-05 is checked FIRST so the message is the specific one §9.9.8 requires:
  // «لا يمكن تسجيل الانصراف لأنك لم تسجّل حضورك في هذه الجلسة»
  if (!hasCheckIn) return { ok: false, reason: 'no_check_in' };

  const w = checkOutWindow(S, E, policy);
  if (now < w.opensAt)  return { ok: false, reason: 'window_not_open_yet' };
  if (now > w.closesAt) return { ok: false, reason: 'window_closed' };
  return { ok: true };
}
```

### 🟠 GAP-A4 — sessions shorter than 60 minutes invert the windows

The PRD guarantees nothing about session duration; §7.7 only requires `end_time > start_time`. For a 20-minute session, `E − 30m` is **10 minutes before `S`**, so the check-out window opens *before the session starts*. A trainee could check in at `S−30m` and check out at `S−10m` and hold a complete "attended" record for a session that had not begun.

**Two independent fixes, apply both:**

1. **Clamp in code** (already in `checkOutWindow` above): `opensAt = max(E − 30m, S)`.
2. **Forbid the shape at the database layer:**

```sql
ALTER TABLE sessions
  ADD CONSTRAINT sessions_min_duration
  CHECK (ends_at >= starts_at + INTERVAL '60 minutes');
```

The 60-minute floor is exactly `checkOutLeadMs + presentGraceMs`; below it the `present` grace and the check-out window overlap and the model stops being coherent. If the centre ever needs a 45-minute session, the policy durations must shrink with it — enforce that at policy-load time so the incoherence is impossible rather than merely unlikely:

```ts
export function assertPolicyCoherent(p: AttendancePolicy, sessionDurationMs: number): void {
  if (sessionDurationMs < p.presentGraceMs + p.checkOutLeadMs) {
    throw new Error(
      `Incoherent attendance policy: session ${sessionDurationMs}ms is shorter than ` +
      `presentGrace(${p.presentGraceMs}) + checkOutLead(${p.checkOutLeadMs}).`,
    );
  }
}
```

---

## 1.5 The sweep job — `absent` and `incomplete` transitions

> **PRD sources:** §9.9.5 («مهمة مجدولة تعمل كل 15 دقيقة»), BR-08, BR-09.

### Normative rules

```
T1 (absent):
   ∀ session s where  now > s.E  ∧  s.status ≠ 'cancelled'  ∧  s.deleted_at IS NULL
   ∀ enrollment e where e.cohort_id = s.cohort_id ∧ e.role_in_cohort = 'participant'
                        ∧ e.status = 'active' ∧ e.enrolled_at ≤ s.E
     if  no attendance row (s.id, e.user_id) with check_in_at IS NOT NULL
     then upsert attendance(s.id, e.user_id) with
            checkin_class = NULL, completion = 'not_attended'

T2 (incomplete):
   ∀ attendance a where  a.check_in_at IS NOT NULL
                     ∧  a.check_out_at IS NULL
                     ∧  a.completion   = 'pending'
                     ∧  now > (a.session.E + 30m)
                     ∧  a.session.status ≠ 'cancelled'
     then  a.completion := 'incomplete'    -- a.checkin_class is NOT touched (BLOCKER-A3)
           and notify the trainer (§9.16 «حضور غير مكتمل | المدرب»)

T3 (rate recompute):
   ∀ enrollment e touched by T1 or T2, or by a manual edit, a cancellation, or a check-out
     recompute e.attendance_rate per §1.9
```

**Boundary inequalities that matter:**
- T1 uses **`now > E`**, strictly. At exactly `E` a check-in is still legal (§1.2), so marking absent at `now = E` would race a legitimate in-flight check-in and steal attendance credit.
- T2 uses **`now > E + 30m`**, strictly. At exactly `E + 30m` a check-out is still legal (§1.4).

### 🟡 HARDENING-A5 — the sweep must be idempotent, re-entrant, and observable

Cloudflare Cron Triggers are an at-least-once mechanism, and a slow run can overlap the next tick. The sweep must therefore produce the same result when run twice concurrently as when run once:

- T1 is a conditional upsert (`WHERE check_in_at IS NULL`), never a blind insert.
- T2 is a conditional update (`WHERE completion = 'pending'`), so a second runner updates zero rows.
- T3 is a full recomputation from source rows (never an increment), so it is naturally idempotent.
- A `sweep_runs` table records `(started_at, finished_at, sessions_scanned, rows_absent, rows_incomplete, clock_delta_ms, error)`.

That last table is not bureaucracy. **A silently dead cron is a certificate-affecting failure that produces no error anywhere** — attendance simply stops being finalised, `absent` is never written, rates freeze, and nobody notices until certificates are issued. An alert on "no successful sweep in 45 minutes" is the only thing that catches it.

```ts
// /features/attendance/sweep.ts — Workers scheduled handler
export default {
  async scheduled(event: ScheduledController, env: Env, ctx: ExecutionContext) {
    // event.scheduledTime is the cron's intended time; the SQL still uses the DB clock (§1.7).
    ctx.waitUntil(runAttendanceSweep(env, { cronAt: event.scheduledTime }));
  },
} satisfies ExportedHandler<Env>;
```

### 🔴 BLOCKER-A6 — the sweep must never manufacture absence for a cancelled session

§7.8 says a session with attendance records is cancelled, not deleted. §9.9.4 forbids checking in to a cancelled session. The PRD never says what happens to the *cohort* when a session is cancelled. If T1 is written naively (`now > E` and no check-in ⇒ absent), then **cancelling a session creates an absence for all 60 trainees**, permanently lowering everyone's attendance rate and potentially voiding certificates under BR-26.

**Normative rule (proposed, requires sign-off):**

```
A cancelled session is removed from BOTH the numerator and the denominator of the
attendance rate for every participant. Pre-existing attendance rows for that session are
preserved unchanged (never deleted — §7.8), are excluded from all rate computations, and
are displayed with a «ملغاة» badge.
Cancelling a session MUST enqueue a rate recomputation (T3) for every affected enrollment.
```

---

## 1.6 Riyadh, DST, and why UTC storage is mandatory

### The DST claim, verified — not assumed

`Asia/Riyadh` has **no DST**. Verified against the IANA tz database as shipped in the JS runtime (`Intl.DateTimeFormat` with `timeZoneName: 'longOffset'`), sampling every month of every year from **1970 to 2035**:

```
distinct offsets observed = { "GMT+03:00" }      // exactly one element, across all 66 years
```

Saudi Arabia has observed Arabia Standard Time (UTC+03:00) continuously; the tzdb records no transition rules for the zone in the modern era.

### 🟡 Why UTC storage is *still* mandatory despite the constant offset

The absence of DST removes one hazard. It removes none of these four.

**1. The offset is a fact about current Saudi law, not a property of the universe.**
A single decree adopting DST would ship as a tzdb update, and every stored "local wall-clock" value would become ambiguous overnight — including historical attendance records that determine already-issued certificates. UTC storage makes past records immutable under any future legal change; local-time storage does not.

**2. `Date` parsing of a zoneless string is runtime-dependent — silently, by three hours.**

```ts
new Date('2026-10-12T18:00:00')      // NO zone designator
// on Cloudflare Workers (TZ=UTC)      → 2026-10-12T18:00:00.000Z
// on a laptop in Riyadh (TZ=+03:00)   → 2026-10-12T15:00:00.000Z
```

Same code, same input, three-hour difference between the developer's machine and production. Every attendance boundary test would pass locally and fail in Riyadh. This is precisely the failure §18 rates «مرتفع — يمس حقوق المتدربين».

**3. `Date` has no zone-aware arithmetic. "Add 30 minutes to a local time" is not an operation `Date` supports.**

```ts
// ❌ FORBIDDEN — string/local arithmetic
const s = '2026-10-12 18:00';
const presentUntil = new Date(s);
presentUntil.setMinutes(presentUntil.getMinutes() + 30);   // reads the ambient zone twice

// ✅ REQUIRED — instant arithmetic
const S: Instant = asInstant(1_760_284_800_000);
const presentUntil: Instant = asInstant(S + 30 * MINUTE);
```

`setMinutes` reads the ambient zone, mutates in place, and returns a number rather than the Date — three footguns in one line.

**4. Rendering and parsing are the only places the zone name may appear.**

```ts
// /lib/time/riyadh.ts — the ONLY module permitted to name the timezone.
export const RIYADH = 'Asia/Riyadh' as const;

/** Instant → what a human in Riyadh sees. Display only. Never feed the result back in. */
export function formatRiyadh(t: Instant, opts: Intl.DateTimeFormatOptions = {}): string {
  return new Intl.DateTimeFormat('ar-SA-u-nu-latn', {   // §5.4: Latin digits mandated
    timeZone: RIYADH, hour12: true, hour: 'numeric', minute: '2-digit', ...opts,
  }).format(t);
}

/**
 * Admin types "12 Oct 2026, 6:00 pm Riyadh" → Instant.
 * Uses the offset the tz database reports FOR THAT DATE, so it stays correct even if
 * the zone's rules ever change. Never `new Date(localString)`.
 */
export function riyadhWallClockToInstant(
  y: number, mo: number, d: number, h: number, mi: number,
): Instant {
  const guess = Date.UTC(y, mo - 1, d, h, mi, 0, 0);       // provisional
  const off1 = zoneOffsetMs(RIYADH, guess);
  const corrected = guess - off1;
  const off2 = zoneOffsetMs(RIYADH, corrected);            // one fixed-point iteration,
  return asInstant(off2 === off1 ? corrected : guess - off2); // in case we crossed a transition
}

function zoneOffsetMs(tz: string, at: number): number {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: tz, hourCycle: 'h23',
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit',
  }).formatToParts(at);
  const g = (t: string) => Number(parts.find(p => p.type === t)!.value);
  const asUtc = Date.UTC(g('year'), g('month') - 1, g('day'), g('hour'), g('minute'), g('second'));
  return asUtc - at;
}
```

### Storage types

| Store | Column type | Rationale |
|---|---|---|
| PostgreSQL | `TIMESTAMPTZ` | Stores an absolute instant. **Never `TIMESTAMP`** — `TIMESTAMP WITHOUT TIME ZONE` is a wall-clock string with no defined meaning. |
| Cloudflare D1 (SQLite) | `INTEGER` epoch **milliseconds** | SQLite has no date type. Storing ISO strings invites lexicographic-comparison bugs and offset-suffix drift. Integers compare and index correctly. |

### 🔴 BLOCKER-A7 — the `(date, start_time, end_time)` shape forbids midnight-crossing sessions, which §14.1 requires

§7.3 models a session as `date: Date`, `start_time: Time`, `end_time: Time`, and §7.7 adds `CHECK (end_time > start_time)`. That constraint makes a session from **23:00 to 01:00 structurally impossible**, yet §14.1 mandates the test «جلسة تمتد عبر منتصف الليل». The PRD contradicts itself.

Even ignoring the check constraint, the shape is broken: `S = date + start_time` and `E = date + end_time` gives `E < S` for a midnight-crossing session, which inverts every window in §1.2–1.5 — `checkInWindow.opensAt > closesAt`, so the check-in button never opens and no error is ever raised.

**Fix (mandatory):**

```sql
-- Replace date + start_time + end_time with two absolute instants.
ALTER TABLE sessions
  ADD COLUMN starts_at TIMESTAMPTZ NOT NULL,
  ADD COLUMN ends_at   TIMESTAMPTZ NOT NULL,
  DROP COLUMN date, DROP COLUMN start_time, DROP COLUMN end_time;

ALTER TABLE sessions ADD CONSTRAINT sessions_end_after_start
  CHECK (ends_at > starts_at);                                   -- §7.7, preserved
ALTER TABLE sessions ADD CONSTRAINT sessions_min_duration
  CHECK (ends_at >= starts_at + INTERVAL '60 minutes');          -- GAP-A4
ALTER TABLE sessions ADD CONSTRAINT sessions_max_duration
  CHECK (ends_at <= starts_at + INTERVAL '12 hours');            -- typo guard: catches a
                                                                 -- year/date entry mistake
                                                                 -- before it opens a
                                                                 -- 300-day check-in window

-- The calendar day in Riyadh, for grouping and the §9.8 schedule views.
-- GENERATED so it can never drift from starts_at.
ALTER TABLE sessions
  ADD COLUMN session_date_riyadh DATE
  GENERATED ALWAYS AS ((starts_at AT TIME ZONE 'Asia/Riyadh')::date) STORED;

CREATE INDEX sessions_cohort_date_idx   ON sessions (cohort_id, session_date_riyadh);  -- §7.7
CREATE INDEX sessions_cohort_starts_idx ON sessions (cohort_id, starts_at)
  WHERE deleted_at IS NULL;                        -- the index every window query actually uses
```

With absolute instants a midnight-crossing session needs **no special handling anywhere**. `S = 2026-10-12T20:00Z` (23:00 Riyadh), `E = 2026-10-12T22:00Z` (01:00 Riyadh, next day). Every inequality in §1.2–1.5 works unchanged; the check-in window opens on the previous Riyadh calendar day, which is correct and invisible to the arithmetic.

The only thing needing care is **display**: §9.8 groups by `session_date_riyadh`, so a midnight-crossing session appears under its *start* date and the UI must render an explicit "ends next day" marker.

**D1 equivalent** (SQLite has no `INTERVAL`, no `TIMESTAMPTZ`, and a generated column cannot call a timezone conversion):

```sql
CREATE TABLE sessions (
  id            TEXT PRIMARY KEY,
  cohort_id     TEXT NOT NULL REFERENCES cohorts(id),
  starts_at_ms  INTEGER NOT NULL,                  -- epoch ms UTC
  ends_at_ms    INTEGER NOT NULL,
  -- Riyadh is a fixed +03:00 offset, so the day boundary is pure integer arithmetic.
  -- If the zone ever gains DST this must become a maintained column, not a generated one.
  session_date_riyadh TEXT
    GENERATED ALWAYS AS (date((starts_at_ms + 10800000) / 1000, 'unixepoch')) VIRTUAL,
  status        TEXT NOT NULL CHECK (status IN ('scheduled','live','completed','cancelled')),
  cancellation_reason TEXT,
  deleted_at_ms INTEGER,
  CHECK (ends_at_ms >  starts_at_ms),
  CHECK (ends_at_ms >= starts_at_ms + 3600000),     -- 60 min
  CHECK (ends_at_ms <= starts_at_ms + 43200000),    -- 12 h
  CHECK (status <> 'cancelled' OR cancellation_reason IS NOT NULL)   -- §7.3
);
CREATE INDEX sessions_cohort_starts_idx ON sessions (cohort_id, starts_at_ms);
```

---

## 1.7 Clock skew at the edge — is `Date.now()` trustworthy in a Worker?

> ### 📌 ملخص
> **الخلاصة: لا.** ساعة الـ Worker غير صالحة كمرجع زمني لتسجيل الحضور، لسببين مستقلين:
> **(أ)** Cloudflare **تجمّد الساعة عمدًا** داخل الـ Worker كإجراء حماية من هجمات القنوات الجانبية الزمنية (Spectre) — `Date.now()` لا يتقدّم أثناء التنفيذ المتزامن، بل يعود بوقت آخر عملية إدخال/إخراج.
> **(ب)** الـ Worker يعمل في **مئات مراكز البيانات**، ولا تنشر Cloudflare أي ضمان موثّق لحدّ أقصى لانحراف الساعة بينها.
> **الحل المعتمد:** اللحظة المرجعية `now` تُقرأ من **ساعة قاعدة البيانات داخل نفس عبارة SQL** التي تنفّذ الكتابة. هذا يحقق أمرين معًا: مصدر زمني واحد لا انحراف فيه، وإلغاء الفجوة بين «الفحص» و«الكتابة».

### Finding (a) — the clock is intentionally frozen between I/O operations

Cloudflare freezes `Date.now()` inside a Worker as a timing side-channel (Spectre) mitigation. The value returned is the time of the **last I/O operation**, and it does not advance during synchronous execution. Two consecutive `Date.now()` calls with pure computation between them return the *same* value; a Worker therefore cannot measure elapsed time, and its notion of "now" is exactly as stale as its last network round-trip. [W1][W2]

Cloudflare's own words, from the runtime documentation:

> “`Date.now()` returns the time of the last I/O; it does not advance during code execution.” [W1]

> “As a security measure to mitigate against Spectre attacks … APIs that return timers, including `performance.now()` and `Date.now()`, only advance or increment after I/O occurs.” [W2]

`performance.timeOrigin` is `0` in Workers, so `performance.now()` always equals `Date.now()` — there is no second, finer clock to fall back on. Cloudflare's August 2026 Spectre review confirms the mitigation is unchanged: the response to new research was V8 sandbox hardening and Memory Protection Keys, **not** relaxing the clock freeze. [W2a]

This is not entirely bad news — within a single request all `Date.now()` calls agree, which removes a class of “the boundary moved mid-request” bug. But it means the value is *an approximation of a past instant*, not the present, and it has two consequences this application must design around:

**Consequence 1 — two rows written in one request without intervening I/O receive identical timestamps.** Ordering cannot be derived from `Date.now()`. Anywhere ordering matters (the audit hash chain in §4.5, submission versions in §4.3), use a database sequence or counter, never a wall-clock value.

**🔴 Consequence 2 — local development does not reproduce this.** Cloudflare states plainly: “In local development, however, timers will increment regardless of whether I/O happens or not.” [W2] So a Worker that measures elapsed time, or that relies on `Date.now()` advancing, **works perfectly in `wrangler dev` and silently misbehaves in production**. For an attendance engine this is the worst possible failure shape — every boundary test passes locally. The mandated pattern below (the authoritative instant comes from the database, inside the write statement) removes the dependency entirely, which is the only reliable way to avoid this trap.

### Finding (b) — there is no published cross-datacentre skew bound

Workers execute in whichever of Cloudflare's datacentres the user's request lands in, and the same user's two requests may land in different ones. Cloudflare's fleet is NTP-synchronised and the company operates public time services, but **no SLA or documented maximum inter-colo clock skew is published**. [W3]

For a boundary that decides `present` versus `late` at a millisecond, "probably within a few milliseconds" is not a guarantee we may build a certificate on. Two trainees clicking simultaneously — one routed to a Jeddah colo, one to a Frankfurt colo — could be classified differently under the same rule.

### The consequence, stated plainly

```ts
// ❌ FORBIDDEN in any code path that writes an attendance record.
const now = asInstant(Date.now());
const verdict = canCheckIn(now, S, E, status);
if (verdict.ok) await db.insert(...);      // two independent defects
```

This has **two** defects, not one:
1. `Date.now()` is the wrong clock — frozen, per-colo, unbounded skew.
2. Even with a perfect clock there is a gap between the check and the write. Under the concurrency §18 explicitly anticipates («ضغط متزامن على تسجيل الحضور في بداية الجلسة»), that gap is exploitable and produces records whose stored `check_in_at` disagrees with the window that was evaluated.

### The mandated pattern

**One clock: the database's. One statement: the conditional upsert.**

```ts
// ✅ REQUIRED — window predicate and write are the same statement, against one clock.
const row = await checkInAtomic(db, { sessionId, userId, ip, ua });
```

Full SQL in §1.8. The properties this buys:
- **No clock to skew.** All Workers, in all colos, defer to the single database primary.
- **No TOCTOU interval.** The window is evaluated and the row is written in one atomic operation.
- **Internal consistency by construction.** The classification (`present`/`late`) is computed from the same instant that is stored in `check_in_at`, so a record can never contradict its own class.

`Date.now()` remains acceptable for exactly three non-authoritative purposes:
1. Rendering the client-side countdown («يُفتح تسجيل الحضور بعد 00:24:15»). Even there the *server* sends the absolute `opensAt` instant and the client counts down to it — so a user with a wrong device clock sees a wrong countdown but cannot affect the outcome. This is what makes §14.1's «تعديل ساعة الجهاز والمحاولة» pass by construction rather than by a check.
2. Logs and metrics.
3. Cache TTL arithmetic.

### 🟡 HARDENING-A8 — clock-drift canary

A silently wrong database clock would corrupt attendance with no error anywhere. Every sweep run records `db_now − worker_now` into `sweep_runs.clock_delta_ms` and pages if `|delta| > 5000 ms`. This catches both a drifting DB host and a Worker executing far from its last I/O.

---

## 1.8 Concurrent double check-in — the exact constraint and statement

> **PRD sources:** §9.9.4 bullet 3, §7.7 bullet 1, BR-06, §14.1 «إرسال طلبَي تسجيل حضور متزامنين»,
> §9.9 acceptance criterion «التسجيل المكرر مستحيل حتى بإرسال طلبين متزامنين».

### The constraint

The unique index is necessary but **not sufficient**. On its own it turns a double submit into a *database error* (`23505` / `SQLITE_CONSTRAINT_UNIQUE`), which the user sees as «حدث خطأ غير متوقع» — a failure, not the required «سجّلت حضورك مسبقًا لهذه الجلسة». The correct design makes a double submit **idempotent**, not erroneous.

```sql
-- The constraint. Deliberately TOTAL, not partial: attendance rows are never soft-deleted
-- (§7.8 — they are evidence), so no `WHERE deleted_at IS NULL` predicate is needed. That
-- also keeps ON CONFLICT inference simple.
ALTER TABLE attendance
  ADD CONSTRAINT attendance_one_row_per_user_per_session
  UNIQUE (session_id, user_id);
```

> ⚠️ **If this index is ever made partial, `ON CONFLICT (session_id, user_id)` silently stops inferring it.**
> A partial unique index requires its predicate to be repeated in the conflict target:
> `ON CONFLICT (session_id, user_id) WHERE deleted_at IS NULL`. Retrofitting soft-delete onto a
> table that has an upsert is a classic silent production break. Attendance must not be soft-deleted.

### PostgreSQL — the complete statement

```sql
-- checkInAtomic (PostgreSQL)
-- $1 = new attendance id (uuid, from crypto.randomUUID() in the Worker)
-- $2 = acting user id    $3 = session id    $4 = CF-Connecting-IP    $5 = user agent
WITH policy AS (
  SELECT INTERVAL '30 minutes' AS lead_in,
         INTERVAL '30 minutes' AS grace
),
candidate AS (
  SELECT
    s.id                                     AS session_id,
    e.user_id                                AS user_id,
    now()                                    AS at,
    CASE WHEN now() <= s.starts_at + p.grace
         THEN 'present' ELSE 'late' END      AS checkin_class
  FROM sessions s
  CROSS JOIN policy p
  -- ▼ This join IS the authorization check (§4.3: query-level scoping, not an endpoint check).
  --   A user who is not an active participant of this session's cohort yields zero rows,
  --   so the INSERT inserts nothing. Authorization and business rule share one predicate.
  JOIN enrollments e
    ON e.cohort_id      = s.cohort_id
   AND e.user_id        = $2
   AND e.role_in_cohort = 'participant'
   AND e.status         = 'active'
  WHERE s.id          = $3
    AND s.deleted_at IS NULL
    AND s.status     <> 'cancelled'                   -- §9.9.4
    -- ▼ BR-01, evaluated against the DATABASE clock, inside the write statement.
    AND now() >= s.starts_at - p.lead_in              -- inclusive lower bound
    AND now() <= s.ends_at                            -- inclusive upper bound
)
INSERT INTO attendance (
  id, session_id, user_id, check_in_at, checkin_class, completion,
  is_manual, ip_address, user_agent, source, created_at, updated_at
)
SELECT $1, c.session_id, c.user_id, c.at, c.checkin_class, 'pending',
       FALSE, $4, $5, 'self', c.at, c.at
FROM candidate c
ON CONFLICT ON CONSTRAINT attendance_one_row_per_user_per_session
DO UPDATE SET
  check_in_at   = EXCLUDED.check_in_at,
  checkin_class = EXCLUDED.checkin_class,
  completion    = 'pending',
  ip_address    = EXCLUDED.ip_address,
  user_agent    = EXCLUDED.user_agent,
  updated_at    = EXCLUDED.updated_at
WHERE
  -- ▼ Only upgrade an `absent` stub the sweep wrote. A real check-in is NEVER overwritten:
  --   this is exactly what makes a double submit a no-op rather than a second write. (BR-06)
      attendance.check_in_at IS NULL
  -- ▼ Never clobber a trainer's manual correction (§4.2, BR-10).
  AND attendance.is_manual  = FALSE
RETURNING
  id, check_in_at, checkin_class,
  (xmax = 0) AS was_inserted;      -- Postgres: xmax = 0 ⇔ the row came from the INSERT arm
```

**Result interpretation — three distinct outcomes from one round-trip:**

| Rows | `was_inserted` | Meaning | HTTP | §9.9.8 message |
|---|---|---|---|---|
| 1 | `true` | First check-in; row created | `201` | «تم تسجيل حضورك الساعة …» (+ «وسُجّل متأخرًا» when `late`) |
| 1 | `false` | Upgraded the `absent` stub the sweep had written | `201` | same |
| 0 | — | Either the window/enrolment predicate failed, **or** a real check-in already existed | `409`/`403` | disambiguated by one read-only follow-up |

The zero-row case is deliberately ambiguous *in the write statement* — that ambiguity is precisely what makes it race-free. Disambiguate afterwards with a **read-only** query, which cannot affect correctness because no row was written either way:

```ts
if (result.rows.length === 0) {
  const existing = await db.attendance.findFirst({
    where:  { sessionId, userId, checkInAt: { not: null } },
    select: { checkInAt: true, checkinClass: true },
  });
  // BR-06 → «سجّلت حضورك مسبقًا لهذه الجلسة»
  if (existing) return conflict('ALREADY_CHECKED_IN', existing);
  // Otherwise recompute the precise denial for the §9.9.8 message. Read-only ⇒ no race risk.
  return denyWithReason(await explainCheckInDenial(db, sessionId, userId));
}
```

### Cloudflare D1 (SQLite) — the complete statement

```sql
-- checkInAtomic (D1 / SQLite)
-- ?1 = new attendance id  ?2 = user id  ?3 = session id  ?4 = ip  ?5 = user agent
-- The clock is SQLite's, not the Worker's: unixepoch('subsec') * 1000 → epoch ms.
INSERT INTO attendance (
  id, session_id, user_id, check_in_at_ms, checkin_class, completion,
  is_manual, ip_address, user_agent, source, created_at_ms, updated_at_ms
)
SELECT
  ?1, s.id, e.user_id,
  CAST(unixepoch('subsec') * 1000 AS INTEGER),
  CASE WHEN CAST(unixepoch('subsec') * 1000 AS INTEGER) <= s.starts_at_ms + 1800000
       THEN 'present' ELSE 'late' END,
  'pending',
  0, ?4, ?5, 'self',
  CAST(unixepoch('subsec') * 1000 AS INTEGER),
  CAST(unixepoch('subsec') * 1000 AS INTEGER)
FROM sessions s
JOIN enrollments e
  ON e.cohort_id      = s.cohort_id
 AND e.user_id        = ?2
 AND e.role_in_cohort = 'participant'
 AND e.status         = 'active'
WHERE s.id             = ?3
  AND s.deleted_at_ms IS NULL
  AND s.status        <> 'cancelled'
  AND CAST(unixepoch('subsec') * 1000 AS INTEGER) >= s.starts_at_ms - 1800000
  AND CAST(unixepoch('subsec') * 1000 AS INTEGER) <= s.ends_at_ms
ON CONFLICT (session_id, user_id) DO UPDATE SET
  check_in_at_ms = excluded.check_in_at_ms,
  checkin_class  = excluded.checkin_class,
  completion     = 'pending',
  ip_address     = excluded.ip_address,
  user_agent     = excluded.user_agent,
  updated_at_ms  = excluded.updated_at_ms
WHERE attendance.check_in_at_ms IS NULL
  AND attendance.is_manual = 0
RETURNING id, check_in_at_ms, checkin_class;
```

**D1/SQLite notes that matter here:** [W4][W5]
- SQLite's UPSERT (`ON CONFLICT … DO UPDATE`) and the `excluded.` pseudo-table behave as in Postgres, and the `WHERE` clause on the `DO UPDATE` arm is supported — that clause is what makes the operation a no-op for a genuine duplicate.
- `RETURNING` is supported. There is no `xmax`; insert-versus-upgrade can be distinguished by comparing the returned `check_in_at_ms`, or simply ignored (the user-visible outcome is identical).
- Repeating `unixepoch('subsec')` within one statement is safe — SQLite's time functions are constant for the duration of a single statement.
- **🔴 D1 rejects SQL transactions outright.** `BEGIN TRANSACTION`, `COMMIT`, `ROLLBACK` and `SAVEPOINT` are not merely discouraged — they error (`cannot start a transaction within a transaction`). **`batch()` is D1's only atomicity primitive**: Cloudflare documents that “Batched statements are SQL transactions. If a statement in the sequence fails, then an error is returned for that specific statement, and it aborts or rolls back the entire sequence.” [W5] There are no interactive transactions — you cannot read, decide in JavaScript, then write inside one transaction. Every invariant must therefore be expressed either as a **single statement** (this design), as a `batch()`, as an optimistic `UPDATE … WHERE version = ?`, or inside a Durable Object. This constraint is *why* the check-in was designed as one statement rather than a read-then-write.
- **D1 hard limits that shape the code:** **100 bound parameters per query** (so bulk inserts must use `batch()` with many statements, not one giant multi-`VALUES` statement), 50 queries per Worker invocation on Free / 1,000 on Paid, 100 KB max statement length, 2 MB max row size, 30 s max query duration. [W5a] The 100-parameter cap is the one that bites first — the sweep job (§1.5) must page its work rather than build one large statement.
- **Read replicas can serve stale data unless the Sessions API is used.** Cloudflare: “To use read replication, you must use the D1 Sessions API, otherwise all queries will continue to be executed only by the primary database.” [W5b] Since all attendance writes go to the primary and the window predicate is evaluated there, this design is unaffected — but any read path that later adds replication must use `withSession()` to get read-your-own-writes, or a trainee will check in and then see “not checked in” on the next screen.
- D1 writes are served by a single primary, so `unixepoch()` is one authoritative clock even though the Workers reading it are globally distributed — the same guarantee the Postgres design relies on.

### Check-out — same shape, different predicate

```sql
-- PostgreSQL
UPDATE attendance a
SET    check_out_at = now(),
       completion   = 'complete',
       updated_at   = now()
FROM   sessions s
WHERE  a.session_id    = s.id
  AND  a.session_id    = $2
  AND  a.user_id       = $1
  AND  s.deleted_at   IS NULL
  AND  s.status       <> 'cancelled'
  AND  a.check_in_at  IS NOT NULL                                        -- BR-05
  AND  a.check_out_at IS NULL                                            -- idempotent
  AND  now() >= GREATEST(s.ends_at - INTERVAL '30 minutes', s.starts_at) -- GAP-A4 clamp
  AND  now() <= s.ends_at + INTERVAL '30 minutes'                        -- BR-04, inclusive
RETURNING a.check_out_at;
```

Zero rows ⇒ one read to produce the precise §9.9.8 message (`no_check_in` / `already_checked_out` / `window_not_open_yet` / `window_closed` / `session_cancelled`).

### 🟡 HARDENING-A9 — do not rely on the constraint as the concurrency control

The unique index is the **last** line of defence, not the first. In front of it:
- an **idempotency key** (`Idempotency-Key` header, or a client-generated attendance UUID reused across retries) so a network retry cannot become a second logical attempt;
- the per-account rate limit from §12.4 (10/user/min) — see §5.6 for why attendance limits must be **per-account and never per-IP**;
- the UI submit-disable pattern §9.2.2 already requires — cosmetic only, never counted as a control.

---

## 1.9 🔴 BLOCKER-A10 — the attendance-rate formula does not exist in the PRD

The PRD requires the sweep to «إعادة حساب نسبة الحضور لكل متدرب» (§9.9.5), stores `Enrollment.attendance_rate`, displays it with colour thresholds (§9.9.6), warns when it nears the minimum, and **gates the certificate on it** (BR-26; §9.17 «نسبة حضور لا تقل عن الحد المحدد للدفعة (افتراضيًا 75%)»).

**It never defines the formula.** Every question below is unanswered, and each one changes who receives a certificate:

| Question | One answer | The other |
|---|---|---|
| Are **future** sessions in the denominator? | Everyone shows 8% in week 1; the §9.9.6 red alert fires for the whole cohort | Rate is meaningful from day one |
| Does **`late`** count as attended? | A punctual trainee and a two-hour-late trainee are identical | Late reduces the rate — but §9.9.2 says «يُحتسب حاضرًا» |
| Does **`incomplete`** count as attended? | A dead phone battery costs a certificate | Check-out becomes advisory |
| Is **`excused`** in the denominator? | A medically excused absence lowers the rate | Excused is neutral |
| Are **cancelled** sessions counted? | Cancelling manufactures absences (BLOCKER-A6) | Neutral |
| A trainee who **enrolled late** — do earlier sessions count? | Late joiners can never qualify | Fair |

### Proposed normative formula (requires product-owner sign-off before Phase 4)

```ts
/**
 * BR-26 input. Pure function over rows — no clock, no I/O, fully testable.
 * `elapsedSessions` = the cohort's sessions with ends_at <= now, excluding cancelled ones and
 *                     excluding those that ended before this participant enrolled.
 */
export function attendanceRate(input: {
  elapsedSessions: ReadonlyArray<{ id: string }>;
  records: ReadonlyMap<string, { checkinClass: CheckInClass | null; excused: boolean }>;
}): { rate: number | null; attended: number; counted: number; excused: number } {
  let attended = 0, counted = 0, excused = 0;

  for (const s of input.elapsedSessions) {
    const r = input.records.get(s.id);
    if (r?.excused) { excused++; continue; }     // removed from BOTH numerator and denominator
    counted++;
    // `present` and `late` both count as attended (§9.9.2: «يُقبل حضوره ويُحتسب حاضرًا»).
    // `incomplete` counts as attended too — the person WAS there. The missing check-out is a
    // separate fact carried by `completion` and reported to the trainer (BR-09); it must not
    // silently cost a certificate. See BLOCKER-A3.
    if (r?.checkinClass === 'present' || r?.checkinClass === 'late') attended++;
  }

  return {
    rate: counted === 0 ? null : Math.round((attended / counted) * 10_000) / 100,   // 2 dp
    attended, counted, excused,
  };
}
```

**Decisions this encodes — each of which the PRD left open:**
1. Denominator = **elapsed, non-cancelled** sessions only. Future sessions never count. Before the first session the rate is `null` and the UI renders «—», not «0%».
2. `present` and `late` both count as attended — required by §9.9.2's own wording.
3. `incomplete` counts as attended. Rationale: the person demonstrably attended, and BR-09 already routes the missing check-out to the trainer as an exception to handle by hand. Making it silently cost a certificate would let a UI glitch or a flat battery void a trainee's whole programme.
4. `excused` is removed from **both** numerator and denominator — the only reading under which "excused" means anything.
5. Sessions that ended before `enrollment.enrolled_at` are excluded.
6. Rounding to 2 dp for display; certificate eligibility compares the **unrounded** value against `cohort.min_attendance_rate`, is evaluated once, and is **frozen onto the certificate** (`Certificate.attendance_rate`) so a later recomputation can never retroactively invalidate an issued certificate.

### 🟠 GAP-A11 — §9.9.6's colour thresholds have gaps and contradict the default policy

§9.9.6: «أخضر فوق 85%، برتقالي بين 70 و85، أحمر تحت 70». Exactly 85 and exactly 70 belong to no band. Worse, the *default certificate threshold is 75%* (§9.17 / `Cohort.min_attendance_rate`), so a trainee sitting at 72% sees a reassuring orange badge **while being below the certificate bar**.

**Fix:** derive the bands from the cohort's own threshold and close the intervals:

```ts
export function attendanceBand(rate: number, minRequired: number):
  'ok' | 'at_risk' | 'below_requirement' {
  if (rate <  minRequired)      return 'below_requirement';   // red   — §9.9.6 «تنبيه بارز»
  if (rate <  minRequired + 10) return 'at_risk';             // orange
  return 'ok';                                                 // green
}
```

With the default `min_attendance_rate = 75`: red `< 75`, orange `75 ≤ r < 85`, green `≥ 85`. Every real number now falls in exactly one band, and red means exactly one thing: *you do not currently qualify for the certificate*.

---

## 1.10 THE BOUNDARY TEST TABLE — session 18:00–21:00 Asia/Riyadh

Session `2026-10-12`, `S = 18:00:00.000` Riyadh (`15:00:00.000Z`), `E = 21:00:00.000` Riyadh (`18:00:00.000Z`).
Policy: all four durations = 30 min. Session status = `scheduled`.
Derived boundaries: `S−30m = 17:30`, `S+30m = 18:30`, `E−30m = 20:30`, `E+30m = 21:30`.

### 1.10.1 Complete boundary table — every boundary at ±1 s, plus the exact instants

| # | Riyadh local | UTC instant | `canCheckIn` | `classify` (if it succeeded) | `canCheckOut` (given a prior check-in) | Note |
|---|---|---|---|---|---|---|
| 1 | `17:29:59.000` | `14:29:59Z` | ❌ `window_not_open_yet` | — | ❌ `window_not_open_yet` | **`S−30m−1s`** |
| 2 | `17:29:59.999` | `14:29:59.999Z` | ❌ `window_not_open_yet` | — | ❌ | last closed instant |
| 3 | **`17:30:00.000`** | **`14:30:00Z`** | ✅ **opens** | `present` | ❌ | **`S−30m`, inclusive** — §9.9.3 «بداية النافذة» |
| 4 | `17:30:01.000` | `14:30:01Z` | ✅ | `present` | ❌ | **`S−30m+1s`** |
| 5 | `17:59:59.000` | `14:59:59Z` | ✅ | `present` | ❌ | `S−1s` |
| 6 | **`18:00:00.000`** | **`15:00:00Z`** | ✅ | `present` | ❌ | **`S` exactly** |
| 7 | `18:00:01.000` | `15:00:01Z` | ✅ | `present` | ❌ | `S+1s` |
| 8 | `18:15:00.000` | `15:15:00Z` | ✅ | `present` | ❌ | §9.9.3 row «6:15 م» |
| 9 | `18:29:59.000` | `15:29:59Z` | ✅ | `present` | ❌ | **`S+30m−1s`** |
| 10 | **`18:30:00.000`** | **`15:30:00Z`** | ✅ | **`present`** | ❌ | **`S+30m`, inclusive** — §14.1: «الدقيقة 30 بالضبط … يجب أن يُحتسب **حاضرًا**» |
| 11 | **`18:30:00.001`** | **`15:30:00.001Z`** | ✅ | **`late`** | ❌ | **first `late` instant under R1** ⚠️ **the PRD does not cover this instant — DISC-2** |
| 12 | `18:30:01.000` | `15:30:01Z` | ✅ | `late` | ❌ | **`S+30m+1s`** |
| 13 | `18:30:59.999` | `15:30:59.999Z` | ✅ | `late` (R1) / `present` (R2) | ❌ | ⚠️ **the whole minute 18:30:00.001–18:30:59.999 is undefined in the PRD — DISC-2** |
| 14 | `18:31:00.000` | `15:31:00Z` | ✅ | `late` | ❌ | §9.9.3 «6:31 م — يُحتسب متأخرًا». Both readings agree. |
| 15 | `20:29:59.000` | `17:29:59Z` | ✅ | `late` | ❌ `window_not_open_yet` | **`E−30m−1s`**; §9.9.3 «8:29 م» |
| 16 | **`20:30:00.000`** | **`17:30:00Z`** | ✅ | `late` | ✅ **opens** | **`E−30m`, inclusive** — §9.9.3 «بداية نافذة الانصراف» |
| 17 | `20:30:01.000` | `17:30:01Z` | ✅ | `late` | ✅ | **`E−30m+1s`** |
| 18 | `20:59:59.000` | `17:59:59Z` | ✅ | `late` | ✅ | **`E−1s`** |
| 19 | **`21:00:00.000`** | **`18:00:00Z`** | ✅ **last instant** | `late` | ✅ | **`E`, inclusive** — §9.9.3 «آخر لحظة، ثم يُغلق». See GAP-A1. |
| 20 | **`21:00:00.001`** | **`18:00:00.001Z`** | ❌ `window_closed` | — | ✅ | **`E+1ms` — check-in closed** |
| 21 | `21:00:01.000` | `18:00:01Z` | ❌ `window_closed` | — | ✅ | **`E+1s`** |
| 22 | `21:29:00.000` | `18:29:00Z` | ❌ | — | ✅ | §9.9.3 calls this «آخر لحظة» ⚠️ **contradicts BR-04 — DISC-1** |
| 23 | `21:29:59.000` | `18:29:59Z` | ❌ | — | ✅ | **`E+30m−1s`** |
| 24 | **`21:30:00.000`** | **`18:30:00Z`** | ❌ | — | ✅ **last instant** | **`E+30m`, inclusive** — BR-04 + §14.1 ⚠️ **§9.9.3 omits this row entirely — DISC-1** |
| 25 | **`21:30:00.001`** | **`18:30:00.001Z`** | ❌ | — | ❌ `window_closed` | **`E+30m+1ms`** |
| 26 | `21:30:01.000` | `18:30:01Z` | ❌ | — | ❌ `window_closed` | **`E+30m+1s`** |
| 27 | `21:31:00.000` | `18:31:00Z` | ❌ | — | ❌ `window_closed` | §9.9.3 «9:31 م — انتهت النافذة». Consistent. |
| 28 | `> 21:00:00.000` (sweep) | — | — | — | — | **T1**: participants with no check-in → `completion='not_attended'` (BR-08) |
| 29 | `> 21:30:00.000` (sweep) | — | — | — | — | **T2**: `pending` → `incomplete`, notify trainer (BR-09) |

### 1.10.2 Cross-check against the PRD's own §9.9.3 table — discrepancy report

| §9.9.3 row | PRD's stated behaviour | This spec | Verdict |
|---|---|---|---|
| `5:00 م` — «معطّل مع عدّاد: يُفتح بعد 30:00» | check-in disabled; countdown `30:00` | identical (`17:30:00 − 17:00:00 = 30:00`) | ✅ agree |
| `5:30 م` — «مفعّل — بداية النافذة» | check-in enabled at `S−30m` | inclusive lower bound | ✅ agree |
| `6:15 م` — «يُحتسب حاضرًا» | `present` | `present` | ✅ agree |
| `6:30 م` — «آخر لحظة تُحتسب حاضرًا» | `present` at `S+30m` | inclusive | ✅ agree |
| `6:31 م` — «يُحتسب متأخرًا» | `late` | `late` | ✅ agree — **but the 59.999 s in between is undefined → DISC-2** |
| `8:29 م` — «مفعّل … / انصراف معطّل» | check-out closed at `E−31m` | closed | ✅ agree |
| `8:30 م` — «… / مفعّل — بداية نافذة الانصراف» | check-out opens at `E−30m` | inclusive | ✅ agree |
| `9:00 م` — «آخر لحظة، ثم يُغلق / مفعّل» | check-in closes at `E`, inclusive | inclusive | ✅ agree |
| `9:29 م` — «معطّل / مفعّل — **آخر لحظة**» | **check-out's last moment is 21:29** | **21:30:00.000 is the last moment** | 🔴 **DISC-1 — CONTRADICTION** |
| *(no row)* `9:30 م` | **absent from the table** | valid check-out — the inclusive upper bound | 🔴 **DISC-1** |
| `9:31 م` — «معطّل / معطّل — انتهت النافذة» | check-out closed | closed | ✅ agree |

---

### 🔴 DISC-1 — §9.9.3 loses one minute of the check-out window

**The contradiction.** Four PRD statements cannot all be true:

1. **§9.9.2** — «تسجيل الانصراف | من E ناقص 30 دقيقة، حتى **E زائد 30 دقيقة**»
2. **BR-04** — «نافذة تسجيل الانصراف … تنتهي **بعد نهايتها بـ 30 دقيقة**»
3. **§14.1** — «تسجيل انصراف عند **الحد الأعلى للنافذة بالضبط** وبعده بدقيقة» — a *mandated passing test* at the exact upper bound, which is only meaningful if that instant succeeds
4. **§9.9.3** — «`9:29 م` … مفعّل — **آخر لحظة**» ⟵ places the close at `E+29m`

(1), (2) and (3) agree on `E+30m` inclusive. (4) disagrees by a full minute, and additionally **omits the `9:30 م` row altogether**, so a reader cannot even determine whether the author intended 9:30 to be valid.

**Impact.** A trainee checking out at 21:29:30 is fine under either reading. A trainee checking out at **21:30:00** — the exact boundary the PRD elsewhere mandates as a test — is *accepted* under (1)(2)(3) and *rejected* under (4). Rejection means the sweep flips their record to `incomplete` (BR-09), which under a naive rate formula (BLOCKER-A10) costs attendance credit and potentially a certificate. **This is a one-minute documentation error that can cost a real person a qualification.**

**Root cause (assessment).** §9.9.3 is written entirely in whole minutes, and its author appears to have used "one minute before / one minute after" prose (`9:29` / `9:31`) as informal shorthand for "inside / outside" — the same pattern as rows `6:30` / `6:31`. It reads as a documentation artefact, not a deliberate rule.

**Resolution — normative.** **BR-04 and §14.1 win. The check-out window is `[E−30m, E+30m]`, both bounds inclusive.** §9.9.3 must be corrected to:

| الوقت | حالة زر الحضور | حالة زر الانصراف |
|---|---|---|
| `9:29:59 م` | معطّل | مفعّل |
| **`9:30:00 م`** | **معطّل** | **مفعّل — آخر لحظة مقبولة** |
| **`9:30:01 م`** | **معطّل** | **معطّل — انتهت النافذة** |

**Rationale for choosing the wider window:** where a specification is ambiguous and the ambiguity decides a person's right, the reading that preserves the right must be chosen unless there is a countervailing interest. There is none here — one extra minute of check-out grace costs the centre nothing.

---

### 🔴 DISC-2 — §9.9.3 and §14.1 leave 59.999 seconds of the `present`/`late` boundary undefined

Covered in full at **BLOCKER-A2** (§1.3). Summary: the PRD specifies `6:30 → present` and `6:31 → late` but never states whether `6:30:30` is present or late. Recommendation: **R1, instant comparison** (`checkInAt ≤ S + 1_800_000 ms`), with a mandated PRD amendment adding a millisecond-precision statement and a `6:30:01 م → متأخر` row.

---

### 🟠 DISC-3 — §9.9.5's `absent` definition, checked and confirmed consistent

§9.9.5 defines `absent` as «لم يسجّل حضورًا حتى **انتهاء الجلسة**», and BR-08 says the same. Both are consistent with `canCheckIn`'s upper bound of `E`. ✅ **No contradiction** — recorded here only to document that it was checked, because a sweep that mistakenly used `E+30m` (by reusing the check-out lag) would leave a 30-minute window in which a participant is neither `absent` nor checked in, and the dashboard would render a blank cell for half an hour. **T1 must use `now > E`, never `now > E+30m`.**

---

### 🟠 DISC-4 — the check-in window and the "join the lecture" window are 15 minutes apart, and the PRD never reconciles them

- §9.9.2 / BR-01: **check-in** opens at `S − 30m`.
- §9.10 / BR-24: the **Zoom join button** opens at `S − 15m`.

Between `S−30m` and `S−15m` a trainee can register attendance for a lecture they cannot yet join. This is arguably deliberate (register early, join later), but it is a fifteen-minute window in which the platform records attendance for a session the person has no way to be present at, and neither §9.9 nor §9.10 acknowledges it.

**Recommendation:** keep both windows (they serve different purposes) but make the UI honest. Between `S−30m` and `S−15m` the attendance card must show «تم تسجيل حضورك — يُفتح رابط المحاضرة بعد 00:14:32». No rule change; a copy requirement added to §9.9.8's message table.

---

## 1.11 Cancelled sessions with existing check-ins

> **PRD sources:** §7.8 bullet 2, §9.9.4 bullet 6, §9.8.2, §9.16.

The PRD says a session with attendance records must be cancelled rather than deleted, and that check-in is forbidden for a cancelled session. It says nothing about records that already exist, nor about their effect on the attendance rate. **BLOCKER-A6** gives the rate rule. The full transition:

```ts
/** Cancelling a session. One transaction; every step is required. */
export async function cancelSession(db: ReadWriteDb, input: {
  sessionId: string; actorId: string; reason: string;   // §7.3: cancellation_reason mandatory
}): Promise<void> {
  if (input.reason.trim().length < 10) throw new ValidationError('CANCELLATION_REASON_TOO_SHORT');

  await db.transaction(async (tx) => {
    // 1. Flip the session. Attendance rows are NOT touched (§7.8) — destroying them would
    //    erase evidence a trainee may need to contest a certificate decision.
    const s = await tx.sessions.updateOne({
      where: { id: input.sessionId, status: { in: ['scheduled','live'] }, deletedAt: null },
      data:  { status: 'cancelled', cancellationReason: input.reason, cancelledBy: input.actorId },
    });
    if (!s) throw new ConflictError('SESSION_NOT_CANCELLABLE');   // already completed/cancelled

    // 2. Audit — BR-27, §12.2. before/after captured.
    await tx.auditLog.insert({
      actorId: input.actorId, action: 'session.cancel',
      entityType: 'session', entityId: s.id,
      before: { status: s.previousStatus }, after: { status: 'cancelled', reason: input.reason },
    });

    // 3. Recompute EVERY affected participant's rate (BLOCKER-A6). Without this the
    //    cancellation leaves stale rates that still count the session.
    await tx.enqueueRateRecompute({ cohortId: s.cohortId });

    // 4. Journey steps that depended on this session are re-evaluated (§9.7.1 steps 2 and 9).
    await tx.enqueueJourneyRecompute({ cohortId: s.cohortId });
  });

  // 5. §9.16 «إلغاء أو تأجيل جلسة | كل الدفعة | فور التغيير | منصة + بريد»
  await notifyCohort(input.sessionId, 'session_cancelled');
}
```

**Invariants established:**
- Existing attendance rows survive cancellation untouched — auditable and contestable.
- The sweep skips cancelled sessions entirely (T1 and T2 both filter `status <> 'cancelled'`).
- The session leaves both the numerator and the denominator of the rate.
- **No trainee's rate can fall merely because a session was cancelled** in the case that matters (a participant who was absent from it): removing an unattended session from the denominator can only raise the ratio.

> ⚠️ **Second-order effect — disclose it to the centre.** For a participant who *had* attended the cancelled session, the rate can still decrease: removing a `1/1` from `9/10` gives `8/9 = 88.9%` versus `90%`. This is arithmetically unavoidable under any exclusion rule and is the correct behaviour, but the support team must be able to explain it when a trainee asks. The alternative — counting a cancelled session as attended for everyone — rewards absence and is rejected.

---
---

# 2. Authorization Model

> ### 📌 ملخص القسم بالعربية
> يحوّل هذا القسم مصفوفة §4.2 إلى نموذج صلاحيات رسمي: **قائمة صلاحيات (enum)**، و**خريطة دور→صلاحيات**، و**محلّل نطاق (scope resolver)**.
>
> **القاعدة الحاكمة (§4.3):** «كل استعلام لقاعدة البيانات يُقيَّد بنطاق المستخدم». هذا يعني أن الصلاحية **جزء من شرط `WHERE` في الاستعلام نفسه**، لا فحصًا منفصلًا قبله. الفحص المنفصل يترك ثغرة IDOR في كل نقطة نهاية يُنسى فيها.
>
> **أهم اكتشاف:** §4.4 تنص على أن شخصًا واحدًا قد يكون **مدربًا في دفعة ومشاركًا في أخرى**. هذا يجعل حقل `User.role` **غير كافٍ لاتخاذ أي قرار صلاحية على مستوى الدفعة**. الدور الفعلي يُشتق من `Enrollment.role_in_cohort` **الخاص بدفعة المورد المطلوب**، لا من مبدّل السياق في الواجهة. أي اعتماد على مبدّل السياق كمصدر صلاحية هو ثغرة تصعيد أفقي كاملة.
>
> **تعارضات محسومة في §2.7:** مصفوفة §4.2 تمنع المدير من رصد الدرجات («لا») بينما §4.1 تمنحه «صلاحية كاملة»، وتضع «—» للمدرب في المحادثة الثنائية بينما §9.13.1 تقول إن الطرفين يكتبان.

---

## 2.1 The permission enum

Permissions are **verbs on resource types**, never role names. A role is a bundle of permissions; code never asks "is this user an admin?", it asks "does this actor hold `evaluation:create` in this scope?". This is what makes §4.3's "allow-lists not deny-lists" (§12.2) mechanically true rather than aspirational.

```ts
// /lib/permissions/catalogue.ts
export const PERMISSIONS = [
  // ── Programs & cohorts (§4.2 rows 1–3) ─────────────────────────────────────
  'program:read', 'program:create', 'program:update', 'program:archive',
  'cohort:read', 'cohort:create', 'cohort:update', 'cohort:assign_trainer',
  'landing:configure',                                    // §4.2 «ضبط إعدادات صفحة الهبوط»

  // ── Users & accounts (§4.2 rows 4–7) ───────────────────────────────────────
  'user:read_profile_full',                               // §4.2 «عرض الملف الكامل لأي مستخدم»
  'user:create', 'user:update', 'user:change_role',
  'user:suspend', 'user:soft_delete', 'user:reset_password', 'user:revoke_sessions',
  'registration:review',                                  // §4.2 «قبول أو رفض طلبات التسجيل»

  // ── Schedule (§4.2 rows 8–10) ──────────────────────────────────────────────
  'week:manage', 'session:manage', 'session:cancel',
  'session:zoom_manage', 'session:recording_upload',

  // ── Assignments & submissions (§4.2 rows 11–13) ────────────────────────────
  'assignment:create', 'assignment:update', 'assignment:publish',
  'submission:read', 'submission:download', 'submission:create',
  'final_project:unlock',                                 // BR-15

  // ── Grading (§4.2 rows 14–15) — see §2.7 CONFLICT-P1 ───────────────────────
  'evaluation:create', 'evaluation:revise', 'evaluation:read', 'evaluation:override',

  // ── Attendance (§4.2 rows 16–17) ───────────────────────────────────────────
  'attendance:check_in', 'attendance:check_out',
  'attendance:manual_edit', 'attendance:bulk_mark', 'attendance:excuse',
  'attendance:read',

  // ── Resources (§4.2 row 18) ────────────────────────────────────────────────
  'resource:read', 'resource:download', 'resource:create', 'resource:update', 'resource:archive',

  // ── Communication (§4.2 rows 20–22) ────────────────────────────────────────
  'thread:read', 'thread:post', 'announcement:publish',
  'thread:lock', 'thread:moderate', 'message:report',

  // ── Certificates & cards (§4.2 rows 23–24) ─────────────────────────────────
  'certificate:issue', 'certificate:revoke', 'certificate:read', 'certificate:download_own',
  'card:read_own', 'card:revoke',

  // ── Reports & audit (§4.2 rows 19, 27, 28) ─────────────────────────────────
  'report:read', 'report:export',
  'audit:read',

  // ── The highest-privilege capability in the platform (§4.5, BR-33) ─────────
  'impersonation:start',

  'settings:manage',
] as const;

export type Permission = typeof PERMISSIONS[number];
```

---

## 2.2 🔴 CONFLICT-P0 — `User.role` cannot be the authorization input

§7.1 gives `User.role: admin | trainer | participant`. §7.2 gives `Enrollment.role_in_cohort: participant | trainer`. §4.4 then says:

> «قد يكون شخص واحد **مدربًا في دفعة ومشاركًا في دفعة أخرى**. يُحل ذلك عبر جدول الالتحاق الذي يحمل الدور داخل كل دفعة، مع **مبدّل سياق** في الهيدر.»

**Two consequences the PRD does not draw:**

**(1) `User.role` is not a fact about what a person may do to a cohort resource.** A person with `User.role = 'trainer'` who is enrolled as a `participant` in cohort B must be able to check in to B's sessions — but §4.2 row 16 says trainers may not check in («تسجيل الحضور والانصراف | لا | لا | نعم»). Read at the platform level, the matrix locks that person out of their own trainee account. Read at the cohort level it is correct. **The matrix is a per-cohort matrix and must be labelled as such.**

**(2) The context switcher must never be an authorization input.** If the API derives the acting role from a `X-Active-Cohort` header, a session field, or any client-supplied "current context", then a trainer-in-A / participant-in-B can set the context to A and read B's data — or worse, act on B with trainer powers. This is a complete horizontal *and* vertical escalation in one move, and it is the most likely way this application gets broken.

### The rule

> **The acting role is derived from the cohort that owns the *target resource*, resolved server-side, on every request. The UI's context switcher changes only what is displayed, never what is permitted.**

```ts
// /lib/permissions/actor.ts
export type PlatformRole = 'admin' | 'member';
export type CohortRole   = 'trainer' | 'participant';

export interface Actor {
  readonly userId: string;
  /** Platform-wide. ONLY 'admin' grants anything platform-wide. */
  readonly platformRole: PlatformRole;
  /** Every ACTIVE enrolment. The single source of cohort-scoped authority. */
  readonly cohortRoles: ReadonlyMap<string /* cohortId */, CohortRole>;
  readonly status: 'active' | 'suspended' | 'pending' | 'deleted';
  /** Non-null ⇒ this request is an impersonation. See §3. */
  readonly impersonation: ImpersonationContext | null;
}

/**
 * The ONLY function permitted to answer "what may this actor do here".
 * `cohortId` is derived from the TARGET RESOURCE, never from a header or the session.
 */
export function effectiveRole(actor: Actor, cohortId: string | null): EffectiveRole {
  if (actor.status !== 'active') return 'none';
  if (actor.platformRole === 'admin') return 'admin';
  if (cohortId === null) return 'none';               // a non-admin has no platform-wide role
  return actor.cohortRoles.get(cohortId) ?? 'none';
}

export type EffectiveRole = 'admin' | 'trainer' | 'participant' | 'none';
```

**Schema change required.** Migrate `User.role` from `admin | trainer | participant` to `admin | member`, or — to avoid a breaking rename mid-project — keep the column and add an explicit doc comment plus a lint rule:

```ts
// ❌ banned by ESLint no-restricted-syntax
if (user.role === 'trainer') { /* ... */ }
// ✅ the only permitted shape
if (effectiveRole(actor, resource.cohortId) === 'trainer') { /* ... */ }
```

```jsonc
"no-restricted-syntax": ["error", {
  "selector": "MemberExpression[property.name='role'][object.name=/^(user|session)$/]",
  "message": "CONFLICT-P0: User.role is not an authorization input. Use effectiveRole(actor, resource.cohortId)."
}]
```

---

## 2.3 The role → permission map

Derived row-by-row from §4.2. **Read every cell as scoped to the cohort that owns the target resource.**

```ts
// /lib/permissions/matrix.ts
const ADMIN: readonly Permission[] = [
  'program:read','program:create','program:update','program:archive',
  'cohort:read','cohort:create','cohort:update','cohort:assign_trainer','landing:configure',
  'user:read_profile_full','user:create','user:update','user:change_role',
  'user:suspend','user:soft_delete','user:reset_password','user:revoke_sessions',
  'registration:review',
  'week:manage','session:manage','session:cancel','session:zoom_manage','session:recording_upload',
  'assignment:create','assignment:update','assignment:publish',
  'submission:read','submission:download',
  'final_project:unlock',
  // ⚠️ 'evaluation:create' and 'evaluation:revise' are DELIBERATELY ABSENT — §4.2 says «لا».
  //    See CONFLICT-P1 for the break-glass alternative.
  'evaluation:read','evaluation:override',
  'attendance:manual_edit','attendance:bulk_mark','attendance:excuse','attendance:read',
  'resource:read','resource:download','resource:create','resource:update','resource:archive',
  'thread:read','thread:post','announcement:publish','thread:lock','thread:moderate',
  'certificate:issue','certificate:revoke','certificate:read','card:revoke',
  'report:read','report:export','audit:read',
  'impersonation:start','settings:manage',
] as const;

const TRAINER: readonly Permission[] = [
  'cohort:read',
  'user:read_profile_full',                     // §4.2 «عرض الملف الكامل لأي مستخدم | دفعته فقط»
  'week:manage','session:manage','session:cancel','session:zoom_manage','session:recording_upload',
  'assignment:create','assignment:update','assignment:publish',
  'submission:read','submission:download',
  'final_project:unlock',
  'evaluation:create','evaluation:revise','evaluation:read',
  'attendance:manual_edit','attendance:bulk_mark','attendance:excuse','attendance:read',
  'resource:read','resource:download','resource:create','resource:update','resource:archive',
  'thread:read','thread:post','announcement:publish','thread:lock',
  'report:read','report:export',
  // ⚠️ NOT present: attendance:check_in/check_out — a trainer does not check in to the cohort
  //    they teach. If the same PERSON is a participant in ANOTHER cohort, effectiveRole() for
  //    that cohort returns 'participant' and they get the permission there. (§2.2)
] as const;

const PARTICIPANT: readonly Permission[] = [
  'cohort:read',
  'attendance:check_in','attendance:check_out','attendance:read',
  'submission:create','submission:read',
  'evaluation:read',
  'resource:read','resource:download',
  'thread:read','thread:post','message:report',
  'certificate:download_own','certificate:read','card:read_own',
  'report:read',                                // §4.2 «عرض تقارير … | بياناته فقط»
] as const;

export const ROLE_PERMISSIONS: Readonly<Record<Exclude<EffectiveRole,'none'>, ReadonlySet<Permission>>> = {
  admin:       new Set(ADMIN),
  trainer:     new Set(TRAINER),
  participant: new Set(PARTICIPANT),
};

export function hasPermission(actor: Actor, p: Permission, cohortId: string | null): boolean {
  const role = effectiveRole(actor, cohortId);
  if (role === 'none') return false;
  if (actor.impersonation !== null && !READ_ONLY_PERMISSIONS.has(p)) return false;  // §3
  return ROLE_PERMISSIONS[role].has(p);
}
```

---

## 2.4 The scope resolver

A permission answers *what verb*. A scope answers *over which rows*. **Both are required; neither substitutes for the other.**

```ts
// /lib/permissions/scope.ts
export type Scope =
  | { readonly kind: 'global' }                                        // admin
  | { readonly kind: 'cohorts'; readonly cohortIds: readonly string[] } // trainer
  | { readonly kind: 'self';    readonly userId: string }              // participant
  | { readonly kind: 'none' };                                         // denied

/** §4.1 + §4.3: admin = all, trainer = assigned cohorts only, participant = self only. */
export function resolveScope(actor: Actor): Scope {
  if (actor.status !== 'active') return { kind: 'none' };
  if (actor.platformRole === 'admin') return { kind: 'global' };

  const trainerCohorts = [...actor.cohortRoles]
    .filter(([, r]) => r === 'trainer')
    .map(([cohortId]) => cohortId);

  // NOTE: a person who is BOTH a trainer (in A) and a participant (in B) resolves to
  // `cohorts` for trainer-scoped reads and `self` for participant-scoped reads. The two
  // scopes are applied by DIFFERENT query builders (§2.5); they are never merged into one
  // permissive scope. Merging them is exactly how the two-cohort escalation happens.
  return trainerCohorts.length > 0
    ? { kind: 'cohorts', cohortIds: trainerCohorts }
    : { kind: 'self', userId: actor.userId };
}
```

---

## 2.5 The mandatory server-side enforcement pattern — scope IS the predicate

> **§4.3:** «كل استعلام لقاعدة البيانات يُقيَّد بنطاق المستخدم: المشارك يقرأ سجلاته فقط، والمدرب يقرأ دفعاته فقط.»
> **§4.3:** «المشارك لا يستطيع بأي طريقة الوصول إلى ملف أو درجة أو رسالة خاصة بمشارك آخر، **حتى بتغيير المعرّف في الرابط**.»

### The anti-IDOR pattern

```ts
// ❌ THE VULNERABILITY. Fetch-then-check. Two statements, two chances to forget the second.
export async function getSubmission(id: string, actor: Actor) {
  const sub = await db.submission.findUnique({ where: { id } });
  if (!sub) throw new NotFoundError();
  if (sub.userId !== actor.userId) throw new ForbiddenError();   // ← forget this line once → IDOR
  return sub;
}
```

Every one of these is a defect in the pattern above, independent of whether the check is present:
- The check can be forgotten, and nothing fails — the endpoint works, it just works for everyone.
- The row is loaded into memory before authorization, so a logging middleware, an error serialiser, or a `console.log` in development can leak it.
- Returning `403` when the id exists and `404` when it does not is an **existence oracle**: an attacker enumerating UUIDs learns which submissions exist.
- It cannot be enforced by a linter or a type.

```ts
// ✅ THE PATTERN. Scope is composed into the WHERE clause. A miss is indistinguishable
//    from a non-existent row, and there is no second statement to forget.
export async function getSubmission(id: string, actor: Actor): Promise<SubmissionDTO> {
  const sub = await db.submission.findFirst({
    where: { id, ...submissionScopeFilter(actor) },   // ← scope INSIDE the predicate
    select: SUBMISSION_DTO_SELECT,                    // ← explicit field allow-list (§4.8)
  });
  if (!sub) throw new NotFoundError();                // 404 for object-level misses. Always.
  return toSubmissionDTO(sub);
}

/** One scope filter per resource type. These are the ONLY place scope logic lives. */
export function submissionScopeFilter(actor: Actor): Prisma.SubmissionWhereInput {
  const scope = resolveScope(actor);
  switch (scope.kind) {
    case 'global':  return {};                                        // admin
    case 'cohorts': return { assignment: { cohortId: { in: scope.cohortIds } } };  // BR-23
    case 'self':    return { userId: scope.userId };                  // BR-22
    case 'none':    return { id: '00000000-0000-0000-0000-000000000000' };  // matches nothing
  }
}
```

**`case 'none'` returns an impossible predicate rather than `{}`.** An accidental fall-through must fail closed. Returning `{}` for an unknown scope would silently grant global access — the single worst default in the file, so it is written to be impossible.

### 🟠 GAP-P2 — resolving the 403-vs-404 tension in §4.3

§4.3 mandates «أي محاولة وصول غير مصرح بها تُرجع خطأ 403 وتُسجَّل في سجل التدقيق مع عنوان IP», and §11.1 provides a 403 page. But answering `403` for "this submission exists but is not yours" confirms the resource exists, which contradicts §4.3's own «حتى بتغيير المعرّف في الرابط» intent and BR-22.

**Resolution — two different failures, two different codes, both audited:**

| Failure | Status | Body | Audited? |
|---|---|---|---|
| **Route/role violation** — a participant requests `/trainer/submissions`, a trainer requests `/admin/audit` | `403` + the §11.1 Arabic 403 page | «لا تملك صلاحية الوصول إلى هذه الصفحة» | ✅ `access_denied` with IP |
| **Object-level miss** — the actor may use this endpoint, but the specific row is outside their scope | `404` | «الصفحة التي تبحث عنها غير موجودة» | ✅ `object_not_in_scope` with IP + the requested id |
| **Unauthenticated** | `302 → /login?next=…` (§8 nav rules) | — | only on repeated attempts |

This satisfies §4.3's audit requirement in full (both are logged with IP) while removing the existence oracle. The distinction is: **403 tells you about your role, which you already know. 404 tells you nothing about other people's data.**

### Defence in depth — the three layers, and what each actually catches

| Layer | Mechanism | Catches | Does not catch |
|---|---|---|---|
| **1. Route guard** | Middleware maps route → required permission | A participant hitting `/admin/*` | Anything about *which rows* |
| **2. Query scope** ← **the real control** | `...scopeFilter(actor)` in every `where` | All IDOR / horizontal escalation | A hand-written raw query that omits it |
| **3. Row-level security** (Postgres only) | `CREATE POLICY` + `SET LOCAL app.user_id` | Even a raw query that forgets the filter | Nothing at this layer — it is the backstop |

Layer 3 is available on Postgres (via Hyperdrive) and **not on D1** — SQLite has no RLS. This is one of two reasons this document recommends Postgres over D1 for this application (the other is §3.5). If D1 is chosen, layers 1–2 must be enforced by a **repository pattern with no escape hatch**: the raw client is not exported, and every table is reachable only through a function that takes an `Actor`.

```ts
// /lib/db/index.ts — the raw client is module-private. There is no way to get an unscoped handle.
import { drizzle } from 'drizzle-orm/d1';
const raw = drizzle(env.DB);          // NOT exported

export function repositories(actor: Actor) {
  return {
    submissions: makeSubmissionRepo(raw, actor),
    attendance:  makeAttendanceRepo(raw, actor),
    // ...
  };
}
```

```jsonc
// Enforced:
"no-restricted-imports": ["error", { "patterns": [{
  "group": ["**/lib/db/raw", "**/lib/db/client"],
  "message": "Use repositories(actor). An unscoped DB handle is a BR-22/BR-23 violation."
}]}]
```

---

## 2.6 Horizontal-privilege-escalation surfaces — complete enumeration

> **§18 rates this «مرتفع جدًا — خرق خصوصية».** Each row is an attack an authenticated trainee can attempt today by changing an identifier. Each has a named defence and a named test in §7.

| # | Surface | The attack | Defence | Test |
|---|---|---|---|---|
| **1** | **Submission records** `GET /api/submissions/:id` | Change the UUID to another trainee's submission | `submissionScopeFilter` in the `where`; 404 on miss | `BR-22.submission.idor` |
| **2** | **Submission files** `GET /api/files/:fileId` | Guess/enumerate a storage object id; replay a signed URL shared in the group chat | Worker-mediated download that re-authorizes **on every request** (§5.7); random 128-bit object keys; 15-min TTL; URL bound to the requesting session | `BR-22.file.idor`, `BR-22.file.replay` |
| **3** | **Grades** `GET /api/evaluations?userId=…` | Supply another `userId` in a query param | `userId` is **never** read from the request for participant scope — it is taken from the session. Zod schema has no `userId` field at all. | `BR-22.grades.param_injection` |
| **4** | **Grade aggregate leakage** | Request the cohort average to infer a small cohort's individual scores | §9.15.4 makes the average opt-in; additionally suppress the average when `n < 5` (k-anonymity) | `BR-22.grades.small_n` |
| **5** | **Private messages** `GET /api/threads/:id/messages` | Open a `trainer_dm` thread belonging to another trainee | Scope filter joins `thread_participants` on the actor's id. Membership is the predicate, not a post-check. | `BR-22.messages.idor` |
| **6** | **Message send** `POST /api/threads/:id/messages` | Post into a thread you are not in, or into an `announcement` channel as a participant | Same membership predicate + `thread:post` permission + a thread-type rule (announcements require `announcement:publish`) | `FR-9.13.announcement.participant_denied` |
| **7** | **Attendance records** `GET /api/attendance?userId=…` / `PATCH /api/attendance/:id` | Read or edit someone else's attendance | Read: self-scope. Write: `attendance:manual_edit` + cohort scope + mandatory ≥10-char reason + audit (BR-10) | `BR-22.attendance.idor`, `BR-10.reason_required` |
| **8** | **Attendance for another user's session** | `POST /api/sessions/:id/check-in` for a session in a cohort you are not in | The `JOIN enrollments` inside the upsert (§1.8) — zero rows, no write, no error path to abuse | `BR-01.checkin.not_enrolled` |
| **9** | **Certificates** `GET /api/certificates/:id` / `/certificate/verify/:code` | Download another trainee's certificate PDF; enumerate verify codes | Self-scope on the authenticated route. Public verify route exposes **only** name + programme + date (§9.17, BR-25) and uses an 80-bit random code (§4.6) | `BR-25.verify.minimal_fields`, `BR-25.verify.enumeration` |
| **10** | **Digital cards** `/verify/:token` | Enumerate `qr_token`; derive it from a user id | 256-bit random token, **not** the user id (§9.6.2); stored as SHA-256 so a DB read leak yields no working QR; public page shows first + last name only | `BR-25.card.token_entropy` |
| **11** | **Resources** `GET /api/resources/:id/download` | Download a cohort's materials while enrolled in a different cohort | Cohort scope on `resource.cohort_id`; `download_count` incremented only for authorized, non-impersonated reads | `BR-23.resource.cross_cohort` |
| **12** | **Notifications** `PATCH /api/notifications/:id/read` | Mark another user's notification read; read its body (which may quote a grade) | Self-scope, always. `user_id` from session only. | `BR-22.notification.idor` |
| **13** | **Profile / PII** `GET /api/users/:id` | Read another trainee's phone, email, birth date | Non-admin, non-trainer requests resolve to `self`. The **group chat** exposes only `{displayName, avatarUrl}` — never email or phone (§12.6 minimisation) | `BR-22.profile.pii_scope` |
| **14** | **Zoom URL** | Read `zoom_url` from the RSC/SSR payload before `S−15m` | Field omitted from the `select` entirely until the window opens; separate `POST /join` endpoint re-checks server-side (BR-24, §9.10) | `BR-24.zoom.not_in_payload` |
| **15** | **Final project content** | Fetch the brief before `is_unlocked` | Server-side gate; the field is not selected, not just not rendered (BR-15, BR-16) | `BR-16.project.not_in_payload` |
| **16** | **Audit log** | A trainer reads `/admin/audit` to see other admins' actions | `audit:read` is admin-only (§4.2); route guard + query scope | `BR-27.audit.trainer_denied` |
| **17** | **Exports** `GET /api/reports/export` | A trainer exports another cohort's data by changing `cohortId` | Cohort scope on the export query; the requested cohort id is **intersected** with the actor's scope, never trusted | `BR-23.export.cross_cohort` |
| **18** | **Journey state** | Read another trainee's progress | Self-scope | `BR-21.journey.idor` |
| **19** | **Avatar URLs** | Enumerate `/avatars/{userId}.jpg` to confirm membership | Random object keys, not user ids; served from the separate file origin | `BR-22.avatar.enumeration` |
| **20** | **Vertical: role change** `PATCH /api/users/:id` | A trainer sends `{role:'admin'}` for themselves | §4.3: «المدرب لا يستطيع تغيير دوره أو دور غيره». `user:change_role` is admin-only, is a **separate endpoint**, and the profile schema is `.strict()` with no `role` key (§6.5) | `BR-28.mass_assignment.role` |
| **21** | **Vertical: self-deletion of the last admin** | An admin deletes their own account | §4.3 + BR-32: an admin may not delete their own account, and a DB-level guard keeps ≥1 active admin (§4.9) | `BR-32.last_admin` |
| **22** | **Impersonation** | Any of the above, performed *as* a subject | The whole of §3 | §3.9 test list |

---

## 2.7 Resolving the contradictions in §4.2

### 🔴 CONFLICT-P1 — admin is marked «لا» for grading, but §4.1 grants «صلاحية كاملة»

| §4.2 row | admin | trainer | participant |
|---|---|---|---|
| «رصد الدرجات وكتابة الملاحظات» | **لا** | نعم | لا |
| «تعديل درجة بعد رصدها» | **لا** | نعم مع تسجيل السبب | لا |
| «تحديد درجة كل مهمة وموعدها» | **نعم** | نعم | لا |

So an admin may define the maximum score and deadline of an assignment but may not award a mark against it. Meanwhile §4.1 describes admin as «صلاحية كاملة على المنصة», and §9.15.2 describes grading as a trainer activity throughout.

**Assessment.** The «لا» is not a mistake — it is **segregation of duties**, and it is good design. The person who can change roles, issue certificates, and impersonate accounts should not also be able to author the grades those certificates attest to. If admin could grade, a single compromised admin account could fabricate a complete, internally consistent, certified qualification with no second party involved.

**Recommendation — keep the restriction, add an audited break-glass:**

```ts
// 'evaluation:create' and 'evaluation:revise' are NOT in the ADMIN set.
// 'evaluation:override' IS, and it is a different operation with different requirements.
export interface EvaluationOverride {
  readonly evaluationId: string | null;   // null ⇒ creating where no trainer evaluation exists
  readonly score: number;
  readonly feedback: string;              // ≥ 10 chars (BR-13)
  readonly overrideReason: string;        // ≥ 20 chars — stricter than a trainer's revision
  readonly acknowledgedSegregation: true; // explicit confirmation in the UI, stored
}
```

Rules attached to `evaluation:override`:
1. Requires re-authentication (password re-entry) in the same request — it is a break-glass, not a routine action.
2. Writes `evaluations.overridden_by_admin = true` and a mandatory `override_reason` (≥20 chars).
3. Notifies the participant **and** the assigned trainer (§9.16 already requires a "grade revised" notification to the participant; the trainer notification is added here).
4. Surfaces on the participant's grade page as «رُصدت من إدارة المركز» — the trainee is entitled to know that a mark on their record did not come from their trainer.
5. Appears in a dedicated audit report the centre can review.
6. **Never available during impersonation** (§3).

This preserves §4.2's «لا» as the default, keeps the platform operable when a trainer leaves mid-programme (a real operational need the PRD does not address), and makes every exercise of the exception visible to the person it affects.

---

### 🟠 CONFLICT-P2 — the trainer's cell for «المحادثة مع المدرب» is «—», contradicting §9.13.1

| §4.2 row | admin | trainer | participant |
|---|---|---|---|
| «المحادثة مع المدرب» | — | — | نعم |

But §9.13.1 says: «محادثة مع المدرب | المتدرب ومدرب دفعته | **الطرفان يكتبان**». A dash in the trainer column would mean the trainer cannot reply to their own trainees.

**Resolution:** §9.13.1 is the functional specification and wins. The matrix row means "who *initiates/owns* this thread type", not "who may write in it". Corrected row:

| الصلاحية | مدير | مدرب | مشارك |
|---|---|---|---|
| المحادثة مع المدرب (قراءة وكتابة) | عبر المعاينة فقط (قراءة) | نعم — لمتدربي دفعته | نعم — محادثته فقط |

Note the admin cell: an admin has **no direct write access** to a trainer↔trainee DM. They can read it only through impersonation (§4.5.2 explicitly states private conversations are visible in preview mode), which is read-only and audited. This is the correct privacy posture and must be stated in the privacy policy — §4.5.2 already requires that disclosure, and PDPL transparency makes it mandatory rather than advisory (§5.9).

---

### 🟠 CONFLICT-P3 — «تسجيل الحضور والانصراف | لا | لا | نعم» is a per-cohort rule stated as a platform rule

Covered by **CONFLICT-P0** (§2.2). The row is correct *within a cohort*: the trainer of cohort A does not check in to cohort A. It is wrong platform-wide: the same person, enrolled as a participant in cohort B, must be able to check in to B. Relabel the matrix header as «(داخل الدفعة المعنية)».

---

### 🟠 CONFLICT-P4 — «إنشاء المهام … | نعم | نعم» versus BR-17

§4.2 gives both admin and trainer «إنشاء المهام وتحديد الإجباري والاختياري | نعم | نعم», but BR-17 says «**المدرب هو من يحدد** المهام وأيها إجباري وأيها اختياري ودرجتها وموعدها».

**Resolution:** both may create; BR-17 describes the *normal operating model*, not an exclusive grant. Admin retains `assignment:create` (needed to seed a cohort before a trainer is assigned — §16 phase 5 requires this), and every admin-created assignment records `created_by` (already in §7.5) so the distinction is visible. No conflict in practice; documented so it is not "resolved" later by removing the admin's access and breaking cohort setup.

---

### ✅ CONFIRMED — §4.3's structural rules, restated as enforceable constraints

| §4.3 rule | Enforcement |
|---|---|
| «كل صلاحية تُفحص على الخادم في كل طلب» | `hasPermission()` in the route guard **and** `scopeFilter()` in every query. UI state is never consulted. |
| «كل استعلام … يُقيَّد بنطاق المستخدم» | §2.5 repository pattern; raw client not exported; ESLint import ban |
| «أي محاولة وصول غير مصرح بها تُرجع 403 وتُسجَّل … مع IP» | §2.5 GAP-P2 table; both 403 and 404 paths audited |
| «الروابط المباشرة للملفات لا تُكشف … رابط موقّع مؤقت 15 دقيقة» | §5.7 |
| «المشارك لا يستطيع بأي طريقة الوصول إلى ملف أو درجة أو رسالة خاصة بمشارك آخر» | §2.6 rows 1–13, each with a named test |
| «المدرب لا يستطيع تغيير دوره أو دور غيره» | `user:change_role` absent from `TRAINER`; §2.6 row 20 |
| «مدير النظام لا يستطيع حذف حسابه الخاص، ويجب بقاء مدير واحد على الأقل» | §4.9 — enforced in the database, not only in code (BR-32) |

---
---

# 3. Impersonation («معاينة الحسابات») — Threat Model & Enforcement

> ### 📌 ملخص القسم بالعربية
> §4.5 نفسها تصف هذه الميزة بأنها «**أقوى صلاحية في المنصة**»، وتشترط أن يُفرض وضع القراءة فقط «**على مستوى طبقة الوصول للبيانات نفسها لا على مستوى الواجهة**».
>
> **الآلية الموصى بها — أربع طبقات مستقلة، لا طبقة واحدة:**
> 1. **طبقة الأنواع (وقت الترجمة):** أي دالة تكتب في قاعدة البيانات تتطلب النوع `ReadWriteDb`. وسيط المعاينة **لا يستطيع إنتاج هذا النوع إطلاقًا** — ينتج `ReadOnlyDb` فقط. المخالفة تفشل عند `tsc`، لا في الإنتاج.
> 2. **طبقة التنفيذ (وقت التشغيل):** المقبض `ReadOnlyDb` هو `Proxy` يرمي استثناءً عند أي عبارة ليست `SELECT`.
> 3. **طبقة قاعدة البيانات (الضمان الحقيقي):** كل طلب معاينة يُنفَّذ داخل معاملة `SET LOCAL default_transaction_read_only = on` بدور قاعدة بيانات لا يملك صلاحية الكتابة أصلًا. **حتى لو أخطأ التطبيق بالكامل، قاعدة البيانات ترفض.** هذه الطبقة **غير متاحة على D1** — وهذا سبب رئيسي لتوصية PostgreSQL.
> 4. **مصيدة الخروج:** في نهاية كل طلب معاينة يُتحقق أن عدّاد الكتابات = صفر، وإلا إنهاء فوري للجلسة وتنبيه.
>
> **الأثر الصفري (BR-34):** الـ PRD يذكر أربعة آثار يجب منعها. هذا القسم يحصي **سبعة عشر** أثرًا خفيًا، أخطرها **الإنشاء الكسول عند القراءة** (البطاقة الرقمية، خطوات الرحلة، محادثة المدرب) — قراءة صفحة عادية تُنشئ صفوفًا في قاعدة البيانات.

---

## 3.1 What the PRD requires, restated as testable assertions

| PRD | Assertion |
|---|---|
| §4.5.2 / BR-33 | Strict read-only, **enforced on the server**: no check-in, no check-out, no submission, no message send, no profile edit, no certificate download as the subject |
| §4.5.2 / BR-34 | No trace: `last_login_at` unchanged, notifications not marked read, message read-state unchanged, resource download counters unchanged |
| §4.5.2 | 30-minute hard session limit, then automatic return to the admin's own account |
| §4.5.2 / BR-35 | **No admin may preview another admin** |
| §4.5.2 | Ending preview returns to the original session **without re-authentication** |
| §4.5.2 | A permanent, high-contrast banner naming the subject and offering «إنهاء المعاينة» |
| §4.5.2 | Private conversations **are** visible — and this must be stated in the privacy policy |
| §4.5.3 / BR-35 | Audit: admin identity, subject identity, start time, end time, IP |
| §4.5.3 | Audit **every tab navigation** inside preview mode |
| §4.5.3 | The admin's profile shows their preview count over the last 30 days |
| §14.1 | Every write attempt during preview is rejected; the session expires at 30 min; admin→admin is refused |

---

## 3.2 Token design

### 3.2.1 Decision: opaque server-side grant, not a self-contained JWT

| Option | Instant revocation | Edge cost | Verdict |
|---|---|---|---|
| JWT/JWS, 30-min TTL, stateless | ❌ — a leaked token is valid for its full TTL and cannot be recalled | zero DB reads | **Rejected.** 30 minutes of un-revocable access to an arbitrary account is not an acceptable blast radius for the platform's highest privilege. |
| JWT + KV denylist | ⚠️ KV is eventually consistent; a delete can take up to ~60 s to propagate globally | 1 KV read | **Rejected** for the same reason — "revoked in about a minute" is not revoked. |
| **Opaque random id + DB row** | ✅ genuinely instant — the next request reads the row and sees `ended_at` | 1 DB read, on a path that already reads the DB | **Selected.** |

The extra read costs nothing meaningful: an impersonation request is already loading the subject's dashboard data from the same database, and the volume is a handful of support sessions per week — not a hot path.

### 3.2.2 The grant

```ts
// /features/impersonation/types.ts
export interface ImpersonationGrant {
  /** 32 random bytes, base64url. The value in the cookie. Stored HASHED (§3.2.4). */
  readonly jti: string;

  readonly actorAdminId:  string;   // WHO is previewing
  readonly subjectUserId: string;   // WHOM they are previewing

  /**
   * The admin's own login session. Binding to it means:
   *  - admin logs out                    → every derived preview dies immediately
   *  - admin's password is changed        → same (BR-29 revokes the parent)
   *  - admin's session is revoked by another admin → same
   * Without this binding, a stolen preview cookie outlives the admin's own compromise.
   */
  readonly parentSessionId: string;

  /** A literal type with exactly one inhabitant. There is no writable mode, ever. */
  readonly mode: 'read_only';

  readonly issuedAt:  number;       // epoch ms
  /** issuedAt + 30 min. A HARD cap. Never extended, never slid on activity. */
  readonly expiresAt: number;

  readonly ip:     string;          // CF-Connecting-IP at issue time — pinned (§3.2.3)
  readonly uaHash: string;          // SHA-256 of the User-Agent — pinned

  readonly endedAt:     number | null;
  readonly endedReason: 'manual' | 'expired' | 'parent_revoked'
                      | 'ip_mismatch' | 'subject_became_admin'
                      | 'write_violation' | 'admin_suspended' | null;
}
```

### 3.2.3 Binding decisions and their trade-offs

| Binding | Why | Cost | Decision |
|---|---|---|---|
| `parentSessionId` | A preview must not outlive the session that authorised it | none | **Mandatory** |
| `expiresAt` = hard 30 min | §4.5.2. Not a sliding window — activity must not extend it | Admin must restart a long support session | **Mandatory** |
| IP pin (`CF-Connecting-IP`) | A stolen cookie is useless from another network | Breaks on mobile network handover mid-session | **Mandatory.** The population is a handful of staff on office networks, the session is 30 minutes, and the privilege is total. On mismatch: end the grant, audit `ip_mismatch`, and require a fresh preview — do not silently continue. |
| UA hash pin | Cheap extra binding; catches cookie replay in a different client | Breaks on browser auto-update mid-session (rare in 30 min) | **Mandatory**, with the same end-and-reissue behaviour |
| No nesting | A preview session must never be able to start another preview | none | **Mandatory** — `impersonation:start` is unreachable when `actor.impersonation !== null` (§2.3 `hasPermission`) |

### 3.2.4 Storage and the cookie

```sql
CREATE TABLE impersonation_sessions (
  jti_hash          BYTEA        PRIMARY KEY,     -- SHA-256 of the token. The raw token is
                                                  -- NEVER stored: a DB read leak yields no
                                                  -- usable preview session.
  actor_admin_id    UUID         NOT NULL REFERENCES users(id),
  subject_user_id   UUID         NOT NULL REFERENCES users(id),
  parent_session_id UUID         NOT NULL REFERENCES sessions_auth(id) ON DELETE CASCADE,
  mode              TEXT         NOT NULL DEFAULT 'read_only' CHECK (mode = 'read_only'),
  issued_at         TIMESTAMPTZ  NOT NULL DEFAULT now(),
  expires_at        TIMESTAMPTZ  NOT NULL,
  ip                INET         NOT NULL,
  ua_hash           BYTEA        NOT NULL,
  ended_at          TIMESTAMPTZ,
  ended_reason      TEXT,

  CONSTRAINT imp_ttl_capped     CHECK (expires_at <= issued_at + INTERVAL '30 minutes'),
  CONSTRAINT imp_no_self        CHECK (actor_admin_id <> subject_user_id),
  CONSTRAINT imp_end_consistent CHECK ((ended_at IS NULL) = (ended_reason IS NULL))
);

CREATE INDEX imp_active_idx ON impersonation_sessions (actor_admin_id, issued_at DESC);
-- §4.5.3: «يُعرض في الملف الشخصي للمدير عدد مرات المعاينة … خلال آخر 30 يومًا»
```

`imp_ttl_capped` is a **database-enforced** 30-minute ceiling. Even a bug that computes `expiresAt` wrongly cannot create a 24-hour preview session — the insert fails.

**The cookie:**

```
Set-Cookie: __Host-athar_imp=<32-byte base64url>;
            HttpOnly; Secure; SameSite=Strict; Path=/; Max-Age=1800
```

- `__Host-` prefix: forbids a `Domain` attribute and requires `Secure` + `Path=/`. This matters here specifically: the platform lives on `ai.athar-dev.edu.sa` while the centre's main site is `athar-dev.edu.sa`. Without `__Host-`, a compromise of the *main site* could set a cookie for the parent domain that the platform would read. With it, only this exact host can set this cookie.
- `SameSite=Strict` (stricter than the `Lax` §12.1 specifies for the ordinary session cookie): there is no legitimate cross-site navigation into a preview session.
- **The admin's own `__Host-athar_session` cookie is not touched.** That is precisely what makes §4.5.2's «إنهاء المعاينة يعيد المدير إلى جلسته الأصلية **دون الحاجة لتسجيل دخول جديد**» work: ending a preview is a single `Set-Cookie: __Host-athar_imp=; Max-Age=0`, and the parent session is already there.

### 3.2.5 🔴 Never put the token in a URL

`/admin/users/{id}/preview?token=…` would leak the highest privilege in the platform into: the browser history, the `Referer` header of every outbound link the subject's dashboard contains (including the LinkedIn share button in §9.17 and the GitHub links in §9.11), server access logs, and any screenshot the admin sends to a colleague. The route `/admin/users/[id]/preview` (§8) is correct as specified — **it must carry no token**. The grant lives only in the cookie.

---

## 3.3 Read-only enforcement at the data-access layer

> §4.5.2's «حدّ أمني» box: «**رفض أي عملية كتابة أثناء المعاينة على مستوى طبقة الوصول للبيانات نفسها لا على مستوى الواجهة**».

Four independent layers. Each is listed with what it catches **and what it does not** — a control whose limits are undocumented is a control nobody maintains correctly.

### Layer 1 — types (compile time)

```ts
// /lib/db/handles.ts
declare const RO: unique symbol;
declare const RW: unique symbol;

export interface ReadOnlyDb {
  readonly [RO]: true;
  select<T>(q: Query): Promise<T[]>;
  // There is NO insert/update/delete/execute member. Not private — ABSENT.
}

export interface ReadWriteDb extends Omit<ReadOnlyDb, typeof RO> {
  readonly [RW]: true;
  insert(...): Promise<Result>;
  update(...): Promise<Result>;
  delete(...): Promise<Result>;
  transaction<T>(fn: (tx: ReadWriteDb) => Promise<T>): Promise<T>;
}

/** Every mutating service function takes ReadWriteDb. This is the whole mechanism. */
export async function submitAssignment(db: ReadWriteDb, input: SubmitInput): Promise<void> { … }
export async function checkIn        (db: ReadWriteDb, input: CheckInInput): Promise<void> { … }
export async function markNotificationRead(db: ReadWriteDb, id: string): Promise<void> { … }
```

```ts
// /features/impersonation/middleware.ts
/** The impersonation path CANNOT produce a ReadWriteDb. The return type says so. */
export function dbForRequest(ctx: RequestContext): ReadOnlyDb | ReadWriteDb {
  return ctx.actor.impersonation !== null
    ? readOnlyHandle(ctx)     // ReadOnlyDb
    : readWriteHandle(ctx);   // ReadWriteDb
}
```

Now `submitAssignment(db, …)` where `db: ReadOnlyDb` is a **compile error**. §6.3 already mandates `strict` TypeScript and bans `any`; that ban is what keeps this layer sound.

- ✅ Catches: every ordinary mutation reached through the service layer. In practice, the large majority.
- ❌ Does not catch: raw SQL strings, dynamically dispatched calls, an `as ReadWriteDb` cast, or code that reaches a driver directly.

### Layer 2 — runtime proxy

```ts
// /lib/db/read-only-handle.ts
const MUTATING = /^\s*(?:insert|update|delete|replace|upsert|create|drop|alter|truncate|grant|pragma|attach|vacuum|begin|commit)/i;

export class ImpersonationWriteViolation extends Error {
  constructor(readonly detail: string, readonly grantJti: string) {
    super(`BR-34 violation: write attempted during impersonation — ${detail}`);
  }
}

export function readOnlyHandle(ctx: RequestContext): ReadOnlyDb {
  const jti = ctx.actor.impersonation!.jti;

  return new Proxy(rawClient, {
    get(target, prop, recv) {
      // Named mutators are refused outright.
      if (typeof prop === 'string' &&
          /^(insert|update|delete|upsert|create|executeRaw|batch|transaction)$/i.test(prop)) {
        throw new ImpersonationWriteViolation(`method ${prop}`, jti);
      }
      const v = Reflect.get(target, prop, recv);
      // Raw-SQL entry points are wrapped and their first token inspected.
      if (prop === 'prepare' || prop === 'query' || prop === 'queryRaw') {
        return (sql: string, ...rest: unknown[]) => {
          if (MUTATING.test(sql)) throw new ImpersonationWriteViolation(`sql: ${sql.slice(0,80)}`, jti);
          return (v as Function).call(target, sql, ...rest);
        };
      }
      return v;
    },
  }) as unknown as ReadOnlyDb;
}
```

**It throws. It never silently swallows the write.** A swallowed write would let the UI render a success state for an operation that did not happen — which is worse than an error, because the admin would then report the subject's problem as "fixed".

- ✅ Catches: raw SQL, dynamic dispatch, anything Layer 1 missed inside the app process.
- ❌ Does not catch: a `Function.prototype.call` that bypasses the proxy target, or a second driver instance imported directly. Regex parsing of SQL is a heuristic, not a parser — a `WITH x AS (…) INSERT …` starts with `with`. **The regex includes `with` handling in production code** and, more importantly, Layer 3 makes the heuristic non-load-bearing.

### Layer 3 — the database (the actual guarantee) — **PostgreSQL**

```sql
-- A role that is physically incapable of writing.
CREATE ROLE athar_impersonation_reader NOLOGIN;
GRANT USAGE ON SCHEMA public TO athar_impersonation_reader;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO athar_impersonation_reader;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON ALL TABLES IN SCHEMA public
  FROM athar_impersonation_reader;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
  GRANT SELECT ON TABLES TO athar_impersonation_reader;   -- future tables too
```

```ts
// Every impersonated request runs inside this envelope.
await pg.query('BEGIN');
await pg.query('SET LOCAL ROLE athar_impersonation_reader');
await pg.query('SET LOCAL default_transaction_read_only = on');
await pg.query(`SET LOCAL app.impersonation_jti = '${jti}'`);   // for RLS + audit context
try {
  return await handler(readOnlyHandle(ctx));
} finally {
  await pg.query('COMMIT');    // read-only ⇒ nothing to roll back
}
```

Any write now fails with `25006 read_only_sql_transaction` (or `42501 insufficient_privilege`) **inside the database engine**, regardless of what the application code believes it is doing.

- ✅ Catches: **everything**, including a bug in Layers 1 and 2, a compromised dependency, or a raw driver call.
- ❌ Does not catch: a write issued on a *different* connection that never entered the envelope. Solved by making the envelope the only way to obtain a connection during an impersonated request (§3.3.1).

### Layer 3 on **Cloudflare D1** — an honest assessment

D1 does not expose per-connection roles, and `PRAGMA query_only` is not part of D1's supported statement surface. **The database-level guarantee that §4.5.2 asks for is not achievable on D1 today.** [W6]

Options, in order of preference:

1. **Use PostgreSQL for this application** (via Cloudflare Hyperdrive). §6.1 already proposes PostgreSQL; this is the strongest single argument for keeping that choice rather than substituting D1. It also restores row-level security for §2.5 layer 3.
2. If D1 is required, accept Layers 1, 2 and 4, and add a fifth: **a separate Worker binding** for impersonated requests that exposes only a `d1ReadOnly.prepare()` wrapper, with the write-capable binding absent from that Worker's environment entirely. This moves the boundary from a proxy to the platform's binding model — weaker than a DB role, but stronger than a proxy alone, because the write-capable object does not exist in the isolate.
3. Do **not** rely on Layers 1+2 alone. This document does not consider that sufficient for the platform's highest privilege.

### Layer 4 — egress tripwire

```ts
export async function withImpersonationGuard<T>(
  ctx: RequestContext, fn: () => Promise<T>,
): Promise<T> {
  const before = ctx.metrics.dbWrites;
  const result = await fn();
  if (ctx.metrics.dbWrites !== before) {
    await terminateImpersonation(ctx, 'write_violation');   // kill the session immediately
    await audit.critical('impersonation.write_violation', ctx);
    throw new InternalError('IMPERSONATION_INTEGRITY_FAILURE');
  }
  return result;
}
```

Not a control — a **detector**. It converts a silent breach into a page at 03:00, which is how the other three layers stay honest over the project's life.

### 3.3.1 The audit writer is the one legitimate exception

§4.5.3 requires every preview navigation to be logged. That is a write, during a read-only request. If it used the same handle it would trip every layer above.

**Resolution: a separate, narrowly-privileged writer.**

```sql
CREATE ROLE athar_audit_writer NOLOGIN;
GRANT INSERT ON audit_log TO athar_audit_writer;      -- INSERT only. No SELECT, no UPDATE.
```

```ts
export interface AuditWriter { append(e: AuditEvent): Promise<void>; }   // no other methods

// Threaded explicitly. NOT reachable from `db`. Two handles, two capabilities, no overlap.
export async function handleImpersonatedRequest(
  db: ReadOnlyDb, auditDb: AuditWriter, ctx: RequestContext,
) { … }
```

Two handles with disjoint capabilities is the whole idea: the request can read subject data but not write it, and can append audit rows but not read or alter them.

---

## 3.4 Guaranteeing ZERO side effects (BR-34) — the complete inventory

§4.5.2 names four. A working implementation must suppress **seventeen**. The four the PRD names are marked ✱.

| # | Side effect | Where it fires | Suppression |
|---|---|---|---|
| 1 ✱ | `users.last_login_at` | session resolution | `touchLastLogin(db: ReadWriteDb)` — unreachable |
| 2 ✱ | `notifications.is_read` / `read_at` | opening the bell panel or the notifications page | ditto; the UI shows the true unread state, read-only |
| 3 ✱ | `thread_participants.last_read_at` + read receipts | opening any thread | ditto; **and** §9.13.2's «مؤشر تمت القراءة» must not fire for the other party |
| 4 ✱ | `resources.download_count` | resource download | ditto |
| 5 | **Lazy creation of `DigitalCard`** | `/dashboard/card` when no row exists | 🔴 A *read* that creates a row. Under preview the page must render the "not yet issued" state — **not fabricate a card**, which would give the subject a card with a wrong `issued_at` |
| 6 | **Lazy creation of `JourneyStep` / `UserJourneyState`** | `/dashboard/journey` on first visit | 🔴 same class. Render from existing rows only. |
| 7 | **Lazy creation of the trainer DM `Thread`** | §9.13.1 «تُنشأ تلقائيًا لكل متدرب» | 🔴 same class |
| 8 | Lazy certificate PDF generation | `/dashboard/certificate` | Render the existing file or the empty state; never generate |
| 9 | `users.onboarding_completed` (§9.5.4 tour) | first dashboard load | The tour must not auto-start in preview at all — it would also confuse the admin |
| 10 | QR scan counter (§9.6.2 «تُسجَّل كل عملية مسح») | `/verify/{token}` | Suppressed |
| 11 | Rate-limit counters | every request | 🔴 **Key on the ACTOR (admin), never the subject.** Otherwise an admin previewing a trainee can exhaust that trainee's login/check-in budget and lock them out of their own account (§5.6) |
| 12 | Analytics / Sentry / structured logs | every request | Report `actor_admin_id`; tag `impersonation=true`; **never** attribute activity to the subject, and never ship the subject's PII to a third-party processor (§5.9 PDPL) |
| 13 | WebSocket / realtime presence | messages tab | Connect **passively** or not at all. A presence marker would show the subject "online" to their cohort while they are asleep. |
| 14 | Email / notification dispatch | any page that can trigger a resend | All mail goes through `mailer(db: ReadWriteDb, …)`; unreachable |
| 15 | `submissions` draft autosave | assignment detail page | Editors render disabled; the autosave hook is not mounted in preview |
| 16 | `audit_log` rows attributed to the subject | any audited read | 🔴 `actor_id` **must** be the admin. See §3.6. |
| 17 | Signed-URL minting side effects | any file view | URLs minted during preview carry an impersonation claim and `TTL = min(15 min, remaining grant TTL)` (§3.7 leak #14) |

**Rows 5–8 are the ones a careful implementation still gets wrong**, because they are *reads* in every mental model. The rule to write on the wall:

> **In this application, several GET requests write. Enumerate them before building preview mode, not after.**

Mechanically: grep for every mutation call reachable from a `GET` route handler and assert the set is exactly the list above.

```ts
// tests/impersonation/no-writes.integration.ts
// Drives EVERY dashboard route under an impersonation grant against a Postgres instance
// whose app role has been stripped of INSERT/UPDATE/DELETE. Any write ⇒ the DB raises ⇒
// the test fails. This is the single highest-value test in the suite.
for (const route of ALL_DASHBOARD_ROUTES) {
  it(`BR-34: GET ${route} performs zero writes under impersonation`, async () => {
    const before = await snapshotAllTables();
    const res = await fetchAsImpersonator(route);
    expect(res.status).toBeLessThan(500);          // must not 500 — read paths must degrade
    expect(await snapshotAllTables()).toEqual(before);   // byte-identical
  });
}
```

---

## 3.5 Preventing admin → admin (BR-35), and every other refusal

```ts
// /features/impersonation/start.ts
export async function startImpersonation(
  db: ReadWriteDb, ctx: RequestContext, subjectUserId: string,
): Promise<ImpersonationGrant> {
  const actor = ctx.actor;

  // 1. The permission itself. Admin-only (§4.2 «معاينة أي حساب … | نعم | لا | لا»).
  if (!hasPermission(actor, 'impersonation:start', null)) throw new ForbiddenError();

  // 2. No nesting. A preview session can never start another preview.
  //    (hasPermission already denies this, but state it explicitly — this is the rule that
  //     stops a chain of grants outliving the original authorisation.)
  if (actor.impersonation !== null) throw new ForbiddenError('IMPERSONATION_NESTING_DENIED');

  // 3. No self-preview. Pointless, and it would produce a confusing audit trail.
  if (subjectUserId === actor.userId) throw new ForbiddenError('IMPERSONATION_SELF_DENIED');

  const subject = await db.users.findById(subjectUserId);
  if (!subject) throw new NotFoundError();

  // 4. BR-35 — «لا يمكن معاينة حساب مدير نظام آخر».
  if (subject.platformRole === 'admin') {
    await audit.append({ action: 'impersonation.denied', actorId: actor.userId,
      entityType: 'user', entityId: subjectUserId, after: { reason: 'subject_is_admin' } });
    throw new ForbiddenError('IMPERSONATION_ADMIN_TARGET_DENIED');
  }

  // 5. Deleted accounts are not previewable — their data is minimised (§4.4) and a preview
  //    would render a half-erased account as if it were live.
  if (subject.status === 'deleted') throw new ForbiddenError('IMPERSONATION_DELETED_TARGET');

  const now = Date.now();
  const jti = base64url(crypto.getRandomValues(new Uint8Array(32)));
  const grant = await db.impersonationSessions.insert({
    jtiHash: await sha256(jti),
    actorAdminId: actor.userId,
    subjectUserId,
    parentSessionId: ctx.sessionId,
    mode: 'read_only',
    issuedAt: now,
    expiresAt: now + 30 * 60_000,          // DB CHECK enforces the cap independently
    ip: ctx.ip, uaHash: await sha256(ctx.userAgent),
  });

  await audit.append({ action: 'impersonation.start', actorId: actor.userId,
    onBehalfOfUserId: subjectUserId, entityType: 'user', entityId: subjectUserId,
    ip: ctx.ip, userAgent: ctx.userAgent,
    after: { jti_hash: grant.jtiHash, expires_at: grant.expiresAt } });

  return grant;
}
```

### Re-validation on **every** request, not only at issue

The role check at issue time is not enough: another admin could promote the subject to `admin` while a preview is live, and the grant would then be previewing an admin — exactly what BR-35 forbids.

```ts
// /features/impersonation/resolve.ts — runs on every request carrying the preview cookie
export async function resolveImpersonation(
  db: ReadOnlyDb, cookie: string, ctx: RequestContext,
): Promise<ImpersonationContext | null> {
  const row = await db.impersonationSessions.findByJtiHash(await sha256(cookie));

  const end = async (reason: EndReason) => {
    await terminateImpersonation(ctx, reason);   // uses the AuditWriter + a privileged handle
    return null;
  };

  if (!row)                                    return null;
  if (row.endedAt !== null)                    return null;                     // already ended
  if (row.expiresAt <= dbNow())                return end('expired');           // §4.5.2 30 min
  if (row.ip !== ctx.ip)                       return end('ip_mismatch');
  if (row.uaHash !== await sha256(ctx.userAgent)) return end('ip_mismatch');

  const [admin, subject, parent] = await Promise.all([
    db.users.findById(row.actorAdminId),
    db.users.findById(row.subjectUserId),
    db.authSessions.findById(row.parentSessionId),
  ]);

  if (!parent || parent.revokedAt !== null)    return end('parent_revoked');    // admin logged out
  if (!admin || admin.platformRole !== 'admin'
             || admin.status !== 'active')     return end('admin_suspended');   // demoted mid-preview
  if (!subject || subject.platformRole === 'admin') return end('subject_became_admin'); // BR-35

  return { jti: row.jtiHash, adminId: admin.id, subjectId: subject.id,
           expiresAt: row.expiresAt, mode: 'read_only' };
}
```

Note `expiresAt` is compared against the **database** clock (§1.7), not `Date.now()`, and never against the cookie's `Max-Age` — a client can rewrite `Max-Age` trivially; it can rewrite nothing in this function.

---

## 3.6 Ending, expiring, and the exact audit records

### Ending — three paths, one outcome

| Path | Trigger | Effect |
|---|---|---|
| **Manual** | «إنهاء المعاينة» → `POST /api/impersonation/end` | `ended_at = now()`, `ended_reason='manual'`; `Set-Cookie: __Host-athar_imp=; Max-Age=0`; redirect to `/admin/users/{id}`. **The admin's own session cookie is untouched — no re-login (§4.5.2).** |
| **Expiry** | any request after `expires_at` | `resolveImpersonation` ends it and returns `null`; the request proceeds as the plain admin; the UI shows «انتهت مدة المعاينة». |
| **Abandoned tab** | the admin simply closes the browser | A cron sweep closes rows with `expires_at < now() AND ended_at IS NULL`, setting `ended_reason='expired'`. **Without this the audit trail has starts with no ends** — §4.5.3 requires both times. |

### The audit records — exact shape

```ts
export type ImpersonationAuditAction =
  | 'impersonation.start'           // §4.5.3
  | 'impersonation.navigate'        // §4.5.3 «يُسجَّل أيضًا كل تنقل بين التبويبات»
  | 'impersonation.end'
  | 'impersonation.denied'          // BR-35 refusals, and every other refusal in §3.5
  | 'impersonation.write_blocked';  // a layer-2/3 rejection — always investigate
```

```sql
INSERT INTO audit_log
  (id, actor_id, on_behalf_of_user_id, action, entity_type, entity_id,
   before, after, ip_address, user_agent, created_at)
VALUES
  (:id,
   :admin_id,        -- ▲ ALWAYS the admin. NEVER the subject.
   :subject_id,      -- ▲ NEW COLUMN — see below
   'impersonation.navigate', 'user', :subject_id,
   NULL, '{"path":"/dashboard/grades","grant":"<jti_hash>"}'::jsonb,
   :ip, :ua, now());
```

### 🔴 GAP-I1 — `AuditLog` has no `on_behalf_of_user_id` column, and without it the audit log lies

§7.6 defines `AuditLog(actor_id, action, entity_type, entity_id, before, after, ip_address, user_agent, created_at)`. There is nowhere to record that an action was performed *while previewing someone*. Two bad outcomes follow:

- If `actor_id` is set to the **subject**, the log records that the trainee did something they did not do. That is not merely inaccurate — it is a record that could be used against a person in a dispute about their own attendance or grades.
- If `actor_id` is the admin with no subject reference, §4.5.3's requirement to record «هوية الحساب المُعايَن» is unmet, and the 30-day preview count on the admin's profile cannot be built.

```sql
ALTER TABLE audit_log
  ADD COLUMN on_behalf_of_user_id UUID NULL REFERENCES users(id),
  ADD COLUMN impersonation_jti_hash BYTEA NULL;

-- Structural rule: the two always travel together.
ALTER TABLE audit_log ADD CONSTRAINT audit_impersonation_pair
  CHECK ((on_behalf_of_user_id IS NULL) = (impersonation_jti_hash IS NULL));
```

**Invariant, enforced by a test:** `actor_id` is the human who caused the action. `on_behalf_of_user_id` is non-null exactly when that human was in preview mode. There is no row in this table in which a trainee appears as `actor_id` for an action a trainee did not perform.

### Navigation logging must not block the response

§4.5.3's per-navigation audit could add a synchronous write to every page load. On Workers, use the execution context:

```ts
ctx.waitUntil(auditDb.append({ action: 'impersonation.navigate', … }));
```

`waitUntil` keeps the isolate alive after the response is returned, so the audit row lands without adding latency. It also means a failed audit write must be **alerted on**, not silently dropped — an unlogged preview navigation is a compliance gap, and §4.5.3 makes the log mandatory.

---

## 3.7 How a naive implementation leaks or gets abused — fifteen concrete failures

Each is a real pattern, with the specific consequence in **this** application.

**1. Reusing the admin's session with an `impersonatingUserId` field.**
The single most common design, and the most dangerous. The flag rides inside the session object, so any code reading `session.userId` acts as the subject — including background jobs, `ctx.waitUntil` continuations, and queue consumers that run *after* the response. Writes then land attributed to the trainee. **Fix: a separate cookie and a separate grant record, with the acting identity resolved once, explicitly, per request.**

**2. Read-only implemented in the UI only.**
Buttons render `disabled`; the API accepts the POST. §4.5.2 anticipates exactly this and forbids it. **Fix: §3.3 layers 1–3.**

**3. Read-only implemented as an HTTP-method filter** (`block POST/PUT/PATCH/DELETE`).
Misses every GET that writes — download counters, read receipts, lazy creation (rows 5–8 of §3.4), and any "mark all as read" implemented as a link. **Fix: enforce at the data layer, where the write actually happens, not at the transport.**

**4. Caching an impersonated response.**
A dashboard response cached in the Cloudflare Cache API or at the CDN under a URL key serves one trainee's grades to the next visitor of that URL. **Fix:** `Cache-Control: private, no-store` on every authenticated route, never `cf.cacheEverything` for `/dashboard/*`, `/trainer/*`, `/admin/*`, and a test that asserts the header on every such route.

**5. RSC / SSR payload over-serialisation.**
Next.js serialises the props crossing the server→client boundary into the flight payload. Passing a Prisma entity to a client component ships **every column** — `zoom_url`, `verify_code`, `qr_token`, `password_hash` if it was selected — even when the component renders none of them. During impersonation this is the subject's data sitting in a page the admin may screenshot. **Fix: explicit DTOs with `select` allow-lists (§4.8) and a test that greps the rendered HTML for forbidden field names.**

**6. Rate-limit counters keyed on the subject.**
An admin previewing a trainee consumes that trainee's budget; the trainee is then locked out of their own account or, worse, of **check-in during a live session**. **Fix: key on the actor (§5.6).**

**7. Analytics and error tracking attributing the session to the subject.**
The subject's PII goes to a third-party processor without a lawful basis, and their "last active" telemetry becomes false. **Fix: report the admin; tag `impersonation=true`; scrub subject PII from breadcrumbs.**

**8. Realtime presence.**
Subscribing to the subject's channel marks them online to their whole cohort and can deliver read receipts to the trainer. **Fix: passive subscription or none.**

**9. Email side effects.**
A preview lands on a page whose loader triggers "resend verification" or a reminder; the trainee receives an email they did not request, at 02:00, from an action they did not take. **Fix: all mail through `ReadWriteDb`.**

**10. Token in the URL.** §3.2.5.

**11. Not binding to the parent session.**
The admin logs out (or is fired, and their account is suspended) and the preview keeps working for the rest of its TTL. **Fix: `parentSessionId` + the per-request re-validation in §3.5.**

**12. Previewing a *trainer* without recognising the scope change.**
Previewing a trainer exposes the whole cohort's submissions and grades in one screen. An admin can already see this data, so it is not an escalation — but it is a much larger PII exposure than "help one trainee", and it should be visible in the audit as a distinct, reviewable event. **Fix: record `subject_role` on the grant; report trainer-target previews separately.**

**13. Private-conversation visibility that was never disclosed.**
§4.5.2 states that private DMs **are** visible in preview mode and requires the privacy policy to say so. If the policy does not, this is not a UX oversight — under PDPL it is processing without proper notice (§5.9). **Fix: an explicit clause in `/privacy`, and a launch-checklist item (§ملحق ب) that the clause is present before registration opens.**

**14. Signed URLs minted during preview outliving the preview.**
A 15-minute signed URL created at minute 29 of a preview remains valid for 14 minutes after the preview ends — a bearer token for the subject's files that survives its authorisation. **Fix:** `TTL = min(15 min, grant.expiresAt − now)`, embed `imp=<jti_hash>` in the signed payload, and verify at redemption that the grant is still live.

**15. "End preview" implemented client-side.**
If JavaScript can delete the preview cookie, the cookie is not `HttpOnly` — and if it is not `HttpOnly`, any XSS in the admin's dashboard steals the platform's highest privilege. **Fix: ending is a server route that clears the cookie in a `Set-Cookie` response header.**

---

## 3.8 The impersonation banner (§4.5.2) — requirements that are actually security-relevant

The banner is usually treated as a UI item. Two of its properties are security properties:

1. **It must be rendered by the server on every response**, not by a client component that can fail to mount. An admin who believes they are in their own account while previewing a trainee will take actions they would not otherwise take.
2. **It must name the subject exactly** («أنت تعاين حساب: محمد عبدالله القحطاني — وضع القراءة فقط»), and the name must be **bidi-sanitised** (§6.9) — a subject whose display name contains U+202E can visually rewrite the banner text around it, including making «وضع القراءة فقط» render somewhere misleading.
3. It must show the remaining time, driven by the server-sent `expiresAt`, so the 30-minute cut-off is never a surprise mid-support-call.

---

## 3.9 The test cases that must exist

| Test id | Asserts |
|---|---|
| `BR-33.readonly.checkin_denied` | `POST /api/sessions/:id/check-in` under a grant → `403`, and **no attendance row exists** |
| `BR-33.readonly.submit_denied` | `POST /api/assignments/:id/submit` → `403`, no submission row |
| `BR-33.readonly.message_denied` | `POST /api/threads/:id/messages` → `403`, no message row |
| `BR-33.readonly.profile_denied` | `PATCH /api/profile` → `403`, profile unchanged |
| `BR-33.readonly.certificate_download_denied` | `GET /api/certificates/:id/download` as the subject → `403` |
| `BR-33.readonly.direct_api` | Every mutating endpoint called **directly with the preview cookie**, bypassing the UI entirely → all `403` |
| `BR-33.readonly.db_role` | With the Postgres impersonation role active, a deliberate raw `INSERT` raises `25006`/`42501` |
| `BR-34.no_writes.all_routes` | **Table-snapshot equality** across every dashboard route (§3.4) |
| `BR-34.last_login` | `users.last_login_at` unchanged after a full preview session |
| `BR-34.notification_read` | Opening the notifications page leaves `is_read`/`read_at` unchanged |
| `BR-34.message_read_state` | Opening a thread leaves `thread_participants.last_read_at` unchanged |
| `BR-34.download_counter` | Viewing/downloading a resource leaves `download_count` unchanged |
| `BR-34.lazy_card` | Previewing a user with no `DigitalCard` **does not create one** |
| `BR-34.lazy_journey` | Previewing a user with no journey rows does not create them |
| `BR-34.lazy_thread` | Previewing a user with no trainer DM does not create one |
| `BR-34.rate_limit_actor` | Preview traffic consumes the **admin's** limit; the subject's remains full |
| `BR-35.admin_target_denied` | `POST /api/impersonation` with an admin subject → `403` + an `impersonation.denied` audit row |
| `BR-35.admin_promoted_midsession` | Promoting the subject to admin mid-preview ends the grant on the next request |
| `BR-35.self_denied` | An admin previewing themselves → `403` |
| `BR-35.nesting_denied` | Starting a preview from inside a preview → `403` |
| `BR-35.audit_start_end` | Every grant has exactly one `start` and one `end` row, with IPs |
| `BR-35.audit_navigation` | N tab navigations produce N `impersonation.navigate` rows |
| `BR-35.audit_actor_identity` | **No audit row anywhere has `actor_id = subject_id` for a preview action** |
| `IMP.expiry_30min` | At `issuedAt + 30min + 1ms` the grant is refused and auto-ended |
| `IMP.expiry_not_sliding` | Activity at minute 29 does not extend `expires_at` |
| `IMP.db_ttl_cap` | Inserting a grant with `expires_at = issued_at + 31min` is rejected by the CHECK |
| `IMP.parent_logout` | Admin logout ends every derived grant immediately |
| `IMP.parent_password_change` | BR-29 password change ends every derived grant |
| `IMP.ip_pin` | Same cookie from a different IP → grant ended, `ip_mismatch` audited |
| `IMP.end_returns_admin` | After «إنهاء المعاينة» the admin is authenticated **without re-login**, as themselves |
| `IMP.end_clears_cookie` | The end response sets `__Host-athar_imp=; Max-Age=0` |
| `IMP.no_token_in_url` | No response body or `Location` header ever contains the grant token |
| `IMP.banner_present` | Every HTML response during a grant contains the banner and the subject's name |
| `IMP.banner_bidi_safe` | A subject named with U+202E does not alter the banner's rendered text |
| `IMP.no_cache` | Every impersonated response carries `Cache-Control: private, no-store` |
| `IMP.signed_url_ttl` | A URL minted at minute 29 expires with the grant, not 15 minutes later |
| `IMP.signed_url_after_end` | A URL minted during a preview is rejected after the preview ends |
| `IMP.rsc_payload` | The rendered flight payload contains no field from the §4.8 never-return list |
| `IMP.suspended_admin` | Suspending the admin mid-preview ends the grant on the next request |
| `IMP.deleted_subject` | Previewing a soft-deleted user is refused |
| `IMP.30day_count` | The admin's profile count matches the number of grants in the last 30 days |

---
---

# 4. Data Model Hardening

> ### 📌 ملخص القسم بالعربية
> §7 يصف الحقول لكنه يترك معظم القيود ضمنية. هذا القسم يحوّل الضمني إلى صريح.
>
> **أهم النتائج:**
> 1. **قيد رقم الجوال معطوب:** §9.2.1 يقبل صيغتين (`05XXXXXXXX` و`9665XXXXXXXX`) بينما §7.7 يفرض قيد تفرّد على `phone`. بدون **توحيد الصيغة قبل الحفظ**، يمكن لشخص واحد إنشاء حسابين بنفس الرقم. يجب التخزين بصيغة `+9665XXXXXXXX` حصرًا.
> 2. **`Submission.version` يناقض BR-19:** حقل «رقم إصدار» على صف واحد يعني الكتابة فوق التسليم السابق، بينما BR-19 تقول «تحفظ الإصدار السابق **ولا تحذفه**». الحل: جدول تسليمات **يُضاف إليه فقط** مع فهرس فريد جزئي على الإصدار الحالي.
> 3. **`Evaluation.entity_id` بلا مفتاح خارجي إطلاقًا** — يمكن رصد درجة لتسليم غير موجود. الحل: عمودان منفصلان بقيد XOR.
> 4. **`score ≤ max_score` عبر جدولين:** الحل الأنيق هو **مفتاح خارجي مركّب** `(assignment_id, max_score)` يجعل النسخة غير قابلة للانحراف، ويمنع تلقائيًا خفض الدرجة القصوى تحت درجة مرصودة.
> 5. **الحذف الناعم والبريد:** بريد مستخدم محذوف — يُحرَّر أم لا؟ التوصية: **شاهدة (tombstone)** تُحرِّر العنوان للتسجيل من جديد مع حفظ بصمة مجزّأة للكشف عن التكرار.
> 6. **سجل التدقيق غير قابل للتعديل فعليًا** عبر أربع طبقات: صلاحيات قاعدة بيانات، مُشغِّل رفض، سلسلة تجزئة، ونسخ إلى تخزين غير قابل للكتابة.

---

## 4.1 Constraints the PRD implies but does not state

§7.7 lists nine constraints. The following are **also required** by rules stated elsewhere in the PRD, and are missing from §7.7.

### `users`

```sql
-- §7.1 «يُخزَّن بأحرف صغيرة» — stated as a behaviour, never enforced.
ALTER TABLE users ADD CONSTRAINT users_email_lowercase CHECK (email = lower(email));
ALTER TABLE users ADD CONSTRAINT users_email_shape
  CHECK (email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[a-z]{2,}$');

-- §9.3.1 «بعد 5 محاولات فاشلة: قفل مؤقت لمدة 15 دقيقة»
ALTER TABLE users ADD CONSTRAINT users_failed_login_sane
  CHECK (failed_login_count >= 0 AND failed_login_count <= 100);

-- §7.1 status enum + a rule the PRD states in prose only (§4.4):
--   a deleted user must carry a deletion timestamp.
ALTER TABLE users ADD CONSTRAINT users_deleted_consistent
  CHECK ((status = 'deleted') = (deleted_at IS NOT NULL));

-- §9.2.3 «يُنشأ الحساب بحالة pending وبريد غير مفعّل» → an active account has a verified email.
ALTER TABLE users ADD CONSTRAINT users_active_requires_verification
  CHECK (status <> 'active' OR email_verified_at IS NOT NULL);
```

### 🔴 GAP-D1 — `profiles.phone` uniqueness is defeated by the PRD's own input formats

§9.2.1 accepts **two** shapes: «يبدأ بـ 05 ويتكون من 10 أرقام، **أو** بصيغة 9665 متبوعة بـ 8 أرقام». §7.7 requires `phone` to be unique. If the raw input is stored, `0512345678` and `966512345678` are two different strings for the same telephone — so **one person can hold two accounts on one number**, defeating §9.2.1's own «فريد في النظام» and its «هذا الرقم مسجّل مسبقًا لحساب آخر» error entirely.

```sql
-- Canonical storage: E.164, one shape, always.
ALTER TABLE profiles ADD CONSTRAINT profiles_phone_e164
  CHECK (phone ~ '^\+9665[0-9]{8}$');
```

```ts
/** Normalise BEFORE validation and BEFORE the uniqueness probe. Total function. */
export function normalizeSaudiPhone(raw: string): string | null {
  const d = raw.replace(/[\s\-()\u200E\u200F]/g, '')      // spaces, dashes, bidi marks
               .replace(/[٠-٩]/g, c =>              // Arabic-Indic digits ٠١٢…
                 String(c.charCodeAt(0) - 0x0660))
               .replace(/[۰-۹]/g, c =>              // Extended Arabic-Indic ۰۱۲…
                 String(c.charCodeAt(0) - 0x06F0));

  const m = /^(?:\+?966|00966|0)?(5\d{8})$/.exec(d);
  return m ? `+966${m[1]}` : null;
}
```

The Arabic-Indic digit handling is not decoration: an Arabic-locale keyboard produces `٠٥١٢٣٤٥٦٧٨` by default, and §9.2.1's «حقل الجوال يقبل الأرقام فقط» would otherwise reject a correctly-typed Saudi number, or store it unnormalised.

### 🟠 GAP-D2 — the Arabic-name constraint as written rejects valid Saudi names

§9.2.1 and §7.1 require «حروف عربية فقط، من 2 إلى 20 حرفًا». §14.1 additionally mandates that hamza, tāʾ marbūṭa and alif maqṣūra work. Two problems with a naive implementation:

1. A strict "letters only" rule **rejects «عبد الله»** — an extremely common Saudi first name containing a space. So does it reject «عبد العزيز», «عبد الرحمن».
2. A naive range `[ء-ي]` admits **tatweel** (U+0640 `ـ`, a decorative stretch character), and separately Arabic-Indic **digits** (U+0660–0669) sit outside that range but are trivially pasted in.

```ts
// Explicit allow-list, not a range. Letters + a single internal space, nothing else.
const AR_LETTER = 'ء-غف-يٱٹپچژگی';
const AR_NAME = new RegExp(`^[${AR_LETTER}]{2,}(?: [${AR_LETTER}]{2,})*$`, 'u');

export function normalizeArabicName(raw: string): string {
  return raw
    .normalize('NFC')                                   // §14.1: composed hamza forms
    .replace(/[ـ]/g, '')                           // tatweel
    .replace(/[ً-ْٰ]/g, '')              // harakat / diacritics
    .replace(/[\u200B-\u200F\u202A-\u202E\u2066-\u2069\uFEFF]/g, '')  // bidi + zero-width (§6.9)
    .replace(/\s+/g, ' ')                               // §9.2.1 «تُزال المسافات الزائدة»
    .trim();
}
```

The bidi-control strip is **not optional**: §6.9 explains why a name containing U+202E is an injection vector into the participant list, the chat, the impersonation banner, and the printed certificate.

```sql
ALTER TABLE profiles ADD CONSTRAINT profiles_ar_names_shape CHECK (
  first_name_ar  ~ '^[ء-غف-يٱٹپچژگیۃ]{2,}( [ء-غف-يٱٹپچژگیۃ]{2,})*$'
  AND char_length(first_name_ar) BETWEEN 2 AND 20
  -- …repeated for second/third/last
);
ALTER TABLE profiles ADD CONSTRAINT profiles_en_names_shape CHECK (
  first_name_en ~ '^[A-Za-z]{2,}([ ''-][A-Za-z]{2,})*$'
  AND char_length(first_name_en) BETWEEN 2 AND 20
);
```

### `sessions`

```sql
-- §7.3 «cancellation_reason … إلزامي عند الإلغاء» — stated, never constrained.
ALTER TABLE sessions ADD CONSTRAINT sessions_cancel_reason_required
  CHECK (status <> 'cancelled' OR char_length(trim(cancellation_reason)) >= 10);

-- §9.10: recordings appear only for finished sessions.
ALTER TABLE sessions ADD CONSTRAINT sessions_recording_after_end
  CHECK (recording_url IS NULL OR status IN ('completed','cancelled'));

-- BR-24: a passcode without a URL is meaningless.
ALTER TABLE sessions ADD CONSTRAINT sessions_passcode_needs_url
  CHECK (zoom_passcode IS NULL OR zoom_url IS NOT NULL);

-- Plus the three duration constraints from BLOCKER-A7.
```

### `enrollments`

```sql
-- §7.2 states the composite unique. Add the value ranges the PRD implies:
ALTER TABLE enrollments ADD CONSTRAINT enrollments_score_range
  CHECK (final_score IS NULL OR (final_score >= 0 AND final_score <= 100));   -- BR-11
ALTER TABLE enrollments ADD CONSTRAINT enrollments_rate_range
  CHECK (attendance_rate IS NULL OR (attendance_rate >= 0 AND attendance_rate <= 100));
```

### `cohorts`

```sql
ALTER TABLE cohorts ADD CONSTRAINT cohorts_dates       CHECK (end_date >= start_date);
ALTER TABLE cohorts ADD CONSTRAINT cohorts_capacity    CHECK (capacity > 0 AND capacity <= 10000);
ALTER TABLE cohorts ADD CONSTRAINT cohorts_pass_score  CHECK (pass_score BETWEEN 0 AND 100);
ALTER TABLE cohorts ADD CONSTRAINT cohorts_min_rate    CHECK (min_attendance_rate BETWEEN 0 AND 100);
ALTER TABLE cohorts ADD CONSTRAINT cohorts_seats       CHECK (seats_taken >= 0 AND seats_taken <= capacity);
ALTER TABLE cohorts ADD CONSTRAINT cohorts_reg_closes
  CHECK (registration_closes_at <= (start_date + INTERVAL '1 day'));
```

### 🟠 GAP-D3 — `seats_taken` is a denormalised counter with no concurrency story

§7.2 says `seats_taken` «يُحسب آليًا من الالتحاقات». At the edge, "computed automatically" is where capacity gets oversold: two simultaneous registrations both read `seats_taken = 59`, both write `60`, and cohort capacity 60 now holds 61 people. §9.1.2's «تبقى 12 مقعدًا من 60» then lies to the landing page.

**Fix — a conditional increment in the same transaction as the enrolment:**

```sql
-- Atomic. Zero rows returned ⇒ the cohort is full. No read-then-write anywhere.
WITH claimed AS (
  UPDATE cohorts
     SET seats_taken = seats_taken + 1
   WHERE id = $1
     AND status IN ('open')
     AND registration_closes_at > now()
     AND seats_taken < capacity          -- ← the guard, evaluated atomically
  RETURNING id, seats_taken, capacity
)
INSERT INTO enrollments (id, cohort_id, user_id, role_in_cohort, status, enrolled_at)
SELECT $2, c.id, $3, 'participant', $4, now() FROM claimed c
RETURNING id;
```

The `cohorts_seats` CHECK above is the backstop: even a hand-written path cannot push `seats_taken` past `capacity`.

---

## 4.2 The exact unique constraints

```sql
-- ── Stated in §7.7 ─────────────────────────────────────────────────────────────
ALTER TABLE attendance  ADD CONSTRAINT attendance_session_user_uq  UNIQUE (session_id, user_id);
ALTER TABLE enrollments ADD CONSTRAINT enrollments_cohort_user_uq  UNIQUE (cohort_id, user_id);
ALTER TABLE users       ADD CONSTRAINT users_email_uq              UNIQUE (email);
ALTER TABLE profiles    ADD CONSTRAINT profiles_phone_uq           UNIQUE (phone);
ALTER TABLE certificates ADD CONSTRAINT certificates_serial_uq     UNIQUE (serial_number);
ALTER TABLE certificates ADD CONSTRAINT certificates_verify_uq     UNIQUE (verify_code);

-- ── Required but absent from §7.7 ──────────────────────────────────────────────
ALTER TABLE profiles ADD CONSTRAINT profiles_user_uq UNIQUE (user_id);          -- §7.1 «فريد»
ALTER TABLE programs ADD CONSTRAINT programs_slug_uq UNIQUE (slug);             -- §7.2 «فريد»

-- One live certificate per (user, cohort). Revoked ones remain for the verify page (§9.17).
CREATE UNIQUE INDEX certificates_user_cohort_live_uq
  ON certificates (user_id, cohort_id) WHERE revoked_at IS NULL;

-- One live digital card per (user, cohort); qr_token globally unique. §7.6, §9.6.2.
ALTER TABLE digital_cards ADD CONSTRAINT digital_cards_number_uq   UNIQUE (card_number);
CREATE UNIQUE INDEX digital_cards_qr_hash_uq  ON digital_cards (qr_token_hash);
CREATE UNIQUE INDEX digital_cards_user_cohort_live_uq
  ON digital_cards (user_id, cohort_id) WHERE revoked_at IS NULL;

-- Email tokens: the hash is the lookup key AND the uniqueness key (§7.6, §9.2.3).
ALTER TABLE email_tokens ADD CONSTRAINT email_tokens_hash_uq UNIQUE (token_hash);

-- One current submission per (assignment, user) — see GAP-D4.
CREATE UNIQUE INDEX submissions_current_uq
  ON submissions (assignment_id, user_id) WHERE is_current;

CREATE UNIQUE INDEX project_submissions_current_uq
  ON project_submissions (final_project_id, user_id) WHERE is_current;

-- One evaluation per submission version. Revisions are UPDATEs with revision_reason (BR-14),
-- not new rows — otherwise "the grade" becomes ambiguous.
CREATE UNIQUE INDEX evaluations_assignment_sub_uq
  ON evaluations (assignment_submission_id) WHERE assignment_submission_id IS NOT NULL;
CREATE UNIQUE INDEX evaluations_project_sub_uq
  ON evaluations (project_submission_id) WHERE project_submission_id IS NOT NULL;

-- One journey-state row per (user, step). §7.6 UserJourneyState.
ALTER TABLE user_journey_states ADD CONSTRAINT ujs_user_step_uq UNIQUE (user_id, journey_step_id);

-- One notification preference per (user, type). §7.6 NotificationPreference.
ALTER TABLE notification_preferences ADD CONSTRAINT np_user_type_uq UNIQUE (user_id, type);

-- One participant row per (thread, user). §7.6 ThreadParticipant.
ALTER TABLE thread_participants ADD CONSTRAINT tp_thread_user_uq UNIQUE (thread_id, user_id);

-- Week indices are 1..4 and unique within a cohort. §7.3.
ALTER TABLE weeks ADD CONSTRAINT weeks_cohort_index_uq UNIQUE (cohort_id, index);
ALTER TABLE weeks ADD CONSTRAINT weeks_index_range     CHECK (index BETWEEN 1 AND 52);

-- Exactly one announcement thread and one group thread per cohort. §9.13.1.
CREATE UNIQUE INDEX threads_cohort_singleton_uq
  ON threads (cohort_id, type) WHERE type IN ('group','announcement');
```

### Required indexes (§7.7 lists three; these are the rest)

```sql
CREATE INDEX notifications_user_unread_idx ON notifications (user_id, created_at DESC)
  WHERE read_at IS NULL;                       -- §7.7 asks for (user_id, is_read); see GAP-D9
CREATE INDEX submissions_assignment_user_idx ON submissions (assignment_id, user_id);  -- §7.7
CREATE INDEX attendance_user_session_idx     ON attendance (user_id, session_id);
CREATE INDEX attendance_sweep_idx            ON attendance (session_id)
  WHERE check_out_at IS NULL AND completion = 'pending';   -- the T2 sweep predicate
CREATE INDEX messages_thread_sent_idx        ON messages (thread_id, sent_at DESC)
  WHERE deleted_at IS NULL;
CREATE INDEX audit_actor_idx                 ON audit_log (actor_id, created_at DESC);
CREATE INDEX audit_entity_idx                ON audit_log (entity_type, entity_id, created_at DESC);
CREATE INDEX audit_impersonation_idx         ON audit_log (on_behalf_of_user_id, created_at DESC)
  WHERE on_behalf_of_user_id IS NOT NULL;     -- §4.5.3 30-day preview report
CREATE INDEX enrollments_user_idx            ON enrollments (user_id) WHERE status = 'active';
CREATE INDEX resources_cohort_week_idx       ON resources (cohort_id, week_id);
```

---

## 4.3 🔴 GAP-D4 — `Submission.version` contradicts BR-19

§7.5 gives `Submission.version: Int` — «يزيد مع كل إعادة رفع» — a counter on a single row, which means re-submission **overwrites** the previous submission. BR-19 says the opposite: «إعادة التسليم قبل الموعد **تحفظ الإصدار السابق ولا تحذفه**». §9.11.2 agrees with BR-19 («مع حفظ الإصدارات»). §18 lists «ضياع تسليمات المتدربين» as a **high** risk mitigated by «حفظ الإصدارات وعدم الحذف الفعلي».

A single mutable row cannot satisfy BR-19. If a trainee re-submits and the new upload is corrupt, the original is gone.

**Fix — an append-only submissions table:**

```sql
CREATE TABLE submissions (
  id             UUID PRIMARY KEY,
  assignment_id  UUID NOT NULL REFERENCES assignments(id),
  user_id        UUID NOT NULL REFERENCES users(id),
  version        INT  NOT NULL CHECK (version >= 1),
  is_current     BOOLEAN NOT NULL DEFAULT TRUE,
  files          JSONB NOT NULL DEFAULT '[]'::jsonb,
  github_url     TEXT,
  note           TEXT,
  submitted_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
  is_late        BOOLEAN NOT NULL,
  status         TEXT NOT NULL CHECK (status IN ('submitted','under_review','graded')),

  -- §9.11.2: «زر تسليم يُعطَّل حتى يُرفق ملف واحد على الأقل أو رابط GitHub»
  CONSTRAINT submissions_has_content
    CHECK (jsonb_array_length(files) > 0 OR github_url IS NOT NULL),
  -- §9.11.2: «يجب أن يبدأ بـ https://github.com/»
  CONSTRAINT submissions_github_shape
    CHECK (github_url IS NULL OR github_url ~ '^https://github\.com/[A-Za-z0-9._-]+/[A-Za-z0-9._-]+/?'),
  CONSTRAINT submissions_version_uq UNIQUE (assignment_id, user_id, version)
);

CREATE UNIQUE INDEX submissions_current_uq
  ON submissions (assignment_id, user_id) WHERE is_current;
```

Re-submission is then: `UPDATE … SET is_current = FALSE WHERE assignment_id=$1 AND user_id=$2 AND is_current` followed by an insert at `version + 1`, in one transaction. The partial unique index makes "two current versions" impossible even under a concurrent double-submit.

**On D1 (no partial-index-friendly upsert path needed here):** SQLite supports partial indexes, so the same shape applies; the two statements go in one `db.batch([...])` call, which D1 runs in a single implicit transaction.

---

## 4.4 Soft delete, and what happens to a deleted user's email and phone

> **PRD:** §7.8 «المستخدمون: حذف ناعم فقط. **لا يُحذف مستخدم له سجلات تقييم أو شهادة صادرة**.»
> §4.4 «عند حذف مستخدم: تُطبق سياسة الحذف الناعم … ولا تُحذف السجلات المرتبطة بالتقييم أو الشهادات.»
> §12.6 «إمكانية تصدير المستخدم لبياناته الشخصية عند الطلب.» (PDPL — §5.9)

### The question the PRD does not answer

A trainee is soft-deleted. Their row still holds `email = 'sara@example.com'`. Six months later that person wants to register for the next cohort with the same address.

| If the unique index is… | Consequence |
|---|---|
| **Total** (`UNIQUE (email)`) | The address is **permanently burned**. The person can never re-register. Support's only remedy is to hard-delete — which §7.8 forbids for anyone with an evaluation or certificate. |
| **Partial** (`UNIQUE (email) WHERE deleted_at IS NULL`) | The address is reusable — but so is it reusable by **someone else**. A new account can take over the mailbox identity of a deleted trainee, and every email-based support lookup becomes ambiguous. |

Neither is acceptable on its own.

### Recommendation — tombstone the identifier, keep the index total

On soft-delete, **rewrite** the identifiers to a non-routable form and record a peppered hash of the original in a separate table.

```sql
CREATE TABLE retired_identifiers (
  id            UUID PRIMARY KEY,
  kind          TEXT NOT NULL CHECK (kind IN ('email','phone')),
  value_hash    BYTEA NOT NULL,            -- HMAC-SHA256(server_pepper, lower(value))
  former_user_id UUID NOT NULL REFERENCES users(id),
  retired_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  reason        TEXT NOT NULL
);
CREATE INDEX retired_identifiers_hash_idx ON retired_identifiers (kind, value_hash);
```

```ts
export async function softDeleteUser(db: ReadWriteDb, userId: string, actorId: string, reason: string) {
  const u = await db.users.findById(userId);

  // §7.8 — an account with graded work or a certificate is never fully erased.
  const hasRecords = await db.hasEvaluationsOrCertificates(userId);

  await db.transaction(async tx => {
    await tx.retiredIdentifiers.insertMany([
      { kind: 'email', valueHash: await hmac(u.email),          formerUserId: userId, reason },
      { kind: 'phone', valueHash: await hmac(u.profile.phone),  formerUserId: userId, reason },
    ]);

    await tx.users.update(userId, {
      status: 'deleted',
      deletedAt: sqlNow(),
      // Tombstone: unique, non-routable, and irreversible. `.invalid` is reserved by RFC 2606
      // and can never resolve, so no mail can be sent to it by accident.
      email: `deleted+${userId}@invalid.athar-dev.edu.sa`,
      passwordHash: null,          // credentials are destroyed regardless
    });

    await tx.profiles.update(userId, {
      phone: `+966500000000`.slice(0,4) + userId.replace(/-/g,'').slice(0,9),  // unique tombstone
      avatarUrl: null, bio: null, birthDate: null, city: null, educationLevel: null,
      // Names are RETAINED when hasRecords — they are printed on an issued certificate and
      // on evaluation records the centre must be able to stand behind (§5.9 retention).
      ...(hasRecords ? {} : { firstNameAr: '—', secondNameAr: '—', /* … */ }),
    });

    await tx.authSessions.revokeAllFor(userId);          // §4.4 «تُبطل جلساته فورًا»
    await tx.auditLog.insert({ actorId, action: 'user.soft_delete', entityType: 'user',
      entityId: userId, after: { reason, pii_minimised: !hasRecords } });
  });
}
```

**Properties of this design:**

| Property | How |
|---|---|
| The address is reusable by its rightful owner | The tombstone frees `sara@example.com` for a fresh registration |
| The unique index stays **total** | No partial-index `ON CONFLICT` inference traps (§1.8) |
| "Has this address been used before?" is still answerable | `retired_identifiers` hash lookup — for fraud checks and support, without storing plaintext |
| Deletion is genuine PII minimisation | Plaintext email, phone, avatar, bio, birth date and city are gone; only a keyed hash remains, and it is not reversible without the server pepper |
| Certificates keep working | They reference the user **UUID**, not the email; the verify page shows the name printed on the certificate, not the current account (§9.17) |
| §7.8's "not deletable with evaluations" is honoured | The row and its names survive; only contact PII is minimised |

### 🟠 GAP-D5 — PDPL erasure versus the certificate retention obligation

A former trainee may request erasure. §7.8 forbids deleting a user who holds a certificate. These are reconcilable, but only if the position is documented:

- The certificate is a record the centre issued and must be able to stand behind; the verify page (§9.17) exists precisely so third parties can rely on it. Retaining the **name, programme, dates, serial and verify code** is necessary for that purpose.
- Everything else — contact details, avatar, chat history, uploaded files beyond the retention window, IP/user-agent audit fields — is minimised on request.
- **The privacy policy must state this split explicitly and give the retention period** (§19 open decision #12 — currently unresolved, and it cannot ship unresolved).

See §5.9 for the PDPL grounds.

---

## 4.5 Making the audit log genuinely append-only

> **§7.8:** «سجل التدقيق: **لا يُحذف ولا يُعدَّل إطلاقًا**.» **BR-27**, and §9.18 «غير قابل للتعديل أو الحذف».

### The honest starting point

An application with `DELETE` on its own database can delete anything. Immutability is therefore not one control but a **layered set**, where each layer raises the privilege required to tamper and the last layer makes tampering *detectable* even by someone who holds every credential.

### Layer 1 — the application role cannot modify audit rows (primary control)

```sql
REVOKE ALL ON audit_log FROM athar_app;
GRANT INSERT, SELECT ON audit_log TO athar_app;      -- INSERT and SELECT. Nothing else.

-- Migrations run as a DIFFERENT role, used by CI only, whose credentials are not in the Worker.
GRANT ALL ON audit_log TO athar_migrator;
```

This alone defeats every application-level bug, every SQL-injection payload that reaches the audit table, and every compromised dependency — because the credential the Worker holds is physically incapable of `UPDATE` or `DELETE`.

### Layer 2 — a refusing trigger

```sql
CREATE OR REPLACE FUNCTION audit_log_is_append_only() RETURNS TRIGGER AS $$
BEGIN
  RAISE EXCEPTION 'audit_log is append-only (BR-27); % denied', TG_OP
    USING ERRCODE = 'restrict_violation';
END; $$ LANGUAGE plpgsql;

CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log
  FOR EACH ROW EXECUTE FUNCTION audit_log_is_append_only();
CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log
  FOR EACH ROW EXECUTE FUNCTION audit_log_is_append_only();
CREATE TRIGGER audit_log_no_truncate BEFORE TRUNCATE ON audit_log
  FOR EACH STATEMENT EXECUTE FUNCTION audit_log_is_append_only();
```

A superuser can `DROP TRIGGER`. That is the point of Layers 3 and 4.

### Layer 3 — a per-actor hash chain (makes tampering *detectable*)

A single global chain would need a global sequencer and would serialise every audit write — unacceptable at the edge. **Chain per actor** instead: concurrency is then bounded by how many things one human does at once, which is one.

```sql
ALTER TABLE audit_log
  ADD COLUMN chain_seq  BIGINT NOT NULL,
  ADD COLUMN prev_hash  BYTEA  NOT NULL,
  ADD COLUMN row_hash   BYTEA  NOT NULL;

ALTER TABLE audit_log ADD CONSTRAINT audit_chain_uq UNIQUE (actor_id, chain_seq);
```

```ts
/** row_hash = SHA-256(prev_hash ‖ canonicalJSON(row-without-hashes)) */
async function appendAudit(tx: Tx, e: AuditEvent): Promise<void> {
  const prev = await tx.one(`
    SELECT row_hash, chain_seq FROM audit_log
     WHERE actor_id = $1 ORDER BY chain_seq DESC LIMIT 1 FOR UPDATE`, [e.actorId]);

  const prevHash = prev?.row_hash ?? GENESIS;                  // 32 zero bytes
  const seq = (prev?.chain_seq ?? 0n) + 1n;
  const rowHash = await sha256(concat(prevHash, canonicalJson({ ...e, chainSeq: seq })));

  await tx.none(`INSERT INTO audit_log (…, chain_seq, prev_hash, row_hash) VALUES (…)`,
                [...values, seq, prevHash, rowHash]);
}
```

A daily verifier recomputes every chain and alerts on the first mismatch. **Deleting a row breaks the `chain_seq` sequence; editing one breaks every `row_hash` after it.** Neither can be repaired without the ability to rewrite the whole chain *and* the anchors in Layer 4.

### Layer 4 — off-box anchoring (survives full database compromise)

```
audit_log ──(Workers Queue)──▶ R2 bucket `athar-audit-archive`
                               • object key: audit/YYYY/MM/DD/<uuid>.jsonl
                               • Object Lock in compliance mode, retention 7 years
                               • written by a scoped API token with s3:PutObject only
```

Plus a **daily checkpoint**: the max `row_hash` per actor chain, concatenated and hashed, written to the archive **and** emailed to the centre's operations mailbox. An attacker with full database access still cannot alter yesterday's checkpoint sitting in an operations inbox.

| Adversary | Layers 1–2 | Layer 3 | Layer 4 |
|---|---|---|---|
| Application bug / SQL injection | ✅ blocked | — | — |
| Compromised app credentials | ✅ blocked | — | — |
| Compromised DB superuser | ❌ | ⚠️ detected | ✅ evidence survives |
| Compromised DB **and** R2 token | ❌ | ⚠️ detected | ⚠️ emailed checkpoint survives |

### D1 note

D1 has no roles and no `GRANT`. Layers 1–2 are unavailable; **Layers 3 and 4 must be implemented, and a separate D1 database (or a dedicated Durable Object) should hold the audit log** so that the binding used for ordinary application traffic cannot address it. This is the second structural argument for Postgres in this project.

---

## 4.6 `Evaluation` — enforcing `score ≤ max_score` across two parent tables

> **§7.5:** `Evaluation(entity_type: assignment | final_project, entity_id, score …)` with «الدرجة، لا تتجاوز الدرجة القصوى».
> **§7.7:** «قيد تحقق: score لا يتجاوز max_score ولا يقل عن صفر.» **BR-12**, §9.15.4, and §9.15's acceptance criterion «محاولة رصد درجة أكبر من الدرجة القصوى **تُرفض على الخادم**».

`max_score` lives on `assignments.max_score` **or** `final_projects.max_score`. A `CHECK` cannot read another table, so §7.7's constraint as written **cannot be created**.

### 🔴 GAP-D6 — the polymorphic `entity_id` has no foreign key at all

Before solving the score problem: `Evaluation.entity_id` is a bare UUID with `entity_type` as a discriminator. Nothing prevents an evaluation referencing a submission that does not exist, or a *project* submission id under `entity_type='assignment'`. Grades can therefore be orphaned by any bug or any race — in a table that determines certificates.

### The fix — split the polymorphism, then use a composite foreign key

```sql
CREATE TABLE evaluations (
  id UUID PRIMARY KEY,

  -- ── Exactly one of these is non-null. Real FKs, real cascade behaviour. ─────
  assignment_submission_id UUID NULL REFERENCES submissions(id)          ON DELETE RESTRICT,
  project_submission_id    UUID NULL REFERENCES project_submissions(id)  ON DELETE RESTRICT,
  CONSTRAINT eval_exactly_one_parent
    CHECK (num_nonnulls(assignment_submission_id, project_submission_id) = 1),

  -- Denormalised so the range check is LOCAL and therefore expressible as a CHECK.
  parent_id UUID NOT NULL,       -- assignments.id or final_projects.id
  max_score NUMERIC(5,2) NOT NULL CHECK (max_score > 0 AND max_score <= 100),

  user_id      UUID NOT NULL REFERENCES users(id),
  score        NUMERIC(5,2) NOT NULL,
  feedback     TEXT NOT NULL,
  evaluated_by UUID NOT NULL REFERENCES users(id),
  evaluated_at TIMESTAMPTZ NOT NULL DEFAULT now(),

  revision_count  INT  NOT NULL DEFAULT 0,
  revision_reason TEXT NULL,
  overridden_by_admin BOOLEAN NOT NULL DEFAULT FALSE,   -- §2.7 CONFLICT-P1
  override_reason TEXT NULL,

  -- ── BR-12: now a purely LOCAL constraint. Works on Postgres AND SQLite. ─────
  CONSTRAINT eval_score_range CHECK (score >= 0 AND score <= max_score),
  -- ── BR-13: «ملاحظة المدرب إلزامية» — §9.15.2 «لا تقل عن 10 أحرف» ────────────
  CONSTRAINT eval_feedback_required CHECK (char_length(trim(feedback)) >= 10),
  -- ── BR-14: «تعديل درجة مرصودة يتطلب سببًا» ──────────────────────────────────
  CONSTRAINT eval_revision_reason
    CHECK (revision_count = 0 OR char_length(trim(revision_reason)) >= 10),
  CONSTRAINT eval_override_reason
    CHECK (NOT overridden_by_admin OR char_length(trim(override_reason)) >= 20)
);
```

### Making the denormalised `max_score` incapable of drifting

A copied value is worthless if it can diverge. **A composite foreign key makes divergence impossible:**

```sql
-- 1. Give the parents a key that includes max_score.
ALTER TABLE assignments     ADD CONSTRAINT assignments_id_max_uq     UNIQUE (id, max_score);
ALTER TABLE final_projects  ADD CONSTRAINT final_projects_id_max_uq  UNIQUE (id, max_score);

-- 2. Reference the pair, not just the id.
ALTER TABLE evaluations ADD CONSTRAINT eval_assignment_max_fk
  FOREIGN KEY (parent_id, max_score) REFERENCES assignments(id, max_score)
  ON UPDATE CASCADE ON DELETE RESTRICT
  NOT VALID;   -- add per-branch; see the note on polymorphism below
```

**What this buys, and it is the elegant part:**

- The copy cannot be wrong — the database refuses an evaluation whose `max_score` does not match its assignment's.
- If a trainer **raises** `max_score` from 10 to 15, `ON UPDATE CASCADE` propagates it to every evaluation, and each row still satisfies `score <= max_score`. Correct.
- If a trainer **lowers** `max_score` from 10 to 5 while a 9/10 already exists, the cascade tries to set `max_score = 5` on a row with `score = 9`, `eval_score_range` fails, and **the parent UPDATE is rejected**. The trainer gets: «لا يمكن خفض الدرجة القصوى إلى 5 لوجود درجة مرصودة قدرها 9». That rule is nowhere in the PRD, and without it lowering a max score silently creates records violating BR-12.

**On the polymorphism:** a single column cannot carry two composite FKs. Two workable shapes:

| Shape | Trade-off |
|---|---|
| **(a) Two tables** — `assignment_evaluations` and `project_evaluations`, each with its own composite FK, unioned in a view | Cleanest and fully constrained. Slightly more code. **Recommended.** |
| **(b) One table, two nullable parent columns** `(assignment_id, project_id)` each with its own composite FK, plus the XOR check | One table; two mostly-null columns. Also fully constrained. |

Shape (a) is recommended: BR-11 already treats the two as different things (50 points of assignments, 50 points for one project), and §9.15.1's totalling logic differs between them.

### D1 / SQLite

SQLite supports composite foreign keys, `ON UPDATE CASCADE`, and `CHECK` — but **foreign keys are off by default** and must be enabled per connection. If D1 does not reliably enable `PRAGMA foreign_keys`, fall back to a trigger, which SQLite does support: [W7]

```sql
CREATE TRIGGER eval_max_score_guard_ins BEFORE INSERT ON evaluations
BEGIN
  SELECT RAISE(ABORT, 'BR-12: score exceeds the assignment max_score')
  WHERE NEW.score > (SELECT max_score FROM assignments WHERE id = NEW.parent_id);
END;
```

The application-layer Zod check remains in place regardless — but it is the *third* line of defence, never the only one. §9.15's acceptance criterion demands server rejection; §7.7 demands a database constraint. Both.

---

## 4.7 Certificate serial numbers — collision-free under edge concurrency

> **§9.17:** «رقمًا تسلسليًا فريدًا بصيغة `ATHAR-AI101-2026-0001`، ورمز تحقق فريدًا».
> Issuance is «فرديًا أو **جماعيًا لكل المستوفين**» — a bulk operation, i.e. genuine concurrency.

The format decomposes as `ATHAR-{PROGRAM_CODE}-{YEAR}-{SEQ:04}`. The `SEQ` is a per-`(program, year)` monotonic counter — the only part that can collide.

### Rejected approaches

| Approach | Why not |
|---|---|
| `SELECT max(seq) + 1` | The classic race. Two concurrent issuances read the same max. |
| `count(*) + 1` | Same race, plus it renumbers after any deletion. |
| Random + retry on conflict | Produces non-sequential serials, which defeats the point of a serial number. |
| A Postgres `SEQUENCE` per program-year | Requires DDL at runtime to create new sequences; does not exist on D1. |
| UUID as the serial | Contradicts the mandated format, and the format is printed on a certificate. |

### The approach — an atomic counter row, one statement, works on both engines

```sql
CREATE TABLE certificate_counters (
  program_code TEXT NOT NULL,
  year         INT  NOT NULL,
  last_seq     INT  NOT NULL DEFAULT 0 CHECK (last_seq >= 0),
  PRIMARY KEY (program_code, year)
);
```

```sql
-- Allocate ONE. Atomic: the upsert is a single statement, so concurrent callers serialise
-- on the row lock and each receives a distinct value.
INSERT INTO certificate_counters (program_code, year, last_seq)
VALUES ($1, $2, 1)
ON CONFLICT (program_code, year)
DO UPDATE SET last_seq = certificate_counters.last_seq + 1
RETURNING last_seq;
```

```sql
-- Allocate a BLOCK of n for bulk issuance (§9.17) — one round-trip, contiguous range.
INSERT INTO certificate_counters (program_code, year, last_seq)
VALUES ($1, $2, $3)
ON CONFLICT (program_code, year)
DO UPDATE SET last_seq = certificate_counters.last_seq + $3
RETURNING last_seq - $3 + 1 AS first_seq, last_seq AS last_seq;
```

```ts
export function formatSerial(programCode: string, year: number, seq: number): string {
  if (seq > 9999) throw new Error('SERIAL_SPACE_EXHAUSTED');  // widen the format, do not wrap
  return `ATHAR-${programCode}-${year}-${String(seq).padStart(4, '0')}`;
}
```

**Design notes:**
- The allocation happens in the **same transaction** as the certificate insert. If the insert fails, the number is burned and a gap appears. **Gaps are acceptable in a serial number; reuse is not** — a reused serial would mean two different people holding certificates with the same identifier, which is precisely what the number exists to prevent.
- `UNIQUE (serial_number)` (§7.7) remains as the final backstop. On a `23505` the operation retries **once** with a freshly allocated number; a second failure is a hard error and pages.
- The `year` is the **Riyadh** calendar year of `issued_at` (§1.6 conversion), not UTC — otherwise a certificate issued at 02:00 Riyadh on 1 January would carry the previous year.
- Same statement works on D1 (SQLite upsert + `RETURNING`), so the design does not fork per engine.

### The verify code is a different problem and must not use this mechanism

`serial_number` is semi-public and predictable by design. `verify_code` is a **capability**: possessing it lets anyone view the certificate's verification page. A sequential verify code would let an attacker enumerate the centre's entire graduate list — names, programme, dates — by counting upward. See §4.6 of the abuse section (§6.3) for the required entropy: **80 bits, Crockford Base32, from `crypto.getRandomValues`**.

```sql
ALTER TABLE certificates
  ADD CONSTRAINT certificates_verify_code_shape
  CHECK (verify_code ~ '^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{16}$');   -- Crockford: no I, L, O, U
```

---

## 4.8 Fields that must NEVER be returned by any API

> **§7.1:** `password_hash` — «**لا يُرجع أبدًا في أي استجابة**».
> This section extends that single instruction into the complete list, because §7.1's rule is
> correct and radically insufficient.

### The never-return list

| Table.field | Why | Exception |
|---|---|---|
| `users.password_hash` | §7.1, explicit | **None. Ever.** |
| `users.failed_login_count` | Reveals whether an account is under attack; combined with `locked_until` it is an account-existence oracle | None |
| `users.locked_until` | Same | The **owner's** own security tab may show "locked until X" after authentication |
| `email_tokens.*` (every column) | `token_hash` + `expires_at` + `type` is enough to correlate a live reset token | None. This table is never in an API response. |
| `digital_cards.qr_token` / `qr_token_hash` | A bearer capability for the public verify page (§9.6.2) | The raw token appears **only** inside the generated QR image, never as a JSON field |
| `certificates.verify_code` | A bearer capability | The certificate's **owner** and an admin, on the authenticated certificate page only |
| `sessions.zoom_url` | **BR-24** — not before `S − 15m` | Returned only by `POST /api/sessions/:id/join` after the server re-checks the window |
| `sessions.zoom_passcode` | Same | Same |
| `final_projects.brief` / `requirements` / `attachments` | **BR-15, BR-16** — «لا تُرسل للمتصفح قبل التفعيل» | Only when `is_unlocked = true` |
| `attendance.ip_address` / `user_agent` | §9.9.4 says these are «لأغراض التدقيق فقط». Showing a trainee's IP to a trainer is PII processing beyond the stated purpose (§5.9 minimisation) | Admin, in the audit view only |
| `audit_log.before` / `after` | These JSON blobs contain arbitrary prior values, including PII and, if an audit is written carelessly, secrets | Admin only, field-redacted through an allow-list |
| `resources.download_count` | §9.12 «يظهر للمدرب فقط» | Trainer + admin |
| `users.email` / `profiles.phone` **of other users** | §12.6 minimisation. The group chat needs a display name and an avatar, nothing more | Admin; trainer for their own cohort (§4.2 «عرض الملف الكامل … دفعته فقط») |
| `submissions.files[].path` / storage keys | A storage key is a partial capability and defeats the random-name control (§12.5) | None — the API returns a mediated download URL, never a key |
| `impersonation_sessions.*` | The grant token and its hash | None |
| `evaluations.*` **of other users** | BR-22 | Trainer within their cohort; admin |
| `enrollments.final_score` / `attendance_rate` **of other users** | BR-22 | Same |
| `retired_identifiers.value_hash` | A hash oracle for "was this address ever registered" | None |
| `users.session_epoch`, any `*_secret`, any env value | Internal | None |
| `cohorts.landing_setting.*` internals beyond what the page renders | Minor, but reduces surface | Admin |

### Enforcement — three mechanisms, because a list in a document is not a control

**1. Explicit `select` allow-lists. Never return an entity.**

```ts
// /features/users/dto.ts
export const PUBLIC_PROFILE_SELECT = {
  id: true,
  profile: { select: { firstNameAr: true, lastNameAr: true, avatarUrl: true } },
} as const satisfies Prisma.UserSelect;
// There is no `password_hash: false`. The field is simply not named — a deny-list can be
// out-of-date; an allow-list cannot include what nobody wrote down.
```

**2. A response-serialisation tripwire.**

```ts
const FORBIDDEN_KEYS = new Set([
  'password_hash','passwordHash','token_hash','tokenHash','qr_token','qrToken',
  'verify_code','verifyCode','zoom_url','zoomUrl','zoom_passcode','zoomPasscode',
  'failed_login_count','locked_until','ip_address','user_agent','value_hash','jti',
]);

/** Wraps every JSON response. Costs one deep walk; catches a whole class of leak. */
export function assertNoForbiddenKeys(body: unknown, route: string): void {
  walk(body, (key, path) => {
    if (FORBIDDEN_KEYS.has(key)) {
      // In production: strip, log CRITICAL, alert. In dev/CI: throw, so it never merges.
      throw new ResponseLeakError(`${route} would return forbidden field "${key}" at ${path}`);
    }
  });
}
```

**3. A snapshot test over every endpoint's response keys.**

```ts
// tests/api/response-shape.spec.ts
// Every route's response key-set is snapshotted. Adding a field to a Prisma select without
// updating the DTO changes the snapshot and fails review. This is what catches the
// "someone added `select: { user: true }`" regression six months from now.
```

**4. For Next.js specifically — the RSC boundary is an API boundary.**

Props passed from a Server Component to a Client Component are serialised into the flight payload and are visible in the page source. Passing a database row to a client component ships every selected column even if none are rendered. The rule: **entities never cross the boundary; DTOs do.** The `IMP.rsc_payload` and `BR-24.zoom.not_in_payload` tests (§3.9, §2.6) assert this by fetching the rendered HTML and grepping for forbidden field names — the only check that actually catches it.

---

## 4.9 BR-32 — "at least one active admin" enforced in the database

§4.3 and BR-32: «يبقى في النظام مدير نظام واحد فعّال على الأقل في كل الأوقات» and «مدير النظام لا يستطيع حذف حسابه الخاص». Application-level checks lose this race: two admins, two browsers, each demoting the other, both checks pass against a state where the other is still an admin.

```sql
-- A partial unique index cannot express "at least one". A trigger with a lock can.
CREATE OR REPLACE FUNCTION assert_admin_floor() RETURNS TRIGGER AS $$
DECLARE n INT;
BEGIN
  -- Serialise all admin-count-affecting writes on one advisory lock.
  PERFORM pg_advisory_xact_lock(hashtext('athar.admin_floor'));
  SELECT count(*) INTO n FROM users
   WHERE platform_role = 'admin' AND status = 'active' AND deleted_at IS NULL;
  IF n < 1 THEN
    RAISE EXCEPTION 'BR-32: at least one active administrator must remain'
      USING ERRCODE = 'restrict_violation';
  END IF;
  RETURN NULL;
END; $$ LANGUAGE plpgsql;

CREATE CONSTRAINT TRIGGER users_admin_floor
  AFTER UPDATE OR DELETE ON users
  DEFERRABLE INITIALLY DEFERRED
  FOR EACH ROW EXECUTE FUNCTION assert_admin_floor();
```

`DEFERRABLE INITIALLY DEFERRED` means the check runs at commit, so a transaction that promotes B and demotes A in either order succeeds, while one that leaves zero admins fails. The advisory lock closes the concurrent-demotion race.

Self-deletion is a separate, simpler rule enforced in the service layer **and** here:

```sql
-- Belt and braces: an admin's own soft-delete is refused at the row level too.
CREATE OR REPLACE FUNCTION assert_not_self_delete() RETURNS TRIGGER AS $$
BEGIN
  IF NEW.status = 'deleted'
     AND current_setting('app.actor_id', true) = OLD.id::text THEN
    RAISE EXCEPTION 'BR-32: an administrator cannot delete their own account';
  END IF;
  RETURN NEW;
END; $$ LANGUAGE plpgsql;
```

**On D1:** SQLite has triggers but no advisory locks and no `current_setting`. The floor check becomes a trigger over a subquery, which is correct under SQLite's single-writer model (D1 serialises writes on one primary, so the race the advisory lock guards against does not arise). Pass the actor id as a bound parameter into a guarded `UPDATE … WHERE id <> ?actor` instead of `current_setting`.

---
---

# 5. Security Controls on Cloudflare Workers

> ### 📌 ملخص القسم بالعربية
> يترجم هذا القسم كل ضابط في §12 إلى تنفيذ محدد على بيئة Cloudflare Workers، مع ذكر ما **لا يعمل** على هذه البيئة صراحةً.
>
> **قرارات جوهرية تفرضها بيئة الحافة:**
> 1. **تجزئة كلمات المرور:** §12.1 و§6.1 يطلبان `argon2id` أو `bcrypt` — ووثائق Cloudflare **تنصّ صراحةً على أن `argon2` غير مدعوم**، و`bcrypt` وحدة أصلية لا تُحمّل. والبديل الشائع `PBKDF2` **محدود بـ 100,000 تكرار فقط داخل Workers — أي سدس ما توصي به OWASP**، وهذا الحد **غير موثّق في صفحة WebCrypto** ولا يُكتشف إلا وقت التشغيل. **الحل الموصى به: `node:crypto.scrypt`** — مدعوم أصليًا، ومقاوم للذاكرة، ومعتمد من OWASP، وبلا سقف تكرارات. **انحراف عن الـ PRD يجب توثيقه واعتماده.** كما أن **الخطة المجانية (10 مللي ثانية معالجة) لا تكفي لأي تجزئة آمنة** — الخطة المدفوعة متطلب إلزامي.
> 2. **تخزين الجلسات:** `Workers KV` **غير صالح** كمصدر حقيقة للجلسات لأن الحذف فيه متسق نهائيًا (eventually consistent)، بينما BR-29 يشترط إبطالًا **فوريًا**. الجلسات في قاعدة البيانات أو في Durable Object.
> 3. **CSRF:** الدفاع الأساسي على الحافة هو **فحص ترويسة `Origin` / `Sec-Fetch-Site`** — بلا تخزين، وبتكلفة صفرية، ويفشل مغلقًا.
> 4. **حدود المعدل:** نقاط الحضور تُحدَّد **لكل حساب فقط ولا تُحدَّد أبدًا لكل IP** — دفعة كاملة في قاعة واحدة تشترك في IP واحد، وحدّ IP سيمنعهم من تسجيل حضورهم.
> 5. **رفع الملفات:** الفحص من البايتات الأولى يتم بعد الرفع إلى مسار `quarantine/` في R2، ثم الترقية بعد التحقق — لأن الرفع المباشر إلى R2 يتجاوز حد حجم جسم الطلب في Worker.

---

## 5.1 Session storage and instant revocation (§12.1)

### What the PRD requires

- Server-side sessions, instantly revocable (§12.1, §6.1)
- `HttpOnly`, `Secure`, `SameSite=Lax`
- 24 hours default, 30 days with "remember me" (§9.3.1)
- Session-id regeneration on login (§12.1 — session-fixation defence)
- «تسجيل الخروج من جميع الأجهزة» (§9.3.2, §9.4.1)
- BR-29: a password change revokes **all** active sessions

### The storage decision

| Option | Instant revocation | Verdict |
|---|---|---|
| **Workers KV** | ❌ KV is eventually consistent; a delete propagates globally on a timescale of up to ~60 seconds | **Rejected.** BR-29 says «تُبطل جميع الجلسات النشطة» — "within a minute" is not revoked, and the case where it matters is an account the user believes is compromised. |
| Stateless JWT | ❌ un-revocable within its TTL | **Rejected** for the same reason. |
| **Primary database row** | ✅ genuinely instant | **Selected.** One indexed read per request, on a path that already queries the database. |
| **Durable Object per user** | ✅ instant, strongly consistent, single-writer | **Selected for the "revoke all" fan-out** and for rate limiting (§5.6). Optional as the session store itself. |

```sql
CREATE TABLE auth_sessions (
  id            UUID PRIMARY KEY,
  token_hash    BYTEA NOT NULL UNIQUE,       -- SHA-256 of the cookie value; the raw token is
                                             -- never stored, so a DB leak yields no sessions
  user_id       UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
  last_seen_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
  expires_at    TIMESTAMPTZ NOT NULL,
  remember_me   BOOLEAN NOT NULL DEFAULT FALSE,
  ip            INET, user_agent TEXT,       -- §9.16 «تسجيل دخول من جهاز جديد»
  revoked_at    TIMESTAMPTZ,
  revoked_reason TEXT,
  CONSTRAINT sessions_ttl CHECK (
    expires_at <= created_at + (CASE WHEN remember_me THEN INTERVAL '30 days'
                                                      ELSE INTERVAL '24 hours' END))
);
CREATE INDEX auth_sessions_user_live_idx ON auth_sessions (user_id)
  WHERE revoked_at IS NULL;
```

The `sessions_ttl` CHECK enforces §9.3.1's two durations **in the database**, so no code path can mint a longer-lived session than the PRD allows.

### Instant revocation — the two paths

```ts
/** §9.3.2 «تسجيل الخروج من جميع الأجهزة» and BR-29. One statement, all devices. */
export async function revokeAllSessions(db: ReadWriteDb, userId: string, reason: string) {
  await db.execute(`
    UPDATE auth_sessions SET revoked_at = now(), revoked_reason = $2
     WHERE user_id = $1 AND revoked_at IS NULL`, [userId, reason]);
  // §3.2.3 — impersonation grants are bound to a parent session, so ON DELETE/revoke
  // cascades kill every derived preview too.
  await db.execute(`
    UPDATE impersonation_sessions SET ended_at = now(), ended_reason = 'parent_revoked'
     WHERE parent_session_id IN (SELECT id FROM auth_sessions WHERE user_id = $1)
       AND ended_at IS NULL`, [userId]);
}
```

### 🟡 Optional performance note, with the caveat that matters

If a session read per request ever becomes measurable (it will not at 60 trainees), cache the decision — **but never cache the revocation**. The safe shape is a `users.session_epoch` integer bumped on any revocation, with the cached decision keyed by `(sessionId, epoch)`. A revocation bumps the epoch, which invalidates every cached decision *by key* rather than by waiting for a TTL. Do not introduce this before it is measured; an unnecessary cache in an auth path is a liability.

### The cookie

```
Set-Cookie: __Host-athar_session=<32-byte base64url>;
            HttpOnly; Secure; SameSite=Lax; Path=/; Max-Age=86400
```

`__Host-` is load-bearing here for a reason specific to this deployment: the platform is `ai.athar-dev.edu.sa` and the centre's main site is `athar-dev.edu.sa`. Without the prefix, a compromise of the **main site** could set a cookie scoped to `.athar-dev.edu.sa` that the platform would read — a session-fixation path that crosses an administrative boundary the platform does not control. `__Host-` forbids a `Domain` attribute entirely, so only `ai.athar-dev.edu.sa` can set this cookie.

### 🔴 GAP-S1 — the PRD's password-hashing requirement is not implementable as written, and the obvious substitute is worse than it looks

§6.1 and §12.1 specify «argon2id أو bcrypt بمعامل كلفة 12 فأعلى». Three findings, each verified against Cloudflare's own documentation and source:

**1. Argon2 is explicitly unsupported.** Cloudflare's `node:crypto` page lists the exceptions to “all `node:crypto` APIs are fully supported in Workers”, and the list names them: **“`argon2` and `argon2Sync` are not supported.”** [W8] Native modules cannot load either — there is no N-API and no `.node` binary support — so `bcrypt` (C++ bindings) and `@node-rs/argon2` (napi-rs) are both out.

**2. 🔴 The obvious fallback, PBKDF2 via WebCrypto, is capped at 100,000 iterations — six times below OWASP's recommendation, and the cap is undocumented.** Requesting more fails at runtime:

```
NotSupportedError: Pbkdf2 failed: iteration counts above 100000 are not supported (requested 600000).
```

The limit is enforced in `workerd`'s `checkPbkdfLimits()` and is fixed at 100,000 on the Cloudflare platform (it is only configurable in a self-hosted `workerd`). Cloudflare's rationale is DoS prevention in a multi-tenant runtime. **`cloudflare/workerd` issue #1346 — “crypto: 100,000 iterations of PBKDF2 is insecure” — has been open since October 2023 with no team response.** [W9a] OWASP currently recommends **600,000 iterations for PBKDF2-HMAC-SHA256**. [W9]

⇒ **PBKDF2 on Workers cannot be made OWASP-compliant.** Any design that reaches for it as the default is shipping a knowingly sub-standard credential store, and this document does not recommend it.

**3. ✅ `node:crypto.scrypt` IS supported — and it is the right answer.** `scrypt`/`scryptSync` are **absent from Cloudflare's exception list**, i.e. fully supported, and this is recent enough that most guidance has not caught up. [W8][W9b] scrypt is memory-hard, is on OWASP's recommended list, has no iteration cap, needs no WebAssembly, and runs natively.

### Recommendation, in priority order

| Rank | Option | Assessment |
|---|---|---|
| **1** | **`node:crypto.scrypt`** with a memory-conscious OWASP profile | **Selected.** Native, supported, no WASM tax, no iteration cap. Requires `nodejs_compat` — which is **on by default for compatibility dates ≥ 2026-08-04**. [W8a] |
| 2 | **Argon2id via WASM** (`argon2-wasi`, or a dedicated hashing Worker behind a service binding) | Closest to the PRD's literal text, but Cloudflare uses a non-standard WASM loading path (`Wasm code generation disallowed by embedder` is a common failure), it inflates bundle size and cold start, and community reports put Argon2 at ~100 ms CPU. Viable; more moving parts. [W8b] |
| 3 | `bcryptjs` / `bcrypt-ts`, work factor ≥ 10 | Portable and OWASP-acceptable, but pure-JS bcrypt is slow (≈81 ms at 10 rounds, ≈2.6 s at 15). |
| 4 | PBKDF2-HMAC-SHA256 at 100,000 | ⚠️ **Last resort, and NOT OWASP-compliant.** Only with compensating controls: strict DO-backed login rate limiting (§5.6), breached-password screening, and a documented migration path. |

### ⚠️ The memory constraint that decides the scrypt parameters

The Workers isolate memory limit is **128 MB**. OWASP's top scrypt profile is `N=2^17, r=8, p=1` — which is **≈128 MiB on its own** and will not fit alongside the runtime. Use a lower-memory profile from OWASP's published range instead, and set `maxmem` explicitly:

```ts
import { scrypt } from 'node:crypto';

// OWASP publishes several equal-strength scrypt profiles trading RAM against CPU.
// N=2^15 (~32 MiB) with p=3 sits inside the 128 MB isolate budget with room to spare.
export const SCRYPT_PARAMS = { N: 32768, r: 8, p: 3, maxmem: 64 * 1024 * 1024 } as const;

export async function hashPassword(pw: string): Promise<string> {
  const salt = crypto.getRandomValues(new Uint8Array(16));
  const dk = await new Promise<Buffer>((res, rej) =>
    // NFKC first: an Arabic-locale keyboard can produce a visually identical password in a
    // different normalisation form, which would otherwise fail login for the rightful owner.
    scrypt(pw.normalize('NFKC'), salt, 32, SCRYPT_PARAMS,
           (e, dk) => (e ? rej(e) : res(dk))));
  // Parameters are stored WITH the hash, so they can be raised later and the hash upgraded
  // transparently on the user's next successful login.
  const { N, r, p } = SCRYPT_PARAMS;
  return `scrypt$${N}$${r}$${p}$${b64(salt)}$${b64(dk)}`;
}
```

Verification uses `crypto.subtle.timingSafeEqual` (§6.6.1), which Workers provides as a documented extension. [W15]

### ⚠️ The Free plan cannot host any serious password hash

The Free plan's CPU limit is **10 ms per invocation**. Every OWASP-strength hash exceeds it. **A Paid plan is a hard prerequisite for this platform**, not an optimisation — note it in the §6.4 environment setup and the §ملحق ب launch checklist.

### Action required

This is a documented deviation from §6.1/§12.1 and must be approved under §ملحق د («أي انحراف عن هذه الوثيقة يجب توثيقه وسببه، ولا يُنفَّذ دون اعتماد»). The substitution to record is: **`argon2id` → `scrypt` (OWASP-recommended, memory-hard, natively supported), with parameters stored per-hash so they can be strengthened without a migration.**

---

## 5.2 CSRF on state-changing routes (§12.3)

At the edge, the strongest CSRF defence is also the cheapest: **check the `Origin`**. It requires no storage, no token minting, no session lookup, and it fails closed.

```ts
// /lib/security/csrf.ts
const ALLOWED_ORIGIN = env.APP_BASE_URL;      // https://ai.athar-dev.edu.sa — BR-36, from config

export function assertSameOrigin(req: Request): void {
  if (['GET','HEAD','OPTIONS'].includes(req.method)) return;   // safe methods

  // Layer 1 — Fetch Metadata. Modern browsers send this and it cannot be forged by script.
  const site = req.headers.get('Sec-Fetch-Site');
  if (site && site !== 'same-origin' && site !== 'none') throw new ForbiddenError('CSRF_ORIGIN');

  // Layer 2 — Origin. Present on every cross-origin request and on all same-origin POSTs.
  const origin = req.headers.get('Origin');
  if (origin) {
    if (origin !== ALLOWED_ORIGIN) throw new ForbiddenError('CSRF_ORIGIN');
    return;
  }

  // Layer 3 — Referer fallback for the rare client that omits Origin.
  const referer = req.headers.get('Referer');
  if (referer && new URL(referer).origin === ALLOWED_ORIGIN) return;

  // FAIL CLOSED. No Origin, no Sec-Fetch-Site, no Referer on a state-changing request
  // is not a browser we need to support; it is a request we should not honour.
  throw new ForbiddenError('CSRF_ORIGIN_MISSING');
}
```

### Why `SameSite=Lax` alone is not enough

§12.1 specifies `SameSite=Lax`, which is correct and necessary — but it is not sufficient here for three reasons:
1. Lax permits cookies on **top-level GET navigations**, so any GET that changes state is unprotected. §6.5/§3.7 already establish that this application has such endpoints unless they are deliberately removed.
2. Lax is a **same-site**, not same-origin, boundary. `athar-dev.edu.sa` and `ai.athar-dev.edu.sa` are the same site. A compromise of the centre's main website is therefore *not* blocked by `SameSite=Lax` — and the main site is outside this project's control.
3. Older browsers in the §13.3 support matrix have inconsistent Lax implementations.

Point 2 is the decisive one for this deployment.

### Defence in depth — a session-bound double-submit token

For the highest-value operations (grade recording, certificate issuance, role change, impersonation start, account deletion), add a token that a same-site attacker cannot read:

```ts
/** HMAC(session_id) — a sibling subdomain cannot compute it without the server key, and
 *  cannot read it either because the cookie is __Host- scoped. */
export async function issueCsrfToken(sessionId: string): Promise<string> {
  const mac = await hmacSha256(env.CSRF_SECRET, sessionId);
  return b64url(mac);
}
export async function verifyCsrfToken(sessionId: string, presented: string): Promise<boolean> {
  return timingSafeEqual(b64urlDecode(presented), await hmacSha256(env.CSRF_SECRET, sessionId));
}
```

Sent in an `X-Athar-CSRF` header (a custom header cannot be set on a cross-origin form post without a preflight, which the browser will block).

---

## 5.3 Security headers — the exact values

```ts
// /lib/security/headers.ts — applied by middleware to every response.
export function securityHeaders(nonce: string, route: RouteKind): HeadersInit {
  const csp = [
    `default-src 'self'`,
    `base-uri 'none'`,                    // blocks <base href> injection redirecting relative URLs
    `object-src 'none'`,                  // no Flash/applet/plugin surface
    `frame-ancestors 'none'`,             // clickjacking — the modern X-Frame-Options
    `form-action 'self'`,                 // a form cannot POST credentials to an attacker
    `script-src 'self' 'nonce-${nonce}' 'strict-dynamic'`,
    `style-src 'self' 'unsafe-inline'`,   // see the note below — deliberate, and explained
    `img-src 'self' data: blob: https://files.ai.athar-dev.edu.sa`,
    `font-src 'self'`,                    // §5.4: fonts are self-hosted, so no Google origin
    `connect-src 'self' https://files.ai.athar-dev.edu.sa wss://ai.athar-dev.edu.sa`,
    `media-src 'self' https://files.ai.athar-dev.edu.sa`,
    `worker-src 'self' blob:`,
    `manifest-src 'self'`,
    `frame-src 'none'`,                   // the Zoom link opens a TAB, never an iframe (§9.10)
    `upgrade-insecure-requests`,
    `report-uri /api/csp-report`,
    `report-to csp-endpoint`,
  ].join('; ');

  return {
    'Content-Security-Policy': csp,
    'Strict-Transport-Security': 'max-age=63072000; includeSubDomains',
    'X-Content-Type-Options': 'nosniff',
    'X-Frame-Options': 'DENY',
    'Referrer-Policy': route === 'token-bearing'
      ? 'no-referrer'                                  // see below — this matters
      : 'strict-origin-when-cross-origin',
    'Permissions-Policy': [
      'accelerometer=()','autoplay=()','camera=()','display-capture=()',
      'encrypted-media=()','fullscreen=(self)','geolocation=()','gyroscope=()',
      'magnetometer=()','microphone=()','midi=()','payment=()',
      'picture-in-picture=()','publickey-credentials-get=()','screen-wake-lock=()',
      'serial=()','usb=()','xr-spatial-tracking=()','interest-cohort=()',
    ].join(', '),
    'Cross-Origin-Opener-Policy': 'same-origin',
    'Cross-Origin-Resource-Policy': 'same-origin',
    ...(route === 'authenticated' ? { 'Cache-Control': 'private, no-store, max-age=0' } : {}),
  };
}
```

### Notes on the choices that are not obvious

**`'strict-dynamic'`.** This is what makes a nonce work with the Next.js App Router. The nonce is placed on the bootstrap script; `'strict-dynamic'` then propagates trust to the chunks that bootstrap loads, so the framework's dynamic imports work without an origin allow-list. In supporting browsers `'strict-dynamic'` causes `'self'` and host allow-lists in `script-src` to be **ignored** — which is the point: the policy becomes "only scripts this page's own trusted code loaded", which is materially stronger than any allow-list.

**The nonce must be per-response.** It is generated in middleware (`crypto.randomUUID()` or 16 random bytes, base64), passed to the renderer, and echoed in the header. **A page with a nonce cannot be cached at the CDN**, because a reused nonce is no nonce at all. For `/dashboard/*` this is already required (`no-store`); for the **landing page**, which §9.1.3 wants server-rendered and fast, the resolution is: the landing page carries **no inline script at all**, so it needs no nonce and can be cached with a `script-src 'self'` policy.

**`style-src 'unsafe-inline'` — a deliberate, explained decision.** §2.3, §9.6.3 and §5.8 specify tilt effects, staggered fade-ins, animated progress bars and confetti. Motion libraries set inline `style` attributes, and Next.js emits inline `<style>` for critical CSS. A nonce-based `style-src` would break all of it. The honest assessment: **inline styles are a much lower risk than inline scripts** — CSS-only exfiltration requires attribute selectors plus an allowed external origin, and `img-src`/`connect-src` are already restricted to two hosts. The XSS control here is `script-src` with nonce + `strict-dynamic`, and that is not weakened by `style-src 'unsafe-inline'`. **Do not "fix" this by adding `unsafe-inline` to `script-src`** — that would be the actual failure.

**`Referrer-Policy: no-referrer` on token-bearing routes.** `/verify/[token]`, `/reset-password/[token]` and `/certificate/verify/[code]` carry a **capability in the URL**. Under the default policy, the full URL is sent as `Referer` to any origin the page links to — and §9.17 requires the certificate page to link to **LinkedIn**. Without `no-referrer`, sharing a certificate hands the verify code to a third party's servers. This is a small header with a real consequence and it is specific to this application's design.

**HSTS scope.** `includeSubDomains` on `ai.athar-dev.edu.sa` protects only that host's subtree. The centre should also set HSTS with `preload` on the apex `athar-dev.edu.sa` — otherwise a plain-HTTP request to a sibling subdomain remains a cookie-injection vector against the parent domain. That is outside the platform's control, so it belongs in the §ملحق ب launch checklist as an item for the centre's IT, together with the `__Host-` mitigation already in place (§5.1).

---

## 5.4 XSS sanitisation for user content (§12.3)

### Where user-authored content actually appears

| Source | Rendered to | Risk |
|---|---|---|
| Trainer feedback (§9.15.3 — «كاملة غير مقتطعة») | Participant's grades page | High — a trainer is not necessarily trusted against a compromised trainer account |
| Chat messages (§9.13) | All cohort members | High — 60 recipients |
| Assignment description (§9.11.3 — «محرر نصي بسيط») | All participants | High — a rich-text editor implies **HTML** |
| Participant's submission note | Trainer | Medium |
| Manual attendance edit reasons, cancellation reasons | Admin/trainer views | Medium |
| Landing page FAQ / hero text (§9.1.2, admin-authored) | Public | Medium |
| Names | Everywhere, incl. the certificate PDF | See §6.9 E-3 |

### The recommendation: do not store HTML at all

**Store a restricted Markdown subset; render to React elements, never to an HTML string.** React escapes text nodes by construction, so if no code path produces an HTML string, XSS in user content is **structurally impossible** rather than filtered.

```ts
// The permitted subset — enough for §9.11.3's "simple rich text editor", nothing more.
const ALLOWED = ['paragraph','strong','emphasis','inlineCode','code',
                 'list','listItem','link','break','blockquote'] as const;

export function renderUserMarkdown(src: string): ReactNode {
  const tree = parseMarkdown(stripUnsafeControls(src));   // §6.9 bidi strip first
  return toReact(tree, {
    allow: ALLOWED,                       // everything else is dropped, including raw HTML
    allowRawHtml: false,                  // ← the whole point
    link: (href, children) => {
      const u = safeUrl(href);            // https: only; rejects javascript:, data:, vbscript:
      return u ? <a href={u} target="_blank" rel="noopener noreferrer nofollow">{children}</a>
               : <span>{children}</span>; // an unsafe URL renders as inert text
    },
  });
}

export function safeUrl(raw: string): string | null {
  try {
    const u = new URL(raw, env.APP_BASE_URL);
    return (u.protocol === 'https:' || u.protocol === 'mailto:') ? u.toString() : null;
  } catch { return null; }
}
```

Backed by a hard ban:

```jsonc
"react/no-danger": "error",
"no-restricted-syntax": ["error", {
  "selector": "JSXAttribute[name.name='dangerouslySetInnerHTML']",
  "message": "§12.3: user content is rendered as React nodes, never as HTML. See /lib/markdown."
}]
```

### If HTML sanitisation is unavoidable — what works and what does not on Workers

| Library | Works on Workers? | Verified notes |
|---|---|---|
| `dompurify` | ❌ | Requires a real DOM (`window`/`document`). Workers has neither. |
| `isomorphic-dompurify` | ❌ **actively broken** | Bundlers resolve its `browser` entry in edge contexts, so `window` is undefined. **`cloudflare/workerd` issue #5752 — “isomorphic-dompurify fails when used in CF workers” — is open and assigned.** [W10a] Do not attempt this; it fails at build or at runtime, not gracefully. |
| The standard `Sanitizer` API | ❌ **not supported, not planned** | `ReferenceError: Sanitizer is not defined`. Cloudflare's runtime lead: “The Sanitizer API is part of the DOM API more generally… it would be a massive project. **We don't currently have any plans to do this.**” [W10b] |
| **`xss` (js-xss)** | ✅ **first choice** | Pure JavaScript, **zero Node dependencies**, whitelist-based `filterXSS()`, small, works on any compatibility date. [W10c] |
| **`sanitize-html`** | ✅ **now works** | The historic `process is not defined` failure is gone: `nodejs_compat` is **on by default for compatibility dates ≥ 2026-08-04**. [W8a] Richer policy configuration; larger bundle. |
| `HTMLRewriter` | ⚠️ **not a sanitiser** | Native and excellent for *transformation*, but: it takes a `Response`, not a string; and “a lexical tree text node can be represented by **multiple chunks**” as they stream, which makes naive content checks unsound. It ships **no allow-list** — you would be writing an XSS policy from scratch, which is a bad idea. [W10] |
| `@cloudflare/sanitize` | ❌ **does not exist** | No such package is published. Cloudflare's `har-sanitizer` repository is for HAR files and is unrelated. **Do not plan around it.** |

**Recommended order: (1) Markdown-to-React — no HTML stored anywhere, so XSS is structurally impossible. (2) If HTML must be stored, `xss` (js-xss) as the sanitiser. (3) `sanitize-html` where its richer policy model earns the bundle weight. (4) `HTMLRewriter` for transformation only — rewriting links, injecting nonces — never as the XSS boundary. (5) Never DOMPurify, `isomorphic-dompurify`, or `jsdom` at the edge.**

Whichever is chosen: **sanitise on write, escape on render, and set a strict CSP.** Three independent layers, because no single sanitiser has ever stayed correct forever.

### The other XSS surfaces in this application

- **SVG**: §9.4.1 already restricts avatars to JPG/PNG/WebP — correct, keep it. All other user files are served from `files.ai.athar-dev.edu.sa` as attachments (§5.7), so an SVG submission cannot execute in the app origin.
- **GitHub URL** (§9.11.2): validated server-side against `^https://github\.com/…` and rendered with `rel="noopener noreferrer nofollow"`.
- **Notification `link`** (§7.6): constrained to relative paths by a DB CHECK (§6.9 E-4).
- **The PDF certificate template** (§6.9 E-5): escape every interpolated value; disable remote resource loading in the renderer.

---

## 5.5 SQL injection (§12.3)

Prisma and Drizzle parameterise by default, so injection reaches this application only through four specific doors. Close each explicitly.

**1. Raw query escape hatches — ban them.**

```jsonc
"no-restricted-syntax": ["error",
  { "selector": "CallExpression[callee.property.name='$queryRawUnsafe']",
    "message": "§12.3: parameterised queries only." },
  { "selector": "CallExpression[callee.property.name='$executeRawUnsafe']",
    "message": "§12.3: parameterised queries only." },
  { "selector": "MemberExpression[object.name='sql'][property.name='raw']",
    "message": "§12.3: sql.raw() is unparameterised." }
]
```

**2. Dynamic `ORDER BY` — the one place a parameter cannot help.** §5.8's `Table` component and §9.18's reports both sort by a user-chosen column, and a column name cannot be a bind parameter.

```ts
// Map, don't interpolate. An unknown key yields the default, never a string concatenation.
const SORTABLE = {
  name:       sql`p.first_name_ar`,
  email:      sql`u.email`,
  enrolledAt: sql`e.enrolled_at`,
  rate:       sql`e.attendance_rate`,
  score:      sql`e.final_score`,
} as const satisfies Record<string, SQL>;

const SortSchema = z.object({
  by:  z.enum(Object.keys(SORTABLE) as [keyof typeof SORTABLE, ...(keyof typeof SORTABLE)[]])
        .default('name'),
  dir: z.enum(['asc','desc']).default('asc'),
});
```

**3. `LIKE` search** (§9.12 «بحث نصي», §9.13.2 «بحث نصي داخل المحادثات»): escape `%`, `_` and `\` in the user's term — otherwise `%` is a wildcard that turns a search into a full-table scan, and a long pattern of `%` characters is a cheap denial-of-service.

```ts
const escapeLike = (s: string) => s.replace(/[\\%_]/g, c => `\\${c}`);
```

**4. Every query parameter through Zod** (§6.3 already requires this) — including pagination bounds, so `?limit=999999999` cannot exhaust the Worker's memory or CPU limit.

---

## 5.6 Rate limiting — §12.4's table, implemented at the edge

### The two-tier design

| Tier | Mechanism | Purpose |
|---|---|---|
| **Zone-level WAF rate limiting** | Cloudflare dashboard rules on `/api/*` and `/login` | Coarse per-IP volumetric protection. Runs **before** the Worker, so it costs no CPU and absorbs floods. |
| Native Workers **Rate Limiting binding** | `ratelimit` binding (GA since Sept 2025) | Cheap abuse dampening only. **Two disqualifying caveats for §12.4's limits** — see below. |
| **Durable Object token bucket** ← **the one that implements §12.4** | A DO per `(scope, action)` key | Strongly consistent and single-writer, so “5 per account per 15 minutes” is exactly 5. [W12] |

### 🔴 Why the native Rate Limiting binding cannot implement §12.4's table

Cloudflare's own documentation for the `ratelimit` binding states two properties that rule it out for the limits this PRD specifies: [W12a]

1. **The limit is per-datacentre, not global.** “For each unique key you pass to your rate limiting binding, there is a unique limit **per Cloudflare location**.” The effective global limit is therefore roughly `limit × the number of colos serving your traffic`. §12.4's «5 محاولات لكل حساب في 15 دقيقة» becomes “5 per colo”, so an attacker who rotates through datacentres gets many multiples of the intended budget — and the login lockout in §9.3.1 stops meaning anything.
2. **It is explicitly not an accounting system.** Cloudflare: “permissive, eventually consistent, and **intentionally designed to not be used as an accurate accounting system**.”
3. Its `period` must be exactly **10 or 60 seconds**, which cannot express §12.4's 15-minute and 1-hour windows at all.

⇒ **Use the binding as an outer, cheap dampener; implement every limit in §12.4 with a Durable Object.** The DO's single-threaded, globally-unique instance is precisely the property a correct account lockout needs.

### 🔴 The rule that matters most here: attendance limits are **per-account only**

§12.4 lists «تسجيل الحضور والانصراف | 10 طلبات لكل مستخدم في الدقيقة» — correctly keyed per user. **This must never be supplemented with a per-IP limit on the attendance endpoints.**

A training cohort attending from one room, one office, or one campus shares a single public IP. Sixty trainees pressing «تسجيل الحضور» at 5:30 pm is sixty requests from one IP inside a few seconds. A per-IP limit would refuse most of them, and because the check-in window is finite, **some of those people would lose attendance credit for a session they physically attended** — the exact harm §18 rates as high impact.

The same applies, less acutely, to file upload and message sending. Per-account limits protect the platform; per-IP limits on authenticated actions punish shared networks.

### The configuration table

```ts
export const RATE_LIMITS = {
  // §12.4 row 1 — per ACCOUNT (an attacker rotating IPs must not get 5 tries per IP)
  'auth.login':          { key: 'account', limit: 5,   windowMs: 15 * 60_000 },
  // …plus a per-IP layer on login only, because pre-auth there is no account to key on
  'auth.login.ip':       { key: 'ip',      limit: 30,  windowMs: 15 * 60_000 },
  'auth.register':       { key: 'ip',      limit: 5,   windowMs: 60 * 60_000 },  // §12.4 row 2
  'auth.reset':          { key: 'email',   limit: 3,   windowMs: 60 * 60_000 },  // §12.4 row 3
  'attendance.mark':     { key: 'account', limit: 10,  windowMs: 60_000 },       // §12.4 row 4
  //                       ▲ account ONLY. Never 'ip'. See above.
  'files.upload':        { key: 'account', limit: 20,  windowMs: 60 * 60_000 },  // §12.4 row 5
  'messages.send':       { key: 'account', limit: 30,  windowMs: 60_000 },       // §12.4 row 6
  'public.read':         { key: 'ip',      limit: 100, windowMs: 60_000 },       // §12.4 row 7
  // Added by this document:
  'certificate.verify':  { key: 'ip',      limit: 10,  windowMs: 60_000 },       // §6.3
  'card.verify':         { key: 'ip',      limit: 30,  windowMs: 60_000 },       // §6.3
  'impersonation.start': { key: 'account', limit: 10,  windowMs: 60 * 60_000 },  // §3
} as const satisfies Record<string, RateLimitRule>;
```

### The Durable Object

```ts
export class RateLimiter implements DurableObject {
  private tokens = 0;
  private refilledAt = 0;

  async fetch(req: Request): Promise<Response> {
    const { limit, windowMs } = await req.json<RateLimitRule>();
    // Storage read is the I/O that advances the frozen clock (§1.7) — so Date.now() here
    // is the time of THIS read, which is exactly what a token bucket needs.
    const st = await this.state.storage.get<{ tokens: number; at: number }>('b');
    const now = Date.now();
    const at  = st?.at ?? now;
    const refill = ((now - at) / windowMs) * limit;
    const tokens = Math.min(limit, (st?.tokens ?? limit) + refill);

    if (tokens < 1) {
      const retryAfter = Math.ceil(((1 - tokens) * windowMs) / limit / 1000);
      return new Response(null, { status: 429, headers: { 'Retry-After': String(retryAfter) } });
    }
    await this.state.storage.put('b', { tokens: tokens - 1, at: now });
    return new Response(null, { status: 200 });
  }
}
```

Two details that matter:
- The DO's own storage read is I/O, which is what makes `Date.now()` inside the DO meaningful (§1.7). A token bucket implemented in a plain Worker with no I/O would see a frozen clock and never refill.
- The `Retry-After` value feeds §9.3.1's «قفل مؤقت لمدة 15 دقيقة **مع عرض المدة المتبقية**» — the countdown the PRD requires comes from the limiter, not from a guess.

### Getting the client IP

```ts
// The ONLY trustworthy source on Cloudflare. X-Forwarded-For is attacker-controlled.
const ip = req.headers.get('CF-Connecting-IP');
```

### 🟡 Impersonation and rate limits

Repeating §3.4 row 11 because it is the failure that hurts a real person: **rate-limit keys during impersonation use the ADMIN's id, never the subject's.** Otherwise an admin previewing a trainee at 5:29 pm can exhaust that trainee's `attendance.mark` budget and lock them out of checking in to a live session.

---

## 5.7 File-upload security (§12.5, §9.11.2)

### The edge constraint that shapes the whole design

A Worker has a request-body size limit and a CPU-time limit; streaming a 25 MB upload through the isolate to validate it is wasteful and, for the plan tiers likely in use, may not be possible at all. But §12.5 requires MIME sniffing **from content**, which needs the bytes.

### The resolution — quarantine, then promote

```
1. POST /api/uploads/init         → server validates the assignment, the actor's scope, the
                                    declared size against max_file_size_mb, and the file count.
                                    Returns a presigned PUT into  r2://athar-files/quarantine/<uuid>
                                    with a 10-minute expiry.

2. Browser PUTs directly to R2    → bypasses the Worker body limit entirely; the upload
                                    progress bar §9.11.2 requires is the browser's own.

3. POST /api/uploads/finalize     → the Worker reads the FIRST 4 KB of the quarantined object
                                    (an R2 ranged GET — cheap), sniffs the magic bytes,
                                    verifies the ACTUAL object size from R2 metadata (so a
                                    lying Content-Length in step 1 is caught here), and either
                                      • copies to  submissions/<cohort>/<uuid>  and creates the
                                        submission row, or
                                      • deletes the object and returns
                                        «صيغة هذا الملف غير مسموح بها لأسباب أمنية»

4. Lifecycle rule                 → anything left in quarantine/ for 24 h is deleted.
                                    This is also what makes §14.1's «انقطاع الاتصال أثناء رفع
                                    ملف» harmless: an interrupted upload leaves an orphan
                                    object and NO submission row.
```

The submission row is created **only in step 3**, after validation. There is no window in which a partially-uploaded or unvalidated file is visible as a submission.

### Magic-byte sniffing in a Worker

```ts
// /lib/storage/sniff.ts — pure, dependency-free, ~40 signatures. No Node APIs.
const EXECUTABLE_SIGNATURES: ReadonlyArray<{ sig: readonly number[]; label: string }> = [
  { sig: [0x4D,0x5A],                     label: 'PE/DOS executable (MZ)' },
  { sig: [0x7F,0x45,0x4C,0x46],           label: 'ELF executable' },
  { sig: [0xFE,0xED,0xFA,0xCE],           label: 'Mach-O 32' },
  { sig: [0xFE,0xED,0xFA,0xCF],           label: 'Mach-O 64' },
  { sig: [0xCA,0xFE,0xBA,0xBE],           label: 'Java class / Mach-O fat' },
  { sig: [0x23,0x21],                     label: 'shebang script (#!)' },
  { sig: [0xD0,0xCF,0x11,0xE0],           label: 'OLE2 (legacy Office — macro carrier)' },
];

export function sniff(head: Uint8Array): SniffResult {
  for (const { sig, label } of EXECUTABLE_SIGNATURES) {
    if (sig.every((b, i) => head[i] === b)) return { ok: false, reason: label };
  }
  // Reject anything whose first bytes look like markup, whatever it claims to be.
  const text = new TextDecoder('utf-8', { fatal: false }).decode(head.subarray(0, 512)).trimStart();
  if (/^(<!doctype html|<html|<script|<svg|<\?php|<%)/i.test(text)) {
    return { ok: false, reason: 'markup/script content' };
  }
  return { ok: true, detected: detectType(head) };   // pdf, png, jpeg, zip, docx, …
}
```

If a maintained library is preferred over hand-rolled signatures, two work on Workers:
- **`file-type`** — confirmed working; use **`fileTypeFromBuffer()`** with a `Uint8Array` (the ESM, non-Node-stream entry point). Avoid `fileTypeFromFile()`, which touches `node:fs`. [W13]
- **`magic-bytes.js`** — zero dependencies, browser and Node, `filetypeinfo(uint8array)`; explicitly designed so that “it may be enough to load the file's first 100 bytes and validate against them”, which is exactly the quarantine flow above. [W13a]

For an allow-list of five or six formats the hand-rolled function above is often the better answer: it has no supply-chain surface and no bundle cost. In every case, **never trust the client-supplied `Content-Type` or the filename extension** — sniff the bytes and validate the sniffed type against an allow-list.

### The control that actually protects people: a separate origin

§9.11.2 says «يُقبل رفع الملفات **بأي صيغة**». Sniffing therefore cannot be an allow-list, and eventually an HTML or SVG file will be stored. **The decisive control is that user files are never served from the application origin.**

```
files.ai.athar-dev.edu.sa      ← R2 custom domain. No cookies. No session. No CSP relationship.
  Content-Disposition: attachment; filename="<sanitised original name>"
  X-Content-Type-Options: nosniff
  Content-Type: application/octet-stream   (for anything not on a small render-safe list)
  Cross-Origin-Resource-Policy: same-site
```

Without this, a trainee uploads `report.html` containing a script; a trainer clicks "preview"; the script runs on `ai.athar-dev.edu.sa` with the trainer's session and exfiltrates the whole cohort's grades. **This is the highest-severity finding in the upload path, and it follows directly from §9.11.2's own "any format" requirement.**

### Storage naming and access

- **Random keys:** `submissions/{cohortId}/{crypto.randomUUID()}` — §12.5 «أسماء الملفات المخزنة عشوائية، والاسم الأصلي يُحفظ في قاعدة البيانات فقط». The original name is returned only in the `Content-Disposition` of an authorized download, sanitised of path separators, control characters and bidi marks (§6.9).
- **Download:** the Worker-mediated route (§6.2 defence 1) is the default — authorization is re-evaluated on every request, so there is no bearer token to replay. Presigned URLs are used only for large media, with `TTL = 15 minutes` (§12.5) and session binding.
- **Size:** enforced twice — declared at `init`, verified against R2's actual object size at `finalize`. §14.1's «حجم يساوي الحد الأقصى بالضبط» is `<=`, inclusive.
- **Count:** `max_files` (§7.5) checked at `finalize` against the current version's file array, inside the same transaction that creates the submission row.
- **Archives are never extracted** (§6.7 layer 4).

---

## 5.8 Cron triggers and scheduled work

§9.9.5's sweep runs every 15 minutes; §9.16 requires session reminders at 24 h and 1 h, and assignment reminders at 48 h and 6 h.

```jsonc
// wrangler.jsonc
"triggers": { "crons": ["*/15 * * * *", "*/5 * * * *"] }
// Cron expressions are UTC. Because Asia/Riyadh is a fixed +03:00 (§1.6), a "every 15
// minutes" schedule is timezone-independent. A schedule expressed in Riyadh wall-clock
// terms (e.g. "every day at 08:00 Riyadh") must be written as 05:00 UTC — and must be
// re-derived if the zone ever changes. Prefer interval schedules over wall-clock ones.
```

### 🔴 The execution guarantee is **undocumented** — design as if there is none

Cloudflare documents that Cron Triggers run on **UTC**, that the maximum frequency is **every minute**, and that the account limit is 5 triggers (Free) / 250 (Paid) with 30 s CPU for intervals under an hour. [W14] What it does **not** document anywhere is whether a cron invocation is exactly-once or at-least-once. The `scheduled()` handler exposes `controller.noRetry()`, which implies some retry mechanism exists — but its policy is unstated. Config changes also take up to 15 minutes to propagate, and only the 100 most recent invocations are retained in history.

**The one Cloudflare primitive with a documented delivery guarantee is the Durable Object Alarm:** “Alarms have guaranteed at-least-once execution and are retried automatically when the `alarm()` handler throws… exponential backoff starting at a 2 second delay from the first failure with up to 6 retries.” [W14a]

⇒ **Use the Cron Trigger only as a tick.** The handler must be idempotent (§1.5 T1–T3 already are), and any work that must not be lost — reminder emails, certificate issuance batches — belongs behind a Durable Object Alarm or a Queue, not directly in the cron handler.

Because delivery is unguaranteed in both directions, the reminder dispatcher needs the same conditional treatment as the sweep:

```sql
-- A reminder is claimed atomically. Two overlapping cron runs cannot double-send.
UPDATE notification_queue
   SET sent_at = now(), claimed_by = $1
 WHERE id = $2 AND sent_at IS NULL
RETURNING id;    -- zero rows ⇒ another runner already claimed it ⇒ do nothing
```

And, repeating HARDENING-A5 because it is the failure with no symptom: **alert when no sweep has succeeded in 45 minutes.** A dead cron silently stops finalising attendance, and nothing surfaces until certificates are issued against incomplete data.

---

## 5.9 PDPL — Saudi Personal Data Protection Law compliance

> ### 📌 ملخص
> §12.6 يطلب «التوافق مع نظام حماية البيانات الشخصية في المملكة» في سطر واحد. هذا القسم يحوّله إلى التزامات محددة بأرقام مواد ومواعيد دقيقة.
>
> **النظام نافذ فعليًا منذ 14 سبتمبر 2023، وانتهت فترة التوفيق في 14 سبتمبر 2024**، والتطبيق جارٍ (48 قرارًا صادرًا عن لجان سدايا خلال السنة الماضية). الغرامات: حتى **3 ملايين ريال جزائيًا** و**5 ملايين إداريًا**، تُضاعف عند التكرار.
>
> **أخطر نقطة — والنتيجة مفاجئة:** النظام السعودي **لا يفرض توطينًا عامًا للبيانات** في القطاع الخاص — المادة 29 نظام إجازة مشروطة لا حظر. **لكن** Cloudflare **لا توفّر تخزينًا داخل المملكة إطلاقًا**: وثائقها تنصّ على أن **«D1 لا تعمل في الشرق الأوسط»**، وR2 وKV كذلك.
>
> المسار القانوني الوحيد العملي: **المادة 29(1)(د) + البنود التعاقدية المعيارية السعودية (النموذج 2) + تقييم مخاطر نقل إلزامي**. **والعقبة تعاقدية لا تقنية:** هذه البنود **ممنوع تعديلها** (أي تعديل يُعدّ مخالفة للنظام)، وتلزم المستورد **بالخضوع لاختصاص القضاء السعودي والتعاون مع تدقيق سدايا**. **يجب التأكد من استعداد Cloudflare للتوقيع قبل تثبيت المعمارية — في المرحلة صفر.**
>
> **التزامان أغفلهما الـ PRD تمامًا:** ① **التسجيل في السجل الوطني للجهات المتحكمة** — وهو شرط عملي للتمكّن من الإبلاغ عن تسرّب خلال 72 ساعة أصلًا؛ ② **تقييم أثر حماية البيانات (DPIA)** إلزامي هنا بسبب ميزة معاينة الحسابات، **ويجب تسليم نسخة منه للمعالج**.

### 5.9.1 Scope and the platform's role

The centre («مركز أثر») is the **data controller**. It determines why and how trainee personal data is processed. Cloudflare, the email provider, and any error-tracking service are **processors**, and each requires a data-processing agreement.

The personal data this platform processes:

| Category | Fields | Sensitivity |
|---|---|---|
| Identity | four-part Arabic + English names, gender, birth date | Standard |
| Contact | email, phone | Standard, and phone is a strong identifier |
| Account | password hash, login timestamps, IP, user agent | Security-relevant |
| Educational record | attendance, grades, feedback, submissions, certificates | **The core purpose; long retention** |
| Communications | chat messages, including private trainer DMs | **Confidential — and visible to admins in preview mode (§4.5.2)** |
| Behavioural | journey progress, download counters, QR scan counts | Minimal |

### 5.9.2 Status: the law is in force and actively enforced

| Milestone | Date |
|---|---|
| PDPL issued — Royal Decree **M/19** | 16 Sep 2021 |
| Amended — Royal Decree **M/148** (introduced the legitimate-interest basis) | 27 Mar 2023 |
| **In force** (Art 43: 720 days after gazetting) | **14 Sep 2023** |
| **Grace period ended** | **14 Sep 2024** |
| Implementing + Transfer Regulations; Transfer Regulation **v2.0** | Sep 2023; Aug/Sep 2024 |

Enforcement is live: SDAIA's committees issued **48 decisions** in the past reporting year, with warnings, fines and remediation orders — and *marketing without prior consent* is reported as the most widespread violation category. [P5] There is no longer any “the law is new” grace to rely on.

**⚠️ The platform is in scope even if it were hosted entirely abroad.** Art 2(1) applies the law to “the Processing of Personal Data related to individuals **residing in the Kingdom** by any means **from any party outside the Kingdom**.”

### 5.9.3 Obligations, with the exact figures

| Obligation | Source | What this platform must do |
|---|---|---|
| **Lawful basis** | Art 5(1), Art 6 | Consent is the default. Art 6 permits processing without consent on four grounds, including **legitimate interest** — but Impl. Reg. Art 16 requires a **documented Legitimate Interest Assessment before processing**, forbids LI for sensitive data, and requires the processing to be “within the **reasonable expectations** of the Data Subject”. For a training platform, delivering the programme the trainee enrolled in sits comfortably under Art 6(2) (“in implementation of a previous agreement to which the Data Subject is a party”) — **use that**, not consent, for the core service; keep consent for optional extras. |
| **Consent mechanics** | Impl. Reg. Art 11, Art 12; PDPL Art 7 | Consent may be electronic. **“A separate consent shall be obtained for each Processing purpose”**, and it must be **documented for future verification** — so §9.2.1's checkbox needs a stored record: `consents(user_id, purpose, policy_version, consented_at, ip)`. **Explicit** consent is required only for **sensitive data, credit data, and solely-automated decisions** (Art 11(2)). Art 7 forbids bundling. Withdrawal must be **as easy as giving** it (Art 12). |
| **Data-subject rights** | **Art 4 — five rights only** | (1) be informed of the legal basis and purpose; (2) access; (3) **obtain a copy in a readable and clear format**; (4) request correction/completion/update; (5) request destruction. ⚠️ **There is no right to object, no general right to restriction, no standalone automated-decision right, and — importantly — no true GDPR-style portability.** Right (3) is a *copy* right, rendered by Impl. Reg. Art 6(2) as “a commonly used electronic format”. §12.6's «إمكانية تصدير المستخدم لبياناته» satisfies it. |
| **Response deadline** | Impl. Reg. Art 3(1)(a) | **30 days**, extendable **once by a further 30 days** where the request requires disproportionate effort, with **advance notice of the extension and its reasons**. Verify the requester's identity; **log every request, including oral ones**. |
| **🔴 Breach notification — SDAIA** | Impl. Reg. **Art 24(1)** | **Within 72 hours** of becoming aware, where the incident “potentially causes harm”. Contents: description incl. **when the controller became aware**; data categories and number of affected subjects; risk and impact; measures taken; **whether data subjects were notified**; controller/DPO contact. If incomplete at 72 h, supply the rest “as soon as possible with justification for the delay”. Filed **through the National Data Governance Platform** — so **registration is a practical prerequisite for being able to report at all**. [P6] |
| **Breach notification — data subjects** | Impl. Reg. Art 24(5) | **Without undue delay**, in “simple and clear language”, including **recommendations to help the subject mitigate risk**. |
| **Breach definition** | Impl. Reg. Art 1(3) | “Any incident that leads to the Disclosure, Destruction, or unauthorized access to Personal Data, **whether intentional or accidental**” — **no risk threshold in the definition itself**; the harm test lives in the notification trigger. Broader than GDPR. |
| **RoPA** | PDPL Art 31, Impl. Reg. Art 33 | Mandatory, **written**, kept **until five years after the processing activity ends**, available to SDAIA on request. Eight required contents — including **retention periods per category** and a **description of transfers outside the Kingdom with their legal basis and recipients**. |
| **DPO** | Impl. Reg. Art 32(1) | Required if **any** of: a public entity processing at large scale; **core activities require regular and systematic monitoring**; or **core activities involve sensitive data**. The appointment rules define “core” as activities without which the controller “cannot provide products or services” — and state that **HR/employee processing is expressly not core**. Appointment must be **in writing**, and **contact details reported to SDAIA immediately via the Platform**. [P7] |
| **🔴 DPIA** | PDPL Art 22, Impl. Reg. **Art 25** | Four triggers, and **(a) has no scale qualifier — ANY processing of sensitive data requires a written DPIA**. The others: linking **two or more datasets from different sources**; large-scale repetitive processing of persons lacking legal capacity, **constant monitoring**, **newly adopted technologies**, or automated decisions; and any product likely to cause serious harm to privacy. ⚠️ **Art 25(3) requires the controller to give a COPY of the DPIA to the processor** — an obligation with no GDPR equivalent and real commercial sensitivity. |
| **🔴 National Register** | Rules under PDPL Art 30(4)(c) + Impl. Reg. Art 34 | **Registration on the National Data Governance Platform is mandatory** if any of: the controller is a public entity; **its main activity is based on personal-data processing**; it processes **sensitive data**; or an individual processes beyond personal/family use. Certificate valid up to **5 years**. [P8] **For this platform the second limb is arguable and the safest reading is that registration applies** — and in any case breach reporting and DPO notification both run through the Platform. Add to the §ملحق ب launch checklist. |
| **Privacy policy** | PDPL Art 12, Art 13; Impl. Reg. Art 4 | Available **before** collection. Art 13 requires stating which fields are **mandatory vs optional**, the entities to which data will be disclosed, and **“whether the Personal Data will be transferred, disclosed or processed outside the Kingdom”**. Impl. Reg. Art 4 adds: DPO contact where applicable, **the storage period or the criteria for determining it**, and **how to withdraw consent**. SDAIA's guideline lists ten elements including a **complaint and objection filing mechanism**. [P9] |
| **Retention & destruction** | PDPL Art 11(4), Art 18; Impl. Reg. Art 9 | Destroy **without undue delay** when no longer necessary; data may be kept only if it “does not contain anything that may lead to specifically identifying” the subject. Art 18(2) preserves retention where a legal basis or judicial proceedings require it — **this is the ground on which certificate records are retained against an erasure request (GAP-D5)**. “Destruction” means **unreadable and irretrievable**; Impl. Reg. Art 8(2)(c) extends it to **backups**. Properly anonymised data “shall no longer be considered as Personal Data”. |
| **Security measures** | PDPL Art 19; Impl. Reg. Art 23 | Organisational, administrative and technical measures **including during transfer**; controllers must **adopt NCA controls** or recognised best practice. §12 plus this document is the technical answer. |
| **Processor agreements** | Impl. Reg. Art 17 | Must cover purpose, categories, duration, **breach notification without undue delay**, **whether the processor is subject to foreign laws affecting compliance**, and **sub-processor identification with prior controller approval**. ⚠️ **Art 17(4): a processor that exceeds the controller's instructions is deemed a controller and is directly liable.** |
| **Identity documents** | PDPL Art 28; Impl. Reg. Art 31 | ⚠️ **Copying official identity documents is prohibited** except where required by law or requested by a competent public authority. If the centre ever wants a copy of a national ID for enrolment verification, this is the article to check first. |
| **Minors** | Impl. Reg. Art 13 | ⚠️ **The PDPL contains no age threshold** — no GDPR Art 8 equivalent, no “under 13”. It uses legal capacity plus **guardian** consent. §3.1 puts trainees at 18–35, so this is unlikely to bite — but **do not implement or cite an age-13 rule**; it is not Saudi law. |
| **Penalties** | Art 35, Art 36 | **Art 35 (criminal):** disclosing or publishing **sensitive data** with intent to harm or for personal benefit — up to **2 years' imprisonment or SAR 3,000,000**, doubled on recidivism. **Art 36 (administrative):** warning or fine up to **SAR 5,000,000** for any other violation, doubled on repeat. ⚠️ **There is no “2% of annual revenue” provision in the PDPL** — a claim that circulates widely and is simply wrong. Art 38 also allows **publication of a judgment summary in local newspapers at the violator's expense**, and Art 40 gives individuals a private right of action for **material or moral** damage. |

### 5.9.4 🔴 BLOCKER-S2 — cross-border transfer: the most consequential unresolved question in the deployment

> ### 📌 ملخص حاسم
> **لا يوجد إلزام عام بتوطين البيانات** للقطاع الخاص في النظام السعودي — المادة 29 تُجيز النقل بشروط. **لكن** Cloudflare D1 وR2 **لا توفّران تخزينًا داخل المملكة إطلاقًا**، والمسار القانوني الوحيد العملي هو **البنود التعاقدية المعيارية السعودية (SCCs) + تقييم مخاطر نقل إلزامي**. والعقبة العملية: **هذه البنود غير قابلة للتعديل**، وتلزم المستورد **بالخضوع لاختصاص القضاء السعودي**، ومقدّمو الخدمات العالميون لا يوقّعونها عادةً. **يجب التأكد من استعداد Cloudflare للتوقيع قبل اعتماد المعمارية — في المرحلة صفر، لا بعد المرحلة الرابعة.**

**Finding 1 — there is no blanket localisation mandate for private-sector personal data.** Art 29 is a *conditional permission* regime structurally similar to GDPR Chapter V, not a residency rule. A localisation mandate would render Transfer Regulation Arts 2–7 meaningless. Separately, and importantly, the **NCA's Cloud Cybersecurity Controls CCC-2:2024 deleted** the two 2020 subcontrols that required cloud services be provided from within the Kingdom, deferring localisation to the NDMO. [P10] **Anyone citing “NCA CCC requires in-Kingdom hosting” is citing the superseded 2020 version.** The NDMO rules that do mandate in-Kingdom storage are scoped to **public entities and partners handling government data** — not a training centre's own trainee records.

**Finding 2 — 🔴 Cloudflare's storage products have no KSA residency, and this is not configurable.**

| Product | KSA storage residency | Evidence |
|---|---|---|
| **D1** | ❌ **No, and it cannot run there** | Jurisdictions are `eu` and `fedramp` only. Cloudflare states: **“D1 location hints are not currently supported for … the Middle East (`me`). D1 databases do not run in these locations.”** [P11] |
| **R2** | ❌ No | Jurisdictions `eu`, `fedramp`, `us`. No Middle East hint. Jurisdiction cannot be changed after bucket creation. [P12] |
| **Workers KV** | ❌ No | “Jurisdictional Restrictions (storage) for Workers KV pairs is not supported today.” |
| **Durable Objects** | ⚠️ Hint accepted, **does not work** | `locationHint: 'me'` is accepted, but: “Durable Objects currently **do not spawn in this location**. Instead, the Durable Object will spawn in a nearby location which does support Durable Objects.” [P13] |
| **Regional Services** | ✅ Saudi Arabia **is** a supported region | — but it governs **TLS termination and HTTP inspection only**, not storage. |

Cloudflare has points of presence in Riyadh and Jeddah, and data at rest is encrypted — but **the data physically leaves the Kingdom, and no Cloudflare setting can prevent that.** That is a lawful cross-border *transfer* to be justified under Art 29; it is not a localisation solution.

**Finding 3 — the only workable legal route, step by step.**

1. **Art 29(1) purpose:** rely on **29(1)(D)** → Transfer Reg. **Art 2(2)** — “to provide a **service or benefit** to the subject of the personal data” (and/or Art 2(1), central processing operations). ✅ A training platform serving the trainee is squarely within this.
2. **Art 29(2)(a):** document that the transfer does not prejudice national security or the Kingdom's vital interests. ✅
3. **Art 29(2)(b) adequacy — ❌ unavailable.** Transfer Reg. Art 3 obliges SDAIA to publish a list of adequate countries, reviewed every four years. **No such list has been published.** Every private-sector outbound transfer today must therefore run on an Art 4 exemption plus a safeguard, not on adequacy.
4. **Pick the Art 4(2) exemption:** (B) needs the transfer to be non-recurring ❌; (C) needs a multinational group ❌; (D) needs the recipient to hold an SDAIA-licensed accreditation certificate ❌. ⇒ **The route is Art 4(1)(A): Saudi Standard Contractual Clauses, Template 2 (Controller → Processor).**
5. **🔴 A Transfer Risk Assessment is mandatory** under Transfer Reg. **Art 7(1)(A)** (any transfer relying on Art 4). Six required elements, including **“the exact geographical location of personal data storage… including the specific country”** and whether it is public or private cloud. [P14]
6. **Collateral obligations:** the privacy notice must state the transfer (Art 13(4)); the RoPA must describe it with its legal basis and recipients (Impl. Reg. Art 33(5)(g)); the processor agreement must address foreign-law exposure and sub-processors (Impl. Reg. Art 17); and **Transfer Reg. Art 5 keeps Cloudflare's own onward transfers in scope**.

**Finding 4 — 🔴 the practical blocker, and it is contractual, not technical.** SDAIA's Standard Contractual Clauses carry two rules that hyperscaler DPAs do not normally accommodate: [P15]

> **Rule 5:** “If any party modifies the approved text … such modifications **shall not be recognized** by the Competent Authority and **shall be deemed a violation** of the provisions of the Law and Regulations.”
> **Rule 8:** “the Personal Data Importer **submits to the jurisdiction of the Kingdom**” and undertakes to enforce binding KSA decisions.
> **Rule 9:** the importer must respond to SDAIA requests and **cooperate with audits**.

⇒ **Before the architecture is fixed, the centre must confirm in writing whether Cloudflare will execute the Saudi SCCs.** If it will not, the personal-data store must move to a provider that will — reached from Workers via Hyperdrive — and the Worker becomes a stateless compute layer holding no personal data at rest.

### Required actions, in order, before Phase 0 completes

1. **Obtain a legal determination** from the centre's counsel. This is not an engineering decision and this document does not purport to give it.
2. **Confirm Cloudflare's willingness to sign the Saudi SCCs** (Template 2, unmodified).
3. **Conduct and file the Transfer Risk Assessment** — Art 7 is mandatory, and it requires naming the exact storage country, which step 2's answer supplies.
4. **Register on the National Data Governance Platform** — also the prerequisite for being able to file a 72-hour breach report at all.
5. **Conduct the DPIA.** The **impersonation feature** (§4.5) — systematic administrative access to individuals' private communications — is the strongest trigger in this system under Art 25(1)(c)/(d). Remember Art 25(3): the DPIA must be **shared with the processor**.
6. **Set the retention period** (§19 open decision #12) and publish it.
7. **Add cross-border transfer to §19 as open decision #13** and to the §ملحق ب launch checklist.

⚠️ **Sectoral note:** if the centre ever processes payment or banking data, SAMA's parallel jurisdiction applies (PDPL Art 30(1) preserves it) and is widely reported to require in-Kingdom residency. That is outside this platform's current scope but would change the analysis entirely.

### 5.9.5 PII must not leak to processors

| Sink | Rule |
|---|---|
| Error tracking (§6.1, §15) | Scrub `email`, `phone`, names, `ip`, and request bodies before send. Report opaque user IDs, never identities. §15 already says «دون تسجيل أي بيانات شخصية». Note that an error tracker is itself a **processor** requiring an Impl. Reg. Art 17 agreement and, if hosted abroad, its own transfer basis. |
| Structured logs | Same. Log `user_id`, `cohort_id`, route, status, duration — never a name or an address. |
| Analytics | No personal identifiers. |
| **Impersonation** | §3.4 row 12 — telemetry attributes to the **admin**, never to the subject. |
| Email templates | §9.16 requires externally hosted images; ensure the image host receives no identifying query parameter (a tracking pixel keyed by user id is a disclosure to that host). |
| Remote access | ⚠️ Under Art 1(8), “Disclosure” includes “enabling any person … to access”. **A developer administering the production database from outside the Kingdom is itself a transfer** and must be covered by the same basis. |

### 5.9.6 What the platform already gets right

§12.6's minimisation principle, the public verify pages' restricted field lists (§9.6.2, §9.17, BR-25), the export right, the audit log, and §4.5.2's insistence that private-conversation visibility be disclosed in the privacy policy all align with the PDPL's transparency and minimisation duties. **§4.5.2's disclosure requirement is not optional politeness — under Art 12 and Art 13 it is a legal obligation**, and administrative access to private messages that is not disclosed before collection is processing without proper notice.

The gaps to close: the retention period (unset), the transfer basis (unaddressed), the consent record (implied but not stored), the National Register entry (not mentioned), the DPIA (not mentioned), and a concrete portability endpoint (promised in §12.6 but unspecified).

---
---

# 6. Abuse Cases & Edge Cases

> ### 📌 ملخص القسم بالعربية
> يغطي هذا القسم كل حالة في §14.1 **بحلّ محدد**، ثم يضيف **اثنتين وعشرين حالة أغفلها الـ PRD**.
>
> **أخطر ما أُضيف:**
> 1. **حقن الصيغ في تصدير Excel** (§9.18): اسم متدرب يبدأ بـ `=` أو `@` يتحول إلى صيغة تُنفَّذ على جهاز المدير عند فتح الملف. تنفيذ أوامر فعلي، لا مجرد إزعاج.
> 2. **مسح روابط البريد يستهلك رمز التفعيل**: Outlook/Defender يفتح كل رابط في الرسالة تلقائيًا للفحص. رابط التفعيل «صالح لمرة واحدة» (§9.2.3) يُستهلك **قبل أن يضغطه المستخدم**. هذه أكثر أعطال الإنتاج شيوعًا في هذا التصميم.
> 3. **حروف التحكم في اتجاه النص (U+202E)** داخل الأسماء: تقلب النص المعروض حولها بصريًا — في قائمة المتدربين، وفي المحادثة، وفي **شريط تنبيه المعاينة**، وعلى **الشهادة المطبوعة**. خطر خاص بمنصة عربية.
> 4. **تسريب رابط الزوم عبر حمولة RSC** حتى دون عرضه في الواجهة.
> 5. **حد المعدل حسب IP على تسجيل الحضور**: دفعة كاملة خلف شبكة واحدة (قاعة تدريب) تُحجب عن تسجيل حضورها.

---

## 6.1 Every §14.1 edge case, with its resolution

| §14.1 case | Resolution | Test |
|---|---|---|
| «تسجيل حضور عند الثانية الأخيرة قبل فتح النافذة وبعدها بثانية» | §1.10 rows 1–4. Closed interval at `S−30m`. | `BR-01.window.open_boundary` |
| «تسجيل حضور عند الدقيقة 30 بالضبط (يجب أن يُحتسب حاضرًا)» | §1.3 R1, `≤` inclusive. §1.10 row 10. | `BR-02.grace.exact` |
| «تسجيل حضور عند الدقيقة 31 (متأخر)» | §1.10 row 14. **Plus the undefined 59.999 s — DISC-2.** | `BR-03.late.minute_31` |
| «تسجيل انصراف عند الحد الأعلى للنافذة بالضبط وبعده بدقيقة» | §1.10 rows 24–26. **This is DISC-1** — the exact bound must pass, contradicting §9.9.3's `9:29`. | `BR-04.checkout.upper_exact` |
| «محاولة انصراف دون حضور» | §1.4 `hasCheckIn` checked first, in SQL. BR-05. | `BR-05.checkout.no_checkin` |
| «إرسال طلبَي تسجيل حضور متزامنين» | §1.8 conditional upsert + unique constraint. Second request is a **no-op returning the same result**, not an error. | `BR-06.concurrent_checkin` |
| «تعديل ساعة الجهاز والمحاولة» | §1.7 — the client clock is never an input. The countdown is rendered from a server-sent absolute instant. | `BR-07.client_clock_ignored` |
| «جلسة تمتد عبر منتصف الليل» | 🔴 **BLOCKER-A7** — currently *forbidden* by §7.7's `end_time > start_time`. Fixed by absolute instants. | `BR-01.midnight_crossing` |
| «تسليم مهمة في الثانية الأخيرة قبل الموعد وبعده بثانية» | `is_late = submitted_at > due_at`, computed with the **DB clock** in the insert statement, same pattern as §1.8. Boundary: `submitted_at = due_at` ⇒ **on time** (`>` not `>=`). | `BR-18.deadline.boundary` |
| «رفع ملف بحجم يساوي الحد الأقصى بالضبط وأكبر منه بقليل» | `size <= max_file_size_mb * 1024 * 1024` — inclusive. Enforced by **counting streamed bytes**, not by trusting `Content-Length` (§5.7). | `FR-9.11.upload.size_boundary` |
| «رفع ملف بامتداد مسموح لكن محتواه تنفيذي» | §5.7 magic-byte sniffing + separate origin + `Content-Disposition: attachment`. | `FR-9.11.upload.polyglot` |
| «محاولة الوصول لتسليم متدرب آخر بتغيير المعرّف» | §2.5 scope-in-predicate, 404 on miss. | `BR-22.submission.idor` |
| «محاولة الوصول لتبويب المشروع الختامي قبل التفعيل» | BR-15/16. Server gate + field not selected. 403 and **no content in the payload**. | `BR-16.project.not_in_payload` |
| «رصد درجة أكبر من الدرجة القصوى» | §4.6 — local CHECK via composite FK. Rejected at the DB, not only in Zod. | `BR-12.score.exceeds_max` |
| «اسم عربي يحتوي همزة أو تاء مربوطة أو ألف مقصورة» | §4.1 GAP-D2 — NFC normalisation + explicit letter allow-list. | `FR-9.2.name.arabic_forms` |
| «اسم يحتوي مسافات في البداية والنهاية» | `normalizeArabicName` trims and collapses **before** validation and before the uniqueness probe. | `FR-9.2.name.trim` |
| «متدرب يلتحق بدفعتين مختلفتين» | §2.2 CONFLICT-P0 + §6.10 matrix below. | `BR-23.two_cohorts.matrix` |
| «انقطاع الاتصال أثناء رفع ملف» | Direct-to-R2 presigned PUT into `quarantine/`; the submission row is created only by the finalize call. An interrupted upload leaves an orphan object that a lifecycle rule deletes after 24 h — **no partial submission ever appears**. | `FR-9.11.upload.interrupted` |
| «محاولة تنفيذ أي عملية كتابة أثناء وضع المعاينة» | §3.3 four layers. | `BR-33.*` (§3.9) |
| «انتهاء مهلة جلسة المعاينة تلقائيًا بعد 30 دقيقة» | §3.6 + the DB `imp_ttl_capped` CHECK. | `IMP.expiry_30min` |
| «محاولة مدير معاينة حساب مدير آخر» | §3.5, re-validated per request. | `BR-35.admin_target_denied` |

---

## 6.2 Replaying a signed URL

**The attack.** §12.5 and §4.3 specify «رابط موقّع مؤقت صالح لمدة 15 دقيقة». A presigned URL is a **bearer token**: whoever holds it has access, with no identity check. A trainee opens their submission's download URL, pastes it into the cohort group chat (§9.13.1 — 60 people), and for 15 minutes everyone has the file. Nothing in the PRD prevents this.

**Defences, in order of strength:**

1. **Worker-mediated download (recommended as the default).** No presigned URL exists at all. `GET /api/files/:id` re-runs the full authorization (§2.5 scope predicate) on **every** request and streams the object from the R2 binding. Revocation is instant; sharing the URL shares nothing, because the recipient's session fails the scope check. This satisfies §4.3's «الروابط المباشرة للملفات لا تُكشف» more literally than a presigned URL does.
2. **Session-bound signed URLs** where streaming through the Worker is undesirable (large recordings): embed `sub=<userId>` and `sid=<sessionIdHash>` in the signed payload and verify them against the request's cookie at redemption. The URL then only works for the person it was minted for.
3. **Single-use nonce** for one-shot downloads: the signature carries a `nonce` recorded in KV/DO with a 15-minute TTL; first redemption consumes it. Suitable for certificate PDFs.
4. **`Referrer-Policy: no-referrer`** on every page that can contain a signed URL, so the URL never leaks through an outbound click.
5. **Never** place a signed URL in an `<img src>` on a page a third party can embed.

**Test:** `SEC.signed_url.replay_by_other_user` — mint a URL as user A, redeem it with user B's cookie, expect 403.

---

## 6.3 Token entropy: QR tokens and certificate verify codes

### The requirement

| Token | Public exposure | Consequence of a guess | Required entropy |
|---|---|---|---|
| `digital_cards.qr_token` | In a printed/screenshotted QR (§9.6.2) | Reveals a trainee's name, programme, cohort, role, issue date (§9.6.2 field list) | **256 bits** |
| `certificates.verify_code` | Typed and shared by humans; sent to employers | Reveals name + programme + completion date; en-masse, it reveals the centre's entire graduate list | **80 bits minimum** |
| `email_tokens.token` (verify / reset) | In an email; single use; 24 h / 30 min | **Full account takeover** | **256 bits** |

### The real risk is enumeration, not brute force

§9.6.2 already gets the key point right: «الرمز عشوائي وطويل وموقّع، **وليس معرّف المستخدم**». The failure mode to prevent is not someone guessing one code — it is someone **counting**. A sequential or timestamp-derived verify code lets an attacker walk `0001, 0002, 0003…` and harvest every graduate's name and programme. That is a bulk PII disclosure, and under PDPL it is a reportable breach (§5.9).

### Specification

```ts
// /lib/tokens.ts
/** Crockford Base32: no I, L, O, U — unambiguous when read aloud or typed, and it cannot
 *  accidentally spell a word. 16 chars × 5 bits = 80 bits. */
const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

export function generateVerifyCode(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(10));   // 80 bits
  let out = '', acc = 0, bits = 0;
  for (const b of bytes) {
    acc = (acc << 8) | b; bits += 8;
    while (bits >= 5) { out += CROCKFORD[(acc >>> (bits - 5)) & 31]; bits -= 5; }
  }
  return out;                                                  // 16 characters
}

/** Displayed as XXXX-XXXX-XXXX-XXXX. Hyphens are cosmetic and stripped on lookup. */
export const formatVerifyCode = (c: string) => c.match(/.{1,4}/g)!.join('-');

/** 256-bit capabilities. Stored as SHA-256; the raw value exists only in the QR / the email. */
export function generateCapabilityToken(): string {
  return base64url(crypto.getRandomValues(new Uint8Array(32)));
}
```

### Why 80 bits is enough for the verify code, shown

Search space `2^80 ≈ 1.2 × 10^24`. At a sustained (and entirely unrealistic) 1,000 guesses/second past the rate limiter, the expected time to a single hit is ~`10^21` seconds — roughly `10^13` times the age of the universe. **80 bits does not need the rate limit to be safe from brute force.** The rate limit exists for a different reason: to stop bulk automated *validation* traffic and to make any enumeration attempt visible in the logs.

### Rate limits on the public verify endpoints

| Endpoint | Limit | Escalation |
|---|---|---|
| `GET /certificate/verify/:code` | 10/min and 100/hour per IP | Turnstile challenge after 20 failures in an hour |
| `GET /verify/:token` (digital card) | 30/min per IP (a QR is scanned repeatedly at events) | Same |

### On response differentiation

§9.17 requires a revoked certificate to display «هذه الشهادة ملغاة», and §9.6.2 requires «هذه البطاقة ملغاة». That distinguishes *revoked* from *unknown*, which is normally an information leak. **Here it is safe and correct**: with 80 bits of entropy, an attacker cannot reach a valid code by guessing, so the distinction leaks nothing they could not already learn by holding the code. It is also necessary — an employer checking a revoked certificate must be told it was revoked, not that it never existed.

**Storage hardening:** store `sha256(qr_token)` rather than the token. A database read leak then yields no working QR codes. Lookup is by hash, which is also the index. Same for `email_tokens.token_hash` — §7.6 already specifies this correctly.

---

## 6.4 Zoom-link leakage through the SSR / RSC payload

> **BR-24:** «رابط الزوم لا يُكشف قبل 15 دقيقة من بداية الجلسة.»
> **§9.10 acceptance:** «رابط الزوم **غير موجود في مصدر الصفحة** قبل فتح النافذة الزمنية.»

**The mechanism most implementations miss.** In the Next.js App Router, props passed from a Server Component to a Client Component are serialised into the RSC flight payload and are **present in the HTML**. This is true even when the component renders none of those fields.

```tsx
// ❌ LEAKS. `session` is a database row; every selected column lands in the page source.
//    The user never sees zoom_url — but "View Source" and Ctrl-F do.
const session = await db.session.findUnique({ where: { id } });
return <SessionCard session={session} />;
```

```tsx
// ✅ The field is not selected at all until the window is open. Server-side, per request.
const now = await dbNow();
const canReveal = now >= session.startsAt - 15 * MINUTE && now <= session.endsAt;  // BR-24

const dto: SessionCardDTO = {
  id: session.id, title: session.title, topic: session.topic,
  startsAt: session.startsAt, endsAt: session.endsAt,
  trainerName: session.trainer?.displayName ?? null,
  joinAvailable: canReveal,
  // zoom_url is ABSENT from the type. It cannot be leaked by a component that has no field
  // for it — the leak is prevented by the type, not by remembering to omit it.
};
return <SessionCard session={dto} />;
```

The URL is fetched only by an explicit, authenticated call the PRD already specifies (§9.10 «يُجلب عبر طلب مؤمَّن عند الضغط على الزر فقط»):

```ts
// POST /api/sessions/:id/join — re-checks the window server-side; never a GET (no Referer leak,
// no prefetch, no browser history entry).
export async function POST(req: Request, { params }: { params: { id: string } }) {
  const actor = await requireActor(req);
  const row = await db.session.findFirst({
    where: { id: params.id, ...sessionScopeFilter(actor) },
    select: { startsAt: true, endsAt: true, status: true, zoomUrl: true, zoomPasscode: true },
  });
  if (!row) return notFound();
  const now = await dbNow();
  if (row.status === 'cancelled')            return json({ error: 'SESSION_CANCELLED' }, 409);
  if (now < row.startsAt - 15 * MINUTE)      return json({ error: 'TOO_EARLY' }, 403);   // BR-24
  if (now > row.endsAt)                      return json({ error: 'SESSION_ENDED' }, 403);
  await audit.append({ action: 'session.join', actorId: actor.userId, entityId: params.id });
  return json({ url: row.zoomUrl, passcode: row.zoomPasscode });
}
```

**The same bug class, same fix, three more places:**
- `final_project.brief` before `is_unlocked` — BR-16, §9.14.1 «محتوى المشروع … لا تُرسل إلى المتصفح إطلاقًا قبل التفعيل».
- `certificate.verify_code` on any page that lists certificates.
- `digital_card.qr_token` on the card page (the token belongs inside the rendered QR image, not in the props).

**The test that actually catches it** (a code review will not, reliably):

```ts
// tests/security/payload-leak.spec.ts
it('BR-24: the rendered page contains no zoom URL before S-15m', async () => {
  await seedSession({ startsAt: now + 60 * MINUTE, zoomUrl: 'https://zoom.us/j/SECRET123' });
  const html = await fetchPageAsParticipant('/dashboard/live');
  expect(html).not.toContain('zoom.us');
  expect(html).not.toContain('SECRET123');
});
it('BR-16: the rendered page contains no project brief before unlock', async () => {
  await seedFinalProject({ isUnlocked: false, brief: 'CONFIDENTIAL_BRIEF_MARKER' });
  const html = await fetchPageAsParticipant('/dashboard/final-project');
  expect(html).not.toContain('CONFIDENTIAL_BRIEF_MARKER');
});
```

---

## 6.5 Mass assignment on profile update

**The attack.** `PATCH /api/profile` with a body the client controls:

```json
{ "firstNameAr": "محمد", "role": "admin", "status": "active",
  "emailVerifiedAt": "2026-01-01T00:00:00Z", "userId": "<another-user>" }
```

**The rules:**

1. **`.strict()`, not the default `.strip()`.** Zod's default silently discards unknown keys — safe, but it also silently discards evidence that someone probed. `.strict()` rejects and lets the attempt be logged.

```ts
export const ProfileUpdateSchema = z.object({
  firstNameAr:    z.string().transform(normalizeArabicName).pipe(z.string().regex(AR_NAME)),
  secondNameAr:   z.string().transform(normalizeArabicName).pipe(z.string().regex(AR_NAME)),
  thirdNameAr:    z.string().transform(normalizeArabicName).pipe(z.string().regex(AR_NAME)),
  lastNameAr:     z.string().transform(normalizeArabicName).pipe(z.string().regex(AR_NAME)),
  firstNameEn:    z.string().regex(EN_NAME),
  /* … */
  phone:          z.string().transform(normalizeSaudiPhone).pipe(z.string().regex(E164_SA)),
  gender:         z.enum(['male','female']),
  birthDate:      z.string().date().nullable(),
  city:           z.string().max(60).nullable(),
  educationLevel: z.string().max(60).nullable(),
  bio:            z.string().max(500).nullable(),
}).strict();
// `role`, `status`, `email`, `emailVerifiedAt`, `userId`, `passwordHash` are ABSENT.
// Not set to false. Absent. There is no key to assign.
```

2. **Never spread the parsed body into the ORM.**

```ts
// ❌ const data = ProfileUpdateSchema.parse(body); await db.profile.update({ data });
// ✅ Field-by-field. Explicit. Reviewable.
await db.profile.update({
  where: { userId: actor.userId },        // ← from the SESSION, never from the body
  data: {
    firstNameAr: p.firstNameAr, secondNameAr: p.secondNameAr,
    thirdNameAr: p.thirdNameAr, lastNameAr: p.lastNameAr,
    firstNameEn: p.firstNameEn, /* … */ phone: p.phone, gender: p.gender,
  },
});
```

3. **Separate endpoints for privileged transitions**, each with its own guard:

| Transition | Endpoint | Guard |
|---|---|---|
| Email change | `POST /api/profile/email-change` | §9.4.2 — current password **and** a verification link to the **new** address. The email column changes only after the new address is verified. |
| Password change | `POST /api/profile/password` | Current password; BR-29 revokes all sessions; §9.16 sends a notification email |
| Role change | `PATCH /api/admin/users/:id/role` | `user:change_role` (admin only); §4.3 forbids self-elevation; BR-32 floor check |
| Status change | `PATCH /api/admin/users/:id/status` | `user:suspend`; §4.4 «تُبطل جلساته فورًا» |
| **Name change after a certificate has issued** | `POST /api/profile/name-change-request` | 🟠 §9.4.2 requires **admin approval** because the name is printed on the certificate. This needs a `name_change_requests` table the PRD does not define — **GAP-D7** |

4. **The same discipline applies to every other entity**, and these are the ones that matter here: `attendance.status` / `check_in_at` (never client-writable — §1.8 sets them in SQL), `evaluations.score` (trainer-only, via the grading endpoint), `enrollments.final_score` / `attendance_rate` (computed only), `submissions.is_late` / `submitted_at` (computed from the DB clock), `certificates.*` (issuance flow only).

**Test:** `BR-28.mass_assignment.role` — `PATCH /api/profile` with `{"role":"admin"}` returns `400` (strict-mode rejection), the role is unchanged, and an `input_rejected` audit row exists.

---

## 6.6 Account and email enumeration — resolving the PRD's contradiction with itself

### 🔴 CONFLICT-E1 — §9.2.1 leaks exactly what BR-30 forbids

| Rule | Text |
|---|---|
| **BR-30 / §9.3.1** | «رسالة الخطأ موحّدة وغير كاشفة … **لا يُكشف أي الحقلين خاطئ حماية من تعداد الحسابات**» |
| **§9.3.3** | «تُعرض رسالة موحّدة دائمًا … سواء وُجد البريد أم لا» |
| **§9.2.1** ⟵ contradicts both | «رسالة الخطأ عند تكرار البريد: **«هذا البريد مسجّل مسبقًا…»**» |
| **§9.2.1** ⟵ worse | «رسالة الخطأ عند تكرار الجوال: **«هذا الرقم مسجّل مسبقًا لحساب آخر»**» |

The registration form is a **free, unauthenticated oracle**: submit an address, learn whether it holds an account. The phone version is worse — a phone number maps to a person far more tightly than an email does, so «هذا الرقم مسجّل مسبقًا» confirms that a *specific individual* is enrolled in this programme. For a training centre whose cohort membership is not public, that is a privacy disclosure about identifiable people.

### Resolution — a graded response, not a uniform one

| Field | Recommendation | Reasoning |
|---|---|---|
| **Email** | **Keep** «هذا البريد مسجّل مسبقًا. هل تريد تسجيل الدخول؟» | Removing it makes registration materially worse (a user who forgot they registered is stuck in a loop), the oracle is weak (an attacker must already know the address), and §9.2.2 already gates it behind Turnstile + 5/hour/IP. **Accept, with the existing controls.** |
| **Phone** | **Change** to a generic «تعذّر إتمام التسجيل بهذه البيانات. إن كان لديك حساب، سجّل الدخول أو استخدم استعادة كلمة المرور.» and **email the existing account** «حاول أحدهم التسجيل برقم جوالك» | A phone number is a stronger identifier of a named individual, and the UX cost of the generic message is low because the user is far more likely to know their own email than to be confused about their phone. |
| **Login: «لم تفعّل بريدك بعد»** (§9.3.1) | Show **only after a correct password** | Differential messages are safe *post-authentication* — the requester has already proven they own the account. Before authentication this message is an oracle. |
| **Login: «هذا الحساب معطّل»** (§9.3.1) | Same — only after a correct password | Same reasoning |
| **Password reset** (§9.3.3) | Keep the uniform message. ✅ Already correct. | |

**The general principle, worth stating in the PRD:** *a differential message is an information leak before authentication and a usability feature after it.* The fix is almost never to remove the message; it is to move it behind the password check.

### 6.6.1 Timing attacks on login

Even a uniform message leaks if the response times differ. The classic shape: a non-existent user returns in 5 ms (no hash computed), an existing user in 250 ms (Argon2/PBKDF2 runs). That difference is trivially measurable and reconstructs the whole enumeration oracle.

```ts
export async function authenticate(email: string, password: string): Promise<Session | null> {
  const user = await db.users.findByEmail(normalizeEmail(email));

  // Always spend the same work. DUMMY_HASH is a real hash of a random string, computed with
  // the SAME parameters, so the cost is identical whether or not the account exists.
  const hash = user?.passwordHash ?? DUMMY_HASH;
  const ok = await verifyPassword(password, hash);

  // Constant-time-ish combination: neither branch short-circuits before the verify.
  if (!user || !ok) { await recordFailedLogin(email); return null; }
  /* … */
}
```

Also required:
- Look up email tokens by `token_hash` (an indexed equality) rather than scanning — uniform timing by construction.
- Compare tokens and HMACs with a constant-time comparison, never `===` on strings:

```ts
// ✅ Workers DOES provide a constant-time comparison as a Cloudflare extension to WebCrypto.
//    Use it. It is the documented, audited path. [W15]
const equal = crypto.subtle.timingSafeEqual(presentedDigest, expectedDigest);

// Fallback only where the extension is unavailable (e.g. a shared library that must also
// run in a plain browser). Compare FIXED-LENGTH digests, never raw secrets of varying length —
// the length check itself leaks when the inputs are not pre-hashed.
export function timingSafeEqualFallback(a: Uint8Array, b: Uint8Array): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a[i] ^ b[i];
  return diff === 0;
}
```

**Test:** `BR-30.login.timing` — 200 samples each for existing and non-existing accounts; assert the median difference is under 25 ms and the distributions overlap.

---

## 6.7 File upload: an allowed extension with executable content

> §9.11.2: «يُقبل رفع الملفات **بأي صيغة** كما هو مطلوب، مع فحص أمني على الخادم يمنع الملفات التنفيذية الخطرة.»
> §12.5: «فحص نوع MIME الحقيقي **من محتوى الملف لا من امتداده**.»

"Any format is accepted" makes an extension allow-list impossible, so the control set has to be different. Four layers, and the third is the one that actually protects people:

**1. Magic-byte rejection of executables** (§5.7 gives the Worker implementation). Reject by *content*: `MZ` (PE/DOS), `\x7FELF`, Mach-O (`\xFE\xED\xFA\xCE` and friends), `\xCA\xFE\xBA\xBE` (Java class / fat Mach-O), `#!` shebang, `<?php`, `<script` in the first bytes of a file claiming to be an image.

**2. Extension/content agreement.** If the declared extension is `.pdf`, the sniffed type must be `application/pdf`. Disagreement is rejected with «صيغة هذا الملف غير مسموح بها لأسباب أمنية» (§11.1's exact string).

**3. A separate origin, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff` — this is the control that matters.** §9.11.2's "any format" means an HTML file, an SVG, or a `.htm` disguised as `.txt` will eventually be uploaded. If a trainer previews it from `ai.athar-dev.edu.sa`, it runs **in the platform's origin** with the trainer's session cookie — a full trainer account takeover, and from there the whole cohort's grades. Serving every user file from `files.ai.athar-dev.edu.sa` (an R2 custom domain, no cookies, no CSP relationship) as an attachment makes the content inert regardless of what it is.

**4. Archives are opaque.** §9.12 lists «كود مضغوط» as a resource type, so ZIP must be accepted. It is never extracted, never previewed, and never inspected beyond its header. That sidesteps zip-bombs and path traversal entirely — there is no extraction code to attack.

**Polyglots** (a file that is a valid GIF *and* valid JavaScript/HTML) defeat magic-byte sniffing by design. Layer 3 defeats them anyway: `nosniff` + `attachment` + a cookieless origin means the browser will not execute it in any context that matters.

**Test:** `FR-9.11.upload.polyglot` — upload `payload.jpg` whose bytes begin with a JPEG header and contain `<script>alert(1)</script>`; assert it is stored, served from the file origin, carries `Content-Disposition: attachment` and `nosniff`, and that fetching it from the app origin is impossible.

---

## 6.8 A user in two cohorts — the complete authorization matrix

Scenario: **P** is `trainer` in cohort **A** and `participant` in cohort **B**. §14.1 mandates this test; §4.4 mandates the design.

| Action | Target in cohort A | Target in cohort B | Rule |
|---|---|---|---|
| Check in to a session | ❌ denied — P is A's trainer | ✅ allowed | `effectiveRole(P, cohort)` — the resource's cohort decides |
| Read all submissions | ✅ allowed | ❌ **own only** | `submissionScopeFilter` returns `cohorts` for A, `self` for B |
| Grade a submission | ✅ allowed | ❌ denied | `evaluation:create` requires `trainer` **in that cohort** |
| **Grade their own submission** | n/a | ❌ **denied, and this is the one to get right** | P is a participant in B; the trainer of B grades it. Even if P were somehow trainer in B, a self-grading guard applies: `evaluations.user_id <> evaluations.evaluated_by`, enforced by a CHECK |
| See their own grades | n/a | ✅ own only | `self` scope |
| Read other participants' grades | ✅ (A's) | ❌ | scope |
| Edit attendance manually | ✅ (A's) | ❌ | `attendance:manual_edit` in A only |
| Publish an announcement | ✅ (A's channel) | ❌ | `announcement:publish` in A only |
| Post in the group chat | ✅ | ✅ (as a participant) | Both roles may post (§9.13.1) |
| Read the trainer↔trainee DM | ✅ their own DMs with A's trainees | ✅ **only their own DM** with B's trainer | Membership predicate on `thread_participants` |
| Export reports | ✅ A only | ❌ | `report:export` scoped to `cohortIds = [A]` |
| Receive a certificate | ❌ (not a participant in A) | ✅ if eligible in B | Certificates key on a participant enrolment |
| Appear in A's participant list | ❌ | ✅ in B's | `role_in_cohort` filter |

```sql
-- The self-grading guard the PRD does not state but the two-cohort case demands.
ALTER TABLE evaluations ADD CONSTRAINT eval_no_self_grading
  CHECK (user_id <> evaluated_by);
```

**The single rule that makes all of this correct:**

> `effectiveRole(actor, resource.cohortId)` — computed from the **target resource's** cohort, on every request. The header's cohort switcher (§9.5.1 «مبدّل الدفعة») changes only which cohort's data the UI *requests*; it never changes what the server *permits*.

**Test:** `BR-23.two_cohorts.matrix` — a parameterised test that walks the whole table above for a seeded dual-role user.

---

## 6.9 Edge cases the PRD missed

### 🔴 E-1 — CSV/Excel formula injection in the exports (§9.18, §4.2 «تصدير البيانات إلى Excel»)

A trainee registers with the first name `=cmd|'/c calc'!A1`, or a trainer writes feedback beginning `@SUM(1+1)*cmd|…`. §9.18 exports participants, attendance and grades to Excel. When the admin opens the file, Excel interprets any cell beginning with `=`, `+`, `-`, `@`, tab or carriage return as a **formula**, and the DDE variants execute commands on the admin's workstation.

**This is remote code execution against the centre's staff, delivered through a registration form.**

```ts
const DANGEROUS_LEAD = /^[=+\-@\t\r]/;

/** Apply to EVERY cell of EVERY export — names, feedback, notes, reasons, titles. */
export function csvSafe(value: string): string {
  const v = value.replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, '');  // control chars
  return DANGEROUS_LEAD.test(v) ? `'${v}` : v;      // leading apostrophe: Excel treats as text
}
```

Note this applies to real `.xlsx` output too — the formula engine is in the spreadsheet, not the CSV parser. **Test:** `SEC.export.formula_injection`.

### 🔴 E-2 — email link scanners consume single-use tokens before the user clicks

§9.2.3: the activation link is «صالحًا لمدة 24 ساعة **ولمرة واحدة**». §9.3.3: the reset link is «صالح لمدة 30 دقيقة **ولمرة واحدة فقط**».

Microsoft Defender Safe Links, Proofpoint, Barracuda and Gmail's scanners **fetch every URL in an inbound message** to check it. A single-use token consumed by a `GET` is therefore burned **before the human sees the email**. The user clicks, gets «انتهت صلاحية الرابط», and support gets a ticket. This is one of the most common production failures in exactly this design, and it will hit a Saudi training centre whose registrants use Outlook and corporate mail.

**Fix — separate *viewing* the link from *consuming* it:**

```
GET  /verify-email/[token]   → validates the token, renders a page with a button.
                               Does NOT consume it. Idempotent. Safe to prefetch.
POST /api/verify-email        → consumes the token (single-use), activates the account.
                               Requires the button click + a CSRF token.
```

The scanner's GET is harmless; only the human's POST consumes. Same for password reset: the GET renders the new-password form, the POST consumes the token and sets the password. **Test:** `FR-9.2.token.scanner_safe` — issue a token, `GET` it three times, then `POST` once; the POST must succeed.

### 🔴 E-3 — bidirectional-text control characters in names, chat, and the certificate

Unicode bidi overrides (U+202A–U+202E, U+2066–U+2069) and directional marks (U+200E, U+200F) **reverse the visual order of surrounding text**. On an RTL Arabic platform this is far more than cosmetic:

- A display name containing U+202E can visually rewrite the participants list, the chat, and — most seriously — the **impersonation banner** (§3.8), so an admin previewing a trainee could see a banner that reads as if it named someone else.
- A name on the **generated certificate PDF** can be made to render differently from the name in the database, producing a certificate that displays a name the centre never issued.
- Combined with homoglyphs (Arabic vs Persian ی/ي, Cyrillic а vs Latin a), it enables convincing impersonation of another trainee in the group chat.

```ts
const BIDI_CONTROLS = /[\u200E\u200F\u202A-\u202E\u2066-\u2069\u061C]/g;
const ZERO_WIDTH    = /[\u200B-\u200D\uFEFF]/g;

/** Applied to EVERY user-supplied string on write. Not on render — on write. */
export function stripUnsafeControls(s: string): string {
  return s.normalize('NFC').replace(BIDI_CONTROLS, '').replace(ZERO_WIDTH, '');
}
```

Stripping on **write** rather than on render means there is exactly one place to get right, and old rows cannot resurface the problem when a new view is built. **Test:** `SEC.bidi.name_injection`.

### 🟠 E-4 — the notification `link` field is an unvalidated href

§7.6 `Notification(link)` is rendered as an `href`. If any code path lets a value reach it that is not a platform-relative path, it becomes an open redirect or a `javascript:` XSS.

```sql
ALTER TABLE notifications ADD CONSTRAINT notifications_link_relative
  CHECK (link IS NULL OR link ~ '^/[A-Za-z0-9/_\-?=&.%]*$');   -- relative paths only
```

### 🟠 E-5 — certificate PDF generation from an HTML template

The certificate carries the trainee's four-part Arabic and English names (§9.17). If the generator interpolates them into an HTML template, a name is an injection vector into the renderer; if the renderer fetches remote resources, it is an SSRF vector from inside the network. **Escape all interpolated values, disable remote resource loading in the PDF engine, and render from a fixed local template with no network access.**

### 🟠 E-6 — rescheduling a session that already has check-ins

§9.8.2 mentions «موعدها البديل» but no rules exist. Changing `starts_at` after check-ins exist retroactively reclassifies `present` as `late`, or vice versa. **Rule:** once any attendance row exists for a session, `starts_at`/`ends_at` are immutable; the session must be **cancelled** (§1.11) and a new one created. Enforce with a trigger.

### 🟠 E-7 — an admin in a different timezone entering session times

§9.18 lets an admin build the schedule. If the datetime picker submits a browser-local value, an admin travelling abroad creates sessions at the wrong hour. **Rule:** the picker submits `(year, month, day, hour, minute)` as **Riyadh wall-clock components** with an explicit «بتوقيت الرياض» label, converted server-side by `riyadhWallClockToInstant` (§1.6). Never submit an ISO string from the browser.

### 🟠 E-8 — a participant enrolled mid-cohort

Covered by the `enrolled_at` filter in §1.9, but it must also apply to journey steps (§9.7.1) — a trainee who joins in week 2 cannot complete week 1's step and would be permanently stuck at 0%. **Rule:** journey steps for weeks that ended before `enrolled_at` are marked `not_applicable`, excluded from the progress denominator.

### 🟠 E-9 — withdrawal mid-cohort

`Enrollment.status = 'withdrawn'` exists (§7.2) but no rules attach. **Rules:** the sweep stops writing `absent` for withdrawn enrolments (otherwise a withdrawn trainee accrues absences forever); the attendance rate freezes at withdrawal; they disappear from the trainer's live-session list; their submissions and grades are retained (§7.8).

### 🟠 E-10 — a trainer unassigned from a cohort while holding live capabilities

Removing a trainer's assignment must: revoke their sessions' scope on the **next request** (scope is computed per request, so this is automatic), invalidate signed URLs they minted (bind URLs to the scope check — §6.2 defence 1 handles this), and leave their prior evaluations intact with `evaluated_by` pointing at them.

### 🟠 E-11 — changing `pass_score` or `min_attendance_rate` after certificates are issued

Would retroactively invalidate issued certificates. **Rule:** these values are **frozen onto the certificate at issue** (`Certificate.final_score`, `Certificate.attendance_rate`, plus new columns `pass_score_at_issue`, `min_attendance_rate_at_issue`), and the cohort's current values are never consulted when verifying an existing certificate.

### 🟠 E-12 — name change after certificate issuance

§9.4.2 requires admin approval, but no table exists (**GAP-D7**, §6.5). Add `name_change_requests(user_id, requested_names, reason, status, reviewed_by, reviewed_at)`, and on approval **re-issue** the certificate with a new serial while revoking the old one — never silently mutate the name behind an already-shared verify link.

### 🟠 E-13 — the sweep runs twice concurrently, or not at all

Covered by HARDENING-A5. The "not at all" case needs the alert; it is invisible otherwise.

### 🟠 E-14 — two cohorts with overlapping session times

A trainee in two cohorts could check in to two sessions at the same instant. Each has its own row, so nothing breaks structurally — but it is physically impossible and should be surfaced. **Rule:** flag overlapping check-ins in the trainer's report; do not block (a legitimate case is a schedule clash the centre needs to see).

### 🟠 E-15 — browser back-button POST replay

Handled by the idempotency of §1.8 for attendance. For submissions, use POST-redirect-GET plus an idempotency key so a replayed POST does not create a spurious `version + 1`.

### 🟠 E-16 — an admin ends a preview while the subject changes their password

Both operations touch the subject's sessions. `parentSessionId` binding (§3.2.3) means the preview is tied to the *admin's* session, not the subject's, so a subject's password change does not end an active preview. **Decide explicitly:** recommend that a subject's password change **does** terminate any live preview of that subject (they have just taken a security action on their own account), implemented as an additional check in `resolveImpersonation`.

### 🟠 E-17 — Turnstile / CAPTCHA bypass on registration

§9.2.2 requires an invisible CAPTCHA. The token must be **verified server-side** against the siteverify endpoint on every registration, bound to the request IP, and rejected if reused (`idempotency` on the token). A client-side-only CAPTCHA is decoration.

### 🟠 E-18 — the `confirm email` paste-disable is UI-only

§9.2.1 disables paste in the confirmation field. This is a typo-reduction feature, not a control; it is trivially bypassed and must never be relied on. Server-side, both fields are compared. (No action needed — recorded so it is not mistaken for a security property.)

### 🟠 E-19 — `Message` edit/delete window enforced only in the UI

§9.13.2 allows edit/delete «خلال 15 دقيقة من الإرسال فقط». Enforce it structurally:

```sql
ALTER TABLE messages ADD CONSTRAINT messages_edit_window
  CHECK (edited_at IS NULL OR edited_at <= sent_at + INTERVAL '15 minutes');
-- Moderation (§9.13.2 «المدير يستطيع حظر مستخدم») uses a SEPARATE column with no window:
ALTER TABLE messages ADD COLUMN moderated_at TIMESTAMPTZ NULL,
                     ADD COLUMN moderated_by UUID NULL REFERENCES users(id);
```

A `CHECK` over two columns of the same row is immutable and therefore valid — the 15-minute rule becomes a property of the data, not a hope about the code.

### 🟠 E-20 — leap seconds and the frozen clock interaction

A leap second cannot move a boundary by more than one second, and every boundary in §1.10 has one-second tests on both sides. Recorded as considered and non-actionable, since the DB clock is authoritative and monotonic-enough at this granularity.

### 🟠 E-21 — `Resource.download_count` inflated by prefetch

Browsers and link scanners prefetch. A `GET` that increments a counter is inflated by traffic that is not a download. **Rule:** increment only on a `POST /api/resources/:id/download` (or on the streamed-response completion), never on a link hover.

### 🟠 E-22 — the group chat exposes cohort membership to every member

By design (§9.13.1), and unavoidable — but it means cohort membership is not confidential among participants. **This must be disclosed in the privacy policy** so consent under PDPL is informed (§5.9).

---
---

# 7. The Test Suite That Must Exist

> ### 📌 ملخص القسم بالعربية
> §14 يشترط **تغطية 80% للمنطق الحرج** و§17 يجعلها معيار قبول نهائي — لكن الـ PRD **لا يعرّف «المنطق الحرج»**. §7.1 أدناه يعرّفه بقائمة مسارات ملفات محددة، فتصبح النسبة قابلة للقياس والفرض في خط التكامل المستمر بدل أن تكون تقديرًا.
>
> كل اختبار مُسمّى برقم قاعدة العمل التي يثبتها (`BR-01` … `BR-36`) أو رقم المتطلب الوظيفي (`FR-9.x`)، ليكون الربط بين الوثيقة والكود قابلًا للتدقيق في المراجعة، كما يطلب §10 صراحة: «قواعد … يُشار إليها بالرقم في الكود والاختبارات».

---

## 7.1 Defining "critical logic" — the 80% coverage target made measurable

§14 requires «تغطية 80% للمنطق الحرج» over «منطق نوافذ الحضور، حساب الدرجات، فحص الصلاحيات، التحقق من المدخلات، حساب حالات الرحلة». §17 makes it a launch gate. Neither defines the boundary, so the number is unenforceable as written.

**Normative definition — these paths are "critical logic". The 80% line coverage AND 80% branch coverage gate applies to them, not to the repository average:**

```jsonc
// vitest.config.ts
coverage: {
  thresholds: {
    // Repository-wide floor — deliberately low; it is not the real gate.
    lines: 50, branches: 45,

    // §14 / §17 — the actual, enforced requirement.
    'lib/time/**':                      { lines: 100, branches: 100 },  // §1 — no exceptions
    'features/attendance/**':           { lines: 95,  branches: 95  },  // BR-01..BR-10
    'lib/permissions/**':               { lines: 100, branches: 100 },  // §2 — BR-22,23,28
    'features/impersonation/**':        { lines: 95,  branches: 95  },  // §3 — BR-33,34,35
    'features/grading/**':              { lines: 90,  branches: 90  },  // BR-11..BR-14
    'features/journey/**':              { lines: 85,  branches: 85  },  // BR-20, BR-21
    'features/certificates/**':         { lines: 90,  branches: 90  },  // BR-26
    'lib/validation/**':                { lines: 90,  branches: 85  },  // §6.3, §9.2
    'lib/tokens.ts':                    { lines: 100, branches: 100 },  // §6.3
    'features/files/**':                { lines: 85,  branches: 80  },  // §12.5
  }
}
```

**`lib/time/**` and `lib/permissions/**` are set to 100% deliberately.** They are small, pure, and every uncovered branch in them is a rule nobody has ever exercised — in modules where an uncovered branch means a trainee is misclassified or reads someone else's data. There is no acceptable uncovered branch in either.

**Branch coverage, not only line coverage.** `if (now < opensAt) …` is one line and two outcomes. A line-coverage-only gate can report 100% on a boundary engine in which no boundary was ever tested.

---

## 7.2 Unit tests — attendance (§1)

`tests/unit/attendance/windows.spec.ts` — a table-driven test over §1.10's 29 rows.

```ts
const S = riyadhWallClockToInstant(2026, 10, 12, 18, 0);
const E = riyadhWallClockToInstant(2026, 10, 12, 21, 0);

describe('BR-01/02/03/04/05 — attendance window boundaries', () => {
  it.each(BOUNDARY_TABLE)('$label → checkIn=$checkIn class=$class checkOut=$checkOut',
    ({ at, checkIn, cls, checkOut }) => {
      expect(canCheckIn(at, S, E, 'scheduled').ok).toBe(checkIn);
      if (checkIn) expect(classify(at, S)).toBe(cls);
      expect(canCheckOut(at, S, E, true, 'scheduled').ok).toBe(checkOut);
    });
});
```

| Test id | Asserts | PRD |
|---|---|---|
| `BR-01.window.open_lower_minus_1s` | `S−30m−1s` → denied `window_not_open_yet` | §9.9.2, §14.1 |
| `BR-01.window.open_exact` | `S−30m` exactly → **allowed** (inclusive) | §9.9.3 «بداية النافذة» |
| `BR-01.window.open_plus_1s` | `S−30m+1s` → allowed | §14.1 |
| `BR-01.window.close_minus_1s` | `E−1s` → allowed | — |
| `BR-01.window.close_exact` | `E` exactly → **allowed** (inclusive) | §9.9.3 «آخر لحظة» |
| `BR-01.window.close_plus_1ms` | `E+1ms` → denied `window_closed` | §9.9.3 |
| `BR-01.window.close_plus_1s` | `E+1s` → denied | §14.1 |
| `BR-01.cancelled_session` | any instant, `status='cancelled'` → denied | §9.9.4 |
| `BR-01.midnight_crossing` | `S=23:00`, `E=01:00` next day → all boundaries behave identically | 🔴 §14.1 vs §7.7 (BLOCKER-A7) |
| `BR-02.grace.minus_1s` | `S+30m−1s` → `present` | §14.1 |
| `BR-02.grace.exact` | `S+30m` exactly → **`present`** | §14.1 «الدقيقة 30 بالضبط» |
| `BR-03.late.plus_1ms` | `S+30m+1ms` → `late` (reading R1) | 🔴 DISC-2 |
| `BR-03.late.plus_1s` | `S+30m+1s` → `late` | §14.1 |
| `BR-03.late.minute_31` | `S+31m` → `late` | §9.9.3 |
| `BR-03.late.at_session_end` | check-in at `E` → `late` | §9.9.3, GAP-A1 |
| `BR-04.checkout.lower_minus_1s` | `E−30m−1s` → denied | §9.9.3 «8:29 م» |
| `BR-04.checkout.lower_exact` | `E−30m` exactly → **allowed** | §9.9.3 «بداية نافذة الانصراف» |
| `BR-04.checkout.upper_minus_1s` | `E+30m−1s` → allowed | — |
| **`BR-04.checkout.upper_exact`** | **`E+30m` exactly → allowed** | 🔴 **DISC-1** — the test that proves the resolution |
| `BR-04.checkout.upper_plus_1ms` | `E+30m+1ms` → denied | — |
| `BR-04.checkout.upper_plus_1s` | `E+30m+1s` → denied | §14.1 |
| `BR-04.checkout.short_session_clamp` | 20-minute session: check-out does not open before `S` | 🟠 GAP-A4 |
| `BR-05.checkout.no_checkin` | `hasCheckIn=false`, inside the window → denied `no_check_in` | §9.9.8, §14.1 |
| `BR-05.checkout.order` | `no_check_in` is returned **before** any window reason | §9.9.8 message contract |
| `BR-07.policy_coherence` | a session shorter than `grace + lead` throws at policy load | GAP-A4 |
| `BR-07.dst_free` | `Asia/Riyadh` offset is `+03:00` at 24 sampled instants across 2026 | §1.6 |
| `BR-07.instant_roundtrip` | `riyadhWallClockToInstant` ∘ `formatRiyadh` is identity for 1000 random instants | §1.6 |

### Sweep transitions

| Test id | Asserts |
|---|---|
| `BR-08.sweep.absent_after_end` | `now = E+1ms`, no check-in → `completion='not_attended'`, `checkin_class = NULL` |
| `BR-08.sweep.not_at_exactly_E` | `now = E` → **no** absent row written (a check-in is still legal) |
| `BR-08.sweep.skips_cancelled` | a cancelled session produces **zero** absent rows for 60 participants | 🔴 BLOCKER-A6 |
| `BR-08.sweep.skips_pre_enrolment` | sessions that ended before `enrolled_at` produce no absent row |
| `BR-09.sweep.incomplete_after_window` | check-in without check-out, `now > E+30m` → `completion='incomplete'` |
| `BR-09.sweep.not_at_exactly_E_plus_30` | `now = E+30m` → still `pending` |
| `BR-09.sweep.preserves_class` | an `incomplete` row **retains** `checkin_class='present'` | 🔴 BLOCKER-A3 |
| `BR-09.sweep.notifies_trainer` | one notification per incomplete row | §9.16 |
| `BR-08/09.sweep.idempotent` | running the sweep twice produces byte-identical tables | HARDENING-A5 |
| `BR-08/09.sweep.concurrent` | two overlapping sweeps produce the same result as one | HARDENING-A5 |

### Attendance rate (§1.9)

| Test id | Asserts |
|---|---|
| `BR-26.rate.future_excluded` | before the first session the rate is `null`, not `0` |
| `BR-26.rate.late_counts` | `late` counts as attended |
| `BR-26.rate.incomplete_counts` | `incomplete` counts as attended |
| `BR-26.rate.excused_neutral` | `excused` leaves both numerator and denominator |
| `BR-26.rate.cancelled_excluded` | cancelling a session cannot lower an absent participant's rate |
| `BR-26.rate.late_enrolment` | sessions before `enrolled_at` are excluded |
| `BR-26.rate.frozen_on_certificate` | recomputing after issuance does not change `Certificate.attendance_rate` |
| `GAP-A11.band.boundaries` | `rate = min` → `at_risk`; `rate = min − 0.01` → `below_requirement`; `rate = min + 10` → `ok` |

---

## 7.3 Integration tests — attendance concurrency and scoping

| Test id | Asserts | PRD |
|---|---|---|
| `BR-06.concurrent_checkin` | 50 parallel `POST /check-in` for one user+session → exactly **one** attendance row, **zero** 5xx responses, all 50 responses are `201` or `409` | §14.1, §18 |
| `BR-06.checkin_idempotent` | a second check-in returns `409 ALREADY_CHECKED_IN` with the original timestamp, and does **not** overwrite `check_in_at` | §9.9.8 |
| `BR-06.absent_stub_upgrade` | a sweep-written `not_attended` stub is upgraded by a later in-window check-in | §1.8 |
| `BR-06.manual_not_clobbered` | a trainer's `is_manual` record is **not** overwritten by a subsequent self check-in | BR-10 |
| `BR-01.checkin.not_enrolled` | a user not enrolled in the session's cohort → zero rows written, `403` | §2.6 row 8 |
| `BR-07.db_clock` | the stored `check_in_at` equals the DB clock, not any client value | §1.7 |
| `BR-07.client_clock_ignored` | a request carrying a forged `X-Client-Time` header 3 hours ahead is classified from the DB clock | §14.1 |
| `BR-10.manual_edit.reason_required` | a manual edit with a 9-character reason → `400`; 10 characters → accepted | §9.9.7 |
| `BR-10.manual_edit.audited` | every manual edit writes one audit row with before/after and the reason | §9.9, BR-27 |
| `BR-30.rate_limit.checkin_per_account` | 11 check-ins in a minute from one account → limited; **60 different accounts from one IP → all succeed** | §12.4, §5.6 |

---

## 7.4 Authorization tests (§2)

Every row of §2.6 has a test. Additionally:

| Test id | Asserts | BR |
|---|---|---|
| `BR-22.matrix.exhaustive` | For each of {participant A, participant B, trainer of A's cohort, trainer of another cohort, admin} × each of {submission, evaluation, message, attendance, certificate, resource, notification, journey, profile} × {read, write}: the outcome matches the §2.3 matrix | BR-22, BR-23 |
| `BR-22.404_not_403` | an object-level miss returns `404`, not `403` — no existence oracle | GAP-P2 |
| `BR-22.audit_on_denial` | both `403` and `404` denials write an audit row with the IP | §4.3 |
| `BR-23.two_cohorts.matrix` | the full §6.8 table for a dual-role user | §4.4, §14.1 |
| `BR-23.context_switcher_not_authz` | forging `X-Active-Cohort` to a cohort the actor is not in changes nothing | 🔴 CONFLICT-P0 |
| `BR-28.every_endpoint_guarded` | a generated test enumerates every route in the app and asserts each declares a required permission — a route with none **fails the build** | §4.3, BR-28 |
| `BR-28.mass_assignment.role` | `PATCH /api/profile {"role":"admin"}` → `400`, role unchanged | §6.5 |
| `BR-28.mass_assignment.status` | same for `status`, `emailVerifiedAt`, `userId` | §6.5 |
| `BR-32.last_admin` | demoting/deleting the final active admin → rejected by the DB | §4.9 |
| `BR-32.self_delete` | an admin deleting their own account → rejected | §4.3 |
| `BR-29.password_change_revokes` | after a password change every prior session cookie is rejected on the next request | BR-29 |
| `BR-29.revokes_impersonation` | …including any impersonation grant derived from those sessions | §3.5 |

---

## 7.5 Impersonation tests (§3)

The full list is in **§3.9** (40 named tests). The two highest-value ones:

- **`BR-34.no_writes.all_routes`** — a table-snapshot diff across every dashboard route under an active grant, run against a database whose application role has been stripped of `INSERT`/`UPDATE`/`DELETE`. Any write raises at the engine, so the test cannot pass by accident.
- **`BR-35.audit_actor_identity`** — asserts that no audit row anywhere records a trainee as `actor_id` for an action performed during a preview. This is the assertion that keeps the audit log truthful, and truthfulness is the whole point of having one.

---

## 7.6 Grading tests (§4.6, BR-11..BR-14)

| Test id | Asserts | BR |
|---|---|---|
| `BR-11.totals` | assignments sum to 50, project is 50, total is 100 | BR-11 |
| `BR-11.warning_not_block` | assignment max scores summing to 45 produce the §9.15.1 warning but do **not** block | §9.15.1 |
| `BR-11.optional_counts` | an optional assignment with a recorded score counts toward the 50; unsubmitted deducts nothing | §9.15.1 |
| `BR-12.score.exceeds_max` | `score = max_score + 0.01` → rejected **by the database**, not only by Zod | §9.15, §7.7 |
| `BR-12.score.equals_max` | `score = max_score` exactly → accepted | §7.7 |
| `BR-12.score.negative` | `score = −0.01` → rejected | §7.7 |
| `BR-12.score.decimal` | `8.5` accepted and rendered as `8.5` | §9.15.4 |
| `BR-12.max_score_lower_blocked` | lowering `assignments.max_score` below an awarded score → the parent UPDATE is rejected | §4.6 |
| `BR-12.max_score_raise_cascades` | raising `max_score` propagates and leaves every evaluation valid | §4.6 |
| `BR-13.feedback_required` | empty feedback → rejected; 9 characters → rejected; 10 → accepted | §9.15.2 |
| `BR-14.revision_reason` | updating an existing evaluation without a reason → rejected | §9.15.4 |
| `BR-14.revision_notifies` | a revision produces one in-app notification and one email | §9.16 |
| `BR-14.revision_audited` | a revision writes an audit row with before/after scores | BR-27 |
| `CONFLICT-P1.admin_cannot_grade` | an admin calling the ordinary grading endpoint → `403` | §4.2 |
| `CONFLICT-P1.admin_override_path` | `evaluation:override` requires re-authentication, a ≥20-char reason, and notifies both the participant and the trainer | §2.7 |
| `SEC.no_self_grading` | `user_id = evaluated_by` → rejected by the CHECK | §6.8 |
| `BR-18.deadline.boundary` | `submitted_at = due_at` → on time; `due_at + 1ms` → `is_late = true` | §14.1 |
| `BR-18.late_rejected` | with `allow_late = false`, a late submission → `403` | §9.11.2 |
| `BR-19.versions_preserved` | after three re-submissions, three rows exist and version 1's files are still retrievable | 🔴 GAP-D4 |
| `BR-19.one_current` | exactly one row has `is_current = true`, even under a concurrent double submit | GAP-D4 |

---

## 7.7 Business-rule coverage matrix — BR-01 … BR-36

**Every rule has at least one named test.** Rules whose primary evidence is a review artefact rather than an executable assertion are marked; they still carry a check in the launch checklist (§ملحق ب).

| BR | Rule (abbrev.) | Primary test(s) | Kind |
|---|---|---|---|
| **BR-01** | Check-in window `[S−30m, E]` | `BR-01.window.*` (8 tests) | unit + integration |
| **BR-02** | `≤ S+30m` ⇒ present | `BR-02.grace.exact`, `BR-02.grace.minus_1s` | unit |
| **BR-03** | `> S+30m` ⇒ late | `BR-03.late.*` (4) | unit |
| **BR-04** | Check-out window `[E−30m, E+30m]` | `BR-04.checkout.*` (6) — incl. the DISC-1 test | unit |
| **BR-05** | No check-out without check-in | `BR-05.checkout.no_checkin`, `.order` | unit + integration |
| **BR-06** | One check-in per session | `BR-06.concurrent_checkin`, `.idempotent`, `.absent_stub_upgrade` | integration |
| **BR-07** | Riyadh server time is the only reference | `BR-07.db_clock`, `.client_clock_ignored`, `.dst_free` | unit + integration |
| **BR-08** | Auto-absent after the session ends | `BR-08.sweep.*` (4) | integration |
| **BR-09** | Auto-incomplete + trainer alert | `BR-09.sweep.*` (4) | integration |
| **BR-10** | Manual edit needs a reason + audit | `BR-10.manual_edit.*` (2) | integration |
| **BR-11** | 50 + 50 = 100 | `BR-11.*` (3) | unit |
| **BR-12** | `0 ≤ score ≤ max_score` | `BR-12.*` (6) | unit + DB |
| **BR-13** | Feedback mandatory | `BR-13.feedback_required` | unit + DB |
| **BR-14** | Revision needs a reason + notification | `BR-14.*` (3) | integration |
| **BR-15** | Final project locked server-side | `BR-15.project.403_before_unlock` | integration |
| **BR-16** | Project content not sent before unlock | `BR-16.project.not_in_payload` | e2e (HTML grep) |
| **BR-17** | Trainer defines assignments | `BR-17.trainer_creates`, `CONFLICT-P4.admin_may_also` | integration |
| **BR-18** | Late submission tagged or rejected | `BR-18.deadline.boundary`, `.late_rejected` | integration |
| **BR-19** | Re-submission preserves versions | `BR-19.versions_preserved`, `.one_current` | integration |
| **BR-20** | Registration step complete by default | `BR-20.journey.step1_default` | integration |
| **BR-21** | Journey steps auto-complete only | `BR-21.journey.auto`, `BR-21.journey.no_manual_endpoint` | integration |
| **BR-22** | No cross-participant access | `BR-22.*` (13, one per §2.6 surface) | integration |
| **BR-23** | Trainer limited to assigned cohorts | `BR-23.*` (4, incl. the two-cohort matrix) | integration |
| **BR-24** | Zoom link hidden until `S−15m` | `BR-24.zoom.not_in_payload`, `.join_too_early`, `.join_in_window` | e2e + integration |
| **BR-25** | Verify pages expose no sensitive data | `BR-25.verify.minimal_fields`, `.card_token_entropy`, `.enumeration` | e2e |
| **BR-26** | Certificate needs attendance **and** score | `BR-26.eligibility.*` (6) + `BR-26.rate.*` (8) | integration |
| **BR-27** | Everything sensitive audited; log immutable | `BR-27.audit.coverage`, `.update_denied`, `.delete_denied`, `.hash_chain` | integration + DB |
| **BR-28** | Every permission checked server-side | `BR-28.every_endpoint_guarded` (generated over all routes) | integration |
| **BR-29** | Password change revokes all sessions | `BR-29.password_change_revokes`, `.revokes_impersonation` | integration |
| **BR-30** | Login/reset messages reveal nothing | `BR-30.uniform_message`, `BR-30.login.timing`, `CONFLICT-E1.phone_generic` | integration |
| **BR-31** | Changeable content is admin-managed | `BR-31.no_hardcoded_content` — a static scan asserting no Arabic string literal exists outside `/locales`, and no seeded content value appears in `/app` or `/features` | static |
| **BR-32** | At least one active admin, always | `BR-32.last_admin`, `.self_delete` | DB |
| **BR-33** | Preview is strictly read-only, server-enforced | `BR-33.*` (7) | integration |
| **BR-34** | Preview leaves no trace | `BR-34.*` (8, incl. the all-routes snapshot) | integration |
| **BR-35** | Preview audited; no admin→admin | `BR-35.*` (6) | integration |
| **BR-36** | Program name and domain from settings | `BR-36.no_hardcoded_name` — static scan for the literal «البرنامج التأسيسي في الذكاء الاصطناعي» and `ai.athar-dev.edu.sa` outside config/seed | static |

---

## 7.8 Security-specific tests (§5, §6)

| Test id | Asserts |
|---|---|
| `SEC.headers.csp` | every HTML response carries a CSP with a **per-response** nonce and no `unsafe-inline` in `script-src` |
| `SEC.headers.full_set` | HSTS, `nosniff`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` present on every route |
| `SEC.headers.no_referrer_on_token_routes` | `/verify/*`, `/reset-password/*`, `/certificate/verify/*` send `Referrer-Policy: no-referrer` |
| `SEC.headers.no_store_authenticated` | every `/dashboard`, `/trainer`, `/admin`, `/api` response carries `Cache-Control: private, no-store` |
| `SEC.csrf.origin_required` | a state-changing request with a foreign `Origin` → `403` |
| `SEC.csrf.no_origin_header` | a state-changing request with **no** `Origin` and no `Sec-Fetch-Site` → `403` (fail closed) |
| `SEC.xss.markdown_no_html` | `<img src=x onerror=alert(1)>` in trainer feedback and in a chat message renders as text |
| `SEC.xss.javascript_uri` | a GitHub URL of `javascript:alert(1)` is rejected at validation |
| `SEC.xss.no_dangerouslySetInnerHTML` | static scan: zero occurrences in the repository |
| `SEC.sqli.sort_param` | `?sort=id;DROP TABLE users` → `400`, allow-list rejection |
| `SEC.sqli.no_raw_unsafe` | static scan: zero `$queryRawUnsafe` / `sql.raw` occurrences |
| `SEC.upload.magic_bytes` | a `.jpg` whose content is a PE binary → rejected |
| `SEC.upload.polyglot` | §6.7 test |
| `SEC.upload.size_boundary` | exactly the limit → accepted; +1 byte → rejected; a lying `Content-Length` → rejected mid-stream |
| `SEC.upload.separate_origin` | every user file URL is on the file origin, with `attachment` + `nosniff` |
| `SEC.signed_url.replay_by_other_user` | §6.2 |
| `SEC.signed_url.expiry` | valid at `T+14m59s`, rejected at `T+15m1s` |
| `SEC.export.formula_injection` | a name of `=cmd|'/c calc'!A1` exports as `'=cmd…` | 🔴 E-1 |
| `SEC.bidi.name_injection` | U+202E in a name is stripped on write, in every surface incl. the certificate PDF | 🔴 E-3 |
| `FR-9.2.token.scanner_safe` | three `GET`s of a verification link do not consume it; the `POST` does | 🔴 E-2 |
| `SEC.token.entropy` | 100k generated verify codes are unique; the alphabet excludes I/L/O/U; length is 16 |
| `SEC.enumeration.verify_code` | 11 requests/minute to the verify endpoint → rate limited |
| `SEC.notification.link_relative` | a notification with an absolute or `javascript:` link is rejected by the CHECK | 🟠 E-4 |
| `SEC.pdpl.export` | the data-export endpoint returns every category listed in the privacy policy and nothing else |
| `SEC.pdpl.no_pii_to_third_party` | error-tracker payloads contain no email, phone, name, or IP |

---

## 7.9 End-to-end tests (§14 «كل مسار رئيسي مرة واحدة على الأقل»)

| Test id | Journey |
|---|---|
| `E2E.registration_to_dashboard` | landing → register (3 steps) → verification email → **GET the link twice** → POST → enrolment + digital card + journey created → dashboard with the welcome screen |
| `E2E.attendance_full_cycle` | a session opens → countdown → check-in at `S−5m` (`present`) → check-out at `E+10m` → the rate updates → the journey step completes |
| `E2E.attendance_late_and_incomplete` | check-in at `S+45m` (`late`) → no check-out → the sweep marks `incomplete` → the trainer is notified → **`checkin_class` is still `late`** |
| `E2E.assignment_cycle` | trainer publishes → participant is notified → upload with progress → re-submit (version 2) → trainer grades → participant is notified → the total updates |
| `E2E.final_project` | locked tab → 403 on the direct URL → page source contains no brief → trainer unlocks → cohort notified → submission → grading |
| `E2E.certificate` | eligibility met → admin issues → serial `ATHAR-AI101-2026-0001` → PDF renders → the public verify page shows only the permitted fields → revoke → the verify page shows «ملغاة» |
| `E2E.impersonation` | admin opens preview → banner visible → navigates 5 tabs → every write attempt refused → «إنهاء المعاينة» → admin is back, **no re-login** → 5 navigate audit rows + 1 start + 1 end |
| `E2E.two_cohort_user` | the §6.8 matrix walked through the real UI |
| `E2E.a11y.keyboard_only` | registration, check-in and submission completed with the keyboard alone (§13.2) |
| `E2E.rtl_integrity` | every main screen at 320 px and 1920 px with no horizontal overflow and no mirrored-icon errors (§13.4) |

---

## 7.10 What the test suite must **not** do

- **No mocking of the clock inside the domain.** `canCheckIn` takes `now` as a parameter precisely so tests pass instants rather than patch a global. A test that stubs `Date.now()` is testing the stub.
- **No mocking of the database in authorization tests.** The whole point of §2.5 is that the scope lives in the SQL predicate; a mocked repository proves nothing about the predicate. Authorization tests run against a real Postgres/D1 instance (Miniflare for D1, a container for Postgres).
- **No snapshot tests as the only assertion for a security property.** A snapshot records what the code does; a security test must state what the code *must* do.
- **No skipped or `.only` tests on `main`.** Enforced in CI.

---
---

# Appendix A — Discrepancy & Decision Register

> ### 📌 للعميل
> هذا الجدول هو **مخرج المراجعة الأهم**. كل بند هنا يحتاج قرارًا من صاحب المنتج. البنود الحمراء 🔴 **تمسّ حقوق المتدربين مباشرة** ولا يجوز البدء في المرحلة المعنية قبل حسمها، عملًا بنص §1.1 من الوثيقة الأصلية.

| # | ID | Severity | PRD reference | Issue in one line | Recommended resolution | Blocks phase |
|---|---|---|---|---|---|---|
| 1 | **DISC-1** | 🔴 | §9.9.3 vs BR-04 vs §14.1 | The example table makes 9:29 pm the last check-out moment; the rule and the mandated test make it 9:30 pm | **BR-04 wins:** `[E−30m, E+30m]` inclusive. Correct §9.9.3. | 4 |
| 2 | **DISC-2 / A2** | 🔴 | §9.9.3, §14.1 | The 59.999 s between 6:30:00 and 6:31:00 is undefined for present/late | **Instant comparison** `≤ S+1,800,000 ms`. Amend §9.9.2 to millisecond precision. | 4 |
| 3 | **A3** | 🔴 | §9.9.5, §9.7.1 | One `status` enum makes the sweep's `incomplete` destroy the present/late fact, breaking journey step 2 and the attendance rate | Split into `checkin_class` + `completion` + `excused_at`; derive `status` for display | 4 |
| 4 | **A6** | 🔴 | §7.8, §9.9.4 | Cancelling a session would mark all 60 participants absent | Cancelled sessions leave both numerator and denominator; sweep skips them; recompute on cancel | 4 |
| 5 | **A7** | 🔴 | §7.7 vs §14.1 | `CHECK (end_time > start_time)` on (date, time, time) forbids the midnight-crossing session §14.1 mandates testing | Store `starts_at`/`ends_at` as absolute instants; keep a generated Riyadh date for grouping | 3 |
| 6 | **A10** | 🔴 | §9.9.5, BR-26, §9.17 | **The attendance-rate formula does not exist**, yet it gates certificates | Adopt the §1.9 formula: elapsed non-cancelled sessions only; present/late/incomplete count; excused neutral; freeze on the certificate | 4 |
| 7 | **A1** | 🟠 | BR-01 + BR-04 | Check-in at exactly `E` plus check-out 1 ms later yields a "fully attended" record for zero presence | Keep the PRD rule; add a `presence_ms` column and surface anomalies in the trainer's report | 4 |
| 8 | **A4** | 🟠 | §7.7 | A session under 60 minutes inverts the check-out window | `CHECK (ends_at >= starts_at + 60 min)` + clamp `max(E−30m, S)` in code | 3 |
| 9 | **A11** | 🟠 | §9.9.6 vs §9.17 | Colour bands have gaps at exactly 70 and 85, and orange spans values below the 75% certificate threshold | Derive bands from `cohort.min_attendance_rate`; close the intervals | 4 |
| 10 | **DISC-4** | 🟠 | §9.9.2 vs §9.10 | Check-in opens at `S−30m` but the lecture link at `S−15m` — a 15-minute gap the PRD never explains | Keep both; add the explanatory copy to §9.9.8 | 3 |
| 11 | **P0** | 🔴 | §4.2 vs §4.4 | `User.role` cannot decide cohort-scoped permissions when one person is trainer in A and participant in B; the context switcher must never be an authorization input | Derive the role from the **target resource's** cohort on every request; relabel §4.2 as a per-cohort matrix | 2 |
| 12 | **P1** | 🔴 | §4.2 vs §4.1 | Admin is «لا» for grading but has «صلاحية كاملة» | **Keep the restriction** (segregation of duties); add an audited, re-authenticated `evaluation:override` break-glass | 5 |
| 13 | **P2** | 🟠 | §4.3 | Returning 403 for another user's object is an existence oracle | 403 for role violations, 404 for object-level misses; audit both | 2 |
| 14 | **P3** | 🟠 | §4.2 | «تسجيل الحضور… لا/لا/نعم» locks a trainer out of a cohort where they are a participant | Relabel the matrix «(داخل الدفعة المعنية)» | 2 |
| 15 | **P4** | 🟠 | §4.2 vs BR-17 | Both admin and trainer may create assignments, but BR-17 says the trainer does | Both may; BR-17 describes the normal model. Documented, not changed. | 5 |
| 16 | **CONFLICT-P2** | 🟠 | §4.2 vs §9.13.1 | «—» for the trainer in the DM row contradicts «الطرفان يكتبان» | §9.13.1 wins; correct the matrix row | 7 |
| 17 | **GAP-I1** | 🔴 | §7.6, §4.5.3 | `AuditLog` has no `on_behalf_of_user_id`, so impersonated actions cannot be recorded truthfully | Add the column pair + the paired CHECK; `actor_id` is always the human | 8 |
| 18 | **GAP-D1** | 🔴 | §9.2.1 vs §7.7 | Two accepted phone formats defeat the `phone` unique constraint — one person, two accounts | Normalise to `+9665XXXXXXXX` before validation; `CHECK` the shape | 1 |
| 19 | **GAP-D4** | 🔴 | §7.5 vs BR-19 | `Submission.version` on one mutable row contradicts «تحفظ الإصدار السابق ولا تحذفه» | Append-only submissions + partial unique index on `is_current` | 5 |
| 20 | **GAP-D6** | 🔴 | §7.5, §7.7 | `Evaluation.entity_id` has **no foreign key**, and §7.7's cross-table `score <= max_score` CHECK cannot be created | Split the polymorphism; denormalise `max_score` with a composite FK | 5 |
| 21 | **GAP-D2** | 🟠 | §9.2.1, §14.1 | "Arabic letters only" rejects «عبد الله»; the naive range admits tatweel and Arabic-Indic digits | Explicit letter allow-list + single internal space + NFC + control-char strip | 1 |
| 22 | **GAP-D3** | 🟠 | §7.2 | `seats_taken` has no concurrency story — capacity can be oversold | Conditional increment in the enrolment transaction + `CHECK (seats_taken <= capacity)` | 1 |
| 23 | **GAP-D5** | 🟠 | §7.8 vs §12.6 | Erasure right vs the obligation to retain certificate records | Tombstone contact PII; retain certificate-bearing fields; state the split in the privacy policy | 8 |
| 24 | **GAP-D7** | 🟠 | §9.4.2 | Name change after certificate issuance requires admin approval, but no table or flow is defined | Add `name_change_requests`; on approval re-issue with a new serial and revoke the old | 8 |
| 25 | **GAP-S1** | 🔴 | §6.1, §12.1 vs the platform | **`argon2` is explicitly unsupported on Workers, and the obvious fallback — PBKDF2 — is capped at 100,000 iterations, 6× below OWASP** | Use **`node:crypto.scrypt`** (supported, memory-hard, OWASP-listed, no cap) with a profile that fits the 128 MB isolate. **Document the `argon2id → scrypt` substitution and get approval.** A **Paid plan is a hard prerequisite** — the Free plan's 10 ms CPU limit cannot host any OWASP-strength hash. | 0 |
| 26 | **BLOCKER-S2** | 🔴 | §12.6, §19 | **Cross-border transfer.** There is **no blanket KSA localisation mandate** — but **D1, R2 and KV have no KSA storage residency and it is not configurable** (“D1 databases do not run in” the Middle East) | Route: Art 29(1)(D) → Transfer Reg. Art 2(2) + **Saudi SCCs (Template 2, unmodified)** + a **mandatory Transfer Risk Assessment** under Art 7(1)(A). **The practical blocker is contractual: SCC Rules 5 and 8 forbid modification and require the importer to submit to KSA jurisdiction — confirm Cloudflare will sign BEFORE fixing the architecture.** | 0 |
| 26a | **PDPL-REG** | 🔴 | not in the PRD | **National Register enrolment on the National Data Governance Platform is mandatory** where the controller's main activity is personal-data processing — and it is the **prerequisite for filing a 72-hour breach report at all** | Register in Phase 0; add to the §ملحق ب checklist | 0 |
| 26b | **PDPL-DPIA** | 🔴 | not in the PRD | A written **DPIA is mandatory** here — Impl. Reg. Art 25(1)(c)/(d) is triggered by the impersonation feature's systematic access to private communications. **Art 25(3) requires giving a copy of the DPIA to the processor.** | Conduct before Phase 8; file with the RoPA | 8 |
| 26c | **PDPL-BREACH** | 🔴 | §12 (absent) | The PRD has **no incident-response process**, yet notification to SDAIA is due **within 72 hours** and to data subjects **without undue delay** | Documented process, named roles, `security_incidents` register, Platform registration | 1 |
| 27 | **§19 #12** | 🔴 | §19, §12.6 | The data-retention period is an open decision and cannot ship unresolved | Set a period per data category; publish it in the privacy policy | 1 |
| 28 | **CONFLICT-E1** | 🟠 | §9.2.1 vs BR-30 | Registration reveals whether an email — and a **phone number** — is registered, which BR-30 forbids for login | Keep the email message (gated by Turnstile + rate limit); make the **phone** message generic and notify the existing account | 1 |
| 29 | **E-1** | 🔴 | §9.18, §4.2 | Excel exports permit **formula injection → command execution on the admin's machine** | Prefix any cell beginning `= + - @ TAB CR` with an apostrophe, in every export | 8 |
| 30 | **E-2** | 🔴 | §9.2.3, §9.3.3 | Corporate email link scanners consume the single-use verification/reset token before the user clicks | GET renders; POST consumes | 1 |
| 31 | **E-3** | 🔴 | §9.2.1, §9.13, §9.17 | Bidi control characters in names rewrite the participants list, the chat, **the impersonation banner** and **the certificate** | Strip bidi and zero-width characters on write, everywhere | 1 |
| 32 | **E-6** | 🟠 | §9.8.2 | Rescheduling a session that has check-ins retroactively reclassifies present/late | Once any attendance row exists, `starts_at`/`ends_at` are immutable — cancel and recreate | 4 |
| 33 | **E-11** | 🟠 | §9.17 | Changing `pass_score`/`min_attendance_rate` would retroactively invalidate issued certificates | Freeze both thresholds onto the certificate at issue | 8 |
| 34 | **§5.6** | 🟠 | §12.4 | A per-IP limit on attendance would block a whole cohort sharing one network | Attendance limits are **per-account only** | 4 |
| 35 | **§3.3** | 🟠 | §4.5.2 vs D1 | The database-level read-only guarantee §4.5.2 demands **is not achievable on D1** (no roles, no `GRANT`) | Use PostgreSQL via Hyperdrive, or accept a weaker binding-level control and document it | 0 |
| 36 | **§1.7** | 🔴 | not in the PRD | **`Date.now()` is frozen between I/O in production but advances normally in `wrangler dev`** — so a clock-dependent bug passes every local test and fails only in production | The authoritative instant comes from the database inside the write statement (§1.8), removing the dependency entirely | 4 |
| 37 | **§5.6** | 🟠 | §12.4 | The native Workers Rate Limiting binding is **per-datacentre and eventually consistent**, and its period must be 10 or 60 s — it cannot express §12.4's 15-minute or hourly limits | Implement every §12.4 limit with a **Durable Object**; use the binding only as an outer dampener | 1 |
| 38 | **§5.8** | 🟠 | §9.9.5 | Cron Trigger **execution guarantees are undocumented** by Cloudflare | Treat the cron as a tick only; make handlers idempotent; put must-not-be-lost work behind a **Durable Object Alarm** (the one documented at-least-once primitive) | 4 |
| 39 | **§1.8** | 🟠 | §6.1 if D1 is chosen | **D1 rejects `BEGIN`/`COMMIT` outright** and caps queries at **100 bound parameters**; `batch()` is the only atomicity primitive | Single-statement or `batch()` designs only; page the sweep job | 0 |

---

# Appendix B — Sources

## Cloudflare Workers platform behaviour

All Cloudflare claims below were verified against the linked official documentation, changelog entries, or `workerd` source/issues.

| Ref | Claim | Source |
|---|---|---|
| **[W1]** | “`Date.now()` returns the time of the last I/O; it does not advance during code execution.” `performance.timeOrigin` is 0, so `performance.now()` always equals `Date.now()` | https://developers.cloudflare.com/workers/runtime-apis/web-standards/ |
| **[W2]** | “As a security measure to mitigate against Spectre attacks … timers … only advance or increment after I/O occurs.” **“In local development, however, timers will increment regardless of whether I/O happens or not.”** | https://developers.cloudflare.com/workers/runtime-apis/performance/ |
| **[W2a]** | The clock freeze was **retained** after the August 2026 Spectre research; the response was V8 sandbox hardening and Memory Protection Keys | https://blog.cloudflare.com/revisiting-spectre-attacks-on-workers/ |
| **[W3]** | ⚠️ **No published inter-datacentre clock-skew bound or SLA for Workers.** Cloudflare operates NTP/NTS/Roughtime and refers qualitatively to “tight bounds… through Cloudflare Time Services”, with **no number attached**. Roughtime's own design target is only ±10 s | https://developers.cloudflare.com/time-services/ · https://blog.cloudflare.com/announcing-cfnts/ · https://blog.cloudflare.com/rearchitecting-workers-kv-for-redundancy/ |
| **[W4]** | D1 UPSERT (`ON CONFLICT … DO UPDATE`), `RETURNING`, **partial indexes** (documented with a `WHERE` example) and **generated columns** (`STORED`/`VIRTUAL`) all work. ⚠️ UPSERT itself is **not explicitly documented** by Cloudflare — it follows from “D1 is compatible with most SQLite's SQL convention” plus documented features that require SQLite ≥ 3.31, well past 3.24 where UPSERT landed. ⚠️ Against a **partial** unique index the predicate must be repeated in the conflict target | https://developers.cloudflare.com/d1/sql-api/sql-statements/ · https://developers.cloudflare.com/d1/best-practices/use-indexes/ · https://developers.cloudflare.com/d1/reference/generated-columns/ · https://sqlite.org/lang_upsert.html |
| **[W5]** | **D1 rejects SQL transactions** (`cannot start a transaction within a transaction`). “Batched statements are SQL transactions. If a statement in the sequence fails … it aborts or rolls back the entire sequence.” No interactive transactions | https://developers.cloudflare.com/d1/worker-api/d1-database/ · https://developers.cloudflare.com/d1/best-practices/import-export-data/ |
| **[W5a]** | D1 limits: **100 bound parameters per query**, 50 queries/invocation (Free) / 1,000 (Paid), 100 KB statement, 2 MB row, 30 s query, 500 MB DB (Free) / 10 GB (Paid) | https://developers.cloudflare.com/d1/platform/limits/ |
| **[W5b]** | “To use read replication, you must use the D1 Sessions API, otherwise all queries will continue to be executed only by the primary database.” | https://developers.cloudflare.com/d1/best-practices/read-replication/ |
| **[W6]** | D1 exposes no per-connection roles or `GRANT`; a database-enforced read-only mode is unavailable | https://developers.cloudflare.com/d1/ |
| **[W7]** | SQLite triggers with `RAISE(ABORT, …)` and composite foreign keys; `PRAGMA foreign_keys` governs FK enforcement | https://www.sqlite.org/lang_createtrigger.html · https://www.sqlite.org/foreignkeys.html |
| **[W8]** | ⭐ “All `node:crypto` APIs are fully supported in Workers with the following exceptions” — and the exception list states **“`argon2` and `argon2Sync` are not supported”**. `scrypt`/`scryptSync` are **absent from the exception list**, i.e. supported | https://developers.cloudflare.com/workers/runtime-apis/nodejs/crypto/ |
| **[W8a]** | ⭐ **`nodejs_compat` and `nodejs_compat_v2` are enabled by default for compatibility dates ≥ 2026-08-04** | https://developers.cloudflare.com/changelog/post/2026-08-04-nodejs-compat-default/ · https://developers.cloudflare.com/workers/configuration/compatibility-flags/ |
| **[W8b]** | WASM is supported, but Cloudflare uses a non-standard loading path (`Wasm code generation disallowed by embedder`); larger Workers start more slowly | https://developers.cloudflare.com/workers/runtime-apis/webassembly/ · https://github.com/Daninet/hash-wasm/discussions/56 · https://github.com/auth70/argon2-wasi |
| **[W9]** | OWASP: **PBKDF2-HMAC-SHA256 → 600,000 iterations**; Argon2id `m=47104,t=1,p=1` through `m=7168,t=5,p=1`; scrypt `N=2^17,r=8,p=1` through `N=2^13,r=8,p=10`; bcrypt work factor ≥ 10 | https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html |
| **[W9a]** | ⭐ **PBKDF2 is capped at 100,000 iterations in Workers** (`NotSupportedError: Pbkdf2 failed: iteration counts above 100000 are not supported`), enforced by `checkPbkdfLimits()` in `workerd`. **Issue #1346, “crypto: 100,000 iterations of PBKDF2 is insecure”, open since Oct 2023 with no team response.** The cap is **not documented** on Cloudflare's Web Crypto page | https://github.com/cloudflare/workerd/issues/1346 |
| **[W9b]** | Independent confirmation that **Workers now support `node:crypto.scrypt`** natively | https://github.com/better-auth/better-auth/issues/8456 |
| **[W10]** | `HTMLRewriter` is a native streaming transformer. ⚠️ It takes a `Response`, not a string, and “a lexical tree text node can be represented by multiple chunks” — so it is **not** a sanitiser and ships no allow-list | https://developers.cloudflare.com/workers/runtime-apis/html-rewriter/ |
| **[W10a]** | ⚠️ **`isomorphic-dompurify` is broken on Workers** — `cloudflare/workerd` issue #5752, open and assigned | https://github.com/cloudflare/workerd/issues/5752 |
| **[W10b]** | The standard `Sanitizer` API is **not supported and not planned**: “it would be a massive project. We don't currently have any plans to do this.” | https://github.com/cloudflare/workerd/discussions/479 |
| **[W10c]** | `xss` (js-xss) — pure JS, zero Node dependencies, whitelist-based. `sanitize-html` works once `nodejs_compat` is on. ⚠️ **`@cloudflare/sanitize` does not exist** | https://github.com/leizongmin/js-xss · https://worksonworkers.southpolesteve.workers.dev/ |
| **[W12]** | Durable Objects: one active instance per object, globally unique name, “transactional, strongly consistent, and serializable storage”; input/output gates | https://developers.cloudflare.com/durable-objects/ · https://blog.cloudflare.com/durable-objects-easy-fast-correct-choose-three/ |
| **[W12a]** | ⭐ The native Rate Limiting binding (GA Sept 2025) is **per-colo** (“a unique limit per Cloudflare location”), “**permissive, eventually consistent, and intentionally designed to not be used as an accurate accounting system**”, and its `period` “must be either 10 or 60” seconds | https://developers.cloudflare.com/workers/runtime-apis/bindings/rate-limit/ · https://developers.cloudflare.com/changelog/post/2025-09-19-ratelimit-workers-ga/ |
| **[W13]** | `file-type` works on Workers via `fileTypeFromBuffer()` (ESM; avoid `fileTypeFromFile()`, which needs `node:fs`) | https://github.com/sindresorhus/file-type · https://worksonworkers.southpolesteve.workers.dev/ |
| **[W13a]** | `magic-bytes.js` — zero dependencies; “it may be enough to load the file's first 100 bytes and validate against them” | https://github.com/LarsKoelpin/magic-bytes |
| **[W14]** | Cron Triggers run on **UTC**; max frequency **every minute**; 5 (Free) / 250 (Paid) per account; 30 s CPU for sub-hourly intervals; config propagation up to 15 min. ⚠️ **The exactly-once vs at-least-once guarantee is NOT documented**; `controller.noRetry()` implies retries with unstated policy | https://developers.cloudflare.com/workers/configuration/cron-triggers/ · https://developers.cloudflare.com/workers/platform/limits/ |
| **[W14a]** | ⭐ Durable Object Alarms are the one primitive with a documented guarantee: “at-least-once execution … retried automatically … exponential backoff starting at a 2 second delay … up to 6 retries” | https://developers.cloudflare.com/durable-objects/api/alarms/ |
| **[W15]** | Workers WebCrypto: `crypto.randomUUID()`, `crypto.getRandomValues()`, HMAC/SHA-256 sign+verify, PBKDF2/HKDF derive, and the Cloudflare extension **`crypto.subtle.timingSafeEqual`** — “Compare two buffers in a way that is resistant to timing attacks” | https://developers.cloudflare.com/workers/runtime-apis/web-crypto/ |
| **[W16]** | `CF-Connecting-IP` is the Cloudflare-set client IP; `X-Forwarded-For` is client-controllable | https://developers.cloudflare.com/fundamentals/reference/http-headers/ |
| **[W17]** | R2 presigned URLs, custom domains, lifecycle rules | https://developers.cloudflare.com/r2/api/s3/presigned-urls/ · https://developers.cloudflare.com/r2/buckets/object-lifecycles/ |
| **[W18]** | Hyperdrive connects Workers to an external PostgreSQL database with pooling | https://developers.cloudflare.com/hyperdrive/ |
| **[W19]** | `jose` officially lists **Cloudflare Workers** as a supported runtime; zero dependencies, JWS/JWE/JWK/JWT | https://github.com/panva/jose |

## Saudi PDPL

Primary sources are SDAIA's own published English texts. Where a claim rests on law-firm commentary rather than the statutory text, it is marked.

| Ref | Claim | Source |
|---|---|---|
| **[P1]** | **PDPL full text** — Royal Decree M/19 (2021), amended by M/148 (2023). Art 2 scope and extraterritoriality; Art 4 the five data-subject rights; Arts 5–7 consent; Art 6 legal bases; Arts 12–13 privacy notice; Art 18 destruction; Art 19 security; Art 20 breach; Art 22 DPIA; **Art 28 prohibition on copying identity documents**; **Art 29 cross-border transfer**; Art 31 RoPA; **Arts 35–36 penalties (SAR 3M criminal / SAR 5M administrative)**; Art 43 entry into force | https://sdaia.gov.sa/en/SDAIA/about/Documents/Personal%20Data%20English%20V2-23April2023-%20Reviewed-.pdf |
| **[P2]** | **Implementing Regulation** — Art 3(1)(a) **30-day + 30-day** response deadline; Art 4 notice contents; Art 8 destruction incl. backups; Art 9 anonymisation; Art 11 consent (**explicit only for sensitive/credit/automated decisions**; separate consent per purpose); Art 13 guardian consent (**no age threshold**); Art 16 legitimate-interest assessment; Art 17 processor agreements; **Art 24 the 72-hour breach deadline**; **Art 25 DPIA triggers and the Art 25(3) copy-to-processor duty**; **Art 32 DPO triggers**; Art 33 RoPA (**retained 5 years after processing ends**) | https://sdaia.gov.sa/en/SDAIA/about/Documents/ImplementingRegulationPersonalDataProtectionLaw.pdf |
| **[P3]** | **Regulation on Personal Data Transfer outside the Kingdom (v2.0, 2024)** — Art 2 additional purposes; Art 3 the (unpublished) adequacy list; **Art 4 safeguards and exemptions**; Art 5 onward transfers; **Art 7 the mandatory Transfer Risk Assessment** | https://sdaia.gov.sa/Documents/RegulationonPersonalDataEN.pdf |
| **[P4]** | Cloudflare data-localisation options and regional services | https://developers.cloudflare.com/data-localization/region-support/ · https://developers.cloudflare.com/data-localization/compatibility/ |
| **[P5]** | Enforcement is live — SDAIA committees issued **48 decisions** in the reporting year; marketing without prior consent is the most widespread category. ⚠️ No individual fine amounts or named targets are published | https://iapp.org/news/a/saudi-arabia-s-data-protection-authority-steps-up-enforcement · https://www.dlapiperdataprotection.com/index.html?t=law&c=SA |
| **[P6]** | **Personal Data Breach Incidents Procedural Guide** (Oct 2024) — the 72-hour notice is filed through the **National Data Governance Platform**, so Platform registration is a practical prerequisite | https://sdaia.gov.sa/en/SDAIA/about/Documents/PersonalDataBreachIncidents.pdf |
| **[P7]** | **Rules for Appointing a Data Protection Officer** (v1.0, Aug 2024) — written appointment; contact details to SDAIA immediately via the Platform; “core activities” defined; **HR processing expressly not core** | https://sdaia.gov.sa/en/SDAIA/about/Documents/RulesforAppointingPersonalDataProtectionOfficer.pdf |
| **[P8]** | **Rules Governing the National Register of Controllers** — registration mandatory where the controller's **main activity is personal-data processing**, or it processes sensitive data; certificate valid up to 5 years | https://sdaia.gov.sa/Documents/TheRulesGoverningTheNationalRegisterOfControllersWithinTheKingdomPublicEN.pdf |
| **[P9]** | **Privacy Policy Guideline** (v1.0, Aug 2024) — ten required elements incl. retention period and a complaint/objection mechanism. Expressly non-binding | https://sdaia.gov.sa/Documents/PrivacyPolicyGuideline.pdf |
| **[P10]** | ⭐ **NCA Cloud Cybersecurity Controls CCC-2:2024** — Annex D records the **deletion** of subcontrols 2-3-P-1-10 and 2-3-P-1-11, which had required cloud services be provided from within the Kingdom; localisation is deferred to the NDMO. **Citing “NCA CCC requires in-Kingdom hosting” is citing the superseded 2020 version** | https://nca.gov.sa/en/regulatory-documents/controls-list/ccc/ |
| **[P11]** | ⭐ **“D1 location hints are not currently supported for … the Middle East (`me`). D1 databases do not run in these locations.”** Jurisdictions: `eu`, `fedramp` only | https://developers.cloudflare.com/d1/configuration/data-location/ |
| **[P12]** | R2 jurisdictions `eu`, `fedramp`, `us`; no Middle East; jurisdiction is immutable after bucket creation | https://developers.cloudflare.com/r2/reference/data-location/ |
| **[P13]** | Durable Objects accept `locationHint: 'me'` but “**do not spawn in this location**… the Durable Object will spawn in a nearby location which does support Durable Objects” | https://developers.cloudflare.com/durable-objects/reference/data-location/ |
| **[P14]** | **Transfer Risk Assessment Guideline** (Feb 2025) — four phases; requires identifying “the exact geographical location of personal data storage… including the specific country”. Expressly non-binding | https://sdaia.gov.sa/en/SDAIA/about/Documents/RisksTransferringDataOutsideKingdomEn.pdf |
| **[P15]** | ⭐ **Standard Contractual Clauses** (v1.0, Sept 2024) — four modular templates. **Rule 5: modifications “shall be deemed a violation”. Rule 8: the importer “submits to the jurisdiction of the Kingdom”. Rule 9: must cooperate with SDAIA audits** | https://sdaia.gov.sa/Documents/StandardContractualClausesForPersonalDataTransferEN.pdf |
| **[P16]** | **Binding Common Rules Guidelines** — intra-group transfers only; not available for third-party vendor transfers | https://sdaia.gov.sa/Documents/CommonRulesBCRForPersonalDataTransferEN.pdf |
| **[P17]** | ⚠️ **No adequacy list has been published** by SDAIA, so the Art 29(2)(b) route is unavailable in practice and every private-sector outbound transfer needs an Art 4 exemption plus a safeguard | https://www.kslaw.com/news-and-insights/international-personal-data-transfers-under-saudi-arabias-data-protection-law · https://www.globalprivacyblog.com/2025/03/kingdom-of-saudi-arabia-issues-new-data-transfer-risk-assessment-guidelines/ |
| **[P18]** | ⭐ **No general localisation mandate exists** for private-sector personal data — “The PDPL permits international transfers under specified conditions… contrary perception suggests some misunderstanding” | https://www.kslaw.com/news-and-insights/international-personal-data-transfers-under-saudi-arabias-data-protection-law · https://practiceguides.chambers.com/practice-guides/data-protection-privacy-2026/saudi-arabia |
| **[P19]** | Grace period ended **14 Sep 2024**; other summaries and the 2023 amendments | https://www.morganlewis.com/pubs/2024/09/saudi-arabia-personal-data-protection-law-transition-period-ends-september-14 · https://www.clydeco.com/en/insights/2024/09/saudi-arabia-s-personal-data-protection-law-become · https://www.loc.gov/item/global-legal-monitor/2023-06-15/saudi-arabia-new-amendments-to-law-regulating-personal-data-adopted/ |
| **[P20]** | RoPA, destruction/anonymisation guidelines; SDAIA committee working rules | https://sdaia.gov.sa/Documents/PersonalDataProcessingActivitiesRecordsGuideline.pdf · https://sdaia.gov.sa/Documents/PersonalDataDestructionAnonymizationAndEncryptionGuideline.pdf · https://sdaia.gov.sa/en/SDAIA/about/Documents/CommitteeWorkingRules.pdf |

### ⚠️ What could not be verified, and what to do about it

| Item | Status | Consequence for this project |
|---|---|---|
| **SAMA cloud/localisation rules** | ❌ primary text unreachable (`rulebook.sama.gov.sa` returned 404). Commentary consistently reports in-Kingdom residency for financial data but **no source cites a clause number** | Out of scope today (the programme is free — §1.5). **Would change the transfer analysis entirely if payments are ever added.** |
| **CST/CITC Cloud Computing Regulatory Framework** | ❌ primary text unreachable. Commentary describes four classification levels with in-Kingdom residency at L3–L4 | Confirm with counsel if the centre is ever classified as a regulated cloud customer |
| **Status of the 2020 NDMO interim localisation rule** | ⚠️ No repeal notice located. Its companion Standards are scoped to public entities and government-data partners, so it is generally treated as inapplicable here | Include in the legal determination (BLOCKER-S2 step 1) |
| **Registration rules for controllers located outside the Kingdom** | ⚠️ Promised in the Register Rules, **not yet published** | Not applicable — the centre is in the Kingdom |
| **Cloudflare inter-colo clock skew** | ❌ **no published bound** | Already designed around: the DB clock is authoritative (§1.7) |
| **Cron Trigger delivery guarantee** | ❌ **undocumented** | Already designed around: idempotent handlers + DO Alarms (§5.8) |
| **D1 UPSERT** | ⚠️ supported by inference and community use, **not explicitly documented** | Verify with an integration test against a real D1 instance in Phase 0 |
| **Max statements per D1 `batch()`** | ❌ undocumented; the 1,000 queries/invocation limit is the effective ceiling | Page the sweep job conservatively |

## Standards and general references

| Ref | Topic | Source |
|---|---|---|
| **[S1]** | Password storage, session management, authorization, CSRF, file upload | https://cheatsheetseries.owasp.org/ |
| **[S2]** | `strict-dynamic` and nonce-based CSP | https://web.dev/articles/strict-csp |
| **[S3]** | `__Host-` cookie prefix semantics | https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Set-Cookie#cookie_prefixes |
| **[S4]** | Fetch Metadata (`Sec-Fetch-Site`) as a CSRF defence | https://web.dev/articles/fetch-metadata |
| **[S5]** | CSV / formula injection in spreadsheet exports | https://owasp.org/www-community/attacks/CSV_Injection |
| **[S6]** | Unicode bidirectional-override injection | https://trojansource.codes/ |
| **[S7]** | Crockford Base32 alphabet | https://www.crockford.com/base32.html |
| **[S8]** | **`Asia/Riyadh` has no DST** — verified locally against the IANA tzdb via `Intl.DateTimeFormat` for every month of 1970–2035; exactly one offset (`GMT+03:00`) was observed across all 66 years | https://www.iana.org/time-zones |

---

# Appendix C — Implementation order

Mapped onto §16's phases, so the blockers are resolved before the code that depends on them.

| Phase | Must be resolved first | Sections to implement |
|---|---|---|
| **0 — التأسيس** | 🔴 **BLOCKER-S2** (data residency) · 🔴 **GAP-S1** (password hashing) · **§3.3** (Postgres vs D1) | §4 schema with all constraints · §5.1 sessions · §5.3 headers · §5.2 CSRF |
| **1 — الهوية والدخول** | GAP-D1 (phone) · GAP-D2 (names) · CONFLICT-E1 · E-2 · E-3 · §19 #12 (retention) | §5.1 · §6.5 · §6.6 · §5.9 privacy notice + consent record |
| **2 — هيكل اللوحة** | 🔴 **CONFLICT-P0** | §2 in full — permissions, scope resolver, repository pattern |
| **3 — المحتوى التدريبي** | 🔴 **A7** (absolute instants) · A4 · DISC-4 | §1.1–1.6 · §6.4 (zoom payload) |
| **4 — الحضور** | 🔴 **DISC-1, DISC-2, A3, A6, A10** · A1 · A11 · E-6 | §1 in full · §7.2–7.3 |
| **5 — المهام والتقييم** | 🔴 **GAP-D4, GAP-D6** · CONFLICT-P1 | §4.6 · §5.7 uploads · §7.6 |
| **6 — المشروع الختامي** | — | BR-15/16 gates · §6.4 |
| **7 — التواصل والإشعارات** | CONFLICT-P2 · E-4 · E-19 | §5.4 XSS · §5.8 cron |
| **8 — الإدارة والشهادات** | 🔴 **GAP-I1** · 🔴 **E-1** · GAP-D5 · GAP-D7 · E-11 | §3 impersonation in full · §4.5 audit chain · §4.7 serials · §6.3 tokens |
| **9 — التجهيز للإطلاق** | — | §7 full suite · penetration test of §2.6 and §3.9 · §5.9 RoPA + DPIA |

---

*End of document 04. Every finding marked 🔴 requires a product-owner decision recorded against §19 before the corresponding phase begins.*
