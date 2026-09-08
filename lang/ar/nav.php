<?php

/**
 * Sidebar and app-shell navigation labels. The rail sits on the RIGHT and its
 * groups follow the order fixed in PRD §9.5.1. Public-site navigation lives in
 * landing.php.
 *
 * @see PRD §9.5.1, §9.5.2 · CONSTITUTION art. 16
 */

return [

    'sidebar_label' => 'التنقل بين أقسام لوحتك',
    'toggle_sidebar' => 'اطوِ القائمة الجانبية أو وسّعها',
    'close_menu' => 'أغلق القائمة',
    'badge_hint' => 'عناصر تنتظرك',

    /*
    | Breadcrumb labels. The error pages build a link list for a signed-out
    | visitor and need a name for the public home page (PRD §8).
    */

    'breadcrumb' => [
        'home' => 'الصفحة الرئيسية',
    ],

    'badges' => [
        'unread_messages' => 'رسائل غير مقروءة',
        'unread_notifications' => 'إشعارات غير مقروءة',
    ],

    'groups' => [
        'overview' => 'نظرة عامة',
        'program' => 'البرنامج',
        'work' => 'المهام والتقييم',
        'communication' => 'التواصل',
        'trainer' => 'أدوات المدرب',
        'admin' => 'إدارة المنصة',
    ],

    'dashboard' => 'الرئيسية',
    'card' => 'البطاقة الرقمية',
    'journey' => 'رحلتي',
    'schedule' => 'جدول البرنامج',
    'attendance' => 'الحضور والغياب',
    'live' => 'المحاضرات المباشرة',
    'assignments' => 'المهام الأدائية',
    'resources' => 'الحقيبة التدريبية',
    'final_project' => 'المشروع الختامي',
    'grades' => 'التقييم والدرجات',
    'messages' => 'التواصل الداخلي',
    'certificate' => 'الشهادة',
    'notifications' => 'مركز الإشعارات',
    'profile' => 'حسابي',

    /*
    | The same labels addressed by role, for screens that build a link list
    | without knowing the rail (the error pages, for one).
    */

    'participant' => [
        'dashboard' => 'الرئيسية',
        'card' => 'البطاقة الرقمية',
        'journey' => 'رحلتي',
        'schedule' => 'جدول البرنامج',
        'attendance' => 'الحضور والغياب',
        'live' => 'المحاضرات المباشرة',
        'assignments' => 'المهام الأدائية',
        'resources' => 'الحقيبة التدريبية',
        'final_project' => 'المشروع الختامي',
        'grades' => 'التقييم والدرجات',
        'messages' => 'التواصل الداخلي',
        'certificate' => 'الشهادة',
        'notifications' => 'مركز الإشعارات',
        'profile' => 'حسابي',
    ],

    'current_cohort' => 'دفعتك الحالية',
    'switch_cohort' => 'انتقل إلى دفعة أخرى',

    'empty_title' => 'لا أقسام متاحة بعد',
    'empty_body' => 'تظهر أقسام لوحتك فور التحاقك بدفعة. راسلنا إن تأخر ظهورها.',

    /* ---------------------------------------------------------------
     | تسميات هيكل اللوحة — الشريط العلوي والقائمة الجانبية
     |---------------------------------------------------------------*/
    'chrome' => [
        'sidebar_label' => 'القائمة الجانبية',
        'toggle_sidebar' => 'اطوِ القائمة الجانبية أو وسِّعها',
        'open_menu' => 'افتح قائمة التنقل',
        'close_menu' => 'أغلق قائمة التنقل',
        'badge_hint' => 'عناصر تحتاج انتباهك',
        'current_cohort' => 'الدفعة الحالية',
        'switch_cohort' => 'بدِّل الدفعة',
        'empty_title' => 'لا توجد أقسام متاحة بعد',
        'empty_body' => 'ستظهر أقسام لوحتك هنا فور التحاقك بدفعة.',
        'account' => 'حسابي',
        'logout' => 'تسجيل الخروج',
        'footer_note' => 'كل الأوقات بتوقيت الرياض.',
    ],

];
