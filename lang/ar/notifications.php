<?php

/**
 * The in-platform notification centre and the per-channel preference table.
 * One title and one body for every event in the matrix of PRD §9.16.1; the
 * email wording for the same events lives in emails.php.
 *
 * @see PRD §9.16, §9.16.1, §9.4.1
 */

return [

    'subtitle' => 'كل ما يخص جلساتك ومهامك ودرجاتك في مكان واحد',

    'mark_all_read' => 'علّم الكل كمقروء',
    'unread' => 'غير مقروء',
    'all_read' => 'عُلّمت كل إشعاراتك كمقروءة.',
    'filter_type' => 'نوع الإشعار',
    'filter_state' => 'حالة القراءة',

    /*
    |--------------------------------------------------------------------------
    | Preferences table (PRD §9.4.1)
    |--------------------------------------------------------------------------
    */

    'preferences_title' => 'إعدادات الإشعارات',
    'event' => 'الحدث',
    'channel_platform' => 'داخل المنصة',
    'channel_email' => 'البريد الإلكتروني',
    'toggle_aria' => ':event عبر :channel',
    'preferences_empty_title' => 'لا إعدادات لعرضها',
    'preferences_empty_body' => 'تظهر خيارات الإشعارات بعد التحاقك بدفعة. الإشعارات الأمنية تصلك دائمًا لحماية حسابك.',

    /*
    |--------------------------------------------------------------------------
    | Trainer-facing attendance notices
    |--------------------------------------------------------------------------
    */

    'attendance' => [
        'incomplete' => [
            'title' => 'حضور غير مكتمل لـ :name',
            'body' => 'سجّل حضوره في جلسة :session ولم يسجّل انصرافه حتى إغلاق نافذة الانصراف.',
        ],

        /*
        | BR-09: the reconciliation job converts a whole session at once, so it
        | sends the trainer one summary rather than a notification per row.
        */

        'incomplete_summary' => [
            'title' => 'حضور غير مكتمل في جلسة :session',
            'body' => 'سجّل :count من المتدربين حضورهم ولم يسجّلوا انصرافهم حتى إغلاق نافذة الانصراف. افتح سجل الجلسة لمراجعتهم.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification matrix (PRD §9.16.1)
    |--------------------------------------------------------------------------
    */

    'types' => [
        'session_reminder' => [
            'label' => 'تذكير بجلسة',
            'title' => 'جلسة :session تبدأ قريبًا',
            'body' => 'تبدأ الجلسة :datetime بتوقيت الرياض. ادخل من تبويب المحاضرات المباشرة.',
        ],
        'session_started' => [
            'label' => 'بدء الجلسة',
            'title' => 'بدأت جلسة :session',
            'body' => 'الجلسة جارية الآن. سجّل حضورك وادخل المحاضرة.',
        ],
        'session_changed' => [
            'label' => 'إلغاء أو تأجيل جلسة',
            'title' => 'تغيّر موعد جلسة :session',
            'body' => 'راجع الجدول لمعرفة الموعد الجديد وسبب التغيير.',
        ],
        'session_cancelled' => [
            'label' => 'إلغاء جلسة',
            'title' => 'أُلغيت جلسة :session',
            'body' => 'سبب الإلغاء: :reason. يصلك إشعار بالموعد البديل فور تحديده.',
        ],
        'assignment_published' => [
            'label' => 'نشر مهمة جديدة',
            'title' => 'مهمة جديدة: :assignment',
            'body' => 'الموعد النهائي :datetime. افتح المهمة لمعرفة المتطلبات.',
        ],
        'assignment_due_reminder' => [
            'label' => 'تذكير بموعد مهمة',
            'title' => 'يقترب موعد تسليم :assignment',
            'body' => 'تبقّى :countdown على إغلاق التسليم. سلّم قبل الموعد لتُحتسب في الموعد.',
        ],
        'submission_received' => [
            'label' => 'تأكيد استلام تسليم',
            'title' => 'استلمنا تسليمك لمهمة :assignment',
            'body' => 'سجّلنا وقت التسليم وأشعرنا مدربك. تصلك الدرجة والملاحظة فور التقييم.',
        ],
        'submission_new' => [
            'label' => 'وصول تسليم جديد',
            'title' => 'تسليم جديد من :name',
            'body' => 'وصل تسليم لمهمة :assignment وهو بانتظار تقييمك.',
        ],
        'grade_recorded' => [
            'label' => 'رصد درجة',
            'title' => 'رُصدت درجتك في :item',
            'body' => 'حصلت على :score من :max. افتح تبويب التقييم لقراءة ملاحظة مدربك كاملة.',
        ],
        'grade_revised' => [
            'label' => 'تعديل درجة',
            'title' => 'عُدّلت درجتك في :item',
            'body' => 'الدرجة الآن :score من :max. سبب التعديل: :reason',
        ],
        'final_project_unlocked' => [
            'label' => 'تفعيل المشروع الختامي',
            'title' => 'فُتح المشروع الختامي',
            'body' => 'اطّلع على دليل المشروع ومعايير التقييم. الموعد النهائي :datetime.',
        ],
        'resource_added' => [
            'label' => 'مورد جديد في الحقيبة',
            'title' => 'مورد جديد: :resource',
            'body' => 'أُضيف مورد جديد إلى الحقيبة التدريبية.',
        ],
        'announcement_published' => [
            'label' => 'إعلان جديد',
            'title' => 'إعلان جديد من إدارة البرنامج',
            'body' => ':excerpt',
        ],
        'message_received' => [
            'label' => 'رسالة جديدة',
            'title' => 'رسالة جديدة من :name',
            'body' => ':excerpt',
        ],
        'attendance_low' => [
            'label' => 'انخفاض نسبة الحضور',
            'title' => 'نسبة حضورك انخفضت',
            'body' => 'نسبتك الآن :current% والحد المطلوب للشهادة :required%. احرص على حضور الجلسات القادمة.',
        ],
        'attendance_incomplete' => [
            'label' => 'حضور غير مكتمل',
            'title' => 'حضور غير مكتمل لـ :name',
            'body' => 'سجّل حضوره في جلسة :session ولم يسجّل انصرافه حتى إغلاق النافذة.',
        ],
        'certificate_issued' => [
            'label' => 'إصدار الشهادة',
            'title' => 'صدرت شهادتك',
            'body' => 'مبارك لك إتمام البرنامج. حمّل شهادتك وشارك رابط التحقق من تبويب الشهادة.',
        ],
        'enrollment_approved' => [
            'label' => 'اعتماد الالتحاق',
            'title' => 'اعتُمد التحاقك بالدفعة',
            'body' => 'أهلًا بك. افتح لوحتك للاطلاع على الجدول وخطوات رحلتك.',
        ],
        'enrollment_rejected' => [
            'label' => 'رفض طلب الالتحاق',
            'title' => 'لم يُعتمد طلب التحاقك',
            'body' => 'السبب: :reason. يمكنك مراسلتنا لأي استفسار.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Four mandatory states (art. 17)
    |--------------------------------------------------------------------------
    */

    'empty_title' => 'لا توجد إشعارات جديدة',
    'empty_body' => 'تصلك هنا تنبيهات الجلسات والمهام والدرجات وكل ما يخص برنامجك.',
    'no_match_title' => 'لا إشعارات تطابق التصفية',
    'no_match_body' => 'غيّر النوع أو حالة القراءة لعرض بقية إشعاراتك.',
    'error_title' => 'تعذّر عرض الإشعارات',
    'error_body' => 'حدث خطأ أثناء جلب إشعاراتك. أعد المحاولة بعد قليل — لم يُفقد أي إشعار.',

];
