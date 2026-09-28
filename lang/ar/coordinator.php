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

    /*
     * D-127 — the coordinator's final-project tab: the general supervisor
     * makes the project and its guide available; the cohort's primary
     * coordinator publishes them to the trainees.
     */
    'final_project' => [
        'title' => 'المشروع الختامي',
        'intro' => 'المشرف العام يُدخل بيانات المشروع ودليله ويتيحهما، ثم ينشرهما المنسّق الأساسي للدفعة للمتدربين. بقية المنسّقين والمدربين يطّلعون فقط.',
        'no_cohort_title' => 'لا دفعة مسندة إليك بعد',
        'no_cohort_body' => 'يظهر هنا المشروع الختامي لدفعتك حين تُسند إليك.',
        'no_project_title' => 'لا مشروع ختامي لهذه الدفعة بعد',
        'no_project_body' => 'يُدخله المشرف العام من لوحة الإدارة، ثم يظهر هنا لتنشره حين يتيحه.',
        'error_title' => 'تعذّر عرض المشروع الختامي',
        'error_body' => 'حدث خطأ أثناء جلب بيانات المشروع. أعد المحاولة بعد قليل.',
        'summary_title' => 'المشروع',
        'due' => 'آخر موعد للتسليم: :date',
        'states' => [
            'not_available' => 'لم يتحه المشرف العام بعد',
            'available' => 'متاح — بانتظار نشرك',
            'published' => 'منشور للمتدربين',
        ],
        'guide_states' => [
            'not_available' => 'لم يتحه المشرف العام بعد',
            'available' => 'متاح — بانتظار نشرك',
            'published' => 'منشور',
        ],
        'published_note' => ':name نشره بتاريخ :date',
        'hand_ins' => '{0} لا تسليمات بعد|{1} تسليم واحد|{2} تسليمان|[3,10] :count تسليمات|[11,*] :count تسليمًا',
        'primary_only' => 'النشر وإيقافه للمنسّق الأساسي للدفعة وحده. يمكنك الاطلاع هنا فقط.',
        'no_primary' => 'لم يُختر منسّق أساسي لهذه الدفعة بعد، فلا يُنشر شيء حتى يختاره المشرف العام.',
        'waiting_available' => 'لا يمكن النشر قبل أن يتيحه المشرف العام.',
        'publish' => 'انشر المشروع للمتدربين',
        'unpublish' => 'أوقف نشر المشروع',
        'confirm_title' => 'إيقاف نشر المشروع',
        'confirm_body' => 'وصل من المتدربين :count. إيقاف النشر يقفل المشروع أمامهم فلا يرون صفحته ولا يسلّمون، والتسليمات السابقة تبقى محفوظة ويصحّحها المدرب.',
        'confirm_action' => 'نعم، أوقف النشر',
        'guide_title' => 'دليل المشروع',
        'guide_intro' => 'ينشر الدليل لكل لغة على حدة، ولا يُنشر الإنجليزي قبل العربي. المتدرب يراه بعد نشر الدليل والمشروع معًا.',
        'guide_view' => 'اطّلع على الدليل',
        'guide_publish' => 'انشر الدليل',
        'guide_unpublish' => 'أوقف نشر الدليل',
        'guide_needs_arabic' => 'انشر الدليل العربي أولًا.',
        'published' => 'نُشر المشروع للمتدربين، ووصلهم إشعار وبريد.',
        'unpublished' => 'أُوقف نشر المشروع. التسليمات السابقة محفوظة.',
        'guide_published' => 'نُشر الدليل.',
        'guide_unpublished' => 'أُوقف نشر الدليل.',
        'unchanged' => 'لم يتغيّر شيء — الحالة كما طلبت أصلًا.',
        'errors' => [
            'not_available' => 'ألغى المشرف العام إتاحة المشروع قبل النشر، فلم يُنشر. حدّث الصفحة.',
            'guide_not_available' => 'ألغى المشرف العام إتاحة الدليل قبل النشر، فلم يُنشر. حدّث الصفحة.',
            'needs_arabic' => 'لا يُنشر الدليل الإنجليزي قبل العربي. انشر العربي أولًا.',
            'confirm_unpublish' => 'في المشروع تسليمات. أكّد إيقاف النشر من رسالة التأكيد.',
        ],
    ],

];
