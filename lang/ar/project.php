<?php

/**
 * The final project tab. Before the trainer unlocks it, nothing about the
 * project reaches the browser — the locked copy here is all a participant sees,
 * and the check happens on the server (PRD §9.14.1).
 * The submission area reuses the assignments copy.
 *
 * @see PRD §9.14 · CONSTITUTION art. 5
 */

return [

    'subtitle_locked' => 'يُفتح في الأسبوع الأخير بإتاحة من إدارة البرنامج',
    'subtitle_open' => 'دليل المشروع ومعايير تقييمه وموعده النهائي',

    /*
    |--------------------------------------------------------------------------
    | Locked state
    |--------------------------------------------------------------------------
    */

    'locked_title' => 'سيُفتح المشروع الختامي في وقته المحدد',
    'locked_body' => 'تتيح إدارة البرنامج هذا التبويب في الأسبوع الأخير، ويصلك إشعار وبريد فور فتحه. حتى ذلك الحين واصل مهام أسابيعك.',
    'locked_body_with_date' => 'تتيح إدارة البرنامج هذا التبويب في :date تقريبًا، ويصلك إشعار وبريد فور فتحه. حتى ذلك الحين واصل مهام أسابيعك.',

    /*
    |--------------------------------------------------------------------------
    | Brief
    |--------------------------------------------------------------------------
    */

    'requirements' => 'المتطلبات',
    'criteria' => 'معايير التقييم',
    'criterion' => 'المعيار',
    'criterion_points' => 'الدرجة',
    'total' => 'المجموع',
    'attachments' => 'ملفات من المدرب',
    'time_left' => 'الوقت المتبقي للتسليم',

    'criteria_empty_title' => 'لم تُنشر معايير التقييم بعد',
    'criteria_empty_body' => 'يضيف مدربك جدول المعايير ودرجاتها قبل الموعد النهائي، وستظهر هنا.',

    /*
    |--------------------------------------------------------------------------
    | Submission and evaluation
    |--------------------------------------------------------------------------
    */

    'your_submission' => 'تسليمك للمشروع',
    'submission_intro' => 'املأ العناصر أدناه ثم سلّم. العناصر المعلَّمة بنجمة مطلوبة، ولا يكتمل التسليم إلا بها.',
    // D-121 — the hand-in is one request, so its upload fields share the
    // server's two ceilings; the participant sees both before choosing files.
    'upload_limits' => '{1} في التسليم الواحد ملف واحد على الأكثر، بمجموع :size.|{2} في التسليم الواحد ملفان على الأكثر، بمجموع :size.|[3,10] في التسليم الواحد :count ملفات على الأكثر، بمجموع :size.|[11,*] في التسليم الواحد :count ملفًا على الأكثر، بمجموع :size.',
    'field_formats' => 'الصيغ المقبولة: :formats',
    'field_limits' => 'حتى :size للملف · :count',
    'optional_mark' => '(اختياري)',
    'fields_empty_title' => 'لم تُحدَّد عناصر التسليم بعد',
    'fields_empty_body' => 'تضبط إدارة البرنامج ما يُسلَّم في المشروع الختامي، ويظهر هنا فور ضبطه. راجع مدربك إن طال الانتظار.',
    'legacy_files_label' => 'ملفات التسليم',
    'submit_action' => 'سلّم المشروع',
    'submission_closed_title' => 'أُغلق تسليم المشروع الختامي',
    'evaluation_title' => 'تقييم مشروعك',

    'submitted' => 'تم استلام مشروعك. سجّلنا وقت التسليم وأشعرنا مدربك، وأرسلنا إيصال التسليم إلى بريدك.',

    /*
    | D-122 — the receipt of a hand-in: its code, its QR, and what comes next.
    */
    'receipt' => [
        'title' => 'إيصال تسليم المشروع الختامي',
        'code_label' => 'رمز التسليم',
        'code_hint' => 'احتفظ بهذا الرمز؛ هو إثبات استلامنا لمشروعك.',
        'qr_label' => 'رمز QR لإيصال التسليم :code',
        'qr_hint' => 'امسحه لفتح هذا الإيصال بعد تسجيل الدخول.',
        'next_title' => 'الخطوة التالية',
        'next_pending' => 'انتظار نتيجة تقييم مشروعك. يصلك إشعار وبريد فور رصد الدرجة.',
        'next_graded' => 'قُيّم مشروعك — درجتك وملاحظة مدربك في تبويب التقييم.',
        'open' => 'اعرض الإيصال',
        'open_grading' => 'افتح التسليم في لوحة التصحيح',
        'open_project' => 'افتح المشروع الختامي',
        'version' => 'النسخة :version',
        'late' => 'سُلِّم بعد الموعد',
        'details' => [
            'project' => 'المشروع',
            'participant' => 'المتدرب',
            'submitted_at' => 'وقت التسليم',
            'version' => 'رقم النسخة',
            'items' => 'العناصر المسلَّمة',
        ],
        'notice_title' => 'استلمنا مشروعك الختامي — رمز التسليم :code',
        'notice_body' => 'سجّلنا وقت تسليم «:project» وأشعرنا مدربك. الخطوة التالية: انتظار نتيجة التقييم، ويصلك إشعار وبريد فور رصد الدرجة.',
    ],

    'errors' => [
        'not_available' => 'لم يُفتح المشروع الختامي بعد. يصلك إشعار فور فتحه.',
        'no_fields' => 'لا يمكن التسليم الآن: لم تُحدَّد عناصر التسليم لهذا المشروع بعد. راجع مدربك.',
        'field_required' => '«:field» مطلوب لإتمام التسليم.',
        'field_invalid' => 'تعذّرت قراءة ما أُرسل في «:field». أعد إدخاله ثم سلّم.',
        'field_url' => '«:field» يجب أن يكون رابطًا كاملًا يبدأ بـ https:// — انسخه من شريط العنوان كما هو.',
        'field_github' => '«:field» يجب أن يكون رابط مستودع يبدأ بـ https://github.com/ — انسخه من صفحة المستودع.',
        'field_too_long' => '«:field» أطول من المسموح (:max حرف). اختصره ثم أعد التسليم.',
        'field_file_type' => 'صيغة ملف في «:field» غير مقبولة. الصيغ المقبولة: :formats.',
        'field_file_size' => 'ملف في «:field» أكبر من الحد المسموح (:size). صغّر حجمه أو اضغطه ثم أعد رفعه.',
        'field_file_count' => '{1} «:field» يقبل ملفًا واحدًا فقط. أبقِ ملفًا واحدًا ثم أعد التسليم.|{2} «:field» يقبل ملفين على الأكثر. احذف الزائد ثم أعد التسليم.|[3,10] «:field» يقبل :count ملفات على الأكثر. احذف الزائد ثم أعد التسليم.|[11,*] «:field» يقبل :count ملفًا على الأكثر. احذف الزائد ثم أعد التسليم.',
    ],

    /*
    |--------------------------------------------------------------------------
    | The default hand-in fields (D-121)
    |--------------------------------------------------------------------------
    |
    | What a new project's hand-in form starts with, and what the D-121
    | migration gave the projects that already existed. Once written, each
    | field is the administrator's to edit from their screen (BR-31): these
    | lines are the starting text only, never read again for an existing
    | field. The first three are the owner's picture, word for word but one:
    | «أدناه» is dropped, since no list of platforms sits below it here.
    |
    */

    'default_fields' => [
        'live_url' => [
            'label' => 'رابط مشروع يعمل',
            'description' => 'رابط عام يفتح ويعمل بدون تسجيل دخول.',
            'tips' => [
                'افتحه من متصفح آخر وجرّبه قبل التسليم.',
                'يمكن نشره على إحدى المنصات المجانية.',
            ],
        ],
        'github_url' => [
            'label' => 'مستودع GitHub عام',
            'description' => 'فيه كود المشروع وملف README.',
            'tips' => [
                'يشرح الـ README المشكلة والحل وطريقة التشغيل.',
                'بدون مفاتيح API أو بيانات شخصية.',
            ],
        ],
        'presentation_file' => [
            'label' => 'عرض تقديمي',
            'description' => '10 شرائح كحد أقصى تعرض بها مشروعك في الحفل الختامي.',
            'tips' => [
                'ضع رابط مشروعك في آخر شريحة.',
            ],
        ],
        'logo_file' => [
            'label' => 'شعار الفكرة',
            'description' => 'صورة الشعار إن كان لمشروعك واحد — اختياري تمامًا.',
            'tips' => [],
        ],
        'description' => [
            'label' => 'وصف المشروع',
            'description' => 'اشرح فكرة مشروعك وما بنيته وكيف نُشغّله.',
            'tips' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Four mandatory states (art. 17)
    |--------------------------------------------------------------------------
    */

    'error_title' => 'تعذّر عرض المشروع الختامي',
    'error_body' => 'حدث خطأ أثناء جلب بيانات المشروع. أعد المحاولة بعد قليل — تسليمك محفوظ ولم يتأثر.',

    // A closed submission area always says why (PRD §9.14).
    'closed_deadline' => 'انتهى الموعد النهائي لتسليم المشروع الختامي.',
    'closed_locked' => 'تسليم المشروع الختامي غير متاح لك الآن. راجع مدربك إن كنت تظن أن هذا خطأ.',

];
