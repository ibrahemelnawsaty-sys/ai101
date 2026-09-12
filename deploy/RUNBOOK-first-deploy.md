# دليل النشر الأول — `ai.wareed.vip` على Hostinger

> **الرفيق العملي لـ `deploy/README.md`.** ذاك يشرح *لماذا*؛ وهذا يعطي التسلسل *بالضبط*.
> عند أي تعارض بينهما، **`deploy/README.md` هو المرجع**.
>
> النطاق: `ai.wareed.vip` (`D-36`) · المصدر: `github.com/ibrahemelnawsaty-sys/ai101`
> `$APP` = `$HOME/athar-app` — **خارج جذر الويب دائمًا** (المادة 10 والمادة 24).

---

## ما لا يُعمَل عبر SSH إطلاقًا

أربعة أشياء تُعمَل من **hPanel** قبل أن تفتح الطرفية، ولا بديل عنها:

| # | العمل | المكان في hPanel |
|---|---|---|
| 1 | إنشاء النطاق الفرعي `ai.wareed.vip` | Domains ← Subdomains |
| 2 | اختيار **PHP 8.2 أو 8.3** وتفعيل اللواحق | Advanced ← PHP Configuration |
| 3 | تفعيل SSH وأخذ المضيف والمنفذ | Advanced ← SSH Access |
| 4 | إصدار شهادة SSL **للنطاق الفرعي تحديدًا** | Security ← SSL |

اللواحق المطلوبة: `pdo_mysql` · `mbstring` · `openssl` · `fileinfo` · `zip` · `gd` · `intl` · `curl` · `bcmath`.

شهادة باسم `wareed.vip` وحدها **لا تغطي** `ai.wareed.vip`.

---

## المرحلة 0 · على جهازك — بناء الأصول

**لا Node على الاستضافة المشتركة**، فملفات `public/build` تُبنى هنا وتُرفع. هذه المرحلة تسبق كل شيء.

```sh
cd /c/Users/b.maher/Downloads/wesal/LARAVEL
npm run build
ls public/build/manifest.json && ls public/build/assets/
```

يجب أن ترى `manifest.json` وستة ملفات في `assets/`. إن لم تظهر، لا تكمل.

---

## المرحلة 1 · الدخول واستطلاع الخادم

**لا تنفّذ شيئًا قبل أن تعرف هذه القيم.** كل مرحلة بعدها تعتمد عليها.

```sh
ssh -p <المنفذ> <المستخدم>@<المضيف>
```

```sh
echo "HOME = $HOME"
php -v                                   # يجب أن يبدأ بـ 8.2 أو 8.3
readlink -f "$(command -v php)"           # المسار المطلق — يلزم للكرون
command -v git composer rsync unzip
ls -d $HOME/domains/*/public_html 2>/dev/null
df -h "$HOME" | tail -1
```

- **`php -v` أقل من 8.2** ← ارجع إلى hPanel وغيّر الإصدار. لا تكمل.
- **`git` غير موجود** ← استخدم طريقة الأرشيف في `deploy/README.md` §5.2 بدل الاستنساخ.
- **`composer` غير موجود** ← المرحلة 3 تتكفّل بذلك.

---

## المرحلة 2 · الهيكل والاستنساخ

```sh
mkdir -p "$HOME/athar-app" "$HOME/backups/db" "$HOME/backups/files" "$HOME/releases"
chmod 700 "$HOME/backups"

git clone https://github.com/ibrahemelnawsaty-sys/ai101.git "$HOME/athar-app"
cd "$HOME/athar-app"
git log --oneline -1
```

إن طُلب اسم مستخدم وكلمة مرور فالمستودع **خاص**: أنشئ Personal Access Token من GitHub
(Settings ← Developer settings ← Tokens، صلاحية `repo`) واستخدمه ككلمة مرور، أو اجعل المستودع عامًّا.

> **انحراف معلَن عن `deploy/README.md` §5.1:** الدليل يمنع رفع `.git/`، وهذا الاستنساخ يُنشئها.
> **مقبول هنا بشرط واحد:** أن يبقى `$APP` خارج جذر الويب (المرحلة 9)، فلا يكون `$APP/.git`
> قابلًا للوصول عبر HTTP. تحقّق منه في المرحلة 13، البند 5.

