<?php

/**
 * The training schedule: the accordion view grouped by week and the visual
 * calendar view. Every time on this screen is Riyadh time and says so.
 *
 * @see PRD §9.8 · CONSTITUTION art. 11
 */

return [

    'subtitle' => 'أسابيع البرنامج وجلساته بتوقيت الرياض',

    'view_switch' => 'اختر طريقة عرض الجدول',
    'view_accordion' => 'قوائم منسدلة',
    'view_calendar' => 'عرض تقويمي',

    'filter_week' => 'الأسبوع',
    'filter_type' => 'نوع الجلسة',
    'filter_attendance' => 'حالة الحضور',

    'export_ics' => 'صدّر الجدول لتقويمك',
    'export_pdf' => 'حمّل الجدول PDF',
    'add_to_calendar' => 'أضف إلى تقويمي',

    'session_count' => '{0} بلا جلسات|{1} جلسة واحدة|{2} جلستان|[3,10] :count جلسات|[11,*] :count جلسة',
    'current_week' => 'الأسبوع الحالي',
    'print_title' => 'جدول البرنامج التدريبي',
    'printed_at' => 'طُبع في :at بتوقيت الرياض',
    'table_caption' => 'جلسات :week',
    'unscheduled_group' => 'جلسات خارج الأسابيع',
    'session_details' => 'تفاصيل الجلسة',

    'col_date' => 'اليوم والتاريخ',
    'col_time' => 'الوقت',
    'col_title' => 'العنوان',
    'col_topic' => 'الموضوع',
    'col_trainer' => 'المدرب',
    'col_status' => 'الحالة',

    'cancelled_reason' => 'سبب الإلغاء: :reason',
    'replacement_at' => 'الموعد البديل :when',

    'previous_week' => 'الأسبوع السابق',
    'next_week' => 'الأسبوع التالي',
    'today' => 'اليوم',
    'no_sessions_that_day' => 'لا جلسات في هذا اليوم',

    'timezone_note' => 'كل الأوقات المعروضة بتوقيت الرياض (Asia/Riyadh)، وساعة الخادم هي المرجع.',

    /*
    |--------------------------------------------------------------------------
    | Four mandatory states (art. 17)
    |--------------------------------------------------------------------------
    */

    'empty_title' => 'لم يُنشر الجدول بعد',
    'empty_body' => 'يظهر جدول الجلسات هنا فور اعتماده من إدارة البرنامج، ويصلك إشعار بذلك.',
    'week_empty_title' => 'لا جلسات في هذا الأسبوع',
    'week_empty_body' => 'لم تُضف جلسات لهذا الأسبوع بعد.',
    'calendar_empty_title' => 'لا جلسات في هذا الأسبوع',
    'calendar_empty_body' => 'انتقل إلى أسبوع آخر بالأسهم، أو ارجع إلى الأسبوع الحالي.',
    'error_title' => 'تعذّر عرض الجدول',
    'error_body' => 'حدث خطأ أثناء جلب الجدول. أعد المحاولة بعد قليل — مواعيدك محفوظة ولم تتأثر.',

];
