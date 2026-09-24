<?php

/**
 * Attendance module copy. Mirrors lang/ar/attendance.php key for key; the
 * Arabic wording under `messages` is the one PRD §9.9.8 fixes, and this file
 * is its English reading, not a replacement for it.
 *
 * @see BR-01 … BR-09 · PRD §9.9
 */

return [

    'short_label' => 'Your attendance',
    'server_time_note' => 'The server clock in Riyadh time is the only reference',

    'todays_session' => 'Session today',
    'next_session' => 'Next session',

    'check_in' => 'Check in',
    'check_out' => 'Check out',
    'opens_in' => 'Check-in opens in',
    'check_out_opens_in' => 'Check-out opens in',
    'check_in_closed' => 'Check-in has closed',
    'check_out_closed' => 'Check-out has closed',
    'checked_in_at' => 'You checked in at :time',
    'checked_out_at' => 'You checked out at :time',

    // Flash notices after a successful action (the full wording is in `messages`)
    'checked_in' => 'You are checked in.',
    'checked_out' => 'You are checked out. Thank you for attending.',
    'manual_saved' => 'The change was saved and written to the audit log.',
    'bulk_saved' => 'The bulk record was saved and its reason written to the audit log.',
    'exception_requested' => 'Your request was sent. We will reply once it has been reviewed.',
    'exception_approved' => 'The request was approved, and the attendance record was updated.',
    'exception_rejected_saved' => 'The rejection was saved with its reason in the audit log, and the participant was notified.',

    // Column headings for the exported attendance report
    'export' => [
        'participant' => 'Participant',
        'session' => 'Session',
        'status' => 'Status',
    ],

    'messages' => [
        'check_in_before_window' => 'Check-in opens in :countdown',
        'check_in_after_window' => 'Check-in for this session has closed',
        'check_in_success' => 'You checked in at :time',
        'check_in_success_late' => 'You checked in at :time and were recorded as late',
        'check_in_duplicate' => 'You already checked in to this session',
        'check_out_before_window' => 'Check-out opens in the last half hour of the session',
        'check_out_without_check_in' => 'You cannot check out because you did not check in to this session',
        'check_out_after_window' => 'Check-out for this session has closed',
        'check_out_success' => 'You checked out at :time. Thank you for attending',
        'session_cancelled' => 'This session is cancelled and attendance cannot be recorded for it',
    ],

    'errors' => [
        'client_time_rejected' => 'Attendance is decided by the server clock alone, and no time value sent by your device is accepted.',
        'window_closed' => 'The time window for this action has closed for this session.',
        'not_enrolled' => 'This session does not belong to your cohort.',

        /*
        | Raised by App\Exceptions\AttendanceException. The wording of the
        | first eight matches `messages` above, so the server refusal and the
        | interface hint never disagree.
        */

        'session_cancelled' => 'This session is cancelled and attendance cannot be recorded for it',
        'check_in_not_open' => 'Check-in opens in :countdown',
        'check_in_closed' => 'Check-in for this session has closed',
        'already_checked_in' => 'You already checked in to this session',
        'check_out_not_open' => 'Check-out opens in the last half hour of the session',
        'check_out_closed' => 'Check-out for this session has closed',
        'check_out_without_check_in' => 'You cannot check out because you did not check in to this session',
        'already_checked_out' => 'You already checked out of this session',
        'record_exists' => 'An attendance record already exists in your name for this session. Ask your trainer if it needs correcting.',
        'record_not_found' => 'There is no attendance record in your name for this session.',
        'reason_too_short' => 'Write the reason for the change in at least :min characters. It is stored in the audit log.',

        // D-106 — self-check-in by scanning the session's code
        'self_check_in_not_open' => 'The session has not started yet. Self-check-in opens as soon as it does.',
        'self_check_in_closed' => 'The check-in window has closed.',
        'exception_reason_too_short' => 'Write the reason for the request in at least :min characters. This reason reaches whoever reviews your request.',
        'exception_type_mismatch' => 'Your attendance status changed since this page opened. Refresh the page and try again.',
        'exception_already_pending' => 'You already have a request pending for this record. Wait for a decision before submitting another.',
        'exception_already_decided' => 'This request has already been decided and cannot be changed.',
    ],

    'window' => [
        'check_in_rule' => 'Check-in opens :minutes minutes before the session starts and stays open until it ends.',
        'check_out_rule' => 'Check-out opens for the last :minutes minutes of the session and stays open :after minutes after it ends.',
        'present_rule' => 'Checking in before the start, or within :minutes minutes of it, counts as present',
        'late_rule' => 'Checking in more than :minutes minutes after the start counts as late',
        'absent_rule' => 'No check-in by the end of the session counts as absent, set automatically',
        'incomplete_rule' => 'Checking in without checking out before the window closes counts as incomplete attendance',
    ],

    'legend' => [
        'present' => 'Present: checked in within the first half hour',
        'late' => 'Late: checked in more than half an hour after the start',
        'absent' => 'Absent: no check-in by the end of the session',
    ],

    'rate' => [
        'title' => 'Attendance rate',
        'label' => 'of your cohort sessions',
        'aria' => 'Your attendance rate is :rate percent',
        'of_total' => '{0} no sessions counted yet|{1} you attended one session of :total|[2,*] you attended :attended of :total sessions',
        'above_minimum' => 'Above the required minimum',
        'below_minimum' => 'Below the required minimum',
        'empty_title' => 'Your cohort sessions have not started yet',
        'empty_body' => 'Your rate is counted from the first session that ends, and updates automatically after every session.',
    ],

    'summary' => [
        'total' => 'Total sessions',
    ],

    'status' => [
        'not_recorded' => 'Not recorded',
    ],

    'matrix' => [
        'short' => [
            'present' => 'P',
            'late' => 'L',
            'absent' => 'A',
            'excused' => 'E',
            'incomplete' => 'I',
            'none' => '.',
        ],
    ],

    'near_minimum_title' => 'Keep an eye on your attendance',
    'near_minimum_body' => 'Your attendance is close to the :rate% minimum required for the certificate. Make sure you attend the coming sessions.',

    'log' => [
        'title' => 'Your attendance log',
        'export_pdf' => 'Download my log as PDF',
        'footnote' => 'All times are Riyadh time. Incomplete attendance means a check-in with no check-out, and your trainer is notified automatically.',
        'empty_title' => 'Your attendance log has not started yet',
        'empty_body' => 'Every session you check in to will appear here, with your check-in and check-out times and your status.',
    ],

    'col_session' => 'Session',
    'col_date' => 'Date',
    'col_check_in' => 'Check-in time',
    'col_check_out' => 'Check-out time',
    'col_status' => 'Status',
    'col_note' => 'Note',

    'filter_status' => 'Status',

    'no_session_title' => 'No session today',
    'no_session_body' => 'The check-in and check-out buttons appear here half an hour before the next session starts.',
    'error_title' => 'Your attendance log could not be shown',
    'error_body' => 'Something went wrong while fetching your log. Try again shortly — your record is safe and unaffected.',

    /*
    |--------------------------------------------------------------------------
    | Self-check-in by code — coordinator screen (D-106)
    |--------------------------------------------------------------------------
    */

    'checkin_code' => [
        'title' => 'Self-check-in code',
        'body' => 'A participant scans this with their phone camera and is checked in immediately. The code refreshes automatically every ten minutes.',
        'link_label' => 'Or share this link',
        'closed_title' => 'No code shown right now',
        'closed_body' => 'The self-check-in code appears as soon as the session starts, and disappears an hour after it does.',
        // D-117 — a preview only looks, and the code checks in whoever holds it.
        'preview_title' => 'The check-in code is not shown in preview mode',
        'preview_body' => 'The code checks in any participant who gets it, so it is never shown while an account is being previewed. The account holder sees it when they open this screen themselves.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excuse requests — participant screen (D-106)
    |--------------------------------------------------------------------------
    */

    'exception' => [
        'request_absence_action' => 'Request an excused absence',
        'request_lateness_action' => 'Request excused lateness',
        'reason_label' => 'Reason for the request',
        'reason_placeholder' => 'Briefly state the reason for the absence or lateness…',
        'reason_hint' => 'Write a real reason in at least :min characters; it reaches whoever reviews your request.',
        'submit_action' => 'Send the request',
        'cancel_action' => 'Cancel',
        'pending_note' => 'Your request is under review.',
        'approved_note' => 'Your excuse for this record was accepted.',
        'rejected_note' => 'Your excuse was not accepted. Reason: :reason',
    ],

    /*
    |--------------------------------------------------------------------------
    | Pending excuse requests — coordinator, trainer and admin screen (D-106)
    |--------------------------------------------------------------------------
    */

    'exceptions_queue' => [
        'title' => 'Pending excuse requests',
        'col_participant' => 'Participant',
        'col_session' => 'Session',
        'col_type' => 'Type',
        'col_reason' => 'Reason',
        'col_requested_at' => 'Requested',
        'approve_action' => 'Approve',
        'reject_action' => 'Reject',
        'reject_reason_label' => 'Reason for rejection',
        'reject_reason_placeholder' => 'Explain the reason for rejecting, for the participant…',
        'reject_reason_hint' => 'A rejection reason is required, at least :min characters, and reaches the participant by notice and email.',
        'empty_title' => 'No excuse requests pending',
        'empty_body' => 'Excuse requests from any participant in this cohort appear here as soon as they are submitted.',
    ],

];