---

## المرحلة 3 · تركيب `vendor/`

`vendor/` ليست في المستودع عمدًا. إن وُجد `composer`:

```sh
cd "$HOME/athar-app"
COMPOSER_MEMORY_LIMIT=-1 composer install \
  --no-dev --optimize-autoloader --no-interaction --prefer-dist
```

وإن لم يوجد:

```sh
mkdir -p "$HOME/bin" && cd "$HOME"
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir="$HOME/bin" --filename=composer
rm composer-setup.php

cd "$HOME/athar-app"
COMPOSER_MEMORY_LIMIT=-1 "$HOME/bin/composer" install \
  --no-dev --optimize-autoloader --no-interaction --prefer-dist
```

`--no-dev` **إلزامي**: Pest وLarastan وPint وFaker لا تصل الإنتاج أبدًا.
`--optimize-autoloader` **إلزامي**: بدونه ثمن محسوس على كل طلب على معالج مشترك.

---

## المرحلة 4 · ملف `.env`

**من جهازك، في طرفية ثانية:**

```sh
scp -P <المنفذ> /c/Users/b.maher/Downloads/wesal/LARAVEL/.env.production \
    <المستخدم>@<المضيف>:~/athar-app/.env
```

**ثم على الخادم فورًا:**

```sh
cd "$HOME/athar-app"
chmod 600 .env
ls -l .env                 # المتوقع: -rw-------
grep -E '^(APP_ENV|APP_DEBUG|APP_URL|DB_DATABASE)=' .env
```

المتوقع حرفيًّا: `production` · `false` · `https://ai.wareed.vip` · اسم قاعدتك.

**`APP_DEBUG=true` في الإنتاج حادث، لا خطأ إعداد** (المادة 12).

---

## المرحلة 5 · القاعدة — الترميز قبل أول ترحيل

ترميز خاطئ على بيانات عربية **عيب سلامة بيانات لا عيب شكل**، وإصلاحه بعد الترحيل مؤلم.

```sh
mysql -u "$(grep '^DB_USERNAME=' .env | cut -d= -f2)" -p \
      -D "$(grep '^DB_DATABASE=' .env | cut -d= -f2)" -e \
"SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE();"
```

المتوقع: `utf8mb4 | utf8mb4_unicode_ci`. إن اختلف:

```sql
ALTER DATABASE `<اسم القاعدة>` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

---

## المرحلة 6 · الإقلاع — بهذا الترتيب حرفيًّا

```sh
cd "$HOME/athar-app"

# 1. مفتاح التطبيق — مرة واحدة فقط في العمر
php artisan key:generate --force
```

> **لا يُعاد توليد `APP_KEY` على قاعدة إنتاج قائمة أبدًا.** كل عمود مشفَّر وكل رابط موقَّع
> وكل جلسة نشطة مشتقّ منه؛ إعادة توليده تُتلفها بلا رجعة. خذ نسخة منه الآن واحفظها مع
> بيانات القاعدة في مدير الأسرار.

```sh
# 2. شجرة التخزين
mkdir -p storage/app/private storage/app/public \
         storage/framework/{cache/data,sessions,testing,views} \
         storage/logs bootstrap/cache

# 3. المخطّط — --force لأن APP_ENV=production يسأل بدونها
php artisan migrate --force

# 4. البيانات المرجعية فقط. ProgramSeeder وحده مسموح في الإنتاج: البرنامج ودفعته
#    وأسابيعه الأربعة وخطوات الرحلة العشر وإعدادات صفحة الهبوط.
#    DatabaseSeeder يستدعي بذور العرض التوضيحي — لا يُشغَّل هنا إطلاقًا.
php artisan db:seed --force --class=Database\\Seeders\\ProgramSeeder

