<?php

/**
 * Dashboard home — the first screen a participant sees. It must answer, at a
 * glance: ما التالي؟ وما حالتي؟ Each card carries its own four states; the
 * cards that mirror another module borrow that module's copy.
 *
 * @see PRD §9.5.3 · CONSTITUTION art. 17
 */

return [

    'greeting' => [
        'morning' => 'صباح الخير يا :name',
        'afternoon' => 'مساء الخير يا :name',
        'evening' => 'مساء الخير يا :name',
    ],

    'of_journey' => 'من رحلتك',

    'next_session' => [
        'title' => 'الجلسة القادمة',
        'starts_in' => 'تبدأ بعد',
        'join' => 'دخول المحاضرة',
        'join_locked' => 'يُفتح الدخول قبل بداية الجلسة',
        'link_hint' => 'يُفتح زر الدخول قبل بداية الجلسة بـ :minutes دقيقة، ولا يظهر الرابط قبل ذلك.',
        'empty_title' => 'لا توجد جلسة قادمة',
        'empty_body' => 'لم تُجدول جلسة قادمة بعد. سيظهر موعدها هنا مع عدّاد تنازلي فور اعتمادها.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Welcome card status line (PRD §9.5.3)
    |--------------------------------------------------------------------------
    | «جملة عن حالته الحالية في البرنامج» — built from JourneyEvaluator's count,
    | never from a guess about where the participant stands.
    */

    'status_line' => [
        'not_started' => 'لم تبدأ رحلتك بعد. تظهر خطواتك هنا فور التحاقك بدفعة.',
        'in_progress' => 'أكملت :completed من :total خطوات في رحلتك.',
        'completed' => 'أكملت كل خطوات رحلتك. مبارك لك.',
    ],


    /*
     * The one-time welcome after an invited trainee sets their own password
     * (D-63). Two titles because WelcomePresenter can only offer an e-mail
     * address when a profile has no Arabic first name, and a greeting is not a
     * place for an address.
     */
    'welcome_first' => [
        'title' => 'أهلًا بك يا :name، من هنا يبدأ الأثر',
        'title_plain' => 'أهلًا بك، من هنا يبدأ الأثر',
        'lead' => 'حسابك جاهز وكلمة مرورك صارت لك وحدك. الجدول والمهام ومواد البرنامج وبطاقتك الرقمية في انتظارك.',
        'cta' => 'استكمِل ملفك الشخصي',
        'dismiss' => 'إخفاء',
    ],
];
