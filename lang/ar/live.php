<?php

/**
 * Live sessions: the featured upcoming session, the queue of what is next, and
 * past recordings. The meeting link never reaches the browser before its
 * window opens — the copy here explains that instead of hiding the button.
 *
 * @see PRD §9.10 · CONSTITUTION art. 5
 */

return [

    'subtitle' => 'روابط الدخول والتسجيلات السابقة',

    'next_title' => 'جلستك القادمة',
    'starts_in' => 'تبدأ بعد',
    'join' => 'دخول المحاضرة',
    'join_locked' => 'يُفتح الدخول قبل بداية الجلسة بـ :minutes دقيقة، ولا يُرسل الرابط إلى متصفحك قبل ذلك.',
    'passcode' => 'كلمة مرور الاجتماع',

    'errors' => [
        'no_link' => 'لم يُضَف رابط لهذه الجلسة بعد. راسل مدربك ليضيفه قبل موعدها.',
        'window_closed' => 'انتهى وقت الدخول لهذه الجلسة.',
    ],

    'upcoming_title' => 'الجلسات القادمة',
    'upcoming_empty_title' => 'لا جلسات قادمة بعد الجلسة الحالية',
    'upcoming_empty_body' => 'تظهر هنا بقية جلسات دفعتك مرتبة زمنيًا فور جدولتها.',

    'recordings_title' => 'التسجيلات السابقة',
    'search_recordings' => 'ابحث في التسجيلات…',
    'watch' => 'شاهد التسجيل',
    'recordings_empty_title' => 'لا تسجيلات بعد',
    'recordings_empty_body' => 'يُرفع تسجيل كل جلسة بعد انتهائها، ويظهر هنا مع مدته وتاريخه.',

    /*
    |--------------------------------------------------------------------------
    | Four mandatory states (art. 17)
    |--------------------------------------------------------------------------
    */

    'empty_title' => 'لا توجد محاضرات مباشرة قادمة حاليًا',
    'empty_body' => 'تظهر الجلسة القادمة هنا مع عدّاد تنازلي وزر دخول فور جدولتها.',
    'error_title' => 'تعذّر عرض المحاضرات',
    'error_body' => 'حدث خطأ أثناء جلب جلساتك. أعد المحاولة بعد قليل، وإن تكرّر الأمر راسلنا.',

];