# 5. الذواكر المؤقتة — الترتيب مهم: امسح ثم ابنِ
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 6. تقرير السلامة
php artisan about
```

`php artisan about` يجب أن يُظهر: البيئة `production` · التنقيح `OFF` ·
config و routes و views و events كلها `CACHED` · القاعدة `mysql` متصلة.

---

### 6.1 · أول حساب — بدونه لا أحد يدخل

`ProgramSeeder` يبذر البرنامج والدفعة والأسابيع وخطوات الرحلة وإعدادات صفحة الهبوط،
و**لا يُنشئ مستخدمًا واحدًا**. فبعد الخطوة السابقة مباشرةً تكون المنصة قائمة وبابها
مغلق: لا حساب مدير يدخل ليملأ المحتوى الذي تفرض BR-31 أن يكون في قاعدة البيانات.

```sh
cd "$APP"
php artisan athar:make-user --role=admin
```

يسأل الأمر عن البريد وكلمة المرور (بلا إظهار) والأسماء الأربعة بالعربية والإنجليزية
والجوال والجنس، ويطبّق **نفس** قواعد PRD §9.2.1 التي تطبّقها شاشة التسجيل — لأن حسابًا
يُنشأ من الطرفية بقواعد أضعف يجعل قواعد الشاشة زينة (المادة 5).

`email_verified_at` يُضبط لحظة الإنشاء **عمدًا**: `D-02` مفتوح، ولا مزوّد بريد معتمدًا،
و`MAIL_MAILER=array` يُهمل كل رسالة. حساب يُترك غير موثَّق اليوم لا سبيل إلى توثيقه بأي
وسيلة متاحة.

للمدربين والمتدربين: `--role=trainer` أو `--role=participant`. **حساب المتدرب وحده لا
يكفي** — يحتاج تسجيلًا في دفعة، ويُنشأ من لوحة الإدارة.

> ⚠️ **ما دام `D-02` مفتوحًا فلا استعادة كلمة مرور.** أول متدرب ينسى كلمته يتوقف حتى
> يُنشئ له المدير حسابًا جديدًا أو يُعيد ضبط كلمته يدويًّا. هذا ليس عيبًا في الأمر، بل
> نتيجة مباشرة لبند مفتوح — ولا يُقال إن المنصة جاهزة للمتدربين قبل حسمه.

---

## المرحلة 7 · رفع الأصول المبنية

**من جهازك:**

```sh
scp -P <المنفذ> -r /c/Users/b.maher/Downloads/wesal/LARAVEL/public/build \
    <المستخدم>@<المضيف>:~/athar-app/public/
```

**تحقّق على الخادم:**

```sh
ls "$HOME/athar-app/public/build/manifest.json"
```

> **هذه المرحلة تتكرّر في كل نشر يمسّ CSS أو JS.** نسيانها يعطي موقعًا بتنسيق الإصدار
> السابق — عطل يصعب تشخيصه لأنه لا يرمي خطأ.

---

## المرحلة 8 · الصلاحيات

PHP يعمل بحسابك أنت على Hostinger (LSAPI)، فبتات المجموعة والآخرين لا لزوم لها.
**لا `777` في أي مكان. ولا `775` إلا بعد إثبات أن مستخدم الويب غيرك.**

```sh
cd "$HOME/athar-app"
find . -type d -not -path './vendor/*' -exec chmod 755 {} \;
find . -type f -not -path './vendor/*' -exec chmod 644 {} \;
chmod 755 artisan
chmod -R 755 storage bootstrap/cache
chmod 600 .env
chmod 700 deploy/backup-*.sh 2>/dev/null || true

find "$HOME/athar-app" -perm -o+w -not -path '*/vendor/*' -print
# المتوقع: لا مخرجات إطلاقًا
```

---

## المرحلة 9 · جذر الويب

### الخيار أ — توجيه جذر المستند إلى `$APP/public` *(المفضَّل)*

hPanel ← Websites ← Dashboard ← Advanced ← Website settings، واضبط جذر المستند
لـ `ai.wareed.vip` على `/home/<حسابك>/athar-app/public`. ثم:

```sh
cp "$HOME/athar-app/deploy/.htaccess" "$HOME/athar-app/public/.htaccess"
```

نسخة واحدة من الأصول، بلا إعادة كتابة مسارات، وبلا انحراف بين مجلدين.

### الخيار ب — الوسيط في `public_html` *(إن رفضت اللوحة تغيير الجذر)*

```sh
WEB="$HOME/domains/ai.wareed.vip/public_html"      # تأكّد منه في المرحلة 1

