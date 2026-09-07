<?php

/**
 * Shared platform vocabulary: calendar names read by the date formatter,
 * generic verbs used by more than one screen, and the brand labels.
 *
 * App\Services\Time\RiyadhFormatter reads `days`, `months`, `meridiem`,
 * `date_time` and `time_range` from this file and from nowhere else.
 * Screen copy belongs to that screen's own file (dashboard.php, schedule.php …).
 *
 * @see PRD §11, §11.1 · CONSTITUTION art. 6, art. 11, art. 15
 */

return [

    'brand_name' => 'مركز أثر للتدريب',
    'tagline' => 'من هنا يبدأ الأثر',

    'brand' => [
        'wordmark_alt' => 'شعار مركز أثر للتدريب',
        'mark_alt' => 'رمز مركز أثر',
    ],

    /*
    |--------------------------------------------------------------------------
    | Calendar — read by RiyadhFormatter (Latin numerals, Arabic wording)
    |--------------------------------------------------------------------------
    | «الأحد 12 أكتوبر 2026» · «5:58 مساءً»
    */

    'days' => [
        'sunday' => 'الأحد',
        'monday' => 'الاثنين',
        'tuesday' => 'الثلاثاء',
        'wednesday' => 'الأربعاء',
        'thursday' => 'الخميس',
        'friday' => 'الجمعة',
        'saturday' => 'السبت',
    ],

    'months' => [
        'january' => 'يناير',
        'february' => 'فبراير',
        'march' => 'مارس',
        'april' => 'أبريل',
        'may' => 'مايو',
        'june' => 'يونيو',
        'july' => 'يوليو',
        'august' => 'أغسطس',
        'september' => 'سبتمبر',
        'october' => 'أكتوبر',
        'november' => 'نوفمبر',
        'december' => 'ديسمبر',
    ],

    'meridiem' => [
        'am' => 'صباحًا',
        'pm' => 'مساءً',
    ],

    'date_time' => ':date · :time',
    'time_range' => ':from — :to',

    'riyadh_time_hint' => 'كل الأوقات بتوقيت الرياض، وساعة الخادم هي المرجع.',

    /*
    |--------------------------------------------------------------------------
    | Relative time — used by App\Support\Dates::relative()
    |--------------------------------------------------------------------------
    | Arabic counting: singular · dual · paucal · plural (art. 15).
    */

    'relative' => [
        'now' => 'الآن',
        'minutes' => '{1} قبل دقيقة|{2} قبل دقيقتين|[3,10] قبل :count دقائق|[11,*] قبل :count دقيقة',
        'hours' => '{1} قبل ساعة|{2} قبل ساعتين|[3,10] قبل :count ساعات|[11,*] قبل :count ساعة',
        'days' => '{1} أمس|{2} قبل يومين|[3,10] قبل :count أيام|[11,*] قبل :count يومًا',
        'weeks' => '{1} قبل أسبوع|{2} قبل أسبوعين|[3,10] قبل :count أسابيع|[11,*] قبل :count أسبوعًا',
        'months' => '{1} قبل شهر|{2} قبل شهرين|[3,10] قبل :count أشهر|[11,*] قبل :count شهرًا',
    ],

    /*
    |--------------------------------------------------------------------------
    | Counted units — the number is printed beside the unit by the view
    |--------------------------------------------------------------------------
    */

    'minutes' => '{0} دقائق|{1} دقيقة|{2} دقيقتان|[3,10] دقائق|[11,*] دقيقة',
    'hours' => '{0} ساعات|{1} ساعة|{2} ساعتان|[3,10] ساعات|[11,*] ساعة',

    /*
    |--------------------------------------------------------------------------
    | Generic verbs and labels — every button is a verb (art. 15)
    |--------------------------------------------------------------------------
    */

    'save_changes' => 'احفظ التغييرات',
    'apply_filters' => 'طبّق التصفية',
    'clear_filters' => 'أزل التصفية',
    'retry' => 'أعد المحاولة',
    'back' => 'ارجع',
    'cancel' => 'تراجع',
    'edit' => 'عدّل',
    'open' => 'افتح',
    'show' => 'اعرض',
    'details' => 'التفاصيل',
    'view_all' => 'اعرض الكل',
    'all' => 'الكل',
    'none' => 'لا شيء',
    'select' => 'اختر',
    'copy' => 'انسخ الرابط',
    'copied' => 'نُسخ الرابط',
    'share' => 'شارك',
    'download' => 'حمّل',
    'export_excel' => 'صدّر Excel',
    // `actions` is an array: the layouts read app.actions.skip_to_content and
    // app.actions.close, while a table's hidden action column reads
    // app.actions.label. The flat keys app.skip_to_content and app.close are
    // kept below so nothing that already reads them breaks.
    'actions' => [
        'label' => 'الإجراءات',
        'skip_to_content' => 'تخطَّ إلى المحتوى',
        'close' => 'أغلق',
    ],
    'status' => 'الحالة',
    'not_assigned' => 'لم يُسنَد بعد',
    'optional' => 'اختياري',
    'skip_to_content' => 'تخطَّ إلى المحتوى',

    /*
    |--------------------------------------------------------------------------
    | Accessible names for the app shell (art. 18)
    |--------------------------------------------------------------------------
    */

    'accessibility' => [
        'menu' => 'القائمة',
        'open_menu' => 'افتح القائمة',
        'close_menu' => 'أغلق القائمة',
        'user_menu' => 'قائمة الحساب',
        'notifications_bell' => 'الإشعارات',
        'progress' => 'مؤشر التقدم',
        'loading_region' => 'محتوى قيد التحميل',
        'status_region' => 'رسائل الحالة',
    ],
    'close' => 'أغلق',
    'delete' => 'احذف',
    'archive' => 'أرشِف',
    'view_details' => 'اعرض التفاصيل',
    'search_placeholder' => 'ابحث…',
    'no_search_results_title' => 'لا نتائج تطابق بحثك',
    'no_search_results' => 'جرّب كلمة أخرى أو أزل التصفية لعرض كل العناصر.',

    'common' => [
        'search_placeholder' => 'ابحث…',
    ],

    'ratio' => [
        'of' => ':count من :total',
        'percent' => ':value%',
        'score_of' => ':score من :max',
    ],

    // File sizes. The numeral stays Latin (art. 15) and the unit is a word, so
    // no screen ever prints a hard-coded "MB".
    'size' => [
        'kilobytes' => ':value كيلوبايت',
        'megabytes' => ':value ميجابايت',
    ],

    /*
    |--------------------------------------------------------------------------
    | Counted nouns used by more than one screen (art. 15)
    |--------------------------------------------------------------------------
    */

    'counts' => [
        'sessions' => '{0} لا جلسات|{1} جلسة واحدة|{2} جلستان|[3,10] :count جلسات|[11,*] :count جلسة',
        'submissions' => '{0} لا تسليمات|{1} تسليم واحد|{2} تسليمان|[3,10] :count تسليمات|[11,*] :count تسليمًا',
        'participants' => '{0} لا متدربين|{1} متدرب واحد|{2} متدربان|[3,10] :count متدربين|[11,*] :count متدربًا',
        'seats' => '{0} لم يتبقَّ مقعد|{1} مقعد واحد|{2} مقعدان|[3,10] :count مقاعد|[11,*] :count مقعدًا',
    ],

    /*
    |--------------------------------------------------------------------------
    | Countdown and date-range labels
    |--------------------------------------------------------------------------
    */

    'time' => [
        'now' => 'الآن',
        'from' => 'من',
        'to' => 'إلى',
        'days_label' => 'يوم',
        'hours_label' => 'ساعة',
        'minutes_label' => 'دقيقة',
        'seconds_label' => 'ثانية',
        'server_time_note' => 'العدّاد يعمل بساعة الخادم بتوقيت الرياض، لا بساعة جهازك.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback screen states (art. 17) — a screen overrides these with its own
    |--------------------------------------------------------------------------
    */

    'states' => [
        'loading' => 'جارٍ التحميل…',
        'empty_title' => 'لا يوجد شيء هنا بعد',
        'empty_body' => 'سيظهر المحتوى هنا فور توفره.',
        'error_title' => 'تعذّر عرض هذا القسم',
        'error_body' => 'حدث خطأ غير متوقع. فريقنا يعمل على ذلك. حاول مرة أخرى بعد قليل.',
    ],

    'saved' => 'تم حفظ التغييرات.',
    'delete_confirm' => 'هل تريد حذف هذا العنصر؟ لا يمكن التراجع عن هذا الإجراء.',
    'leave_unsaved' => 'لديك تغييرات لم تُحفظ. هل تريد المغادرة؟',


    /*
    |--------------------------------------------------------------------------
    | Remaining time — read by App\Presenters\Support\Present::remainingLabel()
    |--------------------------------------------------------------------------
    | The deadline colour and this wording are decided together on the server
    | (PRD §9.11.1). Arabic counting: singular · dual · paucal · plural.
    */

    'remaining' => [
        'none' => 'بلا موعد نهائي',
        'passed' => 'انتهى الموعد',
        'now' => 'أقل من دقيقة',
        'minutes' => '{1} تبقّت دقيقة واحدة|{2} تبقّت دقيقتان|[3,10] تبقّت :count دقائق|[11,*] تبقّت :count دقيقة',
        'hours' => '{1} تبقّت ساعة واحدة|{2} تبقّت ساعتان|[3,10] تبقّت :count ساعات|[11,*] تبقّت :count ساعة',
        'days' => '{1} تبقّى يوم واحد|{2} تبقّى يومان|[3,10] تبقّت :count أيام|[11,*] تبقّى :count يومًا',
    ],

    /*
    |--------------------------------------------------------------------------
    | File sizes — Latin digits with an Arabic unit (art. 15)
    |--------------------------------------------------------------------------
    */

    'file_size' => [
        'bytes' => ':size بايت',
        'kb' => ':size كيلوبايت',
        'mb' => ':size ميجابايت',
        'gb' => ':size جيجابايت',
    ],

    'unknown' => 'غير معروف',

];
