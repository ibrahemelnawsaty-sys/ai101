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
        'participant' => 'مشارك',
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
        'participant' => 'مشارك',
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

];