cp "$HOME/athar-app/deploy/public_html-index.php" "$WEB/index.php"
cp "$HOME/athar-app/deploy/.htaccess"             "$WEB/.htaccess"
nano "$WEB/index.php"        # عدّل ATHAR_APP_BASE وحده ليطابق مسارك المطلق

rsync -a --delete "$HOME/athar-app/public/build/" "$WEB/build/"
rsync -a --delete "$HOME/athar-app/public/brand/" "$WEB/brand/"
rsync -a --delete "$HOME/athar-app/public/fonts/" "$WEB/fonts/" 2>/dev/null || true
cp "$HOME/athar-app/public/favicon.ico" "$WEB/" 2>/dev/null || true
cp "$HOME/athar-app/public/robots.txt"  "$WEB/" 2>/dev/null || true

rm -f "$WEB/default.php" "$WEB/index.html" "$WEB"/hostinger-*.html
```

> في الخيار ب **يجب** تكرار سطور `rsync` في كل نشر يمسّ الواجهة، لأن
> `public/build/manifest.json` يُقرأ عبر `public_path()` وهو هنا `$WEB`.

---

## المرحلة 10 · HTTPS

الشهادة تُصدَر من hPanel. فعّل **Force HTTPS** إن وُجد الخيار. ثم تحقّق:

```sh
curl -sS -o /dev/null -w '%{http_code} %{redirect_url}\n' http://ai.wareed.vip/
# المتوقع: 301 https://ai.wareed.vip/

curl -sSI https://ai.wareed.vip/ | grep -Ei 'strict-transport|x-frame|x-content-type|referrer-policy'
```

لا تُفعِّل HSTS إلا بعد تأكّد عمل HTTPS على كل مضيف يخدمه الحساب — لا رجعة عنها خلال
مدّة `max-age` لمن استلمها.

---

## المرحلة 11 · تحصين `audit_logs` — `D-08`

المادة 8 تفرض أن يكون سجل التدقيق **غير قابل للتعديل ولا الحذف على مستوى صلاحية مستخدم
القاعدة**. لوحة Hostinger تمنح `ALL PRIVILEGES` بلا `GRANT OPTION`، فالبديل المتاح هو المشغّلات:

```sql
DELIMITER //
CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only (art. 8)'; END//
CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only (art. 8)'; END//
DELIMITER ;
```

**يجب أن يفشل كلاهما بـ `ERROR 1644`:**

```sql
UPDATE audit_logs SET action = 'x' LIMIT 1;
DELETE FROM audit_logs LIMIT 1;
```

> **خطر متبقٍّ معلَن:** مستخدم يملك `ALL PRIVILEGES` يستطيع `DROP TRIGGER` ثم التعديل،
> و`TRUNCATE` لا يُفعّل مشغّلات الصفوف. هذا يرفع العتبة ولا يجعل الجدول منيعًا.
> **`D-08` يبقى مفتوحًا، ولا يُقال إن المادة 8 محقَّقة.**

---

## المرحلة 12 · الكرون

من hPanel ← Advanced ← Cron Jobs. الأسطر الكاملة في **`deploy/cron-setup.txt`**، وفيها ثلاثة
مدخلات: المُجدوِل كل دقيقة، ونسختان احتياطيتان. **لا مدخل للطابور** — العامل مهمّة مجدولة داخل
`schedule:run` (`bootstrap/app.php`). **استبدل `/usr/bin/php` بالمسار المطلق من المرحلة 1، و`/home/u123456789` بمسار `$HOME`
الحقيقي، في كل سطر.**

قبل إنشاء أي مدخل، أثبت أن التركيبة تعمل:

```sh
<مسار php المطلق> $HOME/athar-app/artisan about
<مسار php المطلق> $HOME/athar-app/artisan schedule:list
```

مدخل كرون مبني على أمر مكسور يفشل صامتًا مرة كل دقيقة، إلى الأبد.

**ممنوع إطلاقًا:** `queue:work` بلا `--stop-when-empty` و`--max-time` (هذا عفريت) ·
`schedule:work` · مدخل `schedule:run` ثانٍ · مدخل `queue:work` مستقل · أي مدخل بلا `>> <ملف> 2>&1`.

**وبعد أول تشغيل للكرون، مرّة واحدة:** الجلسات التي انتهت قبل أن يعمل الكرون بأكثر من ثلاثة
أيام لا تُعاد زيارتها أبدًا، فغيّابها بلا صفّ غياب ونِسَب حضورهم خاطئة:

```sh
cd $HOME/athar-app && php artisan attendance:reconcile --days=30
```

الأمر لا يتكرّر أثره إن أُعيد. التفصيل في `deploy/cron.md` §9.

---

## المرحلة 13 · التحقق — لا يُبلَّغ عن نجاح قبل تشغيل كل سطر

| # | الفحص | المتوقع |
|---|---|---|
| 1 | `php artisan about` | `production` · debug `OFF` · كل الذواكر `CACHED` |
| 2 | `curl -o /dev/null -w '%{http_code}' https://ai.wareed.vip/` | `200` |
| 3 | `curl -o /dev/null -w '%{http_code}' http://ai.wareed.vip/` | `301` |
| 4 | `curl -o /dev/null -w '%{http_code}' https://ai.wareed.vip/.env` | `403` أو `404` |
| 5 | `curl -o /dev/null -w '%{http_code}' https://ai.wareed.vip/.git/config` | `403` أو `404` |
| 6 | `curl -o /dev/null -w '%{http_code}' https://ai.wareed.vip/storage/logs/` | `403` أو `404` |
| 7 | الصفحة في المتصفح | عربية RTL · **الحروف متصلة** · بلا أصفر أو ذهبي |
| 8 | الأرقام والتواريخ | لاتينية · «الأحد 12 أكتوبر 2026» |
| 9 | تسجيل الدخول ثم تحديث الصفحة | الجلسة تبقى — يثبت `TrustProxies` |
| 10 | `php artisan schedule:list` | يعرض المهام بأوقاتها |
| 11 | `date -u` | يطابق UTC الحقيقي — **كل `BR-07` تعتمد عليه** |
| 12 | `UPDATE audit_logs …` و `DELETE FROM audit_logs …` | كلاهما يفشل |
| 13 | `storage/logs/*.log` بعد الاختبار | بلا `ERROR` ولا `CRITICAL` |

