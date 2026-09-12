<?php

/**
 * Transactional email copy: subject, preheader, heading, body and call to
 * action for every email in the notification matrix.
 * Templates are RTL Arabic HTML with externally hosted images — never inline
 * base64, which Gmail and Outlook strip (PRD §9.16).
 *
 * @see BR-29, BR-30, BR-36 · PRD §9.2.3, §9.3.3, §9.16, §9.16.1
 */

return [

    'common' => [
        'greeting' => 'أهلًا :name،',
        'greeting_neutral' => 'أهلًا بك،',
        'sign_off' => 'فريق مركز أثر للتدريب',
        'tagline_note' => 'برنامج :program — الدفعة :cohort',
        // A LABEL for the detail strip, not a sentence. The welcome letter
        // used to label the cohort name with nav.schedule — "the timetable".
        'cohort_label' => 'الدفعة',
        'view_in_platform' => 'افتح المنصة',
        'contact_line' => 'لأي استفسار راسلنا على :email',
        'why_receiving' => 'وصلتك هذه الرسالة لأنك مسجّل في :program.',
        'preferences_link' => 'يمكنك ضبط ما يصلك من رسائل من إعدادات حسابك.',
        'security_notice' => 'هذه رسالة أمنية تتعلق بحسابك وتصلك دائمًا.',
        'link_fallback' => 'إن لم يعمل الزر، انسخ هذا الرابط والصقه في متصفحك:',
        'rights' => 'جميع الحقوق محفوظة لمركز أثر للتدريب.',
        'time_note' => 'كل الأوقات بتوقيت الرياض.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Account lifecycle (PRD §9.2.3, §9.3.3)
    |--------------------------------------------------------------------------
    */

    /*
     * The invitation: an account created FOR someone, carrying the temporary
     * password they sign in with once.
     *
     * Article 7 governs every line: say what happened AND what to do. A reader
     * who did not expect this letter must be able to tell, from the letter
     * itself, what it is and whom to tell.
     */
    'invitation' => [
        // The subject names the programme and never the credential. A subject
        // line is shown on a lock screen and kept in mail-server logs.
        'subject' => 'حسابك في :program جاهز',
        'preheader' => 'بيانات دخولك إلى :program وخطوة واحدة تفصلك عن البداية.',
        'heading' => 'أهلًا بك في مركز أثر',
        'body' => 'أُنشئ لك حساب في :program ضمن :cohort، وأصبحت منضمًّا إليها رسميًّا. ينتظرك في المنصة جدولك ومهامك ومواد البرنامج وبطاقتك الرقمية.',
        'program_label' => 'البرنامج',
        'credentials_title' => 'بيانات الدخول',
        'email_label' => 'البريد الإلكتروني',
        'password_label' => 'كلمة المرور المؤقتة',
        'temporary_note' => 'هذه كلمة مرور مؤقتة لمرّة واحدة، وستُطلب منك إنشاء كلمة مرورك الخاصة فور دخولك الأول. وتنتهي صلاحيتها في :date.',
        'cta' => 'ادخل إلى منصة أثر',
        'next_steps' => 'إن انتهت صلاحية كلمة المرور قبل أن تستخدمها، اختر «نسيت كلمة المرور» في صفحة الدخول وسيصلك رابط جديد فورًا.',
        'not_you' => 'وصلتك هذه الرسالة لأن إدارة البرنامج أنشأت لك حسابًا بهذا العنوان. إن لم تكن تتوقّعها، لا تستعمل كلمة المرور وأبلِغنا على :email.',
    ],
    'verify' => [
        'subject' => 'فعّل حسابك في منصة أثر',
        'preheader' => 'رابط التفعيل صالح 24 ساعة ولمرة واحدة.',
        'heading' => 'خطوة واحدة ويكتمل تسجيلك',
        'body' => 'شكرًا لتسجيلك في :program. اضغط الزر أدناه لتفعيل حسابك، وسننشئ لك بطاقتك الرقمية ونبني خطوات رحلتك مباشرة.',
        'cta' => 'فعّل حسابي',
        'expiry_note' => 'الرابط صالح 24 ساعة من وقت إرساله، ويعمل مرة واحدة فقط.',
        'ignore_note' => 'إن لم تطلب هذا التسجيل فتجاهل الرسالة ولن يُنشأ أي حساب.',
    ],

    'welcome' => [
        'subject' => 'أهلًا بك في :program',
        'preheader' => 'هذه خلاصة برنامجك وموعد لقائك التعريفي.',
        'heading' => 'أهلًا بك. مقعدك محجوز',
        'body' => 'فُعّل حسابك والتحقت بالدفعة :cohort. تجد في لوحتك جدول الجلسات، وبطاقتك الرقمية، وخطوات رحلتك العشر.',
        'intro_session' => 'اللقاء التعريفي: :datetime',
        'cta' => 'افتح لوحتي',
    ],

    'password_reset' => [
        'subject' => 'تعيين كلمة مرور جديدة',
        'preheader' => 'الرابط صالح 30 دقيقة ولمرة واحدة.',
        'heading' => 'طلب تعيين كلمة مرور جديدة',
        'body' => 'وصلنا طلب لتعيين كلمة مرور جديدة لحسابك. اضغط الزر أدناه لاختيار كلمة مرور جديدة.',
        'cta' => 'عيّن كلمة مرور جديدة',
        'expiry_note' => 'الرابط صالح 30 دقيقة، ويعمل مرة واحدة فقط.',
        'ignore_note' => 'إن لم تطلب هذا فتجاهل الرسالة، ولن يتغير شيء في حسابك.',
    ],

    'password_changed' => [
        'subject' => 'تم تغيير كلمة مرور حسابك',
        'preheader' => 'إن لم يكن هذا أنت، راسلنا فورًا.',
        'heading' => 'تم تغيير كلمة المرور',
        'body' => 'غُيّرت كلمة مرور حسابك في :datetime، وأُنهيت كل الجلسات النشطة على أجهزتك.',
        'not_you' => 'إن لم يكن هذا أنت، راسلنا فورًا على :email.',
    ],

    'new_device_login' => [
        'subject' => 'تسجيل دخول من جهاز جديد',
        'preheader' => 'راجع تفاصيل الدخول للتأكد أنه أنت.',
        'heading' => 'دخول جديد إلى حسابك',
        'body' => 'سُجّل دخول إلى حسابك في :datetime من عنوان :ip.',
        'not_you' => 'إن لم يكن هذا أنت، غيّر كلمة مرورك فورًا وراسلنا على :email.',
        'cta' => 'غيّر كلمة المرور',
    ],

    'email_change' => [
        'subject' => 'أكّد بريدك الإلكتروني الجديد',
        'preheader' => 'لن يتغير بريدك قبل فتح هذا الرابط.',
        'heading' => 'تأكيد البريد الجديد',
        'body' => 'طلبت تغيير بريد حسابك إلى هذا العنوان. اضغط الزر لتأكيد التغيير.',
        'cta' => 'أكّد البريد الجديد',
        'expiry_note' => 'الرابط صالح 24 ساعة ويعمل مرة واحدة.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Enrollment decisions
    |--------------------------------------------------------------------------
    */

    'enrollment_approved' => [
        'subject' => 'اعتُمد التحاقك بالدفعة :cohort',
        'preheader' => 'مقعدك محجوز. هذه خطواتك التالية.',
        'heading' => 'اعتُمد طلبك',
        'body' => 'اعتمدت الإدارة التحاقك بالدفعة :cohort. افتح لوحتك للاطلاع على الجدول وبطاقتك الرقمية.',
        'cta' => 'افتح لوحتي',
    ],

    'enrollment_rejected' => [
        'subject' => 'بخصوص طلب التحاقك بـ :program',
        'preheader' => 'تفاصيل القرار وخياراتك القادمة.',
        'heading' => 'لم يُعتمد طلب الالتحاق',
        'body' => 'راجعنا طلبك ولم نتمكن من اعتماده لهذه الدفعة. السبب: :reason',
        'next_steps' => 'يسعدنا استقبال طلبك في الدفعة القادمة، ويمكنك مراسلتنا لأي استفسار على :email.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Programme events (PRD §9.16.1)
    |--------------------------------------------------------------------------
    */

    'session_reminder' => [
        'subject' => 'تذكير: جلسة :session :when',
        'preheader' => 'تفاصيل الجلسة ورابط الدخول في لوحتك.',
        'heading' => 'جلستك القادمة',
        'body' => 'تبدأ جلسة «:session» في :datetime بتوقيت الرياض مع :trainer.',
        'cta' => 'افتح تبويب المحاضرات',
        'when_24h' => 'غدًا',
        'when_1h' => 'بعد ساعة',
        'trainer_fallback' => 'مدربك',
    ],

    'session_changed' => [
        'subject' => 'تغيّر موعد جلسة :session',
        'preheader' => 'راجع الموعد الجديد في الجدول.',
        'heading' => 'تحديث على جدول الدفعة',
        'body' => 'تغيّر موعد جلسة «:session»، وموعدها الجديد :datetime بتوقيت الرياض.',
        'cta' => 'افتح الجدول',
    ],

    'announcement_published' => [
        'subject' => 'إعلان جديد من إدارة البرنامج',
        'preheader' => ':excerpt',
        'heading' => 'إعلان جديد لدفعتك',
        'body' => 'نُشر إعلان جديد في قناة إعلانات الدفعة: «:excerpt»',
        'cta' => 'افتح الإعلان',
    ],

    'assignment_published' => [
        'subject' => 'مهمة جديدة: :assignment',
        'preheader' => 'الموعد النهائي :datetime.',
        'heading' => 'نُشرت مهمة جديدة',
        'body' => 'نشر مدربك مهمة «:assignment» بدرجة قصوى :max. الموعد النهائي :datetime.',
        'cta' => 'افتح المهمة',
    ],

    'assignment_due_reminder' => [
        'subject' => 'يقترب موعد تسليم :assignment',
        'preheader' => 'تبقّى :countdown على إغلاق التسليم.',
        'heading' => 'تذكير بموعد التسليم',
        'body' => 'لم نستلم تسليمك لمهمة «:assignment» بعد، وتبقّى :countdown على الموعد النهائي.',
        'cta' => 'سلّم المهمة',
    ],

    'grade_recorded' => [
        'subject' => 'رُصدت درجتك في :item',
        'preheader' => 'الدرجة وملاحظة المدرب في لوحتك.',
        'heading' => 'وصلت درجتك',
        'body' => 'رصد مدربك :score من :max في «:item»، وأرفق ملاحظته كاملة.',
        'cta' => 'اقرأ الملاحظة',
    ],

    'grade_revised' => [
        'subject' => 'عُدّلت درجتك في :item',
        'preheader' => 'الدرجة الجديدة وسبب التعديل.',
        'heading' => 'تعديل على درجتك',
        'body' => 'عُدّلت درجتك في «:item» لتصبح :score من :max. سبب التعديل: :reason',
        'cta' => 'اعرض التقييم',
    ],

    'final_project_unlocked' => [
        'subject' => 'فُتح المشروع الختامي',
        'preheader' => 'دليل المشروع ومعايير التقييم متاحة الآن.',
        'heading' => 'المشروع الختامي متاح الآن',
        'body' => 'فُتح تبويب المشروع الختامي في لوحتك. اطّلع على الدليل ومعايير التقييم، والموعد النهائي :datetime.',
        'cta' => 'افتح المشروع الختامي',
    ],

    'announcement' => [
        'subject' => 'إعلان جديد في :program',
        'preheader' => ':excerpt',
        'heading' => 'إعلان من إدارة البرنامج',
        'cta' => 'اقرأ الإعلان',
    ],

    'message_received' => [
        'subject' => 'رسالة جديدة من :name',
        'preheader' => ':excerpt',
        'heading' => 'وصلتك رسالة جديدة',
        'body' => 'أرسل :name رسالة في :thread.',
        'cta' => 'افتح المحادثة',
    ],

    'attendance_low' => [
        'subject' => 'نسبة حضورك انخفضت عن الحد المطلوب',
        'preheader' => 'ما زال أمامك وقت لتداركها.',
        'heading' => 'تنبيه بخصوص نسبة حضورك',
        'body' => 'نسبة حضورك الآن :current% والحد المطلوب للشهادة :required%. حضور الجلسات القادمة يرفع نسبتك.',
        'cta' => 'اعرض سجل حضوري',
    ],

    'attendance_low_final' => [
        'subject' => 'انتهت نسبة حضورك دون الحد المطلوب',
        'preheader' => 'لم تبقَ جلسات في هذا البرنامج.',
        'heading' => 'تنبيه بخصوص نسبة حضورك',
        'body' => 'نسبة حضورك :current% والحد المطلوب للشهادة :required%. لم تبقَ جلسات في هذا البرنامج. إن كان غيابٌ ما بعذر فراجع به مدرّبك.',
        'cta' => 'اعرض سجل حضوري',
    ],

    'session_cancelled' => [
        'subject' => 'أُلغيت جلسة :session',
        'preheader' => 'السبب في الداخل.',
        'heading' => 'أُلغيت جلسة في دفعتك',
        'body' => 'أُلغيت جلسة «:session». السبب: :reason. أي موعد بديل يظهر في جدولك.',
        'cta' => 'افتح الجدول',
    ],

    'certificate_issued' => [
        'subject' => 'صدرت شهادتك من مركز أثر',
        'preheader' => 'حمّل شهادتك وشارك رابط التحقق.',
        'heading' => 'مبارك لك إتمام البرنامج',
        'body' => 'استوفيت شروط الحضور والدرجة معًا، وصدرت شهادتك برقم تسلسلي :serial.',
        'cta' => 'افتح شهادتي',
    ],

    'waitlist_confirmation' => [
        'subject' => 'سنراسلك فور فتح الدفعة القادمة',
        'preheader' => 'سجّلنا بريدك في قائمة الانتظار.',
        'heading' => 'سجّلنا بريدك',
        'body' => 'أُغلق التسجيل لهذه الدفعة. سنراسلك أول ما تُفتح الدفعة القادمة قبل الإعلان العام.',
    ],

];
