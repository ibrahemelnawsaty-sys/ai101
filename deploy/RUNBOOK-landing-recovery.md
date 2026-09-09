# إنقاذ صفحة الهبوط — إجراء تشغيلي

> **الغرض:** إسقاط الأعطاب الحيّة على `ai.wareed.vip` ثم نشر عمل الدفعات الأربع.
> هذا الملف يُنفَّذ على الخادم. كل أمر فيه قابل للتراجع، وترتيبه مقصود.
>
> **اقرأ القسم صفر كاملًا قبل تنفيذ أي أمر.**

---

## صفر · قبل أي شيء — نقطة الرجوع

لا يوجد في المستودع اليوم وسم واحد، ولا إجراء تراجع موثَّق، و`deploy/README.md`
يصف مجلّد الإصدارات بأنه **اختياري**. أي أن التراجع اليوم ارتجال. هذه الخطوات
تُنشئ نقطة الرجوع أولًا.

```sh
# 1. وسم ما هو منشور الآن، قبل لمسه.
git tag -a deploy-before-landing-recovery -m "الإنتاج قبل إنقاذ صفحة الهبوط"
git push origin deploy-before-landing-recovery

# 2. نسخة احتياطية لقاعدة البيانات — إلزامية، لا اختيارية.
#    صفحة الهبوط تقرأ: cohorts · programs · weeks · sessions · landing_settings
mysqldump -u "$DB_USER" -p "$DB_NAME" \
  | gzip > "$HOME/backups/athar-$(date +%Y%m%d-%H%M%S).sql.gz"

# 3. أرشيف الإصدار الحالي، ليكون التراجع فكّ ضغط لا إعادة بناء.
tar -czf "$HOME/releases/athar-$(date +%Y%m%d-%H%M%S).tar.gz" -C "$HOME/athar-app" .
```

**احتفظ بآخر ثلاثة أرشيفات على الأقل.** ولا تبدأ القسم الأول قبل أن تتأكد أن
ملفّي النسخة والأرشيف موجودان فعلًا وحجمهما غير صفر.

---

## أولًا · أخطر عطب — وإصلاحه بلا سطر كود

### ما يحدث الآن

الخادم يقدّم PHP و Blade حديثين، وأصول Vite **متأخّرة جيلين**:

| | الإنتاج يقدّم | المستودع |
|---|---|---|
| `public.js` | `public-CND_IEaV.js` | أحدث |
| `public.css` | `public-DaygCSXE.css` | أحدث |
| `app.css` | `app-BWEKZ8DV.css` | أحدث |

الأثر المؤكَّد: حزمة الإنتاج **بلا حارس القسمة على صفر** — `grep "pct"` عليها
يُرجع صفرًا، وفيها `Math.round(v/l*100)` خامة. ومع `data-sessions="0"` يصير
`0/0` ⟵ **`NaN%`** في بوّابة الحضور بمحاكي الشهادة، وجملة فيها نائب خامّ
`:count`. الإصلاح مدموج منذ `f7ebba0` ولم يُنشر قط.

`.gitignore` في هذا المستودع وصف هذا العطل حرفيًّا **قبل وقوعه**:
«الصفحة تُصيَّر، وCSS متأخّر إصدارًا، ولا شيء يُبلغ عن خطأ».

### الإصلاح

```sh
cd "$HOME/athar-app"
git fetch --all
git checkout fix/gate-baseline     # أو الفرع بعد دمجه في main
git pull --ff-only

# public/build ملتزَم في المستودع عمدًا (لا Node على الاستضافة المشتركة)،
# فالسحب وحده يجلب الأصول المبنيّة.
ls -la public/build/assets/

# النسخ إلى جذر الويب. --delete ضرورية: بدونها تبقى الأصول القديمة
# جنبًا إلى جنب مع الجديدة، والصفحة تخدم خليطًا منهما.
rsync -a --delete public/build/ "$HOME/public_html/build/"
rsync -a          public/fonts/ "$HOME/public_html/fonts/"
rsync -a          public/brand/ "$HOME/public_html/brand/"
```

### التحقق — لا تكمل قبل أن يمرّ

```sh
# 1. المضيف يقدّم الأصول الجديدة، لا القديمة.
curl -s https://ai.wareed.vip/ | grep -oE '/build/assets/[a-z]+-[A-Za-z0-9_-]+\.(js|css)'
#    يجب ألّا يظهر public-CND_IEaV.js ولا public-DaygCSXE.css ولا app-BWEKZ8DV.css

# 2. حارس القسمة موجود في الحزمة المخدومة.
curl -s "https://ai.wareed.vip/$(curl -s https://ai.wareed.vip/ \
  | grep -oE '/build/assets/public-[A-Za-z0-9_-]+\.js' | head -1)" | grep -c 'e>0?Math.round'
#    يجب أن يكون 1 لا 0
```

---

## ثانيًا · بيانات الإنتاج — وهي أصل أربعة أعطاب

### ما يحدث الآن

قاعدة الإنتاج تحمل **بذرة عرض توضيحي**، لا محتوى أدخله مدير. الدليل: خمسة حقول
مطابقة حرفيًّا لملف البذر، وتواريخ الأسابيع تساوي «أربعة أسابيع قبل الأسبوع
الجاري» وهي هندسة `SeedContent` نفسها.

أثرها المرئي للزائر:

