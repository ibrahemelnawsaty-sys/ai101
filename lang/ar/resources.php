<?php

/**
 * The training pack: programme resources grouped by week, plus the «new since
 * 72 hours» card on the dashboard. Downloads go through a temporary signed
 * link issued after the permission check — never a direct file path.
 *
 * @see PRD §9.12, §9.5.3 · CONSTITUTION art. 22
 */

return [

    'subtitle' => 'ملفات البرنامج وروابطه وتسجيلاته، مرتبة حسب الأسبوع',

    'search_placeholder' => 'ابحث في عناوين الموارد وأوصافها…',
    'filter_type' => 'نوع المورد',
    'general_group' => 'موارد عامة',
    'new_badge' => 'جديد',
    'preview' => 'اعرض داخل المتصفح',
    'download' => 'حمّل المورد',

    /*
    |--------------------------------------------------------------------------
    | Dashboard card: newest resources (PRD §9.5.3)
    |--------------------------------------------------------------------------
    */

    'new' => [
        'title' => 'موارد جديدة',
        'empty_title' => 'لا موارد جديدة',
        'empty_body' => 'يظهر هنا ما أُضيف إلى الحقيبة خلال آخر ثلاثة أيام.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Four mandatory states (art. 17)
    |--------------------------------------------------------------------------
    */

    'empty_title' => 'لم يُضف أي مورد بعد',
    'empty_body' => 'لم يُضف أي مورد بعد. ستظهر موارد البرنامج هنا فور رفعها.',
    'group_empty_title' => 'لا موارد في هذا القسم',
    'group_empty_body' => 'لم يُرفع لهذا الأسبوع أي مورد بعد.',
    'no_match_title' => 'لا موارد تطابق بحثك',
    'no_match_body' => 'جرّب كلمة أخرى أو أزل التصفية لعرض كل الموارد.',
    'error_title' => 'تعذّر عرض الحقيبة التدريبية',
    'error_body' => 'حدث خطأ أثناء جلب الموارد. أعد المحاولة بعد قليل، وإن تكرّر الأمر راسلنا.',

];
