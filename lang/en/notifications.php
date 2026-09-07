<?php

/**
 * The notification centre and preference table. Mirrors
 * lang/ar/notifications.php key for key.
 *
 * @see PRD §9.16, §9.16.1, §9.4.1
 */

return [

    'subtitle' => 'Everything about your sessions, assignments and grades in one place',

    'mark_all_read' => 'Mark everything as read',
    'unread' => 'Unread',
    'all_read' => 'All your notifications are marked as read.',
    'filter_type' => 'Notification type',
    'filter_state' => 'Read state',

    'preferences_title' => 'Notification settings',
    'event' => 'Event',
    'channel_platform' => 'In the platform',
    'channel_email' => 'By email',
    'toggle_aria' => ':event via :channel',
    'preferences_empty_title' => 'No settings to show',
    'preferences_empty_body' => 'Notification options appear once you are enrolled in a cohort. Security notices always reach you, to protect your account.',

    'attendance' => [
        'incomplete' => [
            'title' => 'Incomplete attendance for :name',
            'body' => 'They checked in to session :session and did not check out before the window closed.',
        ],
        'incomplete_summary' => [
            'title' => 'Incomplete attendance in session :session',
            'body' => ':count participants checked in and did not check out before the window closed. Open the session record to review them.',
        ],
    ],

    'types' => [
        'session_reminder' => [
            'label' => 'Session reminder',
            'title' => 'Session :session starts soon',
            'body' => 'It starts :datetime Riyadh time. Join from the live sessions tab.',
        ],
        'session_started' => [
            'label' => 'Session started',
            'title' => 'Session :session has started',
            'body' => 'The session is live now. Check in and join it.',
        ],
        'session_changed' => [
            'label' => 'Session moved or cancelled',
            'title' => 'Session :session has moved',
            'body' => 'Check the schedule for the new time and the reason for the change.',
        ],
        'session_cancelled' => [
            'label' => 'Session cancelled',
            'title' => 'Session :session was cancelled',
            'body' => 'Reason: :reason. You will be notified once a replacement date is set.',
        ],
        'assignment_published' => [
            'label' => 'New assignment published',
            'title' => 'New assignment: :assignment',
            'body' => 'The deadline is :datetime. Open it to see the requirements.',
        ],
        'assignment_due_reminder' => [
            'label' => 'Assignment deadline reminder',
            'title' => 'The deadline for :assignment is near',
            'body' => ':countdown left before submission closes. Submit before the deadline to count as on time.',
        ],
        'submission_received' => [
            'label' => 'Submission received',
            'title' => 'We received your submission for :assignment',
            'body' => 'We recorded the time and notified your trainer. Your score and feedback arrive once it is graded.',
        ],
        'submission_new' => [
            'label' => 'New submission arrived',
            'title' => 'New submission from :name',
            'body' => 'A submission for :assignment is waiting for your grading.',
        ],
        'grade_recorded' => [
            'label' => 'Score recorded',
            'title' => 'Your score for :item was recorded',
            'body' => 'You scored :score of :max. Open the grades tab to read the full feedback.',
        ],
        'grade_revised' => [
            'label' => 'Score revised',
            'title' => 'Your score for :item was revised',
            'body' => 'It is now :score of :max. Reason: :reason',
        ],
        'final_project_unlocked' => [
            'label' => 'Final project unlocked',
            'title' => 'The final project is open',
            'body' => 'Read the brief and the grading criteria. The deadline is :datetime.',
        ],
        'resource_added' => [
            'label' => 'New resource in the pack',
            'title' => 'New resource: :resource',
            'body' => 'A new resource was added to the training pack.',
        ],
        'announcement_published' => [
            'label' => 'New announcement',
            'title' => 'A new announcement from the programme team',
            'body' => ':excerpt',
        ],
        'message_received' => [
            'label' => 'New message',
            'title' => 'New message from :name',
            'body' => ':excerpt',
        ],
        'attendance_low' => [
            'label' => 'Attendance dropped',
            'title' => 'Your attendance rate has dropped',
            'body' => 'You are at :current% and the certificate requires :required%. Make sure you attend the coming sessions.',
        ],
        'attendance_incomplete' => [
            'label' => 'Incomplete attendance',
            'title' => 'Incomplete attendance for :name',
            'body' => 'They checked in to session :session and did not check out before the window closed.',
        ],
        'certificate_issued' => [
            'label' => 'Certificate issued',
            'title' => 'Your certificate has been issued',
            'body' => 'Congratulations on completing the programme. Download it and share the verification link from the certificate tab.',
        ],
        'enrollment_approved' => [
            'label' => 'Enrolment approved',
            'title' => 'Your enrolment has been approved',
            'body' => 'Welcome. Open your dashboard for the schedule and your journey steps.',
        ],
        'enrollment_rejected' => [
            'label' => 'Enrolment request declined',
            'title' => 'Your enrolment request was not approved',
            'body' => 'Reason: :reason. You are welcome to contact us with any question.',
        ],
    ],

    'empty_title' => 'No new notifications',
    'empty_body' => 'Alerts about sessions, assignments, grades and everything else in your programme arrive here.',
    'no_match_title' => 'No notifications match the filters',
    'no_match_body' => 'Change the type or the read state to see the rest.',
    'error_title' => 'Your notifications could not be shown',
    'error_body' => 'Something went wrong while fetching them. Try again shortly — nothing has been lost.',

];
