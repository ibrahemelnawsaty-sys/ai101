<?php

/**
 * One label for every enum case in PROJECT-CONTRACT §3.
 * Mirrors lang/ar/enums.php key for key; ar is the reference.
 *
 * @see PROJECT-CONTRACT §3 · PRD §7, §9.9.5
 */

return [

    'user_role' => [
        'admin' => 'Administrator',
        'trainer' => 'Trainer',
        'coordinator' => 'Coordinator',
        'participant' => 'Participant',
    ],

    'user_status' => [
        'pending' => 'Awaiting activation',
        'active' => 'Active',
        'suspended' => 'Suspended',
        'deleted' => 'Deleted',
    ],

    'gender' => [
        'male' => 'Male',
        'female' => 'Female',
    ],

    'program_status' => [
        'draft' => 'Draft',
        'published' => 'Published',
        'archived' => 'Archived',
    ],

    'cohort_status' => [
        'upcoming' => 'Upcoming',
        'open' => 'Registration open',
        'running' => 'Running',
        'completed' => 'Completed',
    ],

    'enrollment_status' => [
        'pending' => 'Under review',
        'active' => 'Enrolled',
        'withdrawn' => 'Withdrawn',
        'completed' => 'Completed the programme',
    ],

    'enrollment_role' => [
        'participant' => 'Participant',
        'trainer' => 'Trainer',
        'coordinator' => 'Coordinator',
    ],

    'session_type' => [
        'intro' => 'Orientation session',
        'training' => 'Training session',
        'project' => 'Project session',
        'closing' => 'Closing ceremony',
    ],

    'session_status' => [
        'scheduled' => 'Upcoming',
        'live' => 'Live now',
        'completed' => 'Finished',
        'cancelled' => 'Cancelled',
    ],

    'session_delivery_mode' => [
        'online' => 'Online',
        'in_person' => 'In person',
    ],

    'attendance_status' => [
        'present' => 'Present',
        'late' => 'Late',
        'absent' => 'Absent',
        'excused' => 'Excused',
        'incomplete' => 'Incomplete attendance',
    ],

    'attendance_exception_type' => [
        'absence' => 'Absence',
        'lateness' => 'Lateness',
    ],

    'attendance_exception_status' => [
        'pending' => 'Pending review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ],

    'assignment_status' => [
        'draft' => 'Draft',
        'published' => 'Published',
    ],

    'submission_status' => [
        'submitted' => 'Submitted',
        'under_review' => 'Under review',
        'graded' => 'Graded',
    ],

    'evaluation_entity' => [
        'assignment' => 'Assignment',
        'final_project' => 'Final project',
    ],

    'journey_step_status' => [
        'locked' => 'Upcoming',
        'current' => 'Current step',
        'completed' => 'Completed',
    ],

    'thread_type' => [
        'trainer_dm' => 'Trainer chat',
        'group' => 'Cohort group',
        'announcement' => 'Announcements channel',
    ],

    'broadcast_kind' => [
        'message' => 'Message',
        'sessions' => 'Sessions reminder',
        'assignments' => 'Assignments reminder',
    ],

    'email_token_type' => [
        'verify' => 'Email verification',
        'reset' => 'Password reset',
        'invite' => 'Platform invitation',
    ],

    'resource_type' => [
        'file' => 'File',
        'link' => 'External link',
        'video' => 'Video',
    ],

];