**البند 11 ليس شكليًّا:** ساعة خادم منحرفة تُفسد كل نوافذ الحضور بصمت.

---

## النشر التالي — لا الأول

**هذا هو الإجراء المستعمل فعلًا، وهو الذي يُتَّبع:**

```sh
cd "$APP"
git checkout -B main origin/main
git pull
php artisan optimize:clear && php artisan view:cache && php artisan route:cache
```

ثم **أعد المرحلة 3** إن تغيّر `composer.json`، و**المرحلة 5 من المرحلة 6** (`migrate --force`)
إن أضاف النشر ترحيلًا.

### `config:cache` — لا يُشغَّل على هذا الخادم

النسخة السابقة من هذا القسم كانت تقول
`config:cache && route:cache && view:cache && event:cache`. **الإجراء العامل يبني `view`
و`route` فقط**، ويترك الإعدادات تُقرأ حيّة بعد أن يمسحها `optimize:clear`.

في 9 سبتمبر 2026 شُغِّل `config:cache` على الإنتاج ضمن نشر، وأعقبه **500 على كل مسار**
بما فيها `/robots.txt`. لم يُثبَت أنه السبب المباشر — لكن الإجراء الذي يعمل يتجنّبه،
وهذا وحده يكفي: **لا تُضِف إلى إجراء ناجح خطوةً لا يحتاجها.**

إن وجدت ذاكرة إعدادات من محاولة سابقة:

```sh
rm -f "$APP/bootstrap/cache/config.php"
```

### `git checkout -B main origin/main` — لماذا هذه الصيغة بالذات

تُعيد بناء `main` المحلي من البعيد قسرًا. على خادم يُعدَّل عليه أحيانًا بيد،
`git pull` وحده يتعثّر بتباعد أو بتعديل محلي ويتوقف نصف الطريق. هذه الصيغة لا تتعثّر.

### 🔴 الأصول المبنية — خطوة إلزامية في كل نشر يمسّ CSS أو JS

**هذا هو الدرس الأغلى في هذا الملف. اقرأه قبل أي نشر.**

