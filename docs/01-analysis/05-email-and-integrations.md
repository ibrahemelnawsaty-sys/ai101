# 05 — البريد الصادر والتكاملات الخارجية
## Outbound Email & Third-Party Integrations — Implementation Plan

| | |
|---|---|
| **المشروع** | منصة البرامج التدريبية — مركز أثر للتدريب |
| **البرنامج** | البرنامج التأسيسي في الذكاء الاصطناعي (AI 101) |
| **نطاق المنصة** | `ai.athar-dev.edu.sa` |
| **النطاق الأساسي** | `athar-dev.edu.sa` |
| **الاستضافة** | Cloudflare Workers & Pages |
| **مراجع PRD** | §9.16 الإشعارات · §11 نصوص الواجهة · §12.4 حدود الطلبات · §12.6 الخصوصية · §18 المخاطر · §9.10 الزوم · §9.8.2 ICS · §9.17 الشهادات · §9.2.2 CAPTCHA · §19 القرارات المفتوحة |
| **تاريخ التحقق من المصادر** | 2026-09-04 — كل الأسعار والحدود مُتحقَّق منها من الصفحات الحية في هذا التاريخ. راجعها قبل التوقيع على أي عقد. |

> **Language convention in this document.** All user-facing copy (subjects, bodies, button labels, error strings) is in Arabic and is production-ready — copy it verbatim into `locales/ar.json`. All engineering rationale, code, DNS values and provider analysis is in English.

---

## 0. الخلاصة التنفيذية — Executive Summary

**Five decisions this document makes, and the one-line reason for each.**

| # | القرار | الخلاصة |
|---|---|---|
| 1 | **SMTP من داخل Worker** | **ممكن تقنيًا عبر المنفذ 465 — لكنه غير صالح للإنتاج.** المحجوب هو المنفذ 25 وحده، خلافًا للشائع. الرفض مبني على علل مفتوحة في `startTls()` وسقف 6 اتصالات وغياب معالجة الارتداد، لا على حجب المنفذ. |
| 2 | **استخدام كلمة مرور صندوق البريد `info@`** | **مرفوض — ولا يعمل أصلًا.** فحص DNS حي أثبت أن الصندوق على **Google Workspace**، وجوجل ألغت مصادقة كلمة المرور البسيطة نهائيًا. وحتى لو نجحت، فكلمة مرور صندوق بشري = مفتاح حساب كامل. |
| 3 | **المزوّد الأساسي** | **Resend** — الوحيد بدليل Cloudflare Workers رسمي من الطرفين، ويدعم `Idempotency-Key` (عماد تصميم الطابور). |
| 4 | **المزوّد الاحتياطي** | **Brevo** — الحساب موجود فعلًا والنطاق مُتحقَّق منه جزئيًا (اكتشاف من فحص DNS الحي أدناه)، وبنيته التحتية مستقلة عن Resend. |
| 5 | **نطاق الإرسال** | **الجذر `athar-dev.edu.sa`** — لأن الطلب يلزم أن يكون المُرسِل `info@athar-dev.edu.sa` حرفيًا. مسار الارتداد يُعزل على `send.athar-dev.edu.sa`. |

**التكلفة الشهرية في الإنتاج: `$25`** — `$5` باقة Cloudflare Workers Paid (إلزامية: الطبقة المجانية تحدّ المعالج بـ 10ms وتحتفظ برسائل الطابور 24 ساعة فقط) + `$20` باقة Resend Pro. أثناء التطوير: `$5` فقط.

حجم الإرسال المقدّر ≈ **3,400 رسالة** لدفعة من 60 متدربًا على مدى البرنامج (4 أسابيع). **الرقم الحاسم ليس الشهري بل اليومي: ≈ 300 رسالة في يوم الذروة** — وهو ما يستبعد كل طبقة مجانية سقفها 100/يوم.

> 🔴 **أخطر نتيجة في هذا الملف:** فحص DNS الحي أثبت أن النطاق **لا يملك سجل SPF إطلاقًا**. بريد المركز الحالي غير موثَّق، ومعرَّض للرفض من Outlook منذ مايو 2025. **إصلاح هذا السجل هو أول عمل مطلوب، قبل أي كود.** راجع §1.2 و§4.2.

---

## 1. الوضع الحالي للنطاق — Live DNS Audit

> **هذا القسم ليس نظريًا.** أُجري فحص DNS حي على `athar-dev.edu.sa` عبر Cloudflare DNS-over-HTTPS بتاريخ 2026-09-04. النتائج أدناه حقيقية وتغيّر بعض الافتراضات في PRD.

### 1.1 ما وجدناه فعليًا

| السجل | القيمة الموجودة | الدلالة |
|---|---|---|
| `NS` | `jermaine.ns.cloudflare.com`, `molly.ns.cloudflare.com` | **DNS مُدار بالفعل على Cloudflare.** إضافة السجلات المطلوبة أدناه عملية دقائق، ولا تحتاج تنسيقًا مع مسجّل خارجي. |
| `MX` | `1 smtp.google.com` | **صندوق البريد على Google Workspace.** هذا هو سجل MX المبسّط الذي أطلقته جوجل. يعني أن `info@` و `contact@athar-dev.edu.sa` هما صندوقا Gmail مُدارَان. |
| `TXT` (جذر) | `google-site-verification=VmOPpjYZ…` | تحقق ملكية Google Workspace. |
| `TXT` (جذر) | `brevo-code:bf015be51d24182e6ba9bfd0d3da8150` | **حساب Brevo موجود بالفعل** وأحدهم بدأ توثيق النطاق عليه. |
| `TXT` `google._domainkey` | `v=DKIM1;k=rsa;p=MIIBIjANBgkq…` (مفتاح 2048-bit) | **DKIM مفعّل لبريد Google Workspace.** |
| `TXT` `_dmarc` | `v=DMARC1; p=none; rua=mailto:rua@dmarc.brevo.com` | **DMARC موجود عند `p=none`** وتقاريره تُرسل إلى مُجمِّع Brevo. |
| `A` (جذر) | `104.21.50.48`, `172.67.157.27` | الموقع الرئيسي خلف بروكسي Cloudflare. |
| `ai.athar-dev.edu.sa` | **NXDOMAIN** | نطاق المنصة **لم يُنشأ بعد**. |
| `TXT` `v=spf1 …` | **غير موجود إطلاقًا** | 🔴 **ثغرة حرجة.** |
| `TXT` `brevo._domainkey` / `mail._domainkey` | **غير موجود** | توثيق Brevo **ناقص** — سجل التحقق مضاف لكن DKIM لا. |

### 1.2 الثلاث نتائج التي تغيّر الخطة