| ما يظهر | السبب |
|---|---|
| أسابيع 9 أغسطس ← 5 سبتمبر 2026 (منقضية) | البذرة تضع التدريب في الماضي عمدًا |
| «بلا جلسات مباشرة» أربع مرات، والأسئلة تقول ثلاث جلسات أسبوعيًا | جدول `sessions` فارغ |
| «0 من 60 مقعدًا محجوزة» | `seats_taken = 0` |
| التسجيل مغلق | `status = running` و`registration_closes_at` في الماضي |

### الإصلاح

> ⛔️ **لا تشغّل `migrate:fresh` ولا `db:seed` على الإنتاج.** الأولى تحذف كل شيء،
> والثانية تُعيد حقن البيانات التجريبية نفسها التي تريد التخلص منها.

الطريق الصحيح من لوحة الإدارة، بلا أمر واحد على قاعدة البيانات:

1. **أنشئ دفعة جديدة** بحالة `open`، وتواريخ مستقبلية، و`registration_closes_at`
   في المستقبل، وسعة صحيحة.
2. **أدخل أسابيعها وجلساتها.** هذا وحده يُسقط «بلا جلسات مباشرة»، ويُملأ
   `sessions_total` الذي كان صفرًا وسبّب `NaN`.
3. **أدخل مهام الدفعة** — عددها يظهر الآن في المحاكي بعد إصلاح `BR-11`.
4. **أنشئ حسابَي المدرّبَين** (ابراهيم صابر · احمد سامي) وسجّلهما في الدفعة بدور
   `trainer`، ثم أكمل: المسمّى · النبذة · الصورة. انظر `D-53`.
5. **أرشف الدفعة القديمة** إلى `completed` حتى لا تتنافس على الظهور.

بعد كل خطوة:

```sh
php artisan config:clear && php artisan config:cache
php artisan view:clear   && php artisan route:cache
```

---

## ثالثًا · ترحيل قاعدة البيانات

الدفعة الأخيرة تضيف عمودًا واحدًا `profiles.job_title` (نصّي، يقبل الفراغ).
**لا يحذف شيئًا ولا يغيّر عمودًا قائمًا**، و`down()` مُختبَر ويعمل.

```sh
php artisan migrate --force
php artisan migrate:status | tail -5
```

للتراجع عن هذا الترحيل وحده:

```sh
php artisan migrate:rollback --step=1 --force
```

---

## رابعًا · التحقق النهائي — بعينك لا بالافتراض

```sh
# لا NaN ولا نائب خامّ على الصفحة
curl -s https://ai.wareed.vip/ | grep -c 'NaN'          # ‏0
curl -s https://ai.wareed.vip/ | grep -cE ':count|:sessions|:min'   # ‏0

# وسم الوصف لم يعد فارغًا
curl -s https://ai.wareed.vip/ | grep -oE '<meta name="description" content="[^"]*"'

# ملفّا الفهرسة يستجيبان
curl -s -o /dev/null -w "robots %{http_code}\n"  https://ai.wareed.vip/robots.txt
curl -s -o /dev/null -w "sitemap %{http_code}\n" https://ai.wareed.vip/sitemap.xml

# لا أيقونة فارغة
curl -s https://ai.wareed.vip/ | grep -c 'href="#i-"'   # ‏0
```

ثم **افتح الصفحة بعينك** على 360 و768 و1280 بكسل، وتحقّق من: العدّاد يتحرّك ·
الخط الزمني يعرض تواريخ · قسم المدرّبين يظهر · بطاقة الشهادة لا تنقلب إلى فراغ.

---

## خامسًا · التراجع

**حدّ زمني: إن لم تعُد `/` سليمة خلال عشر دقائق، ارجع. لا تُصلح على الإنتاج.**

```sh
cd "$HOME"
tar -xzf "$HOME/releases/<الأرشيف السابق>.tar.gz" -C "$HOME/athar-app"

cd "$HOME/athar-app"
rsync -a --delete public/build/ "$HOME/public_html/build/"
rsync -a          public/fonts/ "$HOME/public_html/fonts/"
rsync -a          public/brand/ "$HOME/public_html/brand/"

php artisan config:clear && php artisan config:cache
php artisan view:clear   && php artisan route:cache
```

> **`rsync --delete` هي الخطوة الحرجة في التراجع.** بدون إعادة تشغيلها تبقى أصول
> البناء الجديدة في `public_html/build` بينما رجع كود PHP — وهي حالة **أسوأ من
> العطل نفسه**، لأنها بالضبط عطب «النشر المشطور» الذي جاء هذا الملف ليصلحه.

لاسترجاع قاعدة البيانات إن لزم:

```sh
gunzip < "$HOME/backups/<النسخة>.sql.gz" | mysql -u "$DB_USER" -p "$DB_NAME"
```

---

## ما لا يعالجه هذا الإجراء

بنود موقوفة على قرار من المركز، لا على تنفيذ:

- **`D-52`** موعد الشهادة في الخط الزمني — لا عمود يحمله.
- **`D-53`** بيانات المدرّبَين الناقصة: بريد · جوال · مسمّى · نبذة · صورة.
- **شهادة المؤسسة العامة للتدريب التقني والمهني** — الصفحة تصف اليوم «شهادتين
  معتمدتين» وإحداهما بطاقة رقمية، وهي ليست اعتمادًا.
- **عدد الساعات التدريبية** — العمود `programs.hours` موجود وفارغ.
- **عدد المتدربين السابقين** — لا عمود ولا رقم موثّق.
- **الأسئلة الشائعة** — ستة، والحدّ الأدنى ثمانية. تُضاف من لوحة الإدارة.