جذر الويب **لا يقرأ** `$APP/public`. الوسيط `AI/index.php` ينفّذ
`$app->usePublicPath(__DIR__)`، وتعليقه يقول بنصّه: «مانيفست Vite يُقرأ عبر
`public_path()`». فـ`public_path()` يساوي **مجلّد جذر الويب**، ولارافيل يقرأ
`$WEB/build/manifest.json` **وحده** ولا ينظر إلى `$APP/public/build` إطلاقًا.

**النتيجة:** `git pull` يحدّث PHP و Blade فتظهر فورًا، و**لا يحدّث الأصول أبدًا**.

| نوع التعديل | يظهر بالسحب وحده؟ |
|---|---|
| PHP · Blade · lang · config | ✅ فورًا |
| **CSS · JS** | ❌ **أبدًا** حتى تُنسخ الأصول |

في 8–10 سبتمبر 2026 بقيت الأصول متوقّفة عند `public-CND_IEaV.js` — الحزمة التي **بلا
حارس القسمة على صفر** — فظلّ `NaN%` معروضًا على محاكي الشهادة أيامًا، وشيفرة الإصلاح
مدموجة ومنشورة طوال الوقت. العطل لا يرمي خطأً ولا يظهر في أي سجل.

```sh
WEB="$HOME/domains/wareed.vip/public_html/AI"

cp -a "$WEB/build" "$HOME/build-bak-$(date +%Y%m%d-%H%M)"   # خارج جذر الويب

rsync -a --delete "$APP/public/build/" "$WEB/build/"
rsync -a          "$APP/public/fonts/" "$WEB/fonts/"
rsync -a          "$APP/public/brand/" "$WEB/brand/"

diff "$APP/public/build/manifest.json" "$WEB/build/manifest.json" && echo "متطابقان"
```

`--delete` على `build/` **إلزامية**: أسماء الملفات مبنيّة على الهاش وتتغيّر كل بناء،
وبدونها يتراكم القديم مع الجديد ويُخدَم خليط منهما. وعلى `fonts/` و`brand/` **لا
تستعملها** — إضافة فقط، لا حذف.

> **حلّ دائم لم يُطبَّق بعد:** استبدال `$WEB/build` برابط رمزي إلى
> `$APP/public/build` ينهي صنف العطل هذا نهائيًّا. لم يُجرَّب لأن
> `deploy/.htaccess:31` فيه `Options -Indexes -MultiViews` ولا يُصرّح بـ
> `FollowSymLinks`. **اختبره على اسم مؤقّت قبل الاستبدال**، فلو كانت الروابط
> معطّلة على الخادم لاختفت كل الأصول دفعةً واحدة.

### السجلّات

القناة الافتراضية `daily` واللاحقة اسم التطبيق، فالملف **`storage/logs/athar-YYYY-MM-DD.log`**
لا `laravel.log`. البحث عن `laravel.log` يعطي «الملف غير موجود» على خادم سليم تمامًا.

ثم **أعد المرحلة 7** إن تغيّر شيء في `resources/css` أو `resources/js`.

`errors.503` بنقطة لا `::` — الثانية تعني «قالب حزمة» ولا حزمة كهذه، فيتوقف `down` بخطأ.

---

## ما لا يعمل بعد النشر — معلوم ومسجَّل

| البند | الأثر الظاهر للمستخدم |
|---|---|
| `D-02` البريد | **لا رسالة واحدة تُرسَل**: التفعيل · استعادة كلمة المرور · التنبيهات. `MAIL_MAILER=array` يُهملها بلا أثر. |
| `D-28` الخطوط | `public/fonts/` **فارغ**. العربية تظهر بخط النظام (Segoe UI / Tahoma) لا بخط الهوية. مقروءة وصحيحة، لكنها ليست الهوية المعتمدة. |
| `D-11` التسجيل | مسار التسجيل غير مبنيّ — القرار مفتوح. |
| `D-17` الرفع | لا قائمة سماح لصيغ الملفات المرفوعة. |
| `D-08` سجل التدقيق | المرحلة 11 ترفع العتبة ولا تُغلق البند. |
| `D-37` كلمة المرور | **تُدوَّر فور نجاح النشر**، ثم يُحذف `.env.production` من جهازك. |
