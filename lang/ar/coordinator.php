<?php

/**
 * The coordinator's own screens. Two of the role's three tabs are the
 * trainer's own views reused as-is (sessions, attendance — D-109 gave the
 * coordinator write access to the very same screens, so there is nothing
 * coordinator-specific to say about them); this file holds copy for the one
 * screen that is genuinely the coordinator's own: the information dashboard
 * (PR-5 دفعة 2).
 *
 * @see D-105, D-106, D-109 · CONSTITUTION Art. 6, Art. 17
 */

return [

    'dashboard' => [
        'title' => 'لوحة معلومات دفعتك',
        'error_title' => 'تعذّر تحميل لوحة المعلومات',
        'error_body' => 'حدث خطأ غير متوقع أثناء تحميل بيانات لوحتك. أعد المحاولة، فإن تكرر الخطأ راسل الدعم الفني.',
        'stat_participants' => 'متدربو الدفعة',
        'stat_pending_exceptions' => 'طلبات أعذار معلّقة',

        'next_session_title' => 'الجلسة القادمة',
        'next_session_empty_title' => 'لا توجد جلسة قادمة',
        'next_session_empty_body' => 'لم تُجدول جلسة قادمة لدفعتك بعد. ستظهر هنا فور اعتمادها.',
        'next_session_no_location' => 'بانتظار تحديد الموقع منك',
        'next_session_no_link' => 'بانتظار رابط الجلسة منك',

        // D-109 — الجلسات التي ينقصها رابط أو موقع، وحدها من دون الجلسات
        // المكتملة، لأن إتمامها صار مسؤولية المنسّق أو المشرف حصرًا.
        'attention_queue_title' => 'جلسات بحاجة لإكمال بياناتها',
        'attention_queue_empty_title' => 'كل الجلسات القادمة مكتملة البيانات',
        'attention_queue_empty_body' => 'لا توجد جلسة قادمة تنقصها رابط أو موقع في الوقت الحالي.',
        'attention_queue_online' => 'جلسة عن بُعد بلا رابط',
        'attention_queue_in_person' => 'جلسة حضورية بلا موقع',
        'attention_queue_action' => 'أكمل البيانات',
    ],

];
