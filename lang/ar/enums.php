<?php

/**
 * One label for every enum case in PROJECT-CONTRACT §3.
 * Each enum implements label(): string returning __('enums.<snake_case>.<value>').
 * Adding a case to an enum without adding its label here is an incomplete change.
 *
 * @see PROJECT-CONTRACT §3 · PRD §7, §9.9.5
 */

return [

    'user_role' => [
        // D-117 — «مدير النظام» في وثيقة المتطلبات صار دورين: القيمة admin
        // هي المشرف العام (البرنامج كله)، وsystem_admin هو مدير النظام
        // (الحسابات والمعاينة وصفحة الهبوط).
        'admin' => 'مشرف عام',
        'system_admin' => 'مدير نظام',
        'trainer' => 'مدرب',
        'coordinator' => 'منسّق',
        'participant' => 'متدرب',
    ],

    'user_status' => [
        'pending' => 'قيد التفعيل',
        'active' => 'نشط',
        'suspended' => 'معطّل',
        'deleted' => 'محذوف',
    ],

    'gender' => [
        'male' => 'ذكر',
        'female' => 'أنثى',
    ],

    'program_status' => [
        'draft' => 'مسودة',
        'published' => 'منشور',
        'archived' => 'مؤرشف',
    ],

    'cohort_status' => [
        'upcoming' => 'قادمة',
        'open' => 'التسجيل مفتوح',
        'running' => 'جارية',
        'completed' => 'مكتملة',
    ],

    'enrollment_status' => [
        'pending' => 'قيد المراجعة',
        'active' => 'ملتحق',
        'withdrawn' => 'منسحب',
        'completed' => 'أتمّ البرنامج',
    ],

    'enrollment_role' => [
        'participant' => 'متدرب',
        'trainer' => 'مدرب',
        'coordinator' => 'منسّق',
    ],

    'session_type' => [
        'intro' => 'لقاء تعريفي',
        'training' => 'جلسة تدريبية',
        'project' => 'جلسة مشروع',
        'closing' => 'حفل ختامي',
    ],

    'session_status' => [
        'scheduled' => 'قادمة',
        'live' => 'جارية الآن',
        'completed' => 'انتهت',
        'cancelled' => 'ملغاة',
    ],

    'session_delivery_mode' => [
        'online' => 'افتراضي (عن بُعد)',
        'in_person' => 'حضوري',
    ],

    'session_platform' => [
        'zoom' => 'Zoom',
        'google_meet' => 'Google Meet',
        'teams' => 'Microsoft Teams',
    ],

    'attendance_status' => [
        'present' => 'حاضر',
        'late' => 'متأخر',
        'absent' => 'غائب',
        'excused' => 'غياب بعذر',
        'incomplete' => 'حضور غير مكتمل',
    ],

    'attendance_exception_type' => [
        'absence' => 'غياب',
        'lateness' => 'تأخير',
    ],

    'attendance_exception_status' => [
        'pending' => 'قيد المراجعة',
        'approved' => 'مقبول',
        'rejected' => 'مرفوض',
    ],

    'assignment_status' => [
        'draft' => 'مسودة',
        'published' => 'منشورة',
    ],

    'submission_status' => [
        'submitted' => 'مُسلَّمة',
        'under_review' => 'قيد التقييم',
        'graded' => 'مُقيَّمة',
    ],

    'evaluation_entity' => [
        'assignment' => 'مهمة أدائية',
        'final_project' => 'المشروع الختامي',
    ],

    'journey_step_status' => [
        'locked' => 'قادمة',
        'current' => 'الخطوة الحالية',
        'completed' => 'مكتملة',
    ],

    'thread_type' => [
        'trainer_dm' => 'محادثة المدرب',
        'group' => 'مجموعة الدفعة',
        'announcement' => 'قناة الإعلانات',
        'direct' => 'محادثة مباشرة',
    ],

    'broadcast_kind' => [
        'message' => 'رسالة',
        'sessions' => 'تذكير بالجلسات',
        'assignments' => 'تذكير بالمهام',
    ],

    'email_token_type' => [
        'verify' => 'تفعيل البريد',
        'reset' => 'استعادة كلمة المرور',
        'invite' => 'دعوة إلى المنصة',
    ],

    'resource_type' => [
        'file' => 'ملف',
        'link' => 'رابط خارجي',
        'video' => 'فيديو',
    ],

    /*
     * D-121 — what one field of the final-project hand-in asks for, and the
     * file formats an upload field may accept.
     */
    'submission_field_type' => [
        'url' => 'رابط',
        'github' => 'رابط مستودع GitHub',
        'file' => 'رفع ملف',
        'text' => 'نص قصير',
        'textarea' => 'نص طويل',
    ],

    'submission_file_format' => [
        'pdf' => 'PDF',
        'powerpoint' => 'PowerPoint',
        'word' => 'Word',
        'excel' => 'Excel',
        'csv' => 'CSV',
        'text' => 'نص عادي',
        'markdown' => 'Markdown',
        'zip' => 'ZIP',
        'png' => 'PNG',
        'jpeg' => 'JPEG',
        'webp' => 'WebP',
    ],

    /*
     * D-124 — «الدعم الفني»: مراحل التذكرة، ودرجة من عنده الآن (بالدور لا
     * بالاسم)، وتصنيفها الذي لا يغيّر مسارها، وأنواع سطور خطّها الزمني.
     */
    'support_ticket_status' => [
        'open' => 'مفتوحة',
        'in_progress' => 'قيد المعالجة',
        'resolved' => 'تمت المعالجة',
        'closed' => 'مغلقة',
    ],

    'support_ticket_level' => [
        'coordinator' => 'المنسّق',
        'admin' => 'المشرف العام',
        'system_admin' => 'مدير النظام',
    ],

    'support_ticket_category' => [
        'account' => 'الحساب والدخول',
        'platform' => 'عطل في المنصة',
        'program' => 'البرنامج والمحتوى',
        'other' => 'أخرى',
    ],

    'support_ticket_entry_type' => [
        'opened' => 'فتح التذكرة',
        'reply' => 'ردّ المتدرب',
        'message' => 'رسالة المنسّق',
        'note' => 'ملاحظة',
        'escalated' => 'تحويل إلى درجة أعلى',
        'returned' => 'إعادة إلى درجة أدنى',
        'assigned' => 'تحويل إلى منسّق آخر',
        'resolved' => 'تمت المعالجة',
        'reopened' => 'إعادة فتح',
        'closed' => 'إغلاق',
        'auto_closed' => 'إغلاق تلقائي',
    ],

];
