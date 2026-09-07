<?php

/**
 * Attendance module copy. Every entry under `messages` is quoted verbatim from
 * PRD §9.9.8 — it must not be paraphrased. A button label here is a reflection
 * of the server's decision, never its source (BR-07, art. 5).
 *
 * Terminology (PRD §9.3): this module says «تسجيل الحضور» و«تسجيل الانصراف»
 * and never «دخول» أو «خروج».
 *
 * @see BR-01, BR-02, BR-03, BR-04, BR-05, BR-06, BR-07, BR-08, BR-09 · PRD §9.9
 */

return [

    'short_label' => 'حضورك',
    'server_time_note' => 'وقت الخادم بتوقيت الرياض هو المرجع الوحيد',

    'todays_session' => 'جلسة اليوم',
    'next_session' => 'الجلسة القادمة',

    /*
    |--------------------------------------------------------------------------
    | The two buttons (PRD §9.9.4: never hide a button without an explanation)
    |--------------------------------------------------------------------------
    */

    'check_in' => 'سجّل الحضور',
    'check_out' => 'سجّل الانصراف',
    'opens_in' => 'يُفتح تسجيل الحضور بعد',
    'check_out_opens_in' => 'يُفتح تسجيل الانصراف بعد',
    'check_in_closed' => 'انتهى وقت تسجيل الحضور',
    'check_out_closed' => 'انتهى وقت تسجيل الانصراف',
    'checked_in_at' => 'سجّلت حضورك الساعة :time',
    'checked_out_at' => 'سجّلت انصرافك الساعة :time',

    // Flash notices after a successful action (the full wording is in `messages`)
    'checked_in' => 'تم تسجيل حضورك.',
    'checked_out' => 'تم تسجيل انصرافك. شكرًا لحضورك.',
    'manual_saved' => 'حُفظ التعديل وسُجّل في سجل التدقيق.',
    'bulk_saved' => 'حُفظ التسجيل الجماعي وسُجّل سببه في سجل التدقيق.',

    // Column headings for the exported attendance report
    'export' => [
        'participant' => 'المتدرب',
        'session' => 'الجلسة',
        'status' => 'الحالة',
    ],

    /*
    |--------------------------------------------------------------------------
    | System messages — PRD §9.9.8, verbatim
    |--------------------------------------------------------------------------
    */

    'messages' => [
        'check_in_before_window' => 'يُفتح تسجيل الحضور بعد :countdown',
        'check_in_after_window' => 'انتهى وقت تسجيل الحضور لهذه الجلسة',
        'check_in_success' => 'تم تسجيل حضورك الساعة :time',
        'check_in_success_late' => 'تم تسجيل حضورك الساعة :time وسُجّل متأخرًا',
        'check_in_duplicate' => 'سجّلت حضورك مسبقًا لهذه الجلسة',
        'check_out_before_window' => 'يُفتح تسجيل الانصراف في آخر نصف ساعة من الجلسة',
        'check_out_without_check_in' => 'لا يمكن تسجيل الانصراف لأنك لم تسجّل حضورك في هذه الجلسة',
        'check_out_after_window' => 'انتهى وقت تسجيل الانصراف لهذه الجلسة',
        'check_out_success' => 'تم تسجيل انصرافك الساعة :time. شكرًا لحضورك',
        'session_cancelled' => 'هذه الجلسة ملغاة ولا يمكن تسجيل الحضور فيها',
    ],

    'errors' => [
        'client_time_rejected' => 'يعتمد التسجيل على ساعة الخادم وحدها، ولا تُقبل أي قيمة وقت مرسلة من جهازك.',
        'window_closed' => 'انتهت النافذة الزمنية لهذا الإجراء في هذه الجلسة.',
        'not_enrolled' => 'هذه الجلسة ليست ضمن دفعتك.',

        /*
        | Keys raised by App\Exceptions\AttendanceException. The wording of the
        | first eight is the PRD §9.9.8 wording, kept identical to `messages`
        | above so the server refusal and the interface hint never disagree.
        */

        'session_cancelled' => 'هذه الجلسة ملغاة ولا يمكن تسجيل الحضور فيها',
        'check_in_not_open' => 'يُفتح تسجيل الحضور بعد :countdown',
        'check_in_closed' => 'انتهى وقت تسجيل الحضور لهذه الجلسة',
        'already_checked_in' => 'سجّلت حضورك مسبقًا لهذه الجلسة',
        'check_out_not_open' => 'يُفتح تسجيل الانصراف في آخر نصف ساعة من الجلسة',
        'check_out_closed' => 'انتهى وقت تسجيل الانصراف لهذه الجلسة',
        'check_out_without_check_in' => 'لا يمكن تسجيل الانصراف لأنك لم تسجّل حضورك في هذه الجلسة',
        'already_checked_out' => 'سجّلت انصرافك مسبقًا لهذه الجلسة',
        'record_exists' => 'لهذه الجلسة سجل حضور مسبق باسمك. راجع مدربك إن كان بحاجة إلى تصحيح.',
        'record_not_found' => 'لا يوجد سجل حضور لهذه الجلسة باسمك.',
        'reason_too_short' => 'اكتب سبب التعديل في :min أحرف على الأقل. يُسجَّل السبب في سجل التدقيق.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Window rules shown beside a disabled button (PRD §9.9.2)
    |--------------------------------------------------------------------------
    */

    'window' => [
        'check_in_rule' => 'تُفتح نافذة تسجيل الحضور قبل بداية الجلسة بـ :minutes دقيقة وتبقى مفتوحة حتى نهايتها.',
        'check_out_rule' => 'تُفتح نافذة تسجيل الانصراف في آخر :minutes دقيقة من الجلسة وتبقى مفتوحة :after دقيقة بعد نهايتها.',
        'present_rule' => 'التسجيل قبل البداية أو خلال :minutes دقيقة منها ← حاضر',
        'late_rule' => 'التسجيل بعد :minutes دقيقة من البداية ← متأخر',
        'absent_rule' => 'بلا تسجيل حتى نهاية الجلسة ← غائب آليًا',
        'incomplete_rule' => 'حضور بلا انصراف حتى إغلاق النافذة ← حضور غير مكتمل',
    ],

    'legend' => [
        'present' => 'حاضر: سجّلت خلال أول نصف ساعة',
        'late' => 'متأخر: سجّلت بعد نصف ساعة من البداية',
        'absent' => 'غائب: لم تسجّل حتى نهاية الجلسة',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate ring and summary (PRD §9.9.6)
    |--------------------------------------------------------------------------
    */

    'rate' => [
        'title' => 'نسبة الحضور',
        'label' => 'من جلسات دفعتك',
        'aria' => 'نسبة حضورك :rate بالمئة',
        'of_total' => '{0} لا جلسات محسوبة بعد|{1} حضرت جلسة واحدة من :total|{2} حضرت جلستين من :total|[3,10] حضرت :attended جلسات من :total|[11,*] حضرت :attended جلسة من :total',
        'above_minimum' => 'فوق الحد المطلوب',
        'below_minimum' => 'تحت الحد المطلوب',
        'empty_title' => 'لم تبدأ جلسات دفعتك بعد',
        'empty_body' => 'تُحتسب نسبة حضورك من أول جلسة تنتهي، وتتحدّث بعد كل جلسة تلقائيًا.',
    ],

    'summary' => [
        'total' => 'إجمالي الجلسات',
    ],

    // A finished session that carries no row at all is not the same fact as an
    // absence: the reconciliation job writes absences, and until it has run the
    // roster must say plainly that nothing was recorded (BR-08, art. 17).
    'status' => [
        'not_recorded' => 'لم يُسجَّل',
    ],

    // One-letter codes for the trainer's cohort matrix. Each cell also carries
    // the full status as its title, because a colour and a letter alone never
    // carry meaning (art. 18).
    'matrix' => [
        'short' => [
            'present' => 'ح',
            'late' => 'م',
            'absent' => 'غ',
            'excused' => 'ع',
            'incomplete' => 'ن',
            'none' => '·',
        ],
    ],

    'near_minimum_title' => 'انتبه لنسبة حضورك',
    'near_minimum_body' => 'نسبة حضورك اقتربت من الحد الأدنى المطلوب للشهادة وهو :rate%. احرص على حضور الجلسات القادمة.',

    /*
    |--------------------------------------------------------------------------
    | Attendance log table
    |--------------------------------------------------------------------------
    */

    'log' => [
        'title' => 'سجل حضورك',
        'export_pdf' => 'حمّل سجلي PDF',
        'footnote' => 'كل الأوقات بتوقيت الرياض. «حضور غير مكتمل» تعني حضورًا بلا انصراف، ويُنبَّه مدربك بها تلقائيًا.',
        'empty_title' => 'لم يبدأ سجل حضورك بعد',
        'empty_body' => 'ستظهر هنا كل جلسة سجّلت فيها حضورك، بوقت الحضور والانصراف وحالتك في كل جلسة.',
    ],

    'col_session' => 'الجلسة',
    'col_date' => 'التاريخ',
    'col_check_in' => 'وقت الحضور',
    'col_check_out' => 'وقت الانصراف',
    'col_status' => 'الحالة',
    'col_note' => 'ملاحظة',

    'filter_status' => 'الحالة',

    /*
    |--------------------------------------------------------------------------
    | Four mandatory states (art. 17)
    |--------------------------------------------------------------------------
    */

    'no_session_title' => 'لا توجد جلسة اليوم',
    'no_session_body' => 'يظهر زرّا الحضور والانصراف هنا قبل بداية الجلسة القادمة بنصف ساعة.',
    'error_title' => 'تعذّر عرض سجل الحضور',
    'error_body' => 'حدث خطأ أثناء جلب سجلك. أعد المحاولة بعد قليل — سجلك محفوظ ولم يتأثر.',

];
