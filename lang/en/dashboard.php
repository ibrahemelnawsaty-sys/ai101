<?php

/**
 * Dashboard home. Mirrors lang/ar/dashboard.php.
 *
 * @see PRD §9.5.3 · CONSTITUTION art. 17
 */

return [

    'greeting' => [
        'morning' => 'Good morning, :name',
        'afternoon' => 'Good afternoon, :name',
        'evening' => 'Good evening, :name',
    ],

    'of_journey' => 'of your journey',

    'next_session' => [
        'title' => 'Next session',
        'starts_in' => 'Starts in',
        'join' => 'Join the session',
        'join_locked' => 'Joining opens shortly before the session starts',
        'link_hint' => 'The join button opens :minutes minutes before the session starts, and the link is not sent to your browser before then.',
        'empty_title' => 'No upcoming session',
        'empty_body' => 'Nothing is scheduled yet. The date and a live countdown will appear here as soon as it is.',
    ],

    'status_line' => [
        'not_started' => 'Your journey has not started yet. Your steps appear here once you join a cohort.',
        'in_progress' => 'You have completed :completed of :total steps.',
        'completed' => 'You have completed every step of your journey. Congratulations.',
    ],

];
