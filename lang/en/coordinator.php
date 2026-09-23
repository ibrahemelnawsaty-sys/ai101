<?php

/**
 * The coordinator's own screens. Mirrors lang/ar/coordinator.php key for key.
 *
 * @see D-105, D-106, D-109 · CONSTITUTION Art. 6, Art. 17
 */

return [

    'dashboard' => [
        'title' => 'Your cohort dashboard',
        'error_title' => 'Could not load the dashboard',
        'error_body' => 'Something went wrong while loading your dashboard data. Try again, and contact support if it keeps happening.',
        'stat_participants' => 'Cohort participants',
        'stat_pending_exceptions' => 'Pending exception requests',

        'next_session_title' => 'Next session',
        'next_session_empty_title' => 'No upcoming session',
        'next_session_empty_body' => 'No upcoming session is scheduled for your cohort yet. It will appear here once one is set.',
        'next_session_no_location' => 'Location not set yet',
        'next_session_no_link' => 'Meeting link not set yet',

        'attention_queue_title' => 'Sessions needing their details completed',
        'attention_queue_empty_title' => 'Every upcoming session is fully set up',
        'attention_queue_empty_body' => 'No upcoming session is missing a link or a location right now.',
        'attention_queue_online' => 'Online session, no link yet',
        'attention_queue_in_person' => 'In-person session, no location yet',
        'attention_queue_action' => 'Complete its details',
    ],

];
