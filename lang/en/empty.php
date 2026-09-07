<?php

/**
 * Empty-state copy, one entry per screen.
 *
 * Mirror of lang/ar/empty.php, which is the reference. Article 17 requires an
 * illustration, a title, an explanation and an action on every empty state, and
 * requires the copy to belong to that one screen — so there is no shared
 * fallback here on purpose.
 *
 * @see PRD §11 · CONSTITUTION art. 15, art. 17
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Public
    |--------------------------------------------------------------------------
    */

    'landing' => [
        'title' => 'No programme is open for registration right now',
        'body' => 'Registration opens with each new cohort. Write to us and we will tell you the moment the next one opens.',
        'action' => 'Contact Athar Centre',
    ],

    /*
    |--------------------------------------------------------------------------
    | Participant dashboard
    |--------------------------------------------------------------------------
    */

    'dashboard' => [
        'title' => 'Your board is ready and waiting for its first activity',
        'body' => 'Sessions, assignments and progress fill this board as soon as your cohort begins.',
        'action' => 'View the programme schedule',
    ],

    'journey' => [
        'title' => 'Your journey has not started yet',
        'body' => 'The ten journey steps appear here once your enrollment is approved, and each one completes automatically from your real activity.',
        'action' => 'Go to my board',
    ],

    'schedule' => [
        'title' => 'There are no sessions in your schedule yet',
        'body' => 'The session schedule is published before the cohort starts, and you will get a notification and an email the moment it is.',
        'action' => 'Go to my board',
    ],

    'attendance' => [
        'title' => 'You have no attendance record yet',
        'body' => 'Your record starts with the first session you check in to. Check-in opens thirty minutes before a session begins.',
        'action' => 'View the session schedule',
    ],

    'live' => [
        'title' => 'No session is live right now',
        'body' => 'The join link appears here automatically thirty minutes before the session starts, by the server clock in Riyadh time.',
        'action' => 'View the next session',
    ],

    'assignments' => [
        'title' => 'No assignments have been published yet',
        'body' => 'Your trainer publishes each week\'s assignments as the week begins, and you are notified when one is published and before it is due.',
        'action' => 'View the weekly schedule',
    ],

    'resources' => [
        'title' => 'The resource pack is still empty',
        'body' => 'Slides, files and links are added after each session and stay available to you until the programme ends.',
        'action' => 'View the session schedule',
    ],

    'messages' => [
        'title' => 'There are no messages in your inbox',
        'body' => 'Write to your trainer if you have a question about a session or an assignment; the reply arrives here and in your email.',
        'action' => 'Write to your trainer',
    ],

    'final-project' => [
        'title' => 'The final project is not open yet',
        'body' => 'Your trainer opens the final project in the last week. You will be notified as soon as it opens, with its brief and its deadline.',
        'action' => 'View assignments',
    ],

    'grades' => [
        'title' => 'No grades have been recorded for you yet',
        'body' => 'Your score for each assignment appears here once your trainer has marked it, together with their written feedback.',
        'action' => 'View assignments',
    ],

    'certificate' => [
        'title' => 'Your certificate has not been issued yet',
        'body' => 'A certificate is issued once both the attendance rate and the pass score are met; meeting one does not make up for the other.',
        'action' => 'View my grades',
    ],

    'card' => [
        'title' => 'Your participant card is being issued',
        'body' => 'The digital card and its verifiable code are created as soon as your enrollment is approved.',
        'action' => 'Go to my board',
    ],

    'profile' => [
        'title' => 'Your account details are incomplete',
        'body' => 'Add your full name and mobile number: they are what appears on your participant card and on your certificate.',
        'action' => 'Complete my details',
    ],

    'notifications' => [
        'title' => 'You have no notifications',
        'body' => 'Alerts for sessions, assignments, grades and your certificate arrive here as they happen, and you choose which ones reach you.',
        'action' => 'Set notification preferences',
    ],

];