**🔴 1 — لا يوجد سجل SPF على الإطلاق.**
هذه أخطر نتيجة في الفحص. النطاق يرسل بريدًا اليوم عبر Google Workspace بلا أي سجل SPF. النتيجة العملية:
- كل رسالة تخرج من `contact@athar-dev.edu.sa` تفشل في فحص SPF (`none`)، وتعتمد كليًا على DKIM لتجتاز DMARC.
- Microsoft طبّقت اعتبارًا من **5 مايو 2025** رفضًا صريحًا على مستوى SMTP للمرسلين غير المُوثَّقين إلى Outlook.com/Hotmail/Live بالخطأ `550 5.7.15 Access denied, sending domain does not meet the required authentication level` ([Microsoft sender requirements — dmarcian](https://dmarcian.com/microsoft-enforces-spf-dkim-dmarc/)).
- **إصلاح هذا السجل هو أول عمل يجب تنفيذه، قبل أي كود.** راجع §4.2.

**🟡 2 — الصندوق على Google Workspace، لا على استضافة cPanel.**
هذا يحسم سؤال «من يستضيف بريد النطاق» الوارد في المهمة. الأثر المباشر:
- **لا يمكن استخدام كلمة المرور المزوّدة `<MAILBOX_PASSWORD — stored as a Wrangler secret>` مع SMTP إطلاقًا.** جوجل أوقفت «الوصول للتطبيقات الأقل أمانًا» نهائيًا؛ `smtp.gmail.com` يرفض كلمة مرور الحساب العادية ويقبل فقط **App Password** (يتطلب تفعيل التحقق بخطوتين) أو OAuth 2.0.
- يوجد بديل رسمي مدعوم: **Google Workspace SMTP Relay** — راجع §3.7.

**🟢 3 — Brevo موجود مسبقًا، وDMARC عند `p=none` بالفعل.**
هذا يوفّر وقتًا: الاحتياطي المقترح ليس مزوّدًا جديدًا يحتاج موافقة مشتريات، بل حساب قائم. يحتاج فقط إكمال DKIM.

### 1.3 توصية أمنية عاجلة — Credential Rotation

> ⚠️ **إلزامي.** كلمة مرور صندوق `info@athar-dev.edu.sa` وصلت إلى فريق التطوير **بنص صريح داخل رسالة**. يجب اعتبارها مكشوفة (compromised) بغض النظر عن أي شيء آخر.
>
> **الإجراء المطلوب من العميل خلال 24 ساعة:**
> 1. تغيير كلمة مرور الصندوق من لوحة Google Workspace.
> 2. تفعيل التحقق بخطوتين (2SV) على الحساب.
> 3. مراجعة «الأجهزة والجلسات النشطة» وإنهاء أي جلسة غير معروفة.
> 4. مراجعة قواعد إعادة التوجيه (forwarding rules) و «Send mail as» — أشهر أثر لاختراق صندوق بريد.
> 5. **عدم** تسليم كلمة المرور الجديدة لفريق التطوير. المنصة لن تحتاجها إطلاقًا بعد اعتماد المعمارية أدناه.
>
> لا ترد كلمة المرور في أي ملف من ملفات هذا المستودع. حيثما لزمت الإشارة إليها تُكتب `<MAILBOX_PASSWORD — stored as a Wrangler secret>`.

---

---

## 2. مشكلة SMTP داخل Cloudflare Workers — The Definitive Verdict

هذا هو السؤال الحاسم في الملف. الإجابة **ليست** ما يتداوله معظم المحتوى المنشور على الإنترنت، لذا نفصّلها بدقة ونفصل بين ثلاثة أسئلة يخلط بينها كثيرون:
① هل SMTP **ممكن** تقنيًا؟ ② هل استخدام **هذه** البيانات ممكن؟ ③ هل هو **منصوح به**؟

### 2.1 هل يستطيع Worker فتح اتصال TCP صادر؟ — نعم

عبر `connect()` من وحدة `cloudflare:sockets`، وهي واجهة تشغيل قياسية (لا تحمل وسم beta):

```js
import { connect } from "cloudflare:sockets";
const socket = connect({ hostname: "smtp.example.com", port: 465 }, { secureTransport: "on" });
```

قيم `secureTransport`: `"off"` · `"on"` (TLS فوري) · `"starttls"` (يبدأ بلا تشفير ثم يُرقّى عبر `startTls()`).
**المصدر:** [Cloudflare Docs — TCP Sockets](https://developers.cloudflare.com/workers/runtime-apis/tcp-sockets/)

كما أن `node:net` مدعومة الآن **ومبنية فوق `cloudflare:sockets` نفسها** — أي أن قيود المنافذ تنطبق عليها بالتبعية. أما `node:tls` فمدعومة جزئيًا: `connect` و `TLSSocket` فقط، و `tls.Server` يرمي `Not implemented`.
**المصدر:** [Node.js compatibility](https://developers.cloudflare.com/workers/runtime-apis/nodejs/) · [`node:net`](https://developers.cloudflare.com/workers/runtime-apis/nodejs/net/)

### 2.2 هل المنافذ 25 / 465 / 587 محجوبة؟ — المنفذ 25 فقط

> ⚠️ **تصحيح لاعتقاد شائع.** كثير من المقالات والصفحات التسويقية تزعم أن «Workers لا تملك واجهة sockets وأن SMTP مستحيل بنيويًا فيها». **هذا الزعم مخالف لتوثيق Cloudflare نفسه.** لا تبنِ قرارًا عليه.

نصّ التوثيق الرسمي حرفيًا:

> **"By default, Workers cannot create outbound TCP connections on port `25` to send email to SMTP mail servers."**

ورسالة الخطأ عند المحاولة: `Connections to port 25 are prohibited`.

| المنفذ | الحالة الموثّقة |
|---|---|
| **25** (relay) | 🔴 **محجوب صراحةً.** ولا توجد آلية موثّقة لطلب استثناء. |
| **465** (Implicit TLS) | 🟢 **غير محجوب.** يعمل مع `secureTransport: "on"`. |
| **587** (Submission/STARTTLS) | 🟢 **غير محجوب** — لكن مسار STARTTLS نفسه به علل مفتوحة (§2.3). |

القيود الشبكية الأخرى الموثّقة هي فقط: حجب الاتصال بنطاقات IP التابعة لـ Cloudflare، و `localhost`، والشبكات الخاصة، واكتشاف الحلقات (`TCP Loop detected`).
**المصدر:** [TCP Sockets — Considerations & Troubleshooting](https://developers.cloudflare.com/workers/runtime-apis/tcp-sockets/)

**تأكيد مستقل من مكتبة عاملة:** مكتبة `worker-mailer` (عميل SMTP مبني مباشرة فوق `cloudflare:sockets`، بلا تبعيات) تنص في قسم القيود حرفيًا:

> *"Cloudflare Workers cannot make outbound connections on port 25. You won't be able to send emails via port 25, but common ports like 587 and 465 are supported."*

**المصدر:** [github.com/zou-yu/worker-mailer](https://github.com/zou-yu/worker-mailer)

**الخلاصة الفنية:** SMTP على Workers **ممكن** عبر المنفذ 465. المكتبة موجودة وتعمل. الحجب يقتصر على منفذ الترحيل 25.

### 2.3 هل هي صالحة للإنتاج؟ — لا. سبعة أسباب تقنية

كون الشيء ممكنًا لا يجعله صحيحًا. الحواجز التالية تنطبق **حتى مع** مكتبة عاملة ومنفذ مفتوح:

**① علل مفتوحة في `startTls()` على الحافة الإنتاجية.**
- [workerd#2712 — "Unable to use startTls for SMTP"](https://github.com/cloudflare/workerd/issues/2712): مفتوحة منذ 2024-09-14، آخر تحديث 2026-06-10. `startTls()` يتعلّق بعد إرسال `STARTTLS\r\n` على المنفذ 587 مقابل ProtonMail و Outlook. **علّة عمرها عامان تخص SMTP تحديدًا وما زالت مفتوحة.**
- [workerd#6903 — "`startTls({ expectedServerHostname })` ignored on the production edge"](https://github.com/cloudflare/workerd/issues/6903): مفتوحة، آخر تحديث 2026-09-02. حزمة ClientHello تخرج **بلا امتداد SNI** على الحافة الإنتاجية بينما تعمل بشكل صحيح في `wrangler dev`. **أي أن الاختبار المحلي لا يمثّل الإنتاج** — أسوأ فئة من الأخطاء.

**② سقف ستة اتصالات متزامنة.**
> *"Each Worker invocation can have up to six connections simultaneously waiting for response headers… Opening a TCP socket using the `connect()` API [counts toward this limit]."*

إشعار «إعلان جديد لكل الدفعة» = 60 رسالة. مع سقف 6، تصير الطوابير داخلية وبطيئة.
**المصدر:** [Workers Limits](https://developers.cloudflare.com/workers/platform/limits/)

**③ لا تجميع للاتصالات (no pooling).** SMTP بروتوكول ذو حالة ومحادثة متعددة الجولات (`EHLO`→`STARTTLS`→`AUTH`→`MAIL FROM`→`RCPT TO`→`DATA`→`QUIT`)، صُمّم لإعادة استخدام الجلسة لعشرات الرسائل. لكن Workers بيئة عديمة الحالة تُنشأ وتُدمَّر لكل استدعاء على أي من مئات المواقع الطرفية — **كل رسالة تدفع مصافحة TLS ومصادقة كاملتين من الصفر.**

**④ سمعة IP و rDNS.** الرسالة تخرج من IP تابع لـ Cloudflare Workers لا تملكه، ولا تتحكم بسمعته، ولا يملك سجل PTR يشير إلى نطاقك. **هذا يضاعف خطر مجلد الرسائل غير المرغوبة الذي حذّر منه PRD §18 بدل أن يقلّله.**

**⑤ لا معالجة للارتداد.** SMTP يعطي رمز نجاح لحظيًا فقط. الارتداد المؤجّل والشكاوى تصل **كرسائل** إلى صندوق `MAIL FROM`. لتعرف أن بريد متدرب معطّل ستحتاج قارئ IMAP ومحلّل DSN — مشروع فرعي كامل يوفّره أي مزوّد HTTP عبر webhook واحد.

**⑥ لا إعادة محاولة ولا استمرارية.** فشل عابر = إشعار ضائع نهائيًا. **رابط تفعيل حساب ضائع = متدرب لا يستطيع دخول المنصة إطلاقًا.**

**⑦ لا قابلية للرصد.** لا تسليم، لا فتح، لا شكاوى، لا قوائم قمع (suppression lists).

### 2.4 هل يمكن استخدام `info@athar-dev.edu.sa` + كلمة المرور المزوّدة؟ — لا

هذا سؤال منفصل تمامًا عن قدرة المنصة، وإجابته **لا** لسبب لا علاقة له بـ Cloudflare أصلًا:

**فحص DNS الحي أثبت أن `MX = 1 smtp.google.com` — الصندوق على Google Workspace.**

وجوجل **أوقفت نهائيًا** قبول كلمة مرور الحساب العادية في مصادقة SMTP («الوصول للتطبيقات الأقل أمانًا»). الخيارات المتاحة على `smtp.gmail.com` اليوم هي حصرًا:
1. **App Password** — يتطلب تفعيل التحقق بخطوتين على الحساب أولًا، وينتج سرًّا من 16 حرفًا **غير** كلمة المرور المزوّدة.
2. **OAuth 2.0 (XOAUTH2)** — يتطلب دورة تفويض ورمز تحديث.

> **النتيجة:** كلمة المرور `<MAILBOX_PASSWORD — stored as a Wrangler secret>` **سترفَض من خوادم جوجل حتى لو جُرِّبت من خادم عادي خارج Cloudflare.** المسار مغلق قبل أن نصل إلى نقاش المعمارية.

### 2.5 السبب الأعمق للرفض — الأمان

حتى لو زُوِّدنا بـ App Password صالح، القرار يبقى **لا**. والسبب مبدئي:

كلمة مرور `info@` — و App Password المشتق منها — ليست «سرًّا خاصًّا بالإرسال». هي **مفتاح وصول إلى حساب Google Workspace كامل**. أي تسريب — سجل أخطاء، لقطة شاشة، مطوّر مغادر، مستودع خاص يصبح عامًا، تبعية npm مخترقة — يمنح المهاجم:

- قراءة **كل** البريد الوارد والصادر في الصندوق تاريخيًا؛
- انتحال شخصية المركز في مراسلات مع متدربين وجهات حكومية؛
- الوصول إلى Google Drive و Calendar المرتبطين؛
- إعادة تعيين كلمات مرور خدمات أخرى مسجّلة بهذا البريد.

في المقابل، مفتاح API لمزوّد بريد معاملاتي **يفعل شيئًا واحدًا: يرسل بريدًا.** لا يقرأ شيئًا، ونطاقه محصور، ويُبطَل ويُدوَّر في ثوانٍ بلا تعطيل أي إنسان. هذا مبدأ الامتياز الأدنى في أبسط صوره.

### 2.6 جدول الحكم النهائي

| السؤال | الحكم | السبب الحاسم |
|---|---|---|
| هل SMTP ممكن تقنيًا من Worker؟ | 🟡 **نعم، عبر المنفذ 465** | المنفذ 25 وحده محجوب. `worker-mailer` تعمل. |
| هل هو صالح للإنتاج؟ | 🔴 **لا** | علل `startTls` مفتوحة، سقف 6 اتصالات، لا pooling، لا ارتداد، لا إعادة محاولة، سمعة IP معدومة. |
| هل تعمل بيانات `info@` المزوّدة؟ | 🔴 **لا** | الصندوق على Google Workspace وجوجل ألغت مصادقة كلمة المرور البسيطة. |
| هل هو منصوح به؟ | 🔴 **لا — قاطعًا** | كلمة مرور صندوق بشري = مفتاح حساب كامل. انتهاك صارخ لمبدأ الامتياز الأدنى. |

> **الصياغة التي تُقال للعميل:** «طلبكم أن يصل البريد **من** `info@athar-dev.edu.sa` مُنفَّذ بالكامل — كل رسالة ستحمل هذا العنوان في خانة المُرسِل، وردود المتدربين ستصل إلى صندوقكم. ما نغيّره هو **الطريق** الذي تسلكه الرسالة، لا هويتها. ولا نحتاج كلمة مرور الصندوق لتحقيق ذلك.»

---

## 3. المعمارية الموصى بها — Recommended Sending Architecture

### 3.1 حجم الإرسال المتوقع — Volume Model

كل قرار تسعير يعتمد على هذا الجدول. الأساس: دفعة واحدة من **60 متدربًا**، برنامج **4 أسابيع**، **3 جلسات أسبوعيًا** (12 جلسة)، و**6 مهام** تقريبًا.

| الحدث | الحساب | الإجمالي |
|---|---|---|
| تفعيل + ترحيب | 60 × 2 | 120 |
| تذكير بالجلسات (24 ساعة + ساعة) | 12 جلسة × 60 × 2 | 1,440 |
| نشر مهمة + تذكيرَان لمن لم يسلّم | 6 × (60 + 30 + 30) | 720 |
| رصد الدرجات | 6 × 60 | 360 |
| إعلانات | 8 × 60 | 480 |
| تفعيل المشروع الختامي | 60 | 60 |
| إصدار الشهادات | 60 | 60 |
| انخفاض الحضور | ~50 | 50 |
| أمان (كلمة مرور، جهاز جديد) | ~100 | 100 |
| **الإجمالي للبرنامج كاملًا** | | **≈ 3,400** |

**الرقم الحاسم ليس الشهري بل اليومي.** يوم الذروة (يوم جلسة فيه تذكيران + نشر مهمة + إعلان + رصد درجات) يبلغ:

> **≈ 300 رسالة في اليوم الواحد.**

هذا الرقم وحده يستبعد كل طبقة مجانية سقفها 100 رسالة يوميًا (Resend, MailChannels, SendGrid, Mailgun).

### 3.2 مقارنة المزوّدين — مُتحقَّق منها 2026-09-04

| المزوّد | مجاني/شهر | مجاني/يوم | أول باقة مدفوعة | الفائض /1000 | المصادقة | إقامة بيانات في الاتحاد الأوروبي | دليل Cloudflare رسمي |
|---|---|---|---|---|---|---|---|
| **Resend** ⭐ | 3,000 | **100** | **$20 / 50k** | $0.90 | `Bearer` | ✗ (المنطقة تحدد مكان الإرسال فقط) | **✓ من الطرفين** |
| **Brevo** ⭐ | ~9,000 | **300** (مشترك) | ~$9 / 5k | — | `api-key` | ✓ (بنية أوروبية) | ✗ |
| MailChannels | 3,000 | 100 | ~$10 / 10k | إيقاف | `X-Api-Key` | ✗ | ✗ (أُلغي) |
| SendGrid | **تجربة فقط** | 100 × 60 يومًا | $19.95 / 50k | $1.30→$0.50 | `Bearer` | ✓ | ✗ |
| Mailgun | ~3,000 | 100 | $15 / 10k | $1.30→$1.10 | Basic `api:key` | ✓ | ✗ |
| Zoho ZeptoMail | 10k مرة واحدة | — | $2.50 / 10k | **$0.25** | `Zoho-enczapikey` | ✓ (مركز `.eu`) | ✗ |
| Amazon SES | رصيد $200 | 200 (sandbox) | حسب الاستهلاك | **$0.10** | SigV4 | ✓ | ✗ |
| Postmark | 100 | — | $15 / 10k | $1.80→$1.20 | `X-Postmark-Server-Token` | ✗ | ✗ |
| *Cloudflare Email Service* | 3,000 (على الباقة المدفوعة) | — | $5 (Workers Paid) | $0.35 | ربط أصيل | — | أصيل |

**الاستبعادات وأسبابها:**

| المزوّد | سبب الاستبعاد |
|---|---|
| **MailChannels** | 🔴 **التكامل المجاني مع Cloudflare Workers أُلغي.** تاريخ الإنهاء **30 يونيو 2024** والإغلاق الكامل **31 أغسطس 2024**. ([إعلان MailChannels](https://blog.mailchannels.com/important-update-mailchannels-email-sending-api-for-cloudflare-workers-to-be-terminated/)) وطبقته المجانية الحالية **صندوق رملي فعليًا**: «حتى تضيف وسيلة دفع، الإرسال مقيَّد بعناوين المستخدمين المُتحقَّق منهم في حسابك». غير صالح. |
| **SendGrid** | 🔴 **لا توجد طبقة مجانية دائمة.** الحسابات المنشأة من **25 مارس 2025** تحصل على تجربة **60 يومًا** ثم يتوقف الإرسال. ([Twilio SendGrid Trial](https://support.sendgrid.com/hc/en-us/articles/35270136965403-Twilio-SendGrid-Trial-Account-Plan)) |
| **Postmark** | جودة ممتازة لكن الطبقة المجانية **100 رسالة شهريًا** فقط بلا فائض، ولا إقامة بيانات أوروبية. |
| **Amazon SES** | الأرخص ($0.10/1000) لكن التعقيد التشغيلي عالٍ: صندوق رملي (200/يوم) يتطلب طلب ترقية، توقيع SigV4، وwebhooks عبر SNS لا HTTP مباشر. **غير مبرَّر لـ 3,400 رسالة.** |
| **ZeptoMail** | سعر ممتاز، لكن ⚠️ **«سنحدّث أسعارنا — التسعير الجديد يسري على المسجّلين الجدد اعتبارًا من 1 يوليو 2026»** — أي أن سعر $2.50/رصيد قد لا ينطبق على حساب يُفتح اليوم. عدم يقين تسعيري وقت اتخاذ القرار. |

### 3.3 القرار: Resend أساسيًا، Brevo احتياطيًا

**🥇 الأساسي — Resend**

| المعيار | التقييم |
|---|---|
| دعم Cloudflare Workers | **الوحيد الذي يملك دليلًا رسميًا من الطرفين**: [دليل Cloudflare](https://developers.cloudflare.com/workers/tutorials/send-emails-with-resend/) و[دليل Resend](https://resend.com/docs/send-with-cloudflare-workers). |
| **Idempotency** | ✅ **ترويسة `Idempotency-Key` مدعومة رسميًا** على `/emails` و`/emails/batch`. صلاحية 24 ساعة، حد 256 حرفًا. **هذه الميزة وحدها ترجّح الكفة** — هي العمود الفقري لتصميم الطابور في §3.5. ([API Reference](https://resend.com/docs/api-reference/emails/send-email)) |
| الإرسال المجمّع | `POST /emails/batch` — حتى 100 رسالة في نداء واحد. مثالي لإشعار «إعلان لكل الدفعة». |
| Webhooks | 11 حدث بريد: `email.sent`, `delivered`, `bounced`, `complained`, `failed`, `delivery_delayed`, `suppressed`… مع `svix-id` لمنع التكرار. |
| حد المعدل | 10 طلبات/ثانية لكل فريق — كافٍ بفارق كبير. |
| العيوب | 🔻 سقف 100/يوم على المجاني (يستوجب الترقية). 🔻 لا إقامة بيانات أوروبية حقيقية: «كل بيانات الحساب تُخزَّن في الولايات المتحدة بغض النظر عن منطقة الإرسال». |

**🥈 الاحتياطي — Brevo**

| المعيار | التقييم |
|---|---|
| **جاهز فعلًا** | ✅ **الحساب موجود والنطاق مُتحقَّق منه جزئيًا** — سجل `brevo-code` منشور، وDMARC يرسل تقاريره إلى `rua@dmarc.brevo.com` (من فحص §1). ينقصه DKIM فقط. |
| السقف اليومي | **300/يوم مجانًا** — الوحيد الذي يغطي يوم الذروة (300 رسالة) بلا تكلفة. |
| **استقلال البنية التحتية** | ✅ **حاسم للتجاوز عند الفشل.** Resend مبنية فوق Amazon SES. Brevo تشغّل بنيتها الخاصة. **عطل في SES لا يُسقط المسارين معًا** — وهذا هو جوهر وجود احتياطي. |
| الخصوصية | شركة فرنسية ببنية أوروبية — يخدم موقف PRD §12.6 (نظام حماية البيانات الشخصية السعودي). |
| حد المعدل | 1,000 طلب/ثانية. |
| العيوب | 🔻 **الحصة 300/يوم مشتركة بين التسويقي والمعاملاتي.** يجب ألا تُستخدم لأي حملة تسويقية وإلا استُهلكت حصة الإشعارات. وثّق ذلك للعميل صراحةً. |

**لماذا لا Cloudflare Email Service رغم أنها أصيلة وأرخص؟**
منتج قوي ومرشّح ممتاز للمستقبل ($0.35/1000 و3,000 مشمولة على باقة Workers Paid). لكنه **في نسخة تجريبية عامة منذ أبريل 2026**، ووثائقه تنص: *«الحسابات الجديدة تبدأ بحصة يومية متحفّظة وتتوسّع تدريجيًا حسب سلوك الإرسال»*. ([Email Service Limits](https://developers.cloudflare.com/email-service/platform/limits/)) — **حصة يومية غير معلومة مسبقًا خطر غير مقبول ليوم إطلاق دفعة** ترسل فيه 120 رسالة تفعيل دفعة واحدة. يُعاد تقييمه للدفعة الثانية.

### 3.4 التكلفة الشهرية

| البند | التكلفة | ملاحظة |
|---|---|---|
| Cloudflare Workers Paid | **$5/شهر** | **إلزامي عمليًا**: الطبقة المجانية تحدّ زمن المعالج بـ **10ms** (لا يكفي لتوليد HTML)، وتحتفظ برسائل الطابور **24 ساعة فقط بلا إمكانية تعديل**. |
| Resend Free (تطوير + تجريب) | $0 | 3,000/شهر، 100/يوم. |
| Resend Pro (قبل إطلاق الدفعة) | **$20/شهر** | يزيل السقف اليومي. **الترقية إلزامية قبل يوم الإطلاق.** |
| Brevo Free (احتياطي) | $0 | 300/يوم. |
| R2 (أصول الصور) | $0 | ضمن الطبقة المجانية. |
| **الإجمالي — التطوير** | **$5/شهر** | |
| **الإجمالي — الإنتاج** | **$25/شهر** | |

### 3.5 خط الأنابيب — The Sending Pipeline

**المبدأ الحاكم: لا يُرسل بريد من مسار الطلب إطلاقًا.** كل إشعار يُوضع في طابور، ويُرسل من مستهلك منفصل. سبب ذلك أن فشل مزوّد بريد يجب ألا يُفشل تسجيل متدرب.

```
┌──────────────────────────────────────────────────────────────────────┐
│  المُنتِج — أي مسار في التطبيق                                        │
│  register handler · cron (تذكيرات) · grade handler · admin action     │
└────────────────────────────────┬─────────────────────────────────────┘
                                 │  1. INSERT في جدول notifications (D1)
                                 │     يُولَّد event_id (UUID) — مصدر الحقيقة
                                 │  2. env.EMAIL_QUEUE.send({ event_id, ... })
                                 ▼
                    ┌────────────────────────────┐
                    │  Cloudflare Queue          │
                    │  athar-email               │
                    │  max_retries: 5            │
                    │  max_batch_size: 20        │
                    │  dead_letter_queue: …-dlq  │
                    └─────────────┬──────────────┘
                                  ▼
┌──────────────────────────────────────────────────────────────────────┐
│  المستهلك — email-worker                                             │
│                                                                      │
│  لكل رسالة:                                                          │
│   ① اقرأ الحالة من D1 — إن كانت 'sent' ⇒ ack() فورًا (منع التكرار)   │
│   ② تحقق من تفضيلات المستخدم (PRD §9.16) — إن معطّل ⇒ ack()          │
│   ③ تحقق من قائمة القمع (bounced/complained) ⇒ ack()                 │
│   ④ ولّد HTML + نص عادي من القالب                                    │
│   ⑤ أرسل عبر Resend مع Idempotency-Key = event_id                    │
│      ├─ 2xx ⇒ خزّن provider_message_id، الحالة='sent'، ack()          │
│      ├─ 4xx (عدا 429) ⇒ خطأ دائم: الحالة='failed'، ack() (لا تعِد)   │
│      └─ 429 / 5xx / شبكة ⇒ انتقل إلى ⑥                               │
│   ⑥ إن attempts >= 3 ⇒ جرّب Brevo (نفس المحتوى)                      │
│      ├─ نجاح ⇒ الحالة='sent'، provider='brevo'، ack()                │
│      └─ فشل ⇒ retry({ delaySeconds: 2^attempts × 30 })               │
└────────────────────────────────┬─────────────────────────────────────┘
                                 │ بعد 5 محاولات
                                 ▼
                    ┌────────────────────────────┐
                    │  athar-email-dlq           │
                    │  → تنبيه Sentry + لوحة     │
                    │    المدير                  │
                    └────────────────────────────┘
```

**حدود Cloudflare Queues — مُتحقَّق منها ([Queues Limits](https://developers.cloudflare.com/queues/platform/limits/)):**

| الحد | القيمة | الأثر على تصميمنا |
|---|---|---|
| حجم الرسالة | **128 KB** | نمرّر **معرّفات فقط** لا محتوى HTML. لا اقتراب من الحد. |
| أقصى عدد محاولات | **100** | نضبطها على **5**. |
| أقصى حزمة للمستهلك | **100 رسالة** | نضبطها على **20**. |
| تزامن المستهلك | **250** | يُترك تلقائيًا (توصية Cloudflare). |
| مدة الاحتفاظ | حتى **14 يومًا** (المجاني: 24 ساعة غير قابلة للتعديل) | **سبب إضافي لباقة Workers Paid.** |
| إنتاجية الطابور | 5,000 رسالة/ثانية | أعلى بكثير من حاجتنا. |
| مدة المستهلك | 15 دقيقة | كافية. |
| DLQ | ✅ مدعوم | **إلزامي.** «بلا DLQ، الرسائل التي تبلغ حد المحاولات تُحذف نهائيًا». |
| التسعير | المجاني 10,000 عملية/يوم · المدفوع مليون/شهر ثم **$0.40/مليون** | ~10,200 عملية للبرنامج كاملًا (3 عمليات/رسالة). **مجاني فعليًا.** |

> ⚠️ **مصيدة موثّقة:** *«عند فشل رسالة واحدة داخل حزمة، تُعاد الحزمة كاملة، إلا إذا أقررت باستلام الرسائل صراحةً»*. **لذلك: نادِ `msg.ack()` على كل رسالة ناجحة على حدة.** بدونه، رسالة فاشلة واحدة تعيد إرسال 19 رسالة ناجحة.
> **ولا يوجد تراجع أسّي مدمج (exponential backoff)** — نبنيه يدويًا عبر `msg.attempts`.

### 3.6 الكود

**`wrangler.jsonc`**

```jsonc
{
  "name": "athar-platform",
  "main": "src/index.ts",
  "compatibility_date": "2026-08-04",
  "compatibility_flags": ["nodejs_compat"],

  "queues": {
    "producers": [
      { "binding": "EMAIL_QUEUE", "queue": "athar-email" }
    ],
    "consumers": [
      {
        "queue": "athar-email",
        "max_batch_size": 20,
        "max_batch_timeout": 10,
        "max_retries": 5,
        "dead_letter_queue": "athar-email-dlq"
      }
    ]
  },

  "d1_databases": [
    { "binding": "DB", "database_name": "athar", "database_id": "<D1_DATABASE_ID>" }
  ],

  "r2_buckets": [
    { "binding": "ASSETS", "bucket_name": "athar-email-assets" }
  ],

  "vars": {
    "APP_BASE_URL":     "https://ai.athar-dev.edu.sa",
    "APP_TIMEZONE":     "Asia/Riyadh",
    "MAIL_FROM_EMAIL":  "info@athar-dev.edu.sa",
    "MAIL_FROM_NAME":   "مركز أثر للتدريب",
    "MAIL_REPLY_TO":    "contact@athar-dev.edu.sa",
    "ASSET_BASE_URL":   "https://assets.athar-dev.edu.sa/email/v1"
  }
}
```

**الأسرار — عبر `wrangler secret put` حصرًا. لا تُكتب في أي ملف:**

```bash
wrangler secret put RESEND_API_KEY          # re_...
wrangler secret put BREVO_API_KEY           # xkeysib-...
wrangler secret put RESEND_WEBHOOK_SECRET   # whsec_...  (توقيع Svix)
wrangler secret put TURNSTILE_SECRET_KEY
wrangler secret put ZOOM_CLIENT_SECRET
wrangler secret put SENTRY_DSN
```

> ❌ **لا يوجد `SMTP_PASSWORD` في هذه القائمة، ولن يوجد.** متغيرات `SMTP_HOST/PORT/USER/PASSWORD` الواردة في PRD §6.4 **مُلغاة** ويستعاض عنها بـ `RESEND_API_KEY` و`BREVO_API_KEY`. حدّث `.env.example` وفقًا لذلك.

**`src/email/send.ts` — طبقة الإرسال مع التجاوز عند الفشل**

```ts
export interface OutboundEmail {
  eventId: string;            // UUID — also the idempotency key
  to: string;
  subject: string;
  html: string;
  text: string;
  tags: Record<string, string>;
  listUnsubscribeUrl?: string;
}

type SendResult =
  | { ok: true; provider: "resend" | "brevo"; messageId: string }
  | { ok: false; permanent: boolean; provider: string; status: number; detail: string };

const FROM = (env: Env) => `${env.MAIL_FROM_NAME} <${env.MAIL_FROM_EMAIL}>`;

/**
 * RFC 2047 encoding of the Arabic display name is handled by the provider.
 * We send the raw UTF-8 string "مركز أثر للتدريب <info@athar-dev.edu.sa>";
 * Resend converts it to =?UTF-8?B?...?= on the wire. Verified behaviour —
 * do NOT pre-encode it yourself or it will be double-encoded and render as
 * literal "=?UTF-8?B?..." in the recipient's inbox.
 */

export async function sendViaResend(env: Env, m: OutboundEmail): Promise<SendResult> {
  const headers: Record<string, string> = {};
  if (m.listUnsubscribeUrl) {
    headers["List-Unsubscribe"] = `<${m.listUnsubscribeUrl}>`;
    headers["List-Unsubscribe-Post"] = "List-Unsubscribe=One-Click";
  }

  const res = await fetch("https://api.resend.com/emails", {
    method: "POST",
    headers: {
      "Authorization": `Bearer ${env.RESEND_API_KEY}`,
      "Content-Type": "application/json; charset=utf-8",
      // Guarantees that a queue retry after a timeout never double-sends.
      // Resend deduplicates on this for 24h. Max 256 chars.
      "Idempotency-Key": m.eventId,
    },
    body: JSON.stringify({
      from: FROM(env),
      to: [m.to],
      reply_to: env.MAIL_REPLY_TO,
      subject: m.subject,
      html: m.html,
      text: m.text,
      headers,
      tags: Object.entries(m.tags).map(([name, value]) => ({ name, value })),
    }),
  });

  if (res.ok) {
    const { id } = await res.json<{ id: string }>();
    return { ok: true, provider: "resend", messageId: id };
  }
  const detail = await res.text();
  return {
    ok: false,
    provider: "resend",
    status: res.status,
    detail: detail.slice(0, 500),
    // 4xx other than 429 means the request itself is wrong. Retrying is futile
    // and burns quota — fail it permanently and surface it to the admin panel.
    permanent: res.status >= 400 && res.status < 500 && res.status !== 429,
  };
}

export async function sendViaBrevo(env: Env, m: OutboundEmail): Promise<SendResult> {
  const res = await fetch("https://api.brevo.com/v3/smtp/email", {
    method: "POST",
    headers: {
      "api-key": env.BREVO_API_KEY,           // NOT a Bearer token
      "Content-Type": "application/json; charset=utf-8",
      "accept": "application/json",
    },
    body: JSON.stringify({
      sender:  { name: env.MAIL_FROM_NAME, email: env.MAIL_FROM_EMAIL },
      replyTo: { email: env.MAIL_REPLY_TO },
      to: [{ email: m.to }],
      subject: m.subject,
      htmlContent: m.html,
      textContent: m.text,
      // Brevo has no idempotency header. The D1 status check in the consumer
      // is the dedupe guard on this path — see step ① of the consumer.
      headers: m.listUnsubscribeUrl
        ? {
            "List-Unsubscribe": `<${m.listUnsubscribeUrl}>`,
            "List-Unsubscribe-Post": "List-Unsubscribe=One-Click",
          }
        : undefined,
      tags: Object.values(m.tags),
    }),
  });

  if (res.ok) {
    const { messageId } = await res.json<{ messageId: string }>();
    return { ok: true, provider: "brevo", messageId };
  }
  return {
    ok: false, provider: "brevo", status: res.status,
    detail: (await res.text()).slice(0, 500),
    permanent: res.status >= 400 && res.status < 500 && res.status !== 429,
  };
}
```

**`src/email/consumer.ts` — مستهلك الطابور**

```ts
export default {
  async queue(batch: MessageBatch<EmailJob>, env: Env, ctx: ExecutionContext) {
    for (const msg of batch.messages) {
      try {
        await handleOne(msg, env);
      } catch (err) {
        // Never let one message poison the batch. Explicit per-message retry.
        console.error("email job threw", msg.body.eventId, err);
        msg.retry({ delaySeconds: backoff(msg.attempts) });
      }
    }
  },
};

// Cloudflare Queues has NO built-in exponential backoff. This is it.
// 30s → 60s → 120s → 240s → 480s, capped at 15 minutes.
const backoff = (attempts: number) => Math.min(30 * 2 ** (attempts - 1), 900);

async function handleOne(msg: Message<EmailJob>, env: Env) {
  const { eventId } = msg.body;

  // ① Idempotency guard. D1 is the source of truth, not the queue.
  const row = await env.DB
    .prepare("SELECT status, user_id, type FROM notifications WHERE id = ?")
    .bind(eventId).first<{ status: string; user_id: string; type: string }>();

  if (!row) { msg.ack(); return; }                 // deleted meanwhile
  if (row.status === "sent" || row.status === "failed") { msg.ack(); return; }

  // ② User preference — PRD §9.16: "المستخدم يتحكم في تفعيل وتعطيل كل نوع لكل قناة"
  //    Security/transactional types bypass this check entirely (see §5.9).
  if (!isMandatory(row.type) && !(await emailChannelEnabled(env, row.user_id, row.type))) {
    await mark(env, eventId, "skipped_by_preference");
    msg.ack(); return;
  }

  // ③ Suppression list — never mail a hard-bounced or complained address.
  if (await isSuppressed(env, row.user_id)) {
    await mark(env, eventId, "skipped_suppressed");
    msg.ack(); return;
  }

  // ④ Render
  const mail = await renderEmail(env, eventId);

  // ⑤ Primary, then ⑥ fallback after 3 attempts
  let result = await sendViaResend(env, mail);
  if (!result.ok && !result.permanent && msg.attempts >= 3) {
    console.warn("failing over to brevo", eventId, result.status);
    result = await sendViaBrevo(env, mail);
  }

  if (result.ok) {
    await env.DB.prepare(
      `UPDATE notifications
          SET status='sent', provider=?, provider_message_id=?, sent_at=CURRENT_TIMESTAMP
        WHERE id=?`
    ).bind(result.provider, result.messageId, eventId).run();
    msg.ack();
    return;
  }

  if (result.permanent) {
    await mark(env, eventId, "failed", result.detail);
    msg.ack();                                     // do not retry a 4xx
    return;
  }

  msg.retry({ delaySeconds: backoff(msg.attempts) });
}
```

### 3.7 هوية المُرسِل — MAIL_FROM, Display Name, Reply-To, Tagging

| الترويسة | القيمة | السبب |
|---|---|---|
| `From` | `مركز أثر للتدريب <info@athar-dev.edu.sa>` | يلبّي طلب العميل حرفيًا: البريد **من** `info@`. الاسم المعروض عربي. |
| `Reply-To` | `contact@athar-dev.edu.sa` | PRD §1.2: «بريد التواصل … يظهر في … رسائل النظام». **ردود المتدربين تصل لصندوق التواصل، لا لصندوق النظام.** |
| `Return-Path` (المغلّف) | `send.athar-dev.edu.sa` (يديره Resend) | يحقق محاذاة SPF مع DMARC — راجع §4. |
| `List-Unsubscribe` | على الرسائل الاختيارية فقط | راجع §5.9. |
| `X-Entity-Ref-ID` | `event_id` | مفيد للتتبع في السجلات. |

**ترميز الاسم العربي — نقطة تنفيذ حرجة:**

> أرسل السلسلة العربية **خامًا بترميز UTF-8**. المزوّد يحوّلها إلى `=?UTF-8?B?2YXYsdmD2LIg…?=` وفق RFC 2047 على السلك.
> ❌ **لا تُرمّزها بنفسك.** الترميز المزدوج ينتج ظهور النص الحرفي `=?UTF-8?B?...?=` في صندوق المستقبِل — علّة شائعة ومحرجة.
> 📌 لو اضطُررنا يومًا إلى SMTP: Postmark توثّق أن **UTF-8 في الاسم المعروض غير مدعوم عبر SMTP** ويتطلب API. سبب إضافي لاعتماد HTTP API.

**الوسوم (tags) — إلزامية على كل رسالة:**

```ts
tags: {
  type:      "session_reminder_24h",   // معرّف القالب
  cohort:    "ai101-2026-01",          // معرّف الدفعة
  env:       "production",             // production | staging
  role:      "trainee",                // trainee | trainer | admin
}
```

تتيح هذه الوسوم تصفية لوحة Resend بحسب نوع الإشعار، وعزل مشكلة تسليم في نوع واحد (مثلًا: تذكيرات الجلسات تصل للبريد غير المرغوب بينما التفعيل يصل)، وفصل بيانات `staging` عن الإنتاج.

### 3.8 معالجة الارتداد — Bounce & Complaint Webhook

نقطة النهاية: `POST https://ai.athar-dev.edu.sa/api/webhooks/resend`

```ts
// Resend signs webhooks with Svix. NEVER trust an unverified webhook —
// a forged 'bounced' event would let an attacker suppress a victim's account.
import { Webhook } from "standardwebhooks";

export async function onResendWebhook(req: Request, env: Env) {
  const raw = await req.text();
  const wh  = new Webhook(env.RESEND_WEBHOOK_SECRET);

  let evt: ResendEvent;
  try {
    evt = wh.verify(raw, {
      "webhook-id":        req.headers.get("svix-id")!,
      "webhook-timestamp": req.headers.get("svix-timestamp")!,
      "webhook-signature": req.headers.get("svix-signature")!,
    }) as ResendEvent;
  } catch {
    return new Response("invalid signature", { status: 401 });
  }

  const email = evt.data.to?.[0];

  switch (evt.type) {
    case "email.bounced":
      // Hard bounce ⇒ suppress permanently and flag the account so an admin
      // can contact the trainee by phone/WhatsApp. A trainee who never got the
      // activation link is otherwise invisible to us.
      if (evt.data.bounce?.type === "Permanent") {
        await suppress(env, email, "hard_bounce");
        await flagAccountForAdmin(env, email, "بريد غير صالح — تعذّر التسليم نهائيًا");
      }
      break;

    case "email.complained":
      // Spam complaint ⇒ stop ALL optional mail immediately. Complaint rate
      // above 0.30% breaches Google's threshold and damages domain reputation.
      await suppress(env, email, "complaint");
      break;

    case "email.delivered":
      await recordDelivery(env, evt.data.email_id);
      break;
  }
  return new Response("ok");                        // always 2xx to stop redelivery
}
```

> **قاعدة القمع (suppression):** العنوان المقموع **لا يتلقى أي رسالة اختيارية**. لكنه **يستمر** في تلقي رسائل الأمان (تغيير كلمة المرور، دخول من جهاز جديد) لأن حجبها يخلق ثغرة أمنية. وتُعرض حالة «تعذّر التسليم» في ملف المتدرب في لوحة المدير ليتواصل معه عبر الواتساب.

### 3.9 الخطة البديلة إن أصرّ العميل على بيانات الصندوق

إن رفض العميل استخدام مزوّد معاملاتي وأصرّ على الإرسال «من صندوقه»، هذه هي الخيارات المتاحة **مرتبةً من الأفضل إلى الأسوأ**. جميعها أدنى من §3.3، وتُوثَّق المخاطر كتابيًا قبل التنفيذ.

**الحقيقة الحاكمة:** فحص §1 أثبت `MX = smtp.google.com` ⇒ **الصندوق على Google Workspace**. هذا يحدد الخيارات بدقة.

**الخيار أ — Google Workspace SMTP Relay (الأفضل ضمن السيئ)**

خدمة رسمية من جوجل للتطبيقات التي ترسل نيابة عن النطاق:

| البند | القيمة |
|---|---|
| الخادم | `smtp-relay.gmail.com` |
| المنفذ | **465** (TLS ضمني) — **وهو غير محجوب على Workers** |
| التفويض | بعنوان IP أو بحساب مُصادَق من الوحدة التنظيمية |
| الحد | 10,000 مستقبِل يوميًا لكل نطاق |
| الإعداد | Admin console → Apps → Google Workspace → Gmail → Routing → **SMTP relay service** |

**المزايا:** لا يحتاج كلمة مرور صندوق بشري (التفويض بالـ IP)؛ رسمي ومدعوم؛ يحقق محاذاة DKIM تلقائيًا لأن جوجل توقّع بالمفتاح المنشور فعلًا في `google._domainkey`.
**العيوب القاتلة على Workers:** التفويض بعنوان IP **مستحيل** — عناوين Workers ديناميكية عالميًا ولا يمكن إدراجها في قائمة سماح. يبقى التفويض بحساب مُصادَق، وهو يعيدنا إلى App Password. **وتبقى كل مشاكل §2.3: لا ارتداد، لا إعادة محاولة، لا رصد.**

**الخيار ب — مكوّن صغير خارج Workers (النمط الوحيد المقبول لهذا المسار)**

إن كان لا بد من SMTP، فليكن من مكوّن مصمَّم له، لا من الحافة:

```
Worker (Queue producer)
   └─► Cloudflare Queue
          └─► HTTP POST مُوقَّع (HMAC) ──► خدمة relay صغيرة
                                            (Fly.io / Railway / VPS)
                                            IP ثابت + Nodemailer
                                            └─► smtp-relay.gmail.com:465
```

**المزايا:** IP ثابت واحد يُدرَج في قائمة سماح SMTP relay ⇒ **لا حاجة لكلمة مرور إطلاقًا**؛ تجميع اتصالات SMTP حقيقي؛ إمكانية قراءة الارتداد عبر IMAP.
**العيوب:** مكوّن إضافي يحتاج نشرًا ومراقبة وتحديثات أمنية (~$5/شهر) — أي **أغلى وأعقد من Resend Pro مع جودة أقل**. ونقطة فشل جديدة خارج Cloudflare.

**الخيار ج — App Password مباشرة من Worker عبر `worker-mailer` (مرفوض)**
ممكن تقنيًا (المنفذ 465 مفتوح)، لكنه يجمع كل عيوب §2.3 مع خطر §2.5. **لا يُنفَّذ.**

**جدول القرار للعميل**

| الخيار | يعمل على Workers؟ | يحتاج كلمة مرور؟ | ارتداد؟ | إعادة محاولة؟ | التكلفة | الحكم |
|---|---|---|---|---|---|---|
| **Resend + Brevo (§3.3)** | ✅ | ❌ | ✅ webhook | ✅ | $20 | ✅ **المعتمد** |
| SMTP Relay من مكوّن خارجي | ⚠️ جزئيًا | ❌ (IP allowlist) | ⚠️ IMAP يدوي | ⚠️ يدوي | ~$5 + وقت | 🟡 مقبول على مضض |
| App Password من Worker | ⚠️ منفذ 465 | ✅ **خطر** | ❌ | ❌ | $0 | 🔴 مرفوض |
| كلمة المرور المزوّدة كما هي | ❌ | ✅ | ❌ | ❌ | — | 🔴 **لا تعمل أصلًا** |

---

## 4. خطة DNS والقابلية للتسليم — DNS & Deliverability Plan

PRD §18 يصنّف «وصول رسائل البريد إلى مجلد الرسائل غير المرغوبة» مخاطرةً متوسطة الأثر. فحص §1 أثبت أن الوضع **أسوأ من تقدير PRD**: النطاق يرسل اليوم **بلا سجل SPF إطلاقًا**.

### 4.1 القرار الأول: من الجذر أم من نطاق فرعي؟

**التوصية: الإرسال من الجذر `athar-dev.edu.sa`، بمسار ارتداد معزول على نطاق فرعي.**

هذا يخالف النصيحة العامة الشائعة («أرسل دائمًا من نطاق فرعي»)، ولهذا سبب محدد يجب فهمه:

> **الطلب صريح وملزم:** البريد يجب أن يصل **من `info@athar-dev.edu.sa`**. لو أرسلنا من نطاق فرعي، لصار عنوان المُرسِل `info@mail.athar-dev.edu.sa` — **وهذا لا يلبي الطلب**. عنوان `From` يجب أن يكون على النطاق الذي نوثّقه لدى المزوّد.

| | الجذر `athar-dev.edu.sa` ✅ | نطاق فرعي `mail.athar-dev.edu.sa` |
|---|---|---|
| يلبي طلب `info@athar-dev.edu.sa` | ✅ **نعم** | ❌ **لا** |
| عزل السمعة عن بريد المركز البشري | ⚠️ جزئي | ✅ كامل |
| ألفة المستقبِل بالنطاق | ✅ عالية | 🔻 أقل |
| ملاءمة حجم الإرسال | ✅ 3,400 رسالة/4 أسابيع = حجم ضئيل | مبالغة |

**لماذا الخطر مقبول هنا تحديدًا؟** النصيحة بالنطاق الفرعي مصمَّمة للإرسال **التسويقي بكميات كبيرة** إلى قوائم مشتراة أو باردة. رسائلنا **معاملاتية وتعليمية** موجّهة إلى **متدربين سجّلوا بأنفسهم وأدخلوا بريدهم**. معدل الشكاوى المتوقع قريب من الصفر، والحجم ضئيل. مخاطرة إفساد سمعة الجذر منخفضة فعليًا.

**التخفيف المعتمد — نحصل على أفضل ما في الخيارين:**
مسار الارتداد (Return-Path / envelope sender) **يُعزل على نطاق فرعي** هو `send.athar-dev.edu.sa` يديره Resend تلقائيًا. أي أن:
- `From:` على الجذر ⇒ **الطلب ملبّى**، ومحاذاة DKIM محققة.
- `Return-Path:` على النطاق الفرعي ⇒ **الارتدادات وسمعة المغلّف معزولة عن الجذر**.

> 📌 **نقطة تُساء فهمها كثيرًا:** SPF يُفحص على **عنوان المغلّف (Return-Path)** لا على ترويسة `From` المرئية. لذلك سجل SPF على الجذر **لا يحتاج** إلى تضمين Resend إطلاقًا — Resend ترسل بمغلّف على `send.athar-dev.edu.sa` الذي له سجل SPF خاص به. هذا يوفّر علينا استهلاك ميزانية الـ 10 استعلامات على الجذر.

### 4.2 السجلات المطلوبة على `athar-dev.edu.sa` (الجذر)

كل السجلات تُضاف من لوحة Cloudflare DNS (النطاق مُدار عليها فعلًا — راجع §1).
⚠️ **كل سجلات البريد يجب أن تكون `DNS only` (السحابة رمادية)، لا `Proxied`.**

#### 🔴 السجل رقم 1 — SPF (الأعلى أولوية على الإطلاق)

**غير موجود حاليًا. يُضاف فورًا، قبل أي عمل آخر في هذا الملف.**

| النوع | الاسم | القيمة | TTL |
|---|---|---|---|
| `TXT` | `@` | `v=spf1 include:_spf.google.com ~all` | Auto |

- `include:_spf.google.com` هي القيمة الرسمية الموصى بها من جوجل لـ Google Workspace. ([Google — Set up SPF](https://knowledge.workspace.google.com/admin/security/set-up-spf))
- **`~all` لا `-all`.** جوجل توصي صراحةً: *«The `~all` tag tells receiving servers to mark messages as spam if they're from servers that aren't listed… Google recommends you use `~all`»*. الرفض الصارم `-all` يكسر إعادة توجيه البريد (forwarding) ويجب تأجيله لما بعد استقرار DMARC.
- **ميزانية الاستعلامات:** `_spf.google.com` تتوسّع إلى `_netblocks`، `_netblocks2`، `_netblocks3` ⇒ **4 استعلامات من أصل 10**. متسع مريح.

> ⚠️ **حد الـ 10 استعلامات (RFC 7208).** الآليات `include`, `a`, `mx`, `ptr`, `exists` والمُعدِّل `redirect` تُحتسب. `ip4`, `ip6`, `all` لا تُحتسب. تجاوز الحد ينتج **`PermError`**، و **DMARC يعامله فشلًا كاملًا** — فتفشل كل رسائل النطاق دفعة واحدة وبلا إنذار. يوجد أيضًا حد **استعلامَين فارغَين (void lookups)**.
> **قاعدة تشغيلية إلزامية:** لا يُضاف أي `include:` جديد دون تشغيل [MXToolbox SPF Check](https://mxtoolbox.com/spf.aspx) والتأكد من بقاء العدد ≤ 7.

**⚠️ سجل SPF واحد فقط لا غير.** وجود سجلين `v=spf1` على الاسم نفسه ينتج `PermError` فوريًا. إن أضاف Brevo لاحقًا `include:spf.brevo.com`، **يُدمج في السجل القائم**:
```
v=spf1 include:_spf.google.com include:spf.brevo.com ~all
```

#### 🔴 السجل رقم 2 — DKIM لـ Resend

القيمة تُولَّد من لوحة Resend عند إضافة النطاق (Domains → Add Domain → `athar-dev.edu.sa`). Resend تعرض السجلات الدقيقة؛ انسخها حرفيًا.

| النوع | الاسم | القيمة | ملاحظة |
|---|---|---|---|
| `TXT` | `resend._domainkey` | `p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8A…` `<RESEND_DKIM_PUBLIC_KEY>` | مفتاح 2048-bit |
| `MX` | `send` | `feedback-smtp.us-east-1.amazonses.com` (أولوية **10**) | مسار الارتداد |
| `TXT` | `send` | `v=spf1 include:amazonses.com ~all` | SPF لمسار الارتداد |

**ملاحظات تنفيذ:**
- في Cloudflare، أدخل الاسم **`send`** لا `send.athar-dev.edu.sa` — Cloudflare تُلحق اسم النطاق تلقائيًا. الخطأ الشائع ينتج `send.athar-dev.edu.sa.athar-dev.edu.sa`.
- النطاق الفرعي `send` **يحمل سجل MX هذا فقط**. لا تضع عليه أي شيء آخر.
- ⚠️ Resend قد تعرض لبعض النطاقات الجديدة **ثلاثة سجلات CNAME عشوائية للـ DKIM** بدل TXT واحد. **اعتمد ما تعرضه اللوحة فعليًا، لا ما هو مكتوب هنا.**
- **طول المفتاح: 2048-bit إلزامي.** جوجل ترفض مفاتيح أقل من 1024-bit، و2048 هو المعيار الحالي. عند إدخال مفتاح 2048 في بعض لوحات DNS يجب تقسيمه إلى سلاسل نصية ≤255 حرفًا — **Cloudflare تتولى ذلك تلقائيًا**، فلا تقسّمه يدويًا.

#### 🟡 السجل رقم 3 — DKIM لـ Brevo (لإكمال الاحتياطي)

ناقص حاليًا رغم وجود `brevo-code`. يُستكمل من لوحة Brevo (Senders & Domains → Authenticate).

| النوع | الاسم | القيمة |
|---|---|---|
| `TXT` | `brevo-code` منشور بالفعل ✅ | `brevo-code:bf015be51d24182e6ba9bfd0d3da8150` |
| `TXT` | `mail._domainkey` | `<BREVO_DKIM_PUBLIC_KEY>` — من لوحة Brevo |

#### 🟢 السجل رقم 4 — DKIM لـ Google Workspace

**موجود وسليم — لا تلمسه.** `google._domainkey` منشور بمفتاح 2048-bit. يخدم بريد `contact@` البشري.

#### 🔴 السجل رقم 5 — DMARC

**موجود حاليًا:** `v=DMARC1; p=none; rua=mailto:rua@dmarc.brevo.com`
يعمل، لكنه ناقص. **يُستبدل بـ:**

| النوع | الاسم | القيمة |
|---|---|---|
| `TXT` | `_dmarc` | `v=DMARC1; p=none; rua=mailto:dmarc-rua@athar-dev.edu.sa,mailto:rua@dmarc.brevo.com; ruf=mailto:dmarc-ruf@athar-dev.edu.sa; fo=1; adkim=r; aspf=r; pct=100; sp=none; ri=86400` |

| الوسم | القيمة | السبب |
|---|---|---|
| `p` | `none` **مبدئيًا** | مراقبة بلا أثر تسليمي. راجع جدول التدرّج §4.4. |
| `rua` | صندوقان | تقارير مجمّعة يومية. **إبقاء عنوان Brevo لا يضر** ويعطي لوحة جاهزة. |
| `ruf` | صندوق | تقارير الفشل الجنائية. قليل من المستقبِلين يرسلها لأسباب خصوصية. |
| `fo=1` | | أرسل تقرير فشل عند فشل **أي** آلية، لا عند فشل الكل. |
| `adkim=r` / `aspf=r` | relaxed | يسمح بمحاذاة النطاق الفرعي مع الجذر — **ضروري** لأن مسار ارتدادنا على `send.athar-dev.edu.sa`. الوضع الصارم `s` سيكسر المحاذاة. |
| `sp=none` | | سياسة النطاقات الفرعية، تتدرّج مع `p`. |
| `ri=86400` | | فاصل التقارير 24 ساعة. |

**⚠️ قبل النشر:** أنشئ `dmarc-rua@` و`dmarc-ruf@` كمجموعات/أسماء مستعارة في Google Workspace، وإلا ارتدّت التقارير. أو استخدم مُجمِّعًا مجانيًا (§4.7).

#### 🟢 السجل رقم 6 — تعطيل الانتحال على النطاقات غير المرسِلة

`ai.athar-dev.edu.sa` نطاق المنصة، ولن يرسل بريدًا أبدًا (الإرسال من الجذر). لذا نُغلقه صراحةً ضد الانتحال:

| النوع | الاسم | القيمة | الغرض |
|---|---|---|---|
| `MX` | `ai` | `.` (أولوية 0) — **Null MX** (RFC 7505) | «هذا النطاق لا يستقبل بريدًا» |
| `TXT` | `ai` | `v=spf1 -all` | «لا يُصرَّح لأي خادم بالإرسال باسمه» |
| `TXT` | `_dmarc.ai` | `v=DMARC1; p=reject; rua=mailto:dmarc-rua@athar-dev.edu.sa` | «ارفض أي رسالة تدّعي أنها منه» |

> يمكن الوصول إلى `p=reject` هنا **فورًا** وبلا تدرّج، لأن النطاق لا يرسل شيئًا — لا يوجد ما نكسره. هذه مكسب أمني مجاني ضد رسائل تصيّد تنتحل نطاق المنصة على المتدربين.

#### 🟢 السجل رقم 7 — أصول الصور

| النوع | الاسم | القيمة |
|---|---|---|
| `CNAME` | `assets` | يُنشأ تلقائيًا عند ربط النطاق المخصص بحاوية R2 |

### 4.3 ما لا يُلمس

| السجل | القرار |
|---|---|
| `MX @ = 1 smtp.google.com` | 🚫 **لا يُمس إطلاقًا.** أي تعديل يقطع البريد الوارد للمركز فورًا. **الإرسال عبر Resend لا يتطلب أي تغيير في MX الجذر** — تغيير MX يخص الاستقبال، والإرسال يخص SPF/DKIM. |
| `google._domainkey` | 🚫 لا يُمس. |
| `google-site-verification` | 🚫 لا يُمس. |
| `brevo-code` | 🚫 لا يُمس. |

### 4.4 جدول التدرّج في DMARC

**لماذا لا نبدأ من `p=reject` مباشرة؟** لأن `reject` تأمر المستقبِل بحذف كل رسالة تفشل المحاذاة. ولأن الجذر **ليس عليه SPF اليوم**، فبريد المركز البشري كله سيفشل المحاذاة لحظة النشر. `p=reject` قبل التحقق = **قطع بريد المركز بالكامل**. المرحلة `p=none` تكشف المرسلين المنسيّين (أدوات محاسبة، نماذج موقع، خدمات تسجيل) قبل تشديد السياسة.

| المرحلة | التوقيت | القيمة | معيار الانتقال |
|---|---|---|---|
| **0 — الإصلاح** | اليوم | نشر SPF + DKIM لـ Resend | فحص MXToolbox أخضر، وmail-tester ≥ 9/10 |
| **1 — المراقبة** | أسبوعان | `p=none; sp=none; pct=100` | تقارير `rua` **من مستقبِلين متعددين** تُظهر ≥ 98% محاذاة، وكل مصدر إرسال مشروع معروف ومُوثَّق |
| **2 — عزل جزئي** | أسبوعان | `p=quarantine; sp=quarantine; pct=25` | لا ارتفاع في شكاوى «لم تصلني الرسالة» |
| **3 — عزل كامل** | أسبوعان | `p=quarantine; sp=quarantine; pct=100` | استقرار أسبوعين |
| **4 — الرفض** | مستقر | `p=reject; sp=reject; pct=100` | — |

> **التوقيت مقابل البرنامج:** المرحلتان 0 و1 تكتملان **قبل فتح التسجيل**. لا تنتقل إلى المرحلة 2 أو ما بعدها **أثناء تشغيل دفعة**؛ أي خطأ في المحاذاة أثناء التشغيل يعني ضياع روابط تفعيل. أجّل التشديد إلى ما بين الدفعات.

**الترقية المصاحبة لـ SPF:** بعد الوصول إلى `p=reject` بأسبوعين مستقرين، بدّل `~all` إلى `-all`.

### 4.5 متطلبات المستقبِلين الكبار — ما نلتزم به

**Google — تنطبق على كل المرسلين مهما صغر حجمهم:** ([Google Sender Guidelines](https://support.google.com/a/answer/81126))

| المتطلب | حالتنا |
|---|---|
| SPF **أو** DKIM | ✅ **الاثنان** بعد §4.2 |
| سجلات DNS أمامية وعكسية (PTR) صالحة | ✅ مسؤولية Resend — عناوينها ذات PTR سليم |
| اتصال TLS | ✅ افتراضي لدى Resend |
| **معدل شكاوى < 0.30%** في Postmaster Tools | ✅ مراقَب — راجع §3.8 |
| توافق RFC 5322 | ✅ يتولاه المزوّد |

**المرسلون بكميات كبيرة (5,000+ يوميًا لحسابات Gmail الشخصية):** حجمنا (~300/يوم كحد أقصى) **أدنى بكثير من العتبة**، فمتطلبات DMARC والإلغاء بنقرة واحدة لا تنطبق إلزاميًا. **ننفّذها كلها رغم ذلك** لأنها تحسّن التسليم بغض النظر عن الحجم، ولأن العتبة قد تُخفَّض مستقبلًا.

**Microsoft — اعتبارًا من 5 مايو 2025:** رفض على مستوى SMTP بالخطأ `550 5.7.15 Access denied, sending domain does not meet the required authentication level` للمرسلين غير الموثَّقين إلى Outlook.com / Hotmail / Live. ([dmarcian](https://dmarcian.com/microsoft-enforces-spf-dkim-dmarc/)) — **غياب SPF اليوم يعرّض بريد المركز الحالي لهذا الرفض.** سبب إضافي لعجلة §4.2.

### 4.6 خطة الإحماء — Warm-up

النطاق **جديد كمرسِل آلي** رغم قدمه كنطاق. لكن السياق يخفّف كثيرًا:
- نستخدم **عناوين IP مشتركة** لدى Resend ذات سمعة قائمة ومُدارة — الإحماء أقل حرجًا بكثير من الـ IP المخصص.
- الحجم ضئيل (300/يوم ذروةً) ولا يقترب من عتبات الإنذار.
- الجمهور **مشترك بإرادته** (سجّل بنفسه وأدخل بريده).

**مع ذلك، هذا التدرّج إلزامي — والسبب أن أول رسالة يراها النظام يجب ألا تكون دفعة من 60:**

| المرحلة | المدة | الحجم اليومي | المحتوى |
|---|---|---|---|
| 1 — دخان | 3 أيام | 5–10 | إلى صناديق الفريق فقط (Gmail, Outlook, Yahoo, iCloud) + mail-tester |
| 2 — تجريبي | 4 أيام | 20–30 | حسابات اختبار في بيئة staging، دورة إشعارات كاملة |
| 3 — دفعة تجريبية | أسبوع | 50–80 | مجموعة حقيقية صغيرة (5–10 متدربين، «تشغيل تجريبي على جلسة حقيقية» — PRD §18) |
| 4 — الإطلاق | — | حتى 300 | الدفعة كاملة |

**قاعدة إلزامية عند الإطلاق:** رسائل التفعيل الـ 60 **لا تُرسل دفعة واحدة**. الطابور يوزّعها طبيعيًا، لكن أضف `delaySeconds` متدرجًا (10 رسائل كل دقيقة) لأي إرسال جماعي (إعلان، تذكير دفعة). الانفجار المفاجئ من نطاق بلا تاريخ إرسال هو **أقوى إشارة spam** يمكن إنتاجها.

### 4.7 أدوات التحقق — إلزامية قبل الإطلاق

| الأداة | الغرض | متى |
|---|---|---|
| [**mail-tester.com**](https://www.mail-tester.com) | درجة من 10 تفحص SPF/DKIM/DMARC وSpamAssassin وHTML | **قبل كل إطلاق.** الهدف **≥ 9/10**. مجاني بحد يومي؛ استخدم عنوانًا جديدًا في كل فحص. |
| [**MXToolbox**](https://mxtoolbox.com/SuperTool.aspx) | فحص SPF (عدّاد الاستعلامات)، DKIM، DMARC، القوائم السوداء | بعد كل تعديل DNS |
| [**Google Postmaster Tools**](https://postmaster.google.com) | معدل الشكاوى، سمعة النطاق، أخطاء المصادقة | يُفعَّل **الآن** (يتطلب TXT تحقق). ⚠️ **لن يعرض بيانات ذات دلالة تحت ~100 رسالة/يوم إلى Gmail شخصي** — قد يبقى فارغًا عند حجمنا. فعّله رغم ذلك. |
| [**learndmarc.com**](https://www.learndmarc.com) | تصوّر مسار DMARC لرسالة حقيقية | عند تشخيص فشل محاذاة |
| **Microsoft SNDS / JMRP** | سمعة لدى Outlook | إن ظهرت شكاوى من مستخدمي Outlook |
| **DMARC aggregator مجاني** | قراءة تقارير `rua` (XML خام غير قابل للقراءة بشريًا) | Postmark DMARC Digests مجاني · dmarcian طبقة مجانية · URIports |

**قائمة تحقق يدوية على أول رسالة حقيقية:**
1. في Gmail: `⋮` ← **Show original** ← تأكد من `SPF: PASS`, `DKIM: PASS`, `DMARC: PASS`.
2. تأكد أن نطاق `DKIM` هو `athar-dev.edu.sa` (المحاذاة) لا نطاق المزوّد.
3. افحص عرض RTL في: Gmail ويب، Gmail أندرويد، Gmail iOS، Outlook ويندوز، Apple Mail، Outlook ويب.
4. افحص الرسالة **والصور محجوبة** — هل تبقى مفهومة؟
5. افحص الوضع الليلي في Apple Mail وOutlook.app.

### 4.8 BIMI — التوصية: لا

**مؤجَّل. لا يُنفَّذ في الإصدار الأول.**

| المتطلب | الحالة |
|---|---|
| DMARC عند `p=quarantine` أو `p=reject` بـ `pct=100` | ❌ سنكون عند `p=none` لأشهر |
| شعار SVG Tiny PS | 🟡 ممكن تصميمه |
| **علامة تجارية مسجّلة** لدى جهة معترف بها | ❓ يحتاج تأكيدًا من المركز |
| **شهادة VMC** | 🔴 **$1,416–$1,752 سنويًا** حسب أسعار 2026 المعلنة (DigiCert). Entrust توقفت عن الإصدار في مايو 2025؛ الجهات المصدِرة الحالية: DigiCert وGlobalSign وSSL.com |

**الحكم:** تكلفة سنوية تفوق تكلفة المنصة كلها ($25/شهر = $300/سنة)، مقابل عرض شعار في صندوق البريد لدى بعض المستقبِلين فقط. **العائد لا يبرر التكلفة لمرسِل بحجم 3,400 رسالة.** يُعاد النظر إن توسّع المركز إلى برامج متعددة بعشرات آلاف الرسائل، وبعد الوصول إلى `p=reject`.

### 4.9 rDNS وReturn-Path

**rDNS / PTR:** ❌ **ليست مسؤوليتنا ولا نستطيع ضبطها.** الرسالة تخرج من عناوين Resend، وسجل PTR الخاص بها تديره Resend وهو سليم. **لا يوجد إجراء مطلوب.** (لو كنا نرسل من خادم خاص، لكان ضبط PTR إلزاميًا — وهو من أسباب رفض معمارية §3.9-ج.)

**Return-Path:** يُضبط تلقائيًا على `send.athar-dev.edu.sa` عبر سجلَي MX وTXT في §4.2. وظيفته المزدوجة:
1. **محاذاة SPF مع DMARC** — بدونه يكون المغلّف على نطاق Amazon SES، فتفشل محاذاة SPF ويعتمد DMARC على DKIM وحده (نجاح هش بساق واحدة).
2. **استقبال الارتدادات** — سجل MX يوجّه رسائل الارتداد إلى بنية Resend التي تحوّلها إلى webhook (§3.8).

### 4.10 ملاحظة على نطاقات `.edu.sa`

بحثنا عن قيود خاصة بنطاقات `.edu.sa` أو مسجّل SaudiNIC تتعلق بطول سجلات TXT أو تفويض DNS. **لم نجد أي مصدر موثوق يوثّق قيدًا من هذا النوع.** ولا نفترض وجوده.

**والأهم أنه غير ذي صلة عمليًا:** فحص §1 أثبت أن **خوادم الأسماء مفوَّضة بالكامل إلى Cloudflare** (`jermaine`/`molly.ns.cloudflare.com`). أي أن كل السجلات تُدار من لوحة Cloudflare بلا قيود المسجّل، وبلا حاجة لأي تنسيق مع SaudiNIC.

**ما هو حقيقي ويستحق الانتباه:** لاحقة `.edu.sa` **إشارة إيجابية** لدى مرشّحات البريد (نطاق تعليمي مؤسسي)، لكنها **لا تعوّض** غياب المصادقة. نطاق تعليمي بلا SPF أكثر إثارة للشبهة من نطاق تجاري موثَّق — لأن انتحال النطاقات التعليمية نمط تصيّد شائع.

### 4.11 ملخص كل السجلات — نسخ ولصق

```dns
; ═══════════════════════════════════════════════════════════════════
;  athar-dev.edu.sa  —  Cloudflare DNS  —  ALL RECORDS "DNS only"
; ═══════════════════════════════════════════════════════════════════

; ─── 1. SPF for the apex (Google Workspace human mail) — ADD NOW ───
@                       TXT   "v=spf1 include:_spf.google.com ~all"

; ─── 2. Resend: DKIM + isolated return-path ───
resend._domainkey       TXT   "p=<RESEND_DKIM_PUBLIC_KEY>"
send                    MX    10 feedback-smtp.us-east-1.amazonses.com
send                    TXT   "v=spf1 include:amazonses.com ~all"

; ─── 3. Brevo (fallback provider) DKIM ───
mail._domainkey         TXT   "<BREVO_DKIM_PUBLIC_KEY>"

; ─── 4. DMARC — phase 1 (monitor). See §4.4 for the ramp. ───
_dmarc                  TXT   "v=DMARC1; p=none; rua=mailto:dmarc-rua@athar-dev.edu.sa,mailto:rua@dmarc.brevo.com; ruf=mailto:dmarc-ruf@athar-dev.edu.sa; fo=1; adkim=r; aspf=r; pct=100; sp=none; ri=86400"

; ─── 5. Platform subdomain: never sends mail — lock it down now ───
ai                      MX    0 .
ai                      TXT   "v=spf1 -all"
_dmarc.ai               TXT   "v=DMARC1; p=reject; rua=mailto:dmarc-rua@athar-dev.edu.sa"

; ─── 6. R2 public assets (auto-created by the R2 custom-domain flow) ───
assets                  CNAME <r2-target>.r2.cloudflarestorage.com

; ═══════════════════════════════════════════════════════════════════
;  EXISTING — DO NOT MODIFY
; ═══════════════════════════════════════════════════════════════════
; @                     MX    1 smtp.google.com
; google._domainkey     TXT   "v=DKIM1;k=rsa;p=MIIBIjANBgkq…"
; @                     TXT   "google-site-verification=VmOPpjYZ…"
; @                     TXT   "brevo-code:bf015be51d24182e6ba9bfd0d3da8150"
```

---

## 5. نظام قوالب البريد — Email Template System

### 5.1 القيود التي يفرضها PRD §9.16

> «رسائل البريد بقوالب HTML عربية RTL بهوية أثر، **خفيفة الحجم**، و**لا تعتمد على صور مضمّنة (base64) لأنها تُحجب في Gmail و Outlook**. تُستضاف الصور خارجيًا.»

هذه الجملة تحسم ثلاثة قرارات معمارية: RTL أصيل لا معكوس بـ CSS، وحجم صغير، وصور على CDN خارجي.

### 5.2 القواعد الصلبة للقالب — Hard Rules

| القاعدة | السبب |
|---|---|
| **جداول للتخطيط، لا Flexbox ولا Grid** | Outlook على ويندوز يستخدم محرك Word للعرض. `display:flex` و `grid` و `position` لا تعمل فيه إطلاقًا. |
| **CSS مضمّن في السمة `style`** | Gmail يحذف `<style>` في بعض السياقات (خاصة إعادة التوجيه وعرض الجوال). `<style>` يُستخدم للوسائط والوضع الليلي فقط ولا يُعتمد عليه في التخطيط. |
| **العرض 600 بكسل** | العرض الآمن التاريخي لجزء القراءة في Outlook. |
| **`dir="rtl"` على `<html>` و `<body>` و كل `<table>` و `<td>`** | Outlook لا يرث `dir` بشكل موثوق عبر الأجيال. **يجب التصريح به في كل مستوى.** |
| **`align="right"` بجانب `text-align:right`** | Outlook يتجاهل `text-align` في حالات ويحترم سمة HTML القديمة. |
| **لا صور base64 إطلاقًا** | إلزام PRD §9.16. Gmail يحجب `data:` URIs في `<img>` بلا استثناء. |
| **لا خطوط ويب مخصصة كمصدر وحيد** | Gmail يزيل `@font-face`. **IBM Plex Sans Arabic لن يظهر في Gmail.** السلسلة الاحتياطية `Tahoma, "Segoe UI", Arial` إلزامية وهي ما سيراه أغلب المستخدمين فعليًا. |
| **الأرقام لاتينية (1234)** | التزامًا بـ PRD §5.4. |
| **ارتفاع السطر ≥ 1.7** | التزامًا بـ PRD §5.4: «الحروف العربية تحتاج مساحة رأسية أكبر». |
| **لا لون ذهبي أو أصفر** | حظر صريح في PRD §5.1. |
| **`role="presentation"` على جداول التخطيط** | لئلا يقرأها قارئ الشاشة كجدول بيانات (PRD §13.2 — WCAG 2.1 AA). |

### 5.3 القالب الأساسي — Hardened RTL Base Layout

الملف: `src/emails/layouts/base.html`. العناصر بين `{{ }}` فتحات استبدال.

```html
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
  "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml"
      xmlns:v="urn:schemas-microsoft-com:vml"
      xmlns:o="urn:schemas-microsoft-com:office:office"
      lang="ar" dir="rtl">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="x-apple-disable-message-reformatting" />
  <meta name="color-scheme" content="light dark" />
  <meta name="supported-color-schemes" content="light dark" />
  <title>{{PREHEADER}}</title>

  <!--[if mso]>
  <noscript><xml><o:OfficeDocumentSettings>
    <o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch>
  </o:OfficeDocumentSettings></xml></noscript>
  <style>
    /* Word engine ignores web fonts and mis-renders line-height. Force a safe stack. */
    table, td, div, p, a, h1, h2, h3 { font-family: Tahoma, "Segoe UI", Arial, sans-serif !important; }
  </style>
  <![endif]-->

  <style type="text/css">
    /* Gmail may strip this block entirely. Never rely on it for layout. */
    body { margin:0 !important; padding:0 !important; width:100% !important;
           -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%; }
    table { border-collapse:collapse !important; mso-table-lspace:0pt; mso-table-rspace:0pt; }
    img { border:0; outline:none; text-decoration:none; -ms-interpolation-mode:bicubic; display:block; }
    a { text-decoration:none; }

    /* Neutralise iOS auto-linking of dates, times and phone numbers.
       Critical here: every Arabic notification carries a Riyadh date and time. */
    .no-autolink a, a[x-apple-data-detectors] {
      color:inherit !important; text-decoration:none !important;
      font-size:inherit !important; font-family:inherit !important;
      font-weight:inherit !important; line-height:inherit !important;
    }

    @media screen and (max-width:600px) {
      .wrap  { width:100% !important; }
      .px    { padding-left:20px !important; padding-right:20px !important; }
      .btn a { display:block !important; width:auto !important; }
      .h1    { font-size:24px !important; line-height:1.35 !important; }
    }

    /* Dark mode. Apple Mail and Outlook.app honour this. Gmail runs its own
       colour inversion regardless, which is why every surface below is an
       explicit mid-tone that survives inversion without going muddy. */
    @media (prefers-color-scheme: dark) {
      .bg-page    { background-color:#15121C !important; }
      .bg-card    { background-color:#1F1A2B !important; }
      .t-primary  { color:#F3EFFA !important; }
      .t-muted    { color:#B6B0C4 !important; }
      .divider    { border-color:#3A3348 !important; }
      .brand-tint { background-color:#241C33 !important; }
    }
  </style>
</head>

<body dir="rtl" class="bg-page" style="margin:0;padding:0;background-color:#F8F5FC;">

  <!-- Preheader: the grey snippet beside the subject in the inbox list.
       Hidden, then padded so the client cannot pull body text into the snippet. -->
  <div style="display:none;font-size:1px;color:#F8F5FC;line-height:1px;max-height:0;
              max-width:0;opacity:0;overflow:hidden;mso-hide:all;">{{PREHEADER}}&#8202;&#847;&zwnj;&nbsp;&#8199;&shy;&#847;&zwnj;&nbsp;&#8199;&shy;&#847;&zwnj;&nbsp;&#8199;&shy;</div>

  <table role="presentation" dir="rtl" width="100%" cellpadding="0" cellspacing="0" border="0"
         class="bg-page" style="background-color:#F8F5FC;">
    <tr>
      <td align="center" style="padding:24px 12px;">

        <!--[if mso]>
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"><tr><td>
        <![endif]-->

        <table role="presentation" dir="rtl" class="wrap" width="600" cellpadding="0"
               cellspacing="0" border="0" style="width:600px;max-width:600px;">

          <!-- Header: brand bar -->
          <tr>
            <td align="right" dir="rtl"
                style="background-color:#7845B5;border-radius:12px 12px 0 0;padding:22px 28px;">
              <table role="presentation" dir="rtl" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td align="right" dir="rtl" valign="middle">
                    <a href="https://ai.athar-dev.edu.sa" target="_blank">
                      <img src="https://assets.athar-dev.edu.sa/email/v1/athar-logo-white.png"
                           width="132" height="40" alt="مركز أثر للتدريب"
                           style="display:block;border:0;width:132px;height:40px;" />
                    </a>
                  </td>
                  <td align="left" dir="rtl" valign="middle"
                      style="font-family:Tahoma,'Segoe UI',Arial,sans-serif;font-size:12px;
                             line-height:1.6;color:#EFE7F8;">
                    البرنامج التأسيسي<br />في الذكاء الاصطناعي
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Card body -->
          <tr>
            <td class="bg-card px" dir="rtl" align="right"
                style="background-color:#FFFFFF;padding:32px 28px 8px 28px;">
              <h1 class="h1 t-primary" dir="rtl" align="right"
                  style="margin:0 0 16px 0;font-family:Tahoma,'Segoe UI',Arial,sans-serif;
                         font-size:26px;line-height:1.4;font-weight:700;color:#111114;
                         text-align:right;">{{TITLE}}</h1>
              {{BODY}}
            </td>
          </tr>

          {{CTA_BLOCK}}
          {{INFO_BLOCK}}

          <!-- Sign-off -->
          <tr>
            <td class="bg-card px" dir="rtl" align="right"
                style="background-color:#FFFFFF;padding:8px 28px 32px 28px;">
              <p class="t-muted" dir="rtl" align="right"
                 style="margin:0;font-family:Tahoma,'Segoe UI',Arial,sans-serif;font-size:14px;
                        line-height:1.8;color:#5A5A66;text-align:right;">فريق مركز أثر للتدريب</p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td class="bg-card px no-autolink" dir="rtl" align="right"
                style="background-color:#FFFFFF;border-radius:0 0 12px 12px;padding:20px 28px 26px 28px;">
              <table role="presentation" dir="rtl" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr><td class="divider" style="border-top:1px solid #E4E4EB;font-size:0;line-height:0;">&nbsp;</td></tr>
                <tr>
                  <td dir="rtl" align="right" style="padding-top:18px;">
                    <p class="t-muted" dir="rtl" align="right"
                       style="margin:0 0 8px 0;font-family:Tahoma,'Segoe UI',Arial,sans-serif;
                              font-size:12px;line-height:1.8;color:#5A5A66;text-align:right;">
                      وصلتك هذه الرسالة لأنك مسجَّل في البرنامج التأسيسي في الذكاء الاصطناعي
                      لدى مركز أثر للتدريب.
                    </p>
                    <p class="t-muted" dir="rtl" align="right"
                       style="margin:0 0 8px 0;font-family:Tahoma,'Segoe UI',Arial,sans-serif;
                              font-size:12px;line-height:1.8;color:#5A5A66;text-align:right;">
                      للتواصل:
                      <a href="mailto:contact@athar-dev.edu.sa"
                         style="color:#7845B5;text-decoration:underline;">contact@athar-dev.edu.sa</a>
                      &nbsp;·&nbsp;
                      <a href="https://wa.me/966582090332"
                         style="color:#7845B5;text-decoration:underline;">واتساب</a>
                    </p>
                    {{PREFS_LINK}}
                    <p class="t-muted" dir="rtl" align="right"
                       style="margin:12px 0 0 0;font-family:Tahoma,'Segoe UI',Arial,sans-serif;
                              font-size:11px;line-height:1.7;color:#9A9AA8;text-align:right;">
                      مركز أثر للتدريب · المملكة العربية السعودية · جميع الأوقات بتوقيت الرياض
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

        </table>

        <!--[if mso]></td></tr></table><![endif]-->

      </td>
    </tr>
  </table>
</body>
</html>
```

### 5.4 الكتل القابلة للحقن — Injectable Blocks

**كتلة الزر (CTA)** — زر HTML خالص، وطبقة VML تضمن ظهور الخلفية البنفسجية في Outlook/Windows الذي يتجاهل `border-radius` و `background-color` على الروابط:

```html
<tr>
  <td class="bg-card px" dir="rtl" align="right"
      style="background-color:#FFFFFF;padding:12px 28px 20px 28px;">
    <table role="presentation" dir="rtl" cellpadding="0" cellspacing="0" border="0" class="btn">
      <tr>
        <td align="center" dir="rtl" bgcolor="#7845B5" style="border-radius:8px;">
          <!--[if mso]>
          <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml"
                       xmlns:w="urn:schemas-microsoft-com:office:word"
                       href="{{CTA_URL}}" style="height:48px;v-text-anchor:middle;width:260px;"
                       arcsize="17%" stroke="f" fillcolor="#7845B5">
            <w:anchorlock/>
            <center style="color:#FFFFFF;font-family:Tahoma,sans-serif;font-size:16px;font-weight:bold;">
              {{CTA_LABEL}}
            </center>
          </v:roundrect>
          <![endif]-->
          <!--[if !mso]><!-- -->
          <a href="{{CTA_URL}}" target="_blank"
             style="display:inline-block;padding:15px 34px;font-family:Tahoma,'Segoe UI',Arial,sans-serif;
                    font-size:16px;font-weight:700;line-height:1;color:#FFFFFF;background-color:#7845B5;
                    border-radius:8px;text-decoration:none;mso-hide:all;">{{CTA_LABEL}}</a>
          <!--<![endif]-->
        </td>
      </tr>
    </table>
    <p class="t-muted" dir="rtl" align="right"
       style="margin:14px 0 0 0;font-family:Tahoma,'Segoe UI',Arial,sans-serif;font-size:12px;
              line-height:1.8;color:#5A5A66;text-align:right;direction:rtl;unicode-bidi:embed;">
      إن لم يعمل الزر، انسخ هذا الرابط والصقه في المتصفح:<br />
      <span dir="ltr" style="direction:ltr;unicode-bidi:bidi-override;display:inline-block;
                             word-break:break-all;color:#7845B5;">{{CTA_URL}}</span>
    </p>
  </td>
</tr>
```

> **ملاحظة RTL حرجة.** الرابط الخام **يجب** أن يوضع داخل `<span dir="ltr">`. بدونه يُعرض الرابط اللاتيني مبعثرًا داخل الفقرة العربية فيصير غير قابل للنسخ. هذه أشهر علة في رسائل البريد العربية، وتحدث تحديدًا في روابط التفعيل الطويلة الموقّعة.

**كتلة المعلومات** (تفاصيل جلسة / مهمة / درجة):

```html
<tr>
  <td class="bg-card px" dir="rtl" align="right"
      style="background-color:#FFFFFF;padding:4px 28px 16px 28px;">
    <table role="presentation" dir="rtl" width="100%" cellpadding="0" cellspacing="0" border="0"
           class="brand-tint" style="background-color:#EFE7F8;border-radius:10px;">
      <tr>
        <td dir="rtl" align="right" style="padding:18px 20px;">
          <table role="presentation" dir="rtl" width="100%" cellpadding="0" cellspacing="0" border="0">
            <!-- repeat this row per field -->
            <tr>
              <td dir="rtl" align="right" width="35%" valign="top"
                  style="font-family:Tahoma,'Segoe UI',Arial,sans-serif;font-size:14px;line-height:1.9;
                         color:#5A5A66;text-align:right;padding:3px 0;">{{LABEL}}</td>
              <td dir="rtl" align="right" valign="top"
                  style="font-family:Tahoma,'Segoe UI',Arial,sans-serif;font-size:14px;line-height:1.9;
                         color:#111114;font-weight:700;text-align:right;padding:3px 0;">{{VALUE}}</td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </td>
</tr>
```

**فقرة نص** (تُستخدم داخل `{{BODY}}`):

```html
<p class="t-primary" dir="rtl" align="right"
   style="margin:0 0 14px 0;font-family:Tahoma,'Segoe UI',Arial,sans-serif;font-size:16px;
          line-height:1.85;color:#111114;text-align:right;">النص هنا</p>
```

### 5.5 استضافة الصور — Image Hosting

PRD §9.16 يمنع base64 صراحةً. القرار: **R2 + نطاق مخصص عام**، لا Cloudflare Images.

| الخيار | الحكم |
|---|---|
| **R2 + custom domain** ✅ **المعتمد** | 10 GB تخزين و10 مليون عملية قراءة (Class B) مجانًا شهريًا، و**لا رسوم خروج بيانات (egress) إطلاقًا**. أصول البريد بضعة ملفات PNG ثابتة — داخل الطبقة المجانية عمليًا إلى الأبد. ([R2 Pricing](https://developers.cloudflare.com/r2/pricing/)) |
| Cloudflare Images | مصمَّم للتحويل الديناميكي وتغيير المقاسات. أصول البريد ثابتة ولا تحتاج تحويلًا — تعقيد وتكلفة بلا مقابل. |
| نطاق `*.r2.dev` | ❌ **ممنوع في الإنتاج.** يخضع لتحديد معدل من Cloudflare وغير مخصص للإنتاج، ورؤية نطاق غريب في مصدر الرسالة تضر بالثقة وبمؤشرات مكافحة التصيّد. |

**الإعداد:** أنشئ حاوية `athar-email-assets` → اربطها بنطاق مخصص **`assets.athar-dev.edu.sa`** (R2 → Settings → Custom Domains؛ يُنشأ CNAME تلقائيًا) → فعّل `Cache-Control: public, max-age=31536000, immutable`.

**نمط الروابط — إلزامي بمقطع إصدار:**

```
https://assets.athar-dev.edu.sa/email/v1/<asset-name>.png
```

| الأصل | الرابط | المقاس الفعلي |
|---|---|---|
| الشعار (أبيض، فوق الترويسة البنفسجية) | `…/email/v1/athar-logo-white.png` | 264×80 (يُعرض 132×40) |
| الشعار (ملوّن، خلفية فاتحة) | `…/email/v1/athar-logo-color.png` | 264×80 |
| أيقونة الشهادة | `…/email/v1/icon-certificate.png` | 96×96 |
| أيقونة التقويم | `…/email/v1/icon-calendar.png` | 96×96 |
| بانر الشهادة | `…/email/v1/banner-certificate.png` | 1200×400 |

**قواعد إلزامية على كل صورة:**
- الأبعاد **بضعف** المقاس المعروض (retina)، مع `width`/`height` صريحين في الوسم لمنع القفز البصري.
- `alt` عربي وصفي على كل صورة بلا استثناء — أغلب عملاء البريد يحجبون الصور افتراضيًا، والنص البديل هو ما سيراه المستخدم فعلًا.
- **الرسالة يجب أن تُفهم تمامًا والصور محجوبة.** لا تضع معلومة جوهرية داخل صورة إطلاقًا.
- PNG بشفافية للشعارات. لا SVG — Outlook لا يعرضه.
- **لا تُعدّل ملفًا منشورًا تحت `v1`.** التخزين المؤقت طويل الأمد يعني أن التعديل لن يصل؛ أصدر `v2`.

### 5.6 مصفوفة القوالب الكاملة

مشتقة حرفيًا من مصفوفة PRD §9.16، ومكمّلة بالإشعارات التي يفرضها §9.3.3 و§9.2.3 وأغفلتها المصفوفة.
النبرة تتبع §11: **عربية فصيحة مبسطة، بلا لوم، وبأفعال واضحة في الأزرار.**

**مفتاح القناة:** 📧 بريد · 📱 داخل المنصة · 🔒 إلزامي (لا يخضع لتفضيلات المستخدم ولا يحمل رابط إلغاء اشتراك)

---

#### المجموعة أ — الحساب والأمان (إلزامية 🔒)

هذه الرسائل **لا تُعطَّل من إعدادات المستخدم ولا تحمل `List-Unsubscribe`**. إلغاء اشتراك المستخدم من رسالة «تغيير كلمة المرور» يخلق ثغرة أمنية: يفقد صاحب الحساب الإنذار الوحيد عند اختراقه.

---

**`E-01` تفعيل الحساب** · المُطلِق: إتمام نموذج التسجيل · المستقبِل: المتدرب · 📧 🔒

> **الموضوع:** `فعّل حسابك في البرنامج التأسيسي في الذكاء الاصطناعي`
> **النص التمهيدي:** `رابط التفعيل صالح لمدة 24 ساعة`
> **العنوان:** `مرحبًا بك يا {{FIRST_NAME}}`
>
> تم إنشاء حسابك في منصة البرامج التدريبية بمركز أثر للتدريب.
> يبقى خطوة واحدة: فعّل بريدك الإلكتروني لتتمكن من الدخول إلى لوحتك.
>
> **الزر:** `فعّل حسابي` ← `{{APP_BASE_URL}}/activate/{{TOKEN}}`
>
> الرابط صالح لمدة 24 ساعة ويعمل مرة واحدة فقط.
> إن لم تكن أنت من سجّل، تجاهل هذه الرسالة ولن يُنشأ أي حساب.

---

**`E-02` ترحيب بعد التفعيل** · المُطلِق: نجاح التفعيل · المستقبِل: المتدرب · 📧

> **الموضوع:** `أهلًا بك في البرنامج التأسيسي في الذكاء الاصطناعي`
> **النص التمهيدي:** `رحلتك تبدأ من هنا — إليك ما ينتظرك`
> **العنوان:** `أهلًا بك يا {{FIRST_NAME}}`
>
> حسابك جاهز، ومقعدك في البرنامج التأسيسي في الذكاء الاصطناعي محجوز.
>
> **كتلة معلومات:** مدة البرنامج `{{DURATION}}` · عدد الساعات `{{HOURS}}` · اللقاء التعريفي `{{ORIENTATION_DATE}}` · نمط الحضور `عن بُعد`
>
> في لوحتك ستجد الجدول التدريبي كاملًا، وبطاقتك الرقمية، ومسار رحلتك خطوة بخطوة.
> ننصحك بإضافة مواعيد الجلسات إلى تقويمك من صفحة الجدول حتى لا يفوتك لقاء.
>
> **الزر:** `ادخل إلى لوحتي` ← `{{APP_BASE_URL}}/dashboard`

---

**`E-03` استعادة كلمة المرور** · المُطلِق: طلب من `/forgot-password` · المستقبِل: صاحب الحساب · 📧 🔒

> ⚠️ **هذا القالب غير مذكور في مصفوفة §9.16 لكنه إلزامي بموجب §9.3.3.** أُضيف لسدّ الثغرة.

> **الموضوع:** `إعادة تعيين كلمة المرور`
> **النص التمهيدي:** `الرابط صالح لمدة 30 دقيقة`
> **العنوان:** `إعادة تعيين كلمة المرور`
>
> وصلنا طلب لإعادة تعيين كلمة مرور حسابك.
> اضغط الزر أدناه لاختيار كلمة مرور جديدة.
>
> **الزر:** `عيّن كلمة مرور جديدة` ← `{{APP_BASE_URL}}/reset-password/{{TOKEN}}`
>
> الرابط صالح لمدة 30 دقيقة ويعمل مرة واحدة فقط.
> إن لم تطلب ذلك، تجاهل هذه الرسالة وستبقى كلمة مرورك كما هي.

> 📌 **التزام بـ BR-30 و§9.3.3:** الرسالة الظاهرة للمستخدم في الواجهة موحّدة دائمًا («إذا كان هذا البريد مسجّلًا لدينا فستصلك رسالة خلال دقائق») سواء وُجد البريد أم لا. **هذا القالب لا يُرسل إلا لبريد موجود فعلًا**، ولا يُرسل أي بريد لعنوان غير مسجّل — حماية من تعداد الحسابات.

---

**`E-04` تغيير كلمة المرور** · المُطلِق: نجاح التغيير · المستقبِل: صاحب الحساب · 📧 🔒

> **الموضوع:** `تم تغيير كلمة مرور حسابك`
> **النص التمهيدي:** `إن لم يكن هذا أنت، تواصل معنا فورًا`
> **العنوان:** `تم تغيير كلمة المرور`
>
> غُيّرت كلمة مرور حسابك بنجاح، وأُنهيت جميع الجلسات النشطة على كل الأجهزة.
>
> **كتلة معلومات:** وقت التغيير `{{CHANGED_AT}}` (بتوقيت الرياض)
>
> إن لم تكن أنت من أجرى هذا التغيير، تواصل معنا فورًا على contact@athar-dev.edu.sa
>
> **الزر:** `ادخل إلى حسابي` ← `{{APP_BASE_URL}}/login`

---

**`E-05` تسجيل دخول من جهاز جديد** · المُطلِق: دخول من بصمة جهاز غير معروفة · المستقبِل: صاحب الحساب · 📧 🔒

> **الموضوع:** `تسجيل دخول جديد إلى حسابك`
> **النص التمهيدي:** `راجع التفاصيل وتأكد أنك أنت`
> **العنوان:** `تسجيل دخول من جهاز جديد`
>
> سُجّل دخول إلى حسابك من جهاز لم نتعرّف عليه من قبل.
>
> **كتلة معلومات:** الوقت `{{LOGIN_AT}}` · الجهاز `{{DEVICE}}` · المتصفح `{{BROWSER}}` · الموقع التقريبي `{{LOCATION}}`
>
> إن كان هذا أنت، فلا حاجة لأي إجراء.
> إن لم يكن أنت، غيّر كلمة مرورك الآن وسجّل الخروج من جميع الأجهزة.
>
> **الزر:** `غيّر كلمة المرور` ← `{{APP_BASE_URL}}/account/security`

---

#### المجموعة ب — الجلسات

**`E-06` تذكير بجلسة — قبل 24 ساعة** · المُطلِق: cron · المستقبِل: المتدرب · 📧 📱

> **الموضوع:** `تذكير: جلسة {{SESSION_TITLE}} غدًا`
> **النص التمهيدي:** `{{DAY_NAME}} {{DATE}} — {{TIME_FROM}} إلى {{TIME_TO}} بتوقيت الرياض`
> **العنوان:** `جلستك القادمة غدًا`
>
> نذكّرك بجلسة **{{SESSION_TITLE}}** ضمن البرنامج التأسيسي في الذكاء الاصطناعي.
>
> **كتلة معلومات:** الموضوع `{{SESSION_TITLE}}` · المدرب `{{TRAINER_NAME}}` · التاريخ `{{DAY_NAME}} {{DATE}}` · الوقت `{{TIME_FROM}} — {{TIME_TO}}` · المدة `{{DURATION}}`
>
> يفتح زر تسجيل الحضور قبل بداية الجلسة بثلاثين دقيقة، ورابط الدخول يظهر في لوحتك قبل البداية بخمس عشرة دقيقة.
>
> **الزر:** `اعرض تفاصيل الجلسة` ← `{{APP_BASE_URL}}/schedule/{{SESSION_ID}}`
>
> جميع الأوقات بتوقيت الرياض.

---

**`E-07` تذكير بجلسة — قبل ساعة** · المُطلِق: cron · المستقبِل: المتدرب · 📧 📱

> **الموضوع:** `جلسة {{SESSION_TITLE}} تبدأ بعد ساعة`
> **النص التمهيدي:** `تبدأ {{TIME_FROM}} بتوقيت الرياض`
> **العنوان:** `جلستك تبدأ بعد ساعة`
>
> تبدأ جلسة **{{SESSION_TITLE}}** في تمام {{TIME_FROM}} بتوقيت الرياض.
> يفتح زر تسجيل الحضور قبل البداية بثلاثين دقيقة.
>
> **الزر:** `ادخل إلى الجلسة` ← `{{APP_BASE_URL}}/live`

---

**`E-08` إلغاء أو تأجيل جلسة** · المُطلِق: تعديل من المدير/المدرب · المستقبِل: كل الدفعة · 📧 📱

> **الموضوع:** `تغيير في موعد جلسة {{SESSION_TITLE}}`
> **النص التمهيدي:** `اطّلع على الموعد الجديد`
> **العنوان:** `تحديث على جدول البرنامج`
>
> طرأ تغيير على جلسة **{{SESSION_TITLE}}**.
>
> **كتلة معلومات:** الموعد السابق `{{OLD_DATETIME}}` · الحالة `{{STATUS}}` · الموعد الجديد `{{NEW_DATETIME}}` · السبب `{{REASON}}`
>
> حدّثنا الجدول في لوحتك تلقائيًا. لا يؤثر هذا التغيير على نسبة حضورك.
>
> **الزر:** `اعرض الجدول المحدَّث` ← `{{APP_BASE_URL}}/schedule`

> 📌 عند الإلغاء الكامل تُستبدل كتلة الموعد الجديد بـ: «هذه الجلسة ملغاة ولن تُعقد. سنخبرك فور تحديد موعد بديل.»
> 📌 **أرفق ملف ICS محدَّثًا** بـ `SEQUENCE` أعلى و`STATUS:CANCELLED` عند الإلغاء — راجع §6.1.

---

#### المجموعة ج — المهام

**`E-09` نشر مهمة جديدة** · المُطلِق: نشر المدرب للمهمة · المستقبِل: المتدرب · 📧 📱

> **الموضوع:** `مهمة جديدة: {{ASSIGNMENT_TITLE}}`
> **النص التمهيدي:** `الموعد النهائي {{DUE_DATE}}`
> **العنوان:** `نُشرت مهمة جديدة`
>
> أضاف مدربك مهمة جديدة ضمن البرنامج التأسيسي في الذكاء الاصطناعي.
>
> **كتلة معلومات:** المهمة `{{ASSIGNMENT_TITLE}}` · الأسبوع `{{WEEK_NUMBER}}` · الدرجة `{{MAX_SCORE}}` · الحالة `{{REQUIRED_OR_OPTIONAL}}` · الموعد النهائي `{{DUE_DATE}} — {{DUE_TIME}}`
>
> **الزر:** `اطّلع على المهمة` ← `{{APP_BASE_URL}}/assignments/{{ASSIGNMENT_ID}}`

---

**`E-10` تذكير بموعد مهمة — قبل 48 ساعة** · المُطلِق: cron، لمن لم يسلّم فقط · المستقبِل: المتدرب · 📧 📱

> **الموضوع:** `يومان على موعد تسليم {{ASSIGNMENT_TITLE}}`
> **النص التمهيدي:** `الموعد النهائي {{DUE_DATE}} — {{DUE_TIME}}`
> **العنوان:** `يومان على الموعد النهائي`
>
> ينتهي موعد تسليم مهمة **{{ASSIGNMENT_TITLE}}** يوم {{DUE_DATE}} في تمام {{DUE_TIME}} بتوقيت الرياض.
>
> **الزر:** `سلّم المهمة` ← `{{APP_BASE_URL}}/assignments/{{ASSIGNMENT_ID}}`
>
> إن كنت قد سلّمت بالفعل، فقد وصلنا تسليمك ولا حاجة لأي إجراء.

---

**`E-11` تذكير بموعد مهمة — قبل 6 ساعات** · المُطلِق: cron، لمن لم يسلّم فقط · المستقبِل: المتدرب · 📧 📱

> **الموضوع:** `تبقّت ساعات على تسليم {{ASSIGNMENT_TITLE}}`
> **النص التمهيدي:** `ينتهي الموعد اليوم {{DUE_TIME}}`
> **العنوان:** `الموعد النهائي اليوم`
>
> ينتهي موعد تسليم مهمة **{{ASSIGNMENT_TITLE}}** اليوم في تمام {{DUE_TIME}} بتوقيت الرياض.
> يمكنك رفع ملفك أو كتابة إجابتك مباشرة من صفحة المهمة.
>
> **الزر:** `سلّم المهمة الآن` ← `{{APP_BASE_URL}}/assignments/{{ASSIGNMENT_ID}}`

---

#### المجموعة د — الدرجات

**`E-12` رصد درجة** · المُطلِق: رصد المدرب للدرجة · المستقبِل: المتدرب · 📧 📱

> **الموضوع:** `رُصدت درجتك في {{ASSIGNMENT_TITLE}}`
> **النص التمهيدي:** `اطّلع على درجتك وملاحظة مدربك`
> **العنوان:** `رُصدت درجة جديدة`
>
> قيّم مدربك تسليمك في مهمة **{{ASSIGNMENT_TITLE}}**.
>
> **كتلة معلومات:** المهمة `{{ASSIGNMENT_TITLE}}` · الدرجة `{{SCORE}} من {{MAX_SCORE}}` · مجموعك حتى الآن `{{TOTAL}} من 100`
>
> أرفق مدربك ملاحظة على تسليمك تجدها في صفحة التقييم.
>
> **الزر:** `اعرض التقييم والملاحظة` ← `{{APP_BASE_URL}}/grades/{{EVALUATION_ID}}`

---

**`E-13` تعديل درجة** · المُطلِق: تعديل درجة مرصودة (BR-14) · المستقبِل: المتدرب · 📧 📱

> **الموضوع:** `عُدّلت درجتك في {{ASSIGNMENT_TITLE}}`
> **النص التمهيدي:** `اطّلع على الدرجة المحدَّثة وسبب التعديل`
> **العنوان:** `تعديل على درجة مرصودة`
>
> عُدّلت درجتك في مهمة **{{ASSIGNMENT_TITLE}}**.
>
> **كتلة معلومات:** الدرجة السابقة `{{OLD_SCORE}}` · الدرجة الجديدة `{{NEW_SCORE}} من {{MAX_SCORE}}` · سبب التعديل `{{REASON}}` · مجموعك المحدَّث `{{TOTAL}} من 100`
>
> **الزر:** `اعرض التقييم` ← `{{APP_BASE_URL}}/grades/{{EVALUATION_ID}}`

---

#### المجموعة هـ — البرنامج

**`E-14` تفعيل المشروع الختامي** · المُطلِق: تفعيل المدرب (BR-15) · المستقبِل: كل الدفعة · 📧 📱

> **الموضوع:** `فُتح المشروع الختامي`
> **النص التمهيدي:** `اطّلع على متطلبات المشروع وموعد التسليم`
> **العنوان:** `المشروع الختامي متاح الآن`
>
> فتح مدربك تبويب المشروع الختامي، وأصبح بإمكانك الاطلاع على متطلباته والبدء فيه.
> يمثل المشروع خمسين درجة من مئة في التقييم الكلي.
>
> **كتلة معلومات:** المشروع `{{PROJECT_TITLE}}` · الدرجة `50 من 100` · موعد التسليم `{{DUE_DATE}} — {{DUE_TIME}}`
>
> **الزر:** `اطّلع على المشروع` ← `{{APP_BASE_URL}}/capstone`

---

**`E-15` إعلان جديد** · المُطلِق: نشر المدير/المدرب لإعلان · المستقبِل: كل الدفعة · 📧 📱

> **الموضوع:** `إعلان: {{ANNOUNCEMENT_TITLE}}`
> **النص التمهيدي:** `{{ANNOUNCEMENT_EXCERPT}}`
> **العنوان:** `{{ANNOUNCEMENT_TITLE}}`
>
> `{{ANNOUNCEMENT_BODY}}`
>
> **الزر:** `اقرأ الإعلان كاملًا` ← `{{APP_BASE_URL}}/announcements/{{ANNOUNCEMENT_ID}}`

---

**`E-16` رسالة جديدة (عند عدم الاتصال)** · المُطلِق: رسالة غير مقروءة + المستقبِل غير متصل > 15 دقيقة · 📧 📱

> ⚠️ **يُجمَّع (digest)، لا يُرسل لكل رسالة.** بريد لكل رسالة محادثة أسرع طريق إلى زر «هذه رسالة مزعجة». **حد أقصى: بريد واحد كل 6 ساعات لكل مستخدم.**

> **الموضوع:** `لديك {{COUNT}} رسالة غير مقروءة`
> **النص التمهيدي:** `من {{SENDER_NAMES}}`
> **العنوان:** `رسائل بانتظارك`
>
> وصلتك {{COUNT}} رسالة جديدة في محادثاتك على المنصة.
>
> **كتلة معلومات:** من `{{SENDER_NAMES}}` · آخر رسالة `{{LAST_MESSAGE_TIME}}`
>
> **الزر:** `اقرأ رسائلي` ← `{{APP_BASE_URL}}/messages`

---

**`E-17` انخفاض نسبة الحضور** · المُطلِق: نزول النسبة تحت حد الدفعة · المستقبِل: المتدرب · 📧 📱

> ⚠️ **أدق قالب في المجموعة نبرةً.** §11 يلزم: «لا تلوم المستخدم». الغرض إعلامي وداعم، لا تأنيبي. **لا تُستخدم كلمات مثل «تحذير» أو «إنذار» أو «تقصير».**

> **الموضوع:** `نسبة حضورك في البرنامج`
> **النص التمهيدي:** `نسبتك الحالية {{ATTENDANCE_RATE}}% — إليك ما يلزم للشهادة`
> **العنوان:** `نظرة على نسبة حضورك`
>
> نطلعك على وضع حضورك في البرنامج التأسيسي في الذكاء الاصطناعي حتى تتمكن من التخطيط لبقية الجلسات.
>
> **كتلة معلومات:** نسبتك الحالية `{{ATTENDANCE_RATE}}%` · الحد المطلوب للشهادة `{{MIN_RATE}}%` · الجلسات المتبقية `{{REMAINING_SESSIONS}}`
>
> ما زال أمامك {{REMAINING_SESSIONS}} جلسة، وحضورها يرفع نسبتك إلى الحد المطلوب.
> إن كان هناك ظرف يمنعك من الحضور، تواصل مع مدربك عبر المنصة ليساعدك في إيجاد حل.
>
> **الزر:** `اعرض سجل حضوري` ← `{{APP_BASE_URL}}/attendance`

---

**`E-18` إصدار الشهادة** · المُطلِق: إصدار المدير للشهادة · المستقبِل: المتدرب · 📧 📱

> **الموضوع:** `شهادتك في البرنامج التأسيسي في الذكاء الاصطناعي جاهزة`
> **النص التمهيدي:** `مبارك إتمامك البرنامج — حمّل شهادتك الآن`
> **العنوان:** `مبارك يا {{FIRST_NAME}}`
>
> أتممت البرنامج التأسيسي في الذكاء الاصطناعي بنجاح، وصدرت شهادتك من مركز أثر للتدريب.
>
> **كتلة معلومات:** البرنامج `البرنامج التأسيسي في الذكاء الاصطناعي` · عدد الساعات `{{HOURS}}` · تاريخ الإتمام `{{COMPLETION_DATE}}` · الرقم التسلسلي `{{CERTIFICATE_SERIAL}}`
>
> يمكنك تحميل الشهادة بصيغة PDF، ومشاركتها على لينكدإن، والتحقق منها في أي وقت عبر رابط التحقق العام.
>
> **الزر:** `حمّل شهادتي` ← `{{APP_BASE_URL}}/certificate`
>
> رابط التحقق: `{{APP_BASE_URL}}/certificate/verify/{{VERIFY_CODE}}`

---

#### المجموعة و — ثغرات في مصفوفة PRD §9.16 (يلزم حسمها)

> ⚠️ **للمنتج:** الحالتان التاليتان مطلوبتان وظيفيًا في §9.2.3 و§9.18 (مسار «الدفعة تتطلب موافقة المدير») **لكن مصفوفة §9.16 لا تذكر لهما إشعارًا**. القالبان جاهزان أدناه؛ يبقى تأكيد أن مسار الموافقة مفعّل (القرار المفتوح #5 في §19).

**`E-19` طلب التسجيل قيد المراجعة** · المُطلِق: التفعيل ودفعةٌ تتطلب موافقة · 📧

> **الموضوع:** `استلمنا طلب تسجيلك`
> **العنوان:** `طلبك قيد المراجعة`
>
> وصلنا طلب تسجيلك في البرنامج التأسيسي في الذكاء الاصطناعي، وهو الآن قيد المراجعة.
> سنخبرك بالنتيجة عبر البريد خلال {{REVIEW_SLA}}.
>
> **الزر:** `اعرض حالة طلبي` ← `{{APP_BASE_URL}}/dashboard`

**`E-20` نتيجة طلب التسجيل** · المُطلِق: قرار المدير · 📧

> **الموضوع (قبول):** `تم قبولك في البرنامج التأسيسي في الذكاء الاصطناعي`
> **العنوان:** `مبارك — تم قبول طلبك`
> ← يُدمج مع محتوى `E-02` ويوجّه إلى اللوحة.
>
> **الموضوع (اعتذار):** `بخصوص طلب تسجيلك`
> **العنوان:** `بخصوص طلبك`
>
> شكرًا لاهتمامك بالبرنامج التأسيسي في الذكاء الاصطناعي.
> لم يتسنَّ قبول طلبك في هذه الدفعة للسبب التالي: {{REASON}}.
> يسعدنا استقبال طلبك في الدفعات القادمة، وسنخبرك فور فتح التسجيل.
>
> **الزر:** `تواصل معنا` ← `mailto:contact@athar-dev.edu.sa`

---

#### المجموعة ز — إشعارات داخل المنصة فقط (بلا بريد)

مصفوفة §9.16 تحدد قناة «منصة» فقط لهذه — **لا يُرسل لها بريد إطلاقًا.** بريدٌ لكل واحدة منها يُنتج إرهاق إشعارات ويرفع معدل الشكاوى.

| الرمز | الحدث | المستقبِل | التوقيت |
|---|---|---|---|
| `N-01` | بدء الجلسة | المتدرب | عند بداية الجلسة |
| `N-02` | تأكيد استلام تسليم | المتدرب | فور التسليم |
| `N-03` | وصول تسليم جديد | المدرب | فور التسليم |
| `N-04` | مورد جديد في الحقيبة | كل الدفعة | فور الرفع |
| `N-05` | حضور غير مكتمل (BR-09) | المدرب | بعد انتهاء نافذة الانصراف |

---

### 5.7 البدائل النصية — Plain-Text Alternatives

**إلزامية على كل رسالة بلا استثناء.** رسالة `text/html` بلا جزء `text/plain` تُعد إشارة spam قوية لدى SpamAssassin وتخسر نقاطًا في mail-tester. وهي أيضًا ما يقرأه مستخدمو قارئات الشاشة وعملاء البريد النصية.

**❌ لا تولّدها بتجريد وسوم HTML آليًا** — الناتج يحمل بقايا CSS ومسافات مكسورة ويقرأ بشكل رديء بالعربية.
**✅ اكتب حقل `text` مستقلًا لكل قالب.**

**القواعد:**
- سطر لا يتجاوز 72 حرفًا (بعض العملاء يلفّ عند 78 ويكسر العربية).
- الروابط كاملة وخام في سطر منفصل — **بلا أقواس ولا نقاط ملاصقة** (تُلتقط ضمن الرابط فتكسره).
- لا رموز تعبيرية، ولا محاذاة بمسافات (تنكسر مع RTL).
- **ترتيب المحتوى نفسه** في HTML والنص. الاختلاف بينهما إشارة تصيّد لدى المرشّحات.

**مثال — `E-01` تفعيل الحساب:**

```text
مرحبًا بك يا {{FIRST_NAME}}

تم إنشاء حسابك في منصة البرامج التدريبية بمركز أثر للتدريب.
يبقى خطوة واحدة: فعّل بريدك الإلكتروني لتتمكن من الدخول إلى لوحتك.

فعّل حسابك من هذا الرابط:

{{APP_BASE_URL}}/activate/{{TOKEN}}

الرابط صالح لمدة 24 ساعة ويعمل مرة واحدة فقط.
إن لم تكن أنت من سجّل، تجاهل هذه الرسالة ولن يُنشأ أي حساب.

--
مركز أثر للتدريب
البرنامج التأسيسي في الذكاء الاصطناعي
contact@athar-dev.edu.sa
https://ai.athar-dev.edu.sa
```

**مثال — `E-06` تذكير بجلسة:**

```text
جلستك القادمة غدًا

نذكّرك بجلسة {{SESSION_TITLE}} ضمن البرنامج التأسيسي
في الذكاء الاصطناعي.

الموضوع:  {{SESSION_TITLE}}
المدرب:   {{TRAINER_NAME}}
التاريخ:  {{DAY_NAME}} {{DATE}}
الوقت:    {{TIME_FROM}} - {{TIME_TO}}

يفتح زر تسجيل الحضور قبل بداية الجلسة بثلاثين دقيقة،
ورابط الدخول يظهر في لوحتك قبل البداية بخمس عشرة دقيقة.

تفاصيل الجلسة:

{{APP_BASE_URL}}/schedule/{{SESSION_ID}}

جميع الأوقات بتوقيت الرياض.

--
مركز أثر للتدريب
contact@athar-dev.edu.sa
```

### 5.8 دالة التوليد

```ts
// src/email/render.ts
import baseLayout from "./layouts/base.html";

interface RenderInput {
  templateId: string;               // "E-01"
  title: string;
  preheader: string;
  bodyParagraphs: string[];         // Arabic, already escaped
  cta?: { label: string; url: string };
  info?: Array<{ label: string; value: string }>;
  showPrefsLink: boolean;           // false for mandatory 🔒 templates
  prefsUrl?: string;
}

export function renderHtml(env: Env, input: RenderInput): string {
  return baseLayout
    .replaceAll("{{PREHEADER}}", esc(input.preheader))
    .replace("{{TITLE}}", esc(input.title))
    .replace("{{BODY}}", input.bodyParagraphs.map(paragraph).join("\n"))
    .replace("{{CTA_BLOCK}}",   input.cta  ? ctaBlock(input.cta)   : "")
    .replace("{{INFO_BLOCK}}",  input.info ? infoBlock(input.info) : "")
    .replace("{{PREFS_LINK}}",  input.showPrefsLink ? prefsLink(input.prefsUrl!) : "");
}

// Escaping is mandatory: announcement bodies and trainer feedback are
// user-authored and land straight in an email. An unescaped "<" from a
// trainer's note would break the layout at best, inject markup at worst.
const esc = (s: string) =>
  s.replace(/&/g, "&amp;").replace(/</g, "&lt;")
   .replace(/>/g, "&gt;").replace(/"/g, "&quot;");

const paragraph = (t: string) =>
  `<p class="t-primary" dir="rtl" align="right" style="margin:0 0 14px 0;` +
  `font-family:Tahoma,'Segoe UI',Arial,sans-serif;font-size:16px;line-height:1.85;` +
  `color:#111114;text-align:right;">${esc(t)}</p>`;
```

> **قاعدة تشغيلية:** القوالب تُخزَّن كملفات في المستودع، **ونصوصها العربية في `locales/ar.json`** التزامًا بـ PRD §6.3 («يُمنع كتابة أي نص عربي مباشرة داخل مكوّن»). ولوحة المدير تعرضها للتحرير (PRD §9.18: «قوالب البريد») عبر تجاوز يُخزَّن في قاعدة البيانات ويسبق قيمة الملف.

### 5.9 إلغاء الاشتراك وتفضيلات الإشعارات

PRD §9.16 يلزم: «المستخدم يتحكم في تفعيل وتعطيل كل نوع لكل قناة من إعدادات حسابه». وهذا هو التطبيق العملي لذلك في البريد.

**التصنيف — من يحمل `List-Unsubscribe` ومن لا يحمله:**

| الفئة | القوالب | يخضع للتفضيلات؟ | `List-Unsubscribe`؟ |
|---|---|---|---|
| 🔒 **أمان وحساب** | `E-01` `E-03` `E-04` `E-05` | ❌ **لا** | ❌ **لا** |
| 📋 **معاملاتي جوهري** | `E-02` `E-18` `E-19` `E-20` | ❌ لا | ❌ لا |
| 🔔 **تشغيلي** | `E-06`–`E-17` | ✅ **نعم** | ✅ **نعم** — يشير إلى صفحة التفضيلات |

**التبرير:**
هذه الرسائل **معاملاتية وتعليمية، لا تسويقية**. المستقبِل سجّل في برنامج تدريبي وطلب هذه الإشعارات ضمنًا. لذلك:
- **لا تنطبق** عليها إلزاميًا متطلبات الإلغاء بنقرة واحدة الخاصة بالمرسلين بكميات كبيرة (5,000+/يوم إلى Gmail شخصي) — حجمنا أدنى بكثير.
- **لكن حجب زر الإلغاء عن الرسائل التشغيلية خطأ.** المستخدم الذي لا يجد طريقة للإيقاف يضغط «رسالة مزعجة» — وهذا يضرب سمعة النطاق مباشرة ويقترب من عتبة 0.30%. **وجود الرابط يحمي التسليم، لا يضره.**
- **رسائل الأمان تُستثنى** لأن تعطيلها يحرم صاحب الحساب من إنذار الاختراق الوحيد.

**الترويسات على الرسائل التشغيلية:**

```
List-Unsubscribe: <https://ai.athar-dev.edu.sa/notifications/unsubscribe?t=<SIGNED_TOKEN>>
List-Unsubscribe-Post: List-Unsubscribe=One-Click
```

**تنفيذ نقطة النهاية:**

```ts
// POST /notifications/unsubscribe?t=<token>   (one-click, from the mail client)
// GET  /notifications/unsubscribe?t=<token>   (human click → preference page)
//
// The token is an HMAC-signed { userId, category, exp } — NOT a raw user id.
// A guessable id here would let anyone disable another trainee's reminders.
export async function onUnsubscribe(req: Request, env: Env) {
  const token = new URL(req.url).searchParams.get("t");
  const claims = await verifySignedToken(env, token);
  if (!claims) return new Response("رابط غير صالح", { status: 400 });

  if (req.method === "POST") {
    // RFC 8058 one-click. MUST succeed without any further interaction and
    // MUST be honoured within 48 hours. We honour it immediately.
    await disableEmailChannel(env, claims.userId, claims.category);
    return new Response(null, { status: 200 });
  }

  // GET → send them to the granular preference screen rather than a dead end,
  // so a trainee who only wants fewer reminders does not switch everything off.
  return Response.redirect(
    `${env.APP_BASE_URL}/account/notifications?unsubscribed=${claims.category}`, 302
  );
}
```

> ⚠️ **مصيدة موثّقة عند التبديل إلى Brevo أو Mailgun:** كلاهما قد **يحقن تلقائيًا** تذييل إلغاء اشتراك وترويساته في الرسائل المعاملاتية عند تفعيل إدارة الإلغاء على مستوى النطاق (وفي Mailgun، مجرد تمرير `o:tag` قد يُحفّز الحقن). النتيجة: تذييل إلغاء اشتراك في **بريد تفعيل الحساب** — سلوك خاطئ وغير مقبول.
> **الإجراء الوقائي:** عطّل إدارة الإلغاء على مستوى النطاق لدى المزوّد، وتولَّ الترويسات يدويًا كما في الكود أعلاه. **وأدرج هذا في اختبار قبول التبديل إلى الاحتياطي.**

---

## 6. التكاملات الأخرى — Other Integrations

### 6.1 ملفات التقويم ICS — «إضافة إلى تقويمي» (§9.8.2)

**معيار قبول صريح في PRD §9.8.2:** «ملف ICS يُستورد بنجاح في تقويم جوجل وتقويم آبل.»

#### القرارات

| السؤال | القرار | السبب |
|---|---|---|
| `METHOD` | **`PUBLISH`** لزر التحميل | `REQUEST` دعوة صريحة تتطلب `ATTENDEE` وتولّد أزرار RSVP، وتجعل ردود المتدربين تصل إلى `ORGANIZER`. نحن نعلن حدثًا لا ندعو إليه. ([RFC 5546](https://www.rfc-editor.org/rfc/rfc5546.html)) |
| `VTIMEZONE` | **كتلة كاملة إلزامية** | `TZID=Asia/Riyadh` وحده يُقبل من جوجل وآبل، لكن Outlook صارم ويحتاج التعريف. الكتلة صغيرة والتكلفة صفر. |
| الأوقات | محلية + `TZID` لا UTC | يُبقي `Asia/Riyadh` ظاهرًا للمستخدم — التزامًا بـ PRD §9.8.2 «كل الأوقات معروضة بتوقيت الرياض مع ذكر ذلك صراحة». |
| `UID` | **ثابت مدى الحياة** | `session-{{SESSION_ID}}@ai.athar-dev.edu.sa`. تغييره ينتج حدثًا مكررًا بدل تحديث الموجود. |
| التحديث | `UID` نفسه + `SEQUENCE` أعلى | التقويم يستبدل الحدث بدل إضافته. |
| الإلغاء | `STATUS:CANCELLED` + `SEQUENCE` أعلى | يشطب الحدث في تقويم المتدرب. |

#### VTIMEZONE للرياض

الرياض على `UTC+03:00` ثابتًا **بلا توقيت صيفي**، فالكتلة بسيطة — قاعدة `STANDARD` واحدة بلا `RRULE`:

```
BEGIN:VTIMEZONE
TZID:Asia/Riyadh
X-LIC-LOCATION:Asia/Riyadh
BEGIN:STANDARD
DTSTART:19700101T000000
TZOFFSETFROM:+0300
TZOFFSETTO:+0300
TZNAME:+03
END:STANDARD
END:VTIMEZONE
```

#### المولّد

```ts
// src/lib/ics.ts
const CRLF = "\r\n";
const DOMAIN = "ai.athar-dev.edu.sa";

/**
 * RFC 5545 §3.1: "Lines of text SHOULD NOT be longer than 75 octets".
 * OCTETS, not characters. Arabic in UTF-8 is 2 bytes per character, so a
 * naive character-count fold produces lines that are ~150 bytes and, worse,
 * can split a multi-byte sequence in half and corrupt the text.
 * This folds on byte boundaries and never splits a UTF-8 sequence.
 */
function fold(line: string): string {
  const bytes = new TextEncoder().encode(line);
  if (bytes.length <= 75) return line;

  const out: string[] = [];
  let start = 0;
  let limit = 75;                       // first line 75, continuations 74 (leading space)

  while (start < bytes.length) {
    let end = Math.min(start + limit, bytes.length);
    // Walk back off a UTF-8 continuation byte (10xxxxxx) so we never cut a
    // character in half — this is the bug that mangles Arabic in most
    // hand-rolled ICS generators.
    while (end > start && end < bytes.length && (bytes[end] & 0xc0) === 0x80) end--;
    out.push(new TextDecoder().decode(bytes.slice(start, end)));
    start = end;
    limit = 74;
  }
  return out.join(CRLF + " ");
}

/** RFC 5545 §3.3.11 — escape TEXT values. Order matters: backslash first. */
const escText = (s: string) =>
  s.replace(/\\/g, "\\\\")
   .replace(/;/g,  "\\;")
   .replace(/,/g,  "\\,")
   .replace(/\r?\n/g, "\\n");

/** Basic date-time, local to the given TZID. No trailing Z. */
const fmtLocal = (d: Date, tz = "Asia/Riyadh") => {
  const p = new Intl.DateTimeFormat("en-CA", {
    timeZone: tz, year: "numeric", month: "2-digit", day: "2-digit",
    hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false,
  }).formatToParts(d).reduce<Record<string, string>>(
    (a, x) => (a[x.type] = x.value, a), {});
  return `${p.year}${p.month}${p.day}T${p.hour}${p.minute}${p.second}`;
};

/** UTC form, for DTSTAMP only. */
const fmtUtc = (d: Date) => d.toISOString().replace(/[-:]/g, "").replace(/\.\d{3}/, "");

export interface IcsSession {
  id: string;
  title: string;
  description: string;
  trainerName: string;
  startsAt: Date;
  endsAt: Date;
  sequence: number;                     // increment on every edit
  cancelled: boolean;
  joinUrl?: string;                     // omit before the BR-24 window opens
}

export function buildIcs(sessions: IcsSession[], calendarName: string): string {
  const lines: string[] = [
    "BEGIN:VCALENDAR",
    "VERSION:2.0",
    `PRODID:-//Athar Training Center//Training Platform//AR`,
    "CALSCALE:GREGORIAN",
    "METHOD:PUBLISH",
    fold(`X-WR-CALNAME:${escText(calendarName)}`),
    "X-WR-TIMEZONE:Asia/Riyadh",
    "BEGIN:VTIMEZONE",
    "TZID:Asia/Riyadh",
    "X-LIC-LOCATION:Asia/Riyadh",
    "BEGIN:STANDARD",
    "DTSTART:19700101T000000",
    "TZOFFSETFROM:+0300",
    "TZOFFSETTO:+0300",
    "TZNAME:+03",
    "END:STANDARD",
    "END:VTIMEZONE",
  ];

  const stamp = fmtUtc(new Date());

  for (const s of sessions) {
    lines.push(
      "BEGIN:VEVENT",
      // Stable for the life of the session. Never regenerate it.
      `UID:session-${s.id}@${DOMAIN}`,
      `DTSTAMP:${stamp}`,
      `DTSTART;TZID=Asia/Riyadh:${fmtLocal(s.startsAt)}`,
      `DTEND;TZID=Asia/Riyadh:${fmtLocal(s.endsAt)}`,
      `SEQUENCE:${s.sequence}`,
      fold(`SUMMARY:${escText(s.title)}`),
      fold(`DESCRIPTION:${escText(
        `${s.description}\n\nالمدرب: ${s.trainerName}\n` +
        `تفاصيل الجلسة: https://${DOMAIN}/schedule/${s.id}`
      )}`),
      fold(`LOCATION:${escText(s.joinUrl ? "عن بُعد — رابط الدخول في لوحة المنصة" : "عن بُعد")}`),
      fold(`URL:https://${DOMAIN}/schedule/${s.id}`),
      `STATUS:${s.cancelled ? "CANCELLED" : "CONFIRMED"}`,
      "TRANSP:OPAQUE",
      // ORGANIZER is REQUIRED by RFC 5546 for METHOD:PUBLISH.
      // ATTENDEE must NOT appear — adding it turns this into an invitation.
      fold(`ORGANIZER;CN=${escText("مركز أثر للتدريب")}:mailto:contact@athar-dev.edu.sa`),
      "BEGIN:VALARM",
      "TRIGGER:-PT60M",
      "ACTION:DISPLAY",
      fold(`DESCRIPTION:${escText(`تذكير: ${s.title}`)}`),
      "END:VALARM",
      "END:VEVENT",
    );
  }

  lines.push("END:VCALENDAR");
  return lines.join(CRLF) + CRLF;       // trailing CRLF required
}
```

> ⚠️ **`BR-24` وICS.** رابط الزوم **لا يوضع في `LOCATION` ولا `DESCRIPTION` إطلاقًا.** BR-24 يمنع كشفه قبل 15 دقيقة من البداية، وملف ICS يُحمَّل ويُشارك ويبقى في تقويم المستخدم إلى الأبد. ضع رابط صفحة الجلسة في المنصة بدلًا منه.

#### ترويسات الاستجابة

```ts
export function icsResponse(body: string, filename: string): Response {
  return new Response(body, {
    headers: {
      // The method= parameter must match METHOD: in the body.
      "Content-Type": "text/calendar; charset=utf-8; method=PUBLISH",
      // ASCII filename for old clients + RFC 5987 UTF-8 form for the real name.
      "Content-Disposition":
        `attachment; filename="${asciiFallback(filename)}.ics"; ` +
        `filename*=UTF-8''${encodeURIComponent(filename)}.ics`,
      "Cache-Control": "no-store",
      "X-Content-Type-Options": "nosniff",
    },
  });
}
```

**نقاط تنفيذ:**
- `Content-Type: text/calendar` **إلزامي**. إرساله كـ `application/octet-stream` يجعل Google Calendar وApple Calendar يعاملانه ملفًا مجهولًا فلا يعرضان خيار الاستيراد.
- `charset=utf-8` **إلزامي** للنصوص العربية.
- المسارات: `/api/calendar/session/{id}.ics` لجلسة واحدة، و`/api/calendar/cohort/{id}.ics` للجدول كاملًا.
- Google Calendar يحد الاستيراد بـ **1 ميجابايت** — جدولنا (12 جلسة) لا يقترب من ذلك.

**اختبار القبول (§9.8.2):** حمّل الملف واستورده في: Google Calendar (ويب — Settings → Import)، Apple Calendar (macOS نقرة مزدوجة)، iOS Safari (فتح مباشر)، Outlook ويب. تحقق من: صحة الوقت بتوقيت الرياض، وسلامة العنوان العربي بلا حروف مشوّهة، وأن التحديث يستبدل الحدث ولا يكرره.

---

### 6.2 الزوم (§9.10 · القرار المفتوح §19-3)

**التوصية: اللصق اليدوي في الإصدار الأول. لا تكامل API.**

#### تحليل الجهد مقابل العائد

| | لصق يدوي ✅ | Zoom API (S2S OAuth) |
|---|---|---|
| جهد التطوير | **صفر** — حقل نص في لوحة المدير (موجود أصلًا في §9.18) | 3–5 أيام: تطبيق، رموز، تجديد، معالجة أخطاء، مزامنة عند تعديل الجلسة |
| جهد التشغيل | ~12 لصقة للدفعة كاملة (دقيقتان) | صفر بعد الإعداد |
| نقاط الفشل | لصق رابط خاطئ | انتهاء الرمز، تغيّر النطاقات، حدود المعدل، تعطّل الحساب، تغييرات API |
| متطلبات الحساب | أي حساب زوم | **حساب مدفوع + صلاحيات مالك/مدير** لإنشاء تطبيق S2S |
| العائد الحقيقي | — | يظهر عند **عشرات الدفعات المتوازية**، لا عند 12 جلسة |

> **الحكم:** 12 جلسة في الدفعة. أتمتة عملية تستغرق دقيقتين شهريًا مقابل 3–5 أيام تطوير **ونقطة فشل خارجية جديدة في مسار حرج** قرار خاطئ. PRD نفسه يجعل اللصق اليدوي هو الافتراضي (§19-3). **نلتزم به.**
> **متى نعيد النظر؟** عند تشغيل أكثر من 3 برامج متوازية، أو عند طلب تسجيلات تلقائية.

**ما يُبنى الآن بدلًا من ذلك (وهو الأهم فعلًا):** التحقق من صحة الرابط عند اللصق (نمط `https://*.zoom.us/j/...`)، وتخزينه **مشفَّرًا** في قاعدة البيانات، وعدم إرساله للمتصفح قبل نافذة BR-24، وجلبه عبر نقطة نهاية مؤمَّنة عند الضغط على الزر فقط.

#### إن اعتُمد API لاحقًا — المواصفة الجاهزة

```ts
// Server-to-Server OAuth. Token lifetime: 1 hour. Cache it in KV.
async function getZoomToken(env: Env): Promise<string> {
  const cached = await env.KV.get("zoom:token");
  if (cached) return cached;

  const res = await fetch("https://zoom.us/oauth/token", {
    method: "POST",
    headers: {
      // Basic auth with the S2S app's client_id:client_secret
      "Authorization": "Basic " + btoa(`${env.ZOOM_CLIENT_ID}:${env.ZOOM_CLIENT_SECRET}`),
      "Content-Type": "application/x-www-form-urlencoded",
    },
    body: new URLSearchParams({
      grant_type: "account_credentials",
      account_id: env.ZOOM_ACCOUNT_ID,
    }),
  });

  const { access_token, expires_in } = await res.json<any>();
  // Expire our copy early so we never present a token mid-expiry.
  await env.KV.put("zoom:token", access_token, { expirationTtl: expires_in - 300 });
  return access_token;
}

async function createZoomMeeting(env: Env, s: SessionInput) {
  const token = await getZoomToken(env);
  const res = await fetch(
    `https://api.zoom.us/v2/users/${env.ZOOM_HOST_USER_ID}/meetings`,
    {
      method: "POST",
      headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" },
      body: JSON.stringify({
        topic: s.title,
        type: 2,                                   // 2 = scheduled meeting
        start_time: s.startsAtIso,                 // "2026-10-12T19:00:00" (local)
        duration: s.durationMinutes,
        timezone: "Asia/Riyadh",
        agenda: s.description,
        settings: {
          waiting_room: true,                      // prevents link-sharing abuse (PRD §18)
          join_before_host: false,
          mute_upon_entry: true,
          auto_recording: "cloud",
          approval_type: 2,
        },
      }),
    }
  );
  const m = await res.json<any>();
  return { joinUrl: m.join_url, password: m.password, meetingId: m.id };
}
```

**الصلاحيات (granular scopes):** زوم انتقلت إلى تسمية دقيقة؛ `meeting:write:admin` القديم صار **`meeting:write:meeting:admin`**، والقراءة `meeting:read:meeting:admin`. ([Zoom — Granular scopes](https://developers.zoom.us/docs/integrations/oauth-scopes-granular/))
⚠️ **عائق موثّق ومتكرر:** صلاحيات الكتابة **لا تظهر في قائمة «Add Scopes»** إلا إذا منح مالك الحساب المطوّر صلاحيات كافية — بلاغات متعددة في منتدى مطوّري زوم. **تحقق من ظهور الصلاحية قبل تقدير أي وقت تطوير.**

---

### 6.3 زر واتساب العائم (§9.1.2)

الرقم `966582090332` — بلا `+` وبلا أصفار بادئة وبلا شرطات (شرط `wa.me`).

**الرابط الجاهز — منسوخ كما هو:**

```
https://wa.me/966582090332?text=%D8%A7%D9%84%D8%B3%D9%84%D8%A7%D9%85%20%D8%B9%D9%84%D9%8A%D9%83%D9%85%D8%8C%20%D8%A3%D8%B1%D8%BA%D8%A8%20%D8%A8%D8%A7%D9%84%D8%A7%D8%B3%D8%AA%D9%81%D8%B3%D8%A7%D8%B1%20%D8%B9%D9%86%20%D8%A7%D9%84%D8%A8%D8%B1%D9%86%D8%A7%D9%85%D8%AC%20%D8%A7%D9%84%D8%AA%D8%A3%D8%B3%D9%8A%D8%B3%D9%8A%20%D9%81%D9%8A%20%D8%A7%D9%84%D8%B0%D9%83%D8%A7%D8%A1%20%D8%A7%D9%84%D8%A7%D8%B5%D8%B7%D9%86%D8%A7%D8%B9%D9%8A.
```

**النص قبل الترميز:**
> السلام عليكم، أرغب بالاستفسار عن البرنامج التأسيسي في الذكاء الاصطناعي.

**التوليد في الكود (الطريقة الموصى بها):**

```tsx
// Build it at runtime; never paste a pre-encoded string into source — it
// silently rots when the copy changes and nobody re-encodes it.
const WHATSAPP_NUMBER = "966582090332";

export function whatsappUrl(message: string) {
  return `https://wa.me/${WHATSAPP_NUMBER}?text=${encodeURIComponent(message)}`;
}

// t("landing.whatsapp.prefill") — the string lives in locales/ar.json per §6.3
<a href={whatsappUrl(t("landing.whatsapp.prefill"))}
   target="_blank" rel="noopener noreferrer"
   aria-label="تواصل معنا عبر واتساب">…</a>
```

**ملاحظات ترميز العربية:**
- استخدم **`encodeURIComponent`** لا `encodeURI` — الأخير لا يرمّز `&` و`=` و`?` فتنكسر الاستعلامات.
- كل حرف عربي يصير **ثلاثة رموز ‎`%XX`** (3 بايتات UTF-8 لنطاق `U+0600–U+06FF`). الرابط يبدو طويلًا جدًا وهذا طبيعي وسليم.
- الفاصلة العربية `،` (`U+060C`) ترمَّز `%D8%8C` — **ليست** الفاصلة اللاتينية.
- **سطر جديد** = `%0A` (`encodeURIComponent("\n")` ينتجه تلقائيًا).
- أبقِ الرسالة قصيرة. الروابط الطويلة جدًا تُقتطع في بعض المتصفحات على الجوال.
- `rel="noopener noreferrer"` إلزامي مع `target="_blank"` (أمان).

**بديل رسمي مكافئ:** `https://api.whatsapp.com/send?phone=966582090332&text=...` — يعمل بنفس الطريقة. `wa.me` أقصر وأنظف.

---

### 6.4 مشاركة الشهادة على لينكدإن (§9.17)

PRD §9.17: «زر مشاركة على لينكدإن بنص جاهز يذكر مركز أثر واسم البرنامج.»

**نوفّر خيارين — والأول هو الأهم:**

#### ① «إضافة إلى الملف الشخصي» — Add to Profile (موصى به)

يضيف الشهادة إلى قسم **Licenses & Certifications** في ملف المتدرب مع حقولها مملوءة مسبقًا. أقوى بكثير من منشور عابر لأنه أثر دائم في الملف المهني.

```
https://www.linkedin.com/profile/add
  ?startTask=CERTIFICATION_NAME
  &name=<اسم الشهادة>
  &organizationName=<اسم الجهة>
  &issueYear=<YYYY>
  &issueMonth=<M>
  &certUrl=<رابط التحقق>
  &certId=<الرقم التسلسلي>
```

| المعامل | القيمة | ملاحظة |
|---|---|---|
| `startTask` | `CERTIFICATION_NAME` | ثابت |
| `name` | `البرنامج التأسيسي في الذكاء الاصطناعي` | الاسم الرسمي حرفيًا (PRD §1.2: «لا يُختصر ولا يُعاد صياغته») |
| `organizationName` | `مركز أثر للتدريب` | ⚠️ راجع الملاحظة أدناه |
| `issueYear` / `issueMonth` | من `certificate.issued_at` | **الشهر بلا صفر بادئ** (`3` لا `03`) |
| `certUrl` | `https://ai.athar-dev.edu.sa/certificate/verify/{{CODE}}` | صفحة التحقق العامة (§9.17) |
| `certId` | `ATHAR-AI101-2026-0001` | الرقم التسلسلي |

> ⚠️ **`organizationName` مقابل `organizationId`.** لينكدإن يفضّل `organizationId` (المعرّف الرقمي لصفحة الشركة) لأنه يربط الشهادة بصفحة المركز ويعرض شعارها. عند تمرير `organizationName` نصًّا فقط، قد يطلب لينكدإن من المستخدم اختيار الجهة يدويًا أو يعرضها كنص حر بلا شعار.
> **إجراء مطلوب من العميل:** تزويدنا بـ **معرّف صفحة مركز أثر على لينكدإن** إن وُجدت. يُستخرج من رابط الصفحة أو عبر البحث في `linkedin.com/company/`. حينها نستبدل `organizationName` بـ `organizationId=<ID>`.
> **إن لم توجد صفحة:** ننصح بإنشائها — ذلك يرفع مصداقية الشهادة لدى أصحاب العمل، وهو مكسب تسويقي للمركز.

**التوليد:**

```ts
export function linkedInAddToProfileUrl(cert: Certificate, env: Env): string {
  const issued = new Date(cert.issuedAt);
  const p = new URLSearchParams({
    startTask: "CERTIFICATION_NAME",
    name: "البرنامج التأسيسي في الذكاء الاصطناعي",
    organizationName: "مركز أثر للتدريب",
    issueYear:  String(issued.getUTCFullYear()),
    issueMonth: String(issued.getUTCMonth() + 1),   // 1-12, no leading zero
    certUrl: `${env.APP_BASE_URL}/certificate/verify/${cert.verifyCode}`,
    certId: cert.serial,                             // ATHAR-AI101-2026-0001
  });
  // No expirationYear/expirationMonth — a completion certificate does not expire.
  return `https://www.linkedin.com/profile/add?${p.toString()}`;
}
```

**مثال كامل (مرمَّز):**
```
https://www.linkedin.com/profile/add?startTask=CERTIFICATION_NAME&name=%D8%A7%D9%84%D8%A8%D8%B1%D9%86%D8%A7%D9%85%D8%AC+%D8%A7%D9%84%D8%AA%D8%A3%D8%B3%D9%8A%D8%B3%D9%8A+%D9%81%D9%8A+%D8%A7%D9%84%D8%B0%D9%83%D8%A7%D8%A1+%D8%A7%D9%84%D8%A7%D8%B5%D8%B7%D9%86%D8%A7%D8%B9%D9%8A&organizationName=%D9%85%D8%B1%D9%83%D8%B2+%D8%A3%D8%AB%D8%B1+%D9%84%D9%84%D8%AA%D8%AF%D8%B1%D9%8A%D8%A8&issueYear=2026&issueMonth=3&certUrl=https%3A%2F%2Fai.athar-dev.edu.sa%2Fcertificate%2Fverify%2FABC123&certId=ATHAR-AI101-2026-0001
```

#### ② مشاركة كمنشور — Share

```
https://www.linkedin.com/sharing/share-offsite/?url=<رابط التحقق مرمَّزًا>
```

> ⚠️ **لينكدإن لم يعد يقبل معامل `text` أو `summary` مسبق التعبئة** في مسار المشاركة هذا — يُتجاهل بصمت. النص الذي يظهر يأتي من **وسوم Open Graph على صفحة التحقق نفسها**.

**لذلك يجب أن تحمل صفحة `/certificate/verify/{code}` هذه الوسوم:**

```html
<meta property="og:title"
      content="شهادة إتمام البرنامج التأسيسي في الذكاء الاصطناعي — مركز أثر للتدريب" />
<meta property="og:description"
      content="{{HOLDER_NAME}} أتمّ البرنامج التأسيسي في الذكاء الاصطناعي بنجاح لدى مركز أثر للتدريب. شهادة موثّقة برقم {{SERIAL}}." />
<meta property="og:image"
      content="https://assets.athar-dev.edu.sa/email/v1/banner-certificate.png" />
<meta property="og:url"
      content="https://ai.athar-dev.edu.sa/certificate/verify/{{CODE}}" />
<meta property="og:type" content="website" />
```

> 📌 **التزام بـ BR-25 و§12.6:** صفحة التحقق **لا تعرض أي بيانات شخصية حساسة**. الاسم والبرنامج والتاريخ فقط — لا بريد ولا جوال ولا درجات.

---

### 6.5 Cloudflare Turnstile (§9.2.2)

PRD §9.2.2 يلزم: «الحماية من البوتات: تحديد معدل الطلبات (5 محاولات لكل IP في الساعة) + **CAPTCHA غير مرئي**».

**لماذا Turnstile؟** مجاني بلا حد للاستخدام، ولا يستخدم كوكيز تتبع (يخدم موقف §12.6)، ولا يعرض ألغاز صور، ومدمج أصلًا في نفس منصة الاستضافة.

> 📌 **مصطلح مهم:** Cloudflare غيّرت التسمية. لم تعد الأوضاع «managed / non-interactive / invisible» بل تُضبط عبر `appearance` و`execution` و`size`. **للحصول على السلوك «غير المرئي» المطلوب في PRD: `appearance="interaction-only"`** — لا يظهر الودجت إطلاقًا إلا إذا قرر Turnstile أن الزائر يحتاج تحديًا.

#### العميل

```html
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

<form id="registerForm" method="POST" action="/api/register">
  <!-- … حقول التسجيل … -->

  <div class="cf-turnstile"
       data-sitekey="<TURNSTILE_SITE_KEY>"
       data-appearance="interaction-only"
       data-theme="light"
       data-language="ar"
       data-action="register"
       data-callback="onTurnstileSuccess"
       data-error-callback="onTurnstileError"
       data-expired-callback="onTurnstileExpired"></div>

  <button type="submit">سجّل الآن</button>
</form>

<script>
  // Turnstile injects a hidden input named "cf-turnstile-response".
  // The token is single-use and expires after 300 seconds — a user who leaves
  // the multi-step form open will otherwise submit a dead token.
  function onTurnstileExpired() { turnstile.reset(); }
  function onTurnstileError()   {
    showFieldError("تعذّر التحقق من أنك لست روبوتًا. أعد المحاولة.");
  }
</script>
```

`data-language="ar"` مدعوم ويعرض نص التحدي بالعربية.

#### الخادم

```ts
// PRD §6.3: every input is validated server-side regardless of client checks.
const SITEVERIFY = "https://challenges.cloudflare.com/turnstile/v0/siteverify";

interface TurnstileResult {
  success: boolean;
  "error-codes": string[];
  challenge_ts?: string;
  hostname?: string;
  action?: string;
}

export async function verifyTurnstile(
  env: Env, token: string | null, remoteIp: string | null
): Promise<{ ok: true } | { ok: false; reason: string }> {
  if (!token) return { ok: false, reason: "missing-token" };

  const form = new FormData();
  form.append("secret", env.TURNSTILE_SECRET_KEY);
  form.append("response", token);
  if (remoteIp) form.append("remoteip", remoteIp);
  // Lets us safely retry the verification itself without a
  // "timeout-or-duplicate" false negative.
  form.append("idempotency_key", crypto.randomUUID());

  const res  = await fetch(SITEVERIFY, { method: "POST", body: form });
  const data = await res.json<TurnstileResult>();

  if (!data.success) {
    return { ok: false, reason: data["error-codes"]?.join(",") ?? "unknown" };
  }
  // Defence in depth: confirm the token was minted for THIS form on OUR host,
  // so a token harvested from another page cannot be replayed here.
  if (data.action !== "register")               return { ok: false, reason: "action-mismatch" };
  if (data.hostname !== "ai.athar-dev.edu.sa")  return { ok: false, reason: "hostname-mismatch" };

  return { ok: true };
}

// In the registration handler:
export async function onRegister(req: Request, env: Env) {
  const body  = await req.formData();
  const check = await verifyTurnstile(
    env,
    body.get("cf-turnstile-response") as string | null,
    req.headers.get("CF-Connecting-IP")
  );

  if (!check.ok) {
    // §11: explain and offer a way forward; never blame the user.
    return json({ error: "تعذّر التحقق من طلبك. أعد المحاولة." }, 400);
  }
  // … proceed: Zod validation → rate limit (5/IP/hour) → create account
}
```

**رموز الأخطاء:** `missing-input-secret` · `invalid-input-secret` · `missing-input-response` · `invalid-input-response` · `bad-request` · `timeout-or-duplicate` · `internal-error`.

**مفاتيح الاختبار (لبيئة التطوير وCI):**

| الغرض | Site Key | Secret Key |
|---|---|---|
| ينجح دائمًا | `1x00000000000000000000AA` | `1x0000000000000000000000000000000AA` |
| يفشل دائمًا | `2x00000000000000000000AB` | `2x0000000000000000000000000000000AA` |
| يفرض تحديًا تفاعليًا | `3x00000000000000000000FF` | — |
| «الرمز مستهلك» | — | `3x0000000000000000000000000000000AA` |

> ⚠️ مفاتيح الإنتاج ترفض الرموز الوهمية والعكس صحيح. **يجب استخدام مفاتيح الاختبار في اختبارات E2E** وإلا فشلت عشوائيًا.

---

### 6.6 رصد الأخطاء — Error Monitoring

**التوصية: Sentry عبر `@sentry/cloudflare` + Workers Logs من Cloudflare.**

PRD §6.4 يذكر `SENTRY_DSN` كمتغير اختياري. **نوصي بجعله إلزاميًا في الإنتاج** — منصة بلا رصد أخطاء تعني اكتشاف عطل تسجيل الحضور من شكوى متدرب لا من تنبيه.

```bash
npm install @sentry/cloudflare
```

```jsonc
// wrangler.jsonc — both are required by the SDK
{
  "compatibility_date": "2026-08-04",       // must be >= 2024-09-23
  "compatibility_flags": ["nodejs_compat"]  // AsyncLocalStorage
}
```

```ts
import * as Sentry from "@sentry/cloudflare";

export default Sentry.withSentry(
  (env: Env) => ({
    dsn: env.SENTRY_DSN,
    release: env.CF_VERSION_METADATA?.id,
    environment: env.ENVIRONMENT,          // production | staging
    tracesSampleRate: 0.1,

    // PRD §12.6 — "جمع الحد الأدنى من البيانات اللازمة فقط".
    sendDefaultPii: false,

    beforeSend(event) {
      // Never let a token, password or trainee email reach a third party.
      scrub(event, ["password", "token", "activation", "reset", "email", "phone"]);
      return event;
    },
  }),
  {
    async fetch(request, env, ctx) { /* … */ },

    // The queue consumer is where email failures surface. Wrapping only the
    // fetch handler would leave the most failure-prone path unmonitored.
    async queue(batch, env, ctx) { /* … */ },
  }
);
```

**ما يُرسل إلى Sentry تحديدًا:**
- فشل مزوّد البريد بعد استنفاد المحاولات (وصول رسالة إلى DLQ) — **أعلى أولوية**
- أخطاء منطق نوافذ الحضور (PRD §18: أعلى مخاطرة في المشروع)
- فشل توليد الشهادات أو PDF
- كل استثناء غير معالَج في `fetch` أو `queue` أو `scheduled`

**الطبقة المجانية:** 5,000 خطأ شهريًا — أعلى بكثير من احتياج منصة بهذا الحجم.

**البديل الأصيل المكمِّل — Cloudflare Workers Observability:** سجلات منظَّمة داخل لوحة Cloudflare بلا خدمة خارجية ولا تسريب بيانات إلى طرف ثالث. **نفعّلها إلى جانب Sentry** — Sentry للتنبيه والتجميع، وWorkers Logs للتحقيق العميق. تُفعّل بـ:

```jsonc
{ "observability": { "enabled": true, "head_sampling_rate": 1 } }
```

---

### 6.7 التحليلات — Analytics

PRD §12.6 يلزم: «جمع الحد الأدنى من البيانات اللازمة فقط» و«التوافق مع نظام حماية البيانات الشخصية في المملكة».

**التوصية: Cloudflare Web Analytics.**

| المعيار | التقييم |
|---|---|
| التكلفة | **مجاني على كل الباقات** |
| الكوكيز | **لا يضع أي كوكي، ولا يستخدم `localStorage`، ولا يخزّن أي معرّف على جهاز الزائر** |
| **لافتة الموافقة** | ✅ **غير مطلوبة** — لا معالجة بيانات شخصية ⇒ توفير احتكاك على صفحة الهبوط |
| التفعيل على Pages | نقرة واحدة: Workers & Pages → المشروع → Metrics → Enable | 
| ما يقدّمه | مشاهدات، زوار، مصادر الإحالة، الدول، الأجهزة، **Core Web Vitals** (LCP / INP / CLS) |
| القياس | طلب `POST` واحد إلى `cloudflareinsights.com` بلا أي بيانات تعريف شخصية |

**لماذا Core Web Vitals مهم هنا:** PRD §9.1.3 يشترط درجة Lighthouse ≥ 90 و«وقت تحميل أقل من ثانيتين على 4G». Lighthouse قياس مخبري؛ **Web Analytics يعطي القياس الميداني الحقيقي** من أجهزة المتدربين على شبكاتهم الفعلية. هذا ما يثبت تحقق المعيار أو ينفيه.

**البدائل — إن طُلبت أحداث مخصصة (لا يوفّرها Cloudflare):**

| الخيار | التكلفة | الملاحظة |
|---|---|---|
| **Umami** (استضافة ذاتية) | مجاني | مفتوح المصدر، بلا كوكيز، **البيانات تبقى داخل بنيتنا** — الأفضل لموقف §12.6 |
| **Plausible** (استضافة ذاتية) | مجاني | بلا كوكيز، خفيف (< 1KB) |
| Plausible / Umami (سحابي) | مدفوع | بيانات لدى طرف ثالث — يحتاج مراجعة توافق |
| ❌ Google Analytics | «مجاني» | **لا يُوصى به:** يضع كوكيز، يتطلب لافتة موافقة، ينقل بيانات إلى الولايات المتحدة، ويخالف روح §12.6 |

> **القرار:** Cloudflare Web Analytics في الإصدار الأول. يُضاف Umami مستضافًا ذاتيًا **فقط إن ظهرت حاجة فعلية** لتتبع أحداث مخصصة (مثل قياس معدل التحويل في نموذج التسجيل متعدد الخطوات).

> ⚠️ **لا تتبع داخل لوحة التحكم.** التحليلات تُحمَّل على **صفحة الهبوط وصفحات التحقق العامة فقط**. تتبّع سلوك متدرب داخل لوحته الشخصية يتجاوز «الحد الأدنى من البيانات اللازمة» ولا مبرر تشغيليًا له.

---

## 7. الأسرار والإعداد — Secrets & Configuration

### 7.1 تحديث على PRD §6.4

جدول متغيرات البيئة في PRD §6.4 كُتب على افتراض استضافة تقليدية وSMTP. **يُحدَّث كالتالي:**

| متغير PRD §6.4 | الحالة | البديل |
|---|---|---|
| `SMTP_HOST` `SMTP_PORT` `SMTP_USER` `SMTP_PASSWORD` | 🔴 **ملغاة** | `RESEND_API_KEY` · `BREVO_API_KEY` |
| `MAIL_FROM` | ✅ تبقى | تُقسَّم إلى `MAIL_FROM_EMAIL` + `MAIL_FROM_NAME` (الاسم العربي منفصل) |
| `SENTRY_DSN` | ⬆️ من اختياري إلى **إلزامي في الإنتاج** | — |
| `DATABASE_URL` | 🔄 | ربط D1 في `wrangler.jsonc` |
| `STORAGE_*` | 🔄 | ربط R2 في `wrangler.jsonc` |
| `RATE_LIMIT_REDIS_URL` | 🔄 | Durable Object أو KV — لا حاجة لـ Redis |
| `APP_TIMEZONE` | ✅ تبقى | `Asia/Riyadh` |

### 7.2 المرجع الكامل

**متغيرات عامة — في `wrangler.jsonc` تحت `vars` (ليست أسرارًا):**

```
APP_BASE_URL       = https://ai.athar-dev.edu.sa
APP_TIMEZONE       = Asia/Riyadh
MAIL_FROM_EMAIL    = info@athar-dev.edu.sa
MAIL_FROM_NAME     = مركز أثر للتدريب
MAIL_REPLY_TO      = contact@athar-dev.edu.sa
ASSET_BASE_URL     = https://assets.athar-dev.edu.sa/email/v1
WHATSAPP_NUMBER    = 966582090332
ENVIRONMENT        = production | staging
TURNSTILE_SITE_KEY = <public — safe in client bundle>
```

**أسرار — عبر `wrangler secret put` حصرًا:**

| السر | المصدر | التدوير |
|---|---|---|
| `RESEND_API_KEY` | لوحة Resend → API Keys (صلاحية `Sending access` فقط) | كل 6 أشهر |
| `BREVO_API_KEY` | لوحة Brevo → SMTP & API | كل 6 أشهر |
| `RESEND_WEBHOOK_SECRET` | لوحة Resend → Webhooks (`whsec_…`) | عند التدوير |
| `TURNSTILE_SECRET_KEY` | لوحة Cloudflare → Turnstile | نادرًا |
| `AUTH_SECRET` | `openssl rand -base64 32` | كل 12 شهرًا (يُبطل الجلسات) |
| `QR_SIGNING_SECRET` | `openssl rand -base64 32` | 🚫 **لا يُدوَّر** — يُبطل كل أكواد QR الصادرة |
| `UNSUBSCRIBE_SIGNING_SECRET` | `openssl rand -base64 32` | كل 12 شهرًا |
| `SENTRY_DSN` | لوحة Sentry | نادرًا |
| `ZOOM_CLIENT_SECRET` | لوحة Zoom (إن اعتُمد API) | كل 6 أشهر |

### 7.3 قواعد ملزمة

1. **`.env.example` يوثّق كل متغير بلا أي قيمة حقيقية.** التزامًا بـ PRD §6.3.
2. **لا سر في المستودع.** لا في الكود، ولا في `wrangler.jsonc`، ولا في تعليق، ولا في وثيقة تحليل.
3. **مفاتيح منفصلة تمامًا لـ staging والإنتاج.** مفتاح staging لا يجب أن يستطيع الإرسال لعناوين حقيقية.
4. **مبدأ الامتياز الأدنى:** مفتاح Resend بصلاحية الإرسال فقط، لا صلاحية إدارة النطاقات.
5. **سجل تدوير موثَّق** بتاريخ آخر تدوير لكل سر — يُراجع ربع سنويًا.

> 🔴 **تذكير أمني.** كلمة مرور `info@athar-dev.edu.sa` (المشار إليها في هذه الوثيقة بـ `<MAILBOX_PASSWORD — stored as a Wrangler secret>`) وصلت بنص صريح ويجب **تدويرها فورًا** — راجع §1.3. **لن تُستخدم في هذه المعمارية إطلاقًا، ولا يوجد لها مكان في قائمة الأسرار أعلاه.**

---

## 8. قائمة التنفيذ — Execution Checklist

### المرحلة 0 — إصلاحات عاجلة (اليوم، قبل أي كود)

- [ ] 🔴 **نشر سجل SPF** على الجذر: `v=spf1 include:_spf.google.com ~all` — §4.2
- [ ] 🔴 **تدوير كلمة مرور** `info@athar-dev.edu.sa` + تفعيل التحقق بخطوتين — §1.3
- [ ] 🔴 مراجعة قواعد إعادة التوجيه و«Send mail as» في الصندوق
- [ ] التحقق عبر MXToolbox من أن SPF أخضر وعدد الاستعلامات ≤ 7

### المرحلة 1 — البنية التحتية للبريد

- [ ] ترقية حساب Cloudflare إلى **Workers Paid** ($5/شهر)
- [ ] إنشاء حساب Resend وإضافة النطاق `athar-dev.edu.sa`
- [ ] نشر سجلات Resend: `resend._domainkey` TXT + `send` MX + `send` TXT — §4.2
- [ ] إكمال DKIM لـ Brevo (`mail._domainkey`) — §4.2
- [ ] تحديث سجل DMARC بالقيمة الكاملة عند `p=none` — §4.2
- [ ] إغلاق `ai.athar-dev.edu.sa` ضد الانتحال (Null MX + SPF `-all` + DMARC `p=reject`) — §4.2
- [ ] إنشاء `dmarc-rua@` و`dmarc-ruf@` في Google Workspace
- [ ] إنشاء حاوية R2 `athar-email-assets` وربطها بـ `assets.athar-dev.edu.sa`
- [ ] رفع أصول الصور تحت `/email/v1/` — §5.5
- [ ] تفعيل Google Postmaster Tools

### المرحلة 2 — الكود

- [ ] إنشاء طابور `athar-email` وطابور DLQ `athar-email-dlq`
- [ ] بناء جدول `notifications` في D1 (`id`, `user_id`, `type`, `status`, `provider`, `provider_message_id`, `attempts`, `payload`, `sent_at`)
- [ ] تنفيذ `src/email/send.ts` — Resend + Brevo مع التجاوز عند الفشل — §3.6
- [ ] تنفيذ مستهلك الطابور مع الحماية من التكرار والتراجع الأسّي — §3.6
- [ ] تنفيذ القالب الأساسي وكتلتَي CTA والمعلومات — §5.3 و§5.4
- [ ] كتابة القوالب العشرين ونصوصها العادية في `locales/ar.json` — §5.6 و§5.7
- [ ] تنفيذ webhook الارتداد مع التحقق من توقيع Svix — §3.8
- [ ] تنفيذ نقطة إلغاء الاشتراك (GET + POST) — §5.9
- [ ] تنفيذ مولّد ICS مع الطي على البايتات — §6.1
- [ ] تنفيذ Turnstile على العميل والخادم — §6.5
- [ ] تفعيل Sentry على `fetch` و`queue` و`scheduled` — §6.6
- [ ] تفعيل Cloudflare Web Analytics على صفحة الهبوط — §6.7
- [ ] زر واتساب وروابط لينكدإن — §6.3 و§6.4

### المرحلة 3 — التحقق قبل الإطلاق

- [ ] **mail-tester ≥ 9/10** على ثلاثة قوالب مختلفة على الأقل
- [ ] `SPF: PASS` و`DKIM: PASS` و`DMARC: PASS` في Gmail → Show original
- [ ] نطاق توقيع DKIM هو `athar-dev.edu.sa` (المحاذاة محققة)
- [ ] فحص RTL على: Gmail ويب/أندرويد/iOS · Outlook ويندوز/ويب · Apple Mail
- [ ] كل قالب مفهوم **والصور محجوبة**
- [ ] الوضع الليلي سليم في Apple Mail وOutlook.app
- [ ] ICS يُستورد في Google Calendar وApple Calendar بوقت رياض صحيح — **معيار قبول §9.8.2**
- [ ] محاكاة فشل Resend (مفتاح خاطئ) والتأكد من نجاح التجاوز إلى Brevo
- [ ] محاكاة تسليم مكرر من الطابور والتأكد من عدم الإرسال مرتين
- [ ] رسالة تصل إلى DLQ تُنتج تنبيه Sentry
- [ ] الإلغاء بنقرة واحدة (POST) يعمل ويُطبَّق فورًا
- [ ] رسائل الأمان (`E-01` `E-03` `E-04` `E-05`) **لا تحمل** رابط إلغاء اشتراك
- [ ] إكمال إحماء النطاق حتى المرحلة 3 — §4.6
- [ ] تقارير DMARC تُظهر ≥ 98% محاذاة لأسبوعين

### المرحلة 4 — الإطلاق وما بعده

- [ ] ترقية Resend إلى Pro ($20/شهر) **قبل يوم فتح التسجيل**
- [ ] مراقبة معدل الشكاوى في Postmaster Tools (< 0.30%)
- [ ] بعد استقرار أسبوعين: الانتقال إلى `p=quarantine; pct=25` — §4.4
- [ ] **لا يُشدَّد DMARC أثناء تشغيل دفعة**

---

## 9. القرارات المطلوبة من صاحب المنتج

| # | القرار | التوصية |
|---|---|---|
| 1 | الموافقة على رفض SMTP بكلمة مرور الصندوق واعتماد Resend/Brevo | ✅ اعتماد §3.3 |
| 2 | **تدوير كلمة مرور `info@` فورًا** | ✅ إلزامي — §1.3 |
| 3 | ميزانية $25/شهر (Workers Paid + Resend Pro) | ✅ اعتماد |
| 4 | الإرسال من الجذر لا من نطاق فرعي (لتحقيق `info@athar-dev.edu.sa`) | ✅ اعتماد §4.1 |
| 5 | **معرّف صفحة مركز أثر على لينكدإن** — أو قرار إنشائها | 📩 مطلوب من العميل — §6.4 |
| 6 | تكامل الزوم: يدوي في الإصدار الأول (§19-3) | ✅ يدوي — §6.2 |
| 7 | مسار موافقة المدير على التسجيل (§19-5) يحدد تفعيل `E-19` و`E-20` | 📩 مطلوب — §5.6 |
| 8 | مدة مراجعة طلب التسجيل `{{REVIEW_SLA}}` | 📩 مطلوب — §5.6 |
| 9 | BIMI مؤجَّل | ✅ اعتماد §4.8 |
| 10 | تأكيد ملكية علامة تجارية مسجّلة (لو أُعيد النظر في BIMI مستقبلًا) | 📩 للعلم |

---

## 10. المصادر

**Cloudflare**
- [TCP Sockets — `connect()`, port 25 block, `startTls()`](https://developers.cloudflare.com/workers/runtime-apis/tcp-sockets/)
- [Node.js compatibility](https://developers.cloudflare.com/workers/runtime-apis/nodejs/) · [`node:net`](https://developers.cloudflare.com/workers/runtime-apis/nodejs/net/)
- [Workers limits — CPU, subrequests, 6 concurrent connections](https://developers.cloudflare.com/workers/platform/limits/)
- [Queues limits](https://developers.cloudflare.com/queues/platform/limits/) · [Queues pricing](https://developers.cloudflare.com/queues/platform/pricing/)
- [Queues — batching & retries](https://developers.cloudflare.com/queues/configuration/batching-retries/) · [Dead letter queues](https://developers.cloudflare.com/queues/configuration/dead-letter-queues/)
- [Email Service — limits](https://developers.cloudflare.com/email-service/platform/limits/) · [pricing](https://developers.cloudflare.com/email-service/platform/pricing/)
- [R2 pricing](https://developers.cloudflare.com/r2/pricing/)
- [Turnstile — server-side validation](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/) · [client-side rendering](https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/) · [test keys](https://developers.cloudflare.com/turnstile/troubleshooting/testing/)
- [Web Analytics — FAQ](https://developers.cloudflare.com/web-analytics/faq/) · [on Pages](https://developers.cloudflare.com/pages/how-to/web-analytics/)
- [Tutorial — send emails with Resend](https://developers.cloudflare.com/workers/tutorials/send-emails-with-resend/)
- [workerd#2712 — startTls for SMTP (open)](https://github.com/cloudflare/workerd/issues/2712) · [workerd#6903 — SNI ignored on edge (open)](https://github.com/cloudflare/workerd/issues/6903)
- [`worker-mailer` — ports 587/465 supported](https://github.com/zou-yu/worker-mailer)

**مزوّدو البريد**
- [Resend pricing](https://resend.com/pricing) · [send email API + Idempotency-Key](https://resend.com/docs/api-reference/emails/send-email) · [batch](https://resend.com/docs/api-reference/emails/send-batch-emails) · [rate limits](https://resend.com/docs/api-reference/rate-limit) · [webhook events](https://resend.com/docs/webhooks/event-types) · [Cloudflare DNS setup](https://resend.com/docs/dashboard/domains/cloudflare) · [regions](https://resend.com/docs/dashboard/domains/regions) · [transactional unsubscribe](https://resend.com/docs/dashboard/emails/add-unsubscribe-to-transactional-emails)
- [Brevo — send transactional email](https://developers.brevo.com/reference/sendtransacemail) · [API limits](https://developers.brevo.com/docs/api-limits) · [authenticate your domain](https://help.brevo.com/hc/en-us/articles/12163873383186-Authenticate-your-domain-with-Brevo-Brevo-code-DKIM-DMARC)
- [MailChannels — Cloudflare Workers API termination (30 June 2024)](https://blog.mailchannels.com/important-update-mailchannels-email-sending-api-for-cloudflare-workers-to-be-terminated/) · [pricing](https://www.mailchannels.com/pricing/)
- [SendGrid — trial account plan (no permanent free tier)](https://support.sendgrid.com/hc/en-us/articles/35270136965403-Twilio-SendGrid-Trial-Account-Plan) · [pricing](https://www.twilio.com/en-us/products/email-api/pricing)
- [Mailgun pricing](https://www.mailgun.com/pricing/) · [unsubscribe handling](https://help.mailgun.com/hc/en-us/articles/203306610-Unsubscribe-Handling-Links)
- [Zoho ZeptoMail pricing](https://www.zoho.com/zeptomail/pricing.html)
- [Amazon SES pricing](https://aws.amazon.com/ses/pricing/) · [production access](https://docs.aws.amazon.com/ses/latest/dg/request-production-access.html) · [custom MAIL FROM](https://docs.aws.amazon.com/ses/latest/dg/mail-from.html)
- [Postmark pricing](https://postmarkapp.com/pricing) · [custom return-path](https://postmarkapp.com/support/article/910-how-do-i-add-a-custom-return-path)

**التسليم والمصادقة**
- [Google — Email sender guidelines](https://support.google.com/a/answer/81126) · [Set up SPF](https://knowledge.workspace.google.com/admin/security/set-up-spf)
- [dmarcian — Microsoft enforces SPF/DKIM/DMARC (5 May 2025)](https://dmarcian.com/microsoft-enforces-spf-dkim-dmarc/)
- [RFC 7208 — SPF (10-lookup limit)](https://datatracker.ietf.org/doc/html/rfc7208)
- [RFC 5545 — iCalendar](https://datatracker.ietf.org/doc/html/rfc5545) · [RFC 5546 — iTIP (PUBLISH vs REQUEST)](https://www.rfc-editor.org/rfc/rfc5546.html)
- [Google Postmaster Tools](https://postmaster.google.com) · [mail-tester](https://www.mail-tester.com) · [MXToolbox](https://mxtoolbox.com/SuperTool.aspx) · [learndmarc](https://www.learndmarc.com)

**تكاملات**
- [Zoom — Server-to-Server OAuth](https://developers.zoom.us/docs/internal-apps/s2s-oauth/) · [granular scopes](https://developers.zoom.us/docs/integrations/oauth-scopes-granular/)
- [LinkedIn — Add to Profile](https://addtoprofile.linkedin.com/) · [help article](https://www.linkedin.com/help/linkedin/answer/a528030)
- [Sentry — Cloudflare Workers SDK](https://docs.sentry.io/platforms/javascript/guides/cloudflare/)

**فحص DNS حي** — `athar-dev.edu.sa` عبر Cloudflare DNS-over-HTTPS، 2026-09-04. النتائج في §1.
