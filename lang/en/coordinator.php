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

    /*
     * D-127 — the coordinator's final-project tab.
     */
    'final_project' => [
        'title' => 'Final project',
        'intro' => 'The general supervisor enters the project and its guide and makes them available; the cohort\'s primary coordinator then publishes them to the trainees. Other coordinators and the trainers only read.',
        'no_cohort_title' => 'No cohort assigned to you yet',
        'no_cohort_body' => 'Your cohort\'s final project appears here once one is assigned to you.',
        'no_project_title' => 'This cohort has no final project yet',
        'no_project_body' => 'The general supervisor enters it from the admin panel; it appears here for you to publish once made available.',
        'error_title' => 'The final project could not be shown',
        'error_body' => 'Something went wrong while loading the project. Try again in a moment.',
        'summary_title' => 'The project',
        'due' => 'Hand-in deadline: :date',
        'states' => [
            'not_available' => 'Not made available yet',
            'available' => 'Available — waiting for you to publish',
            'published' => 'Published to the trainees',
        ],
        'guide_states' => [
            'not_available' => 'Not made available yet',
            'available' => 'Available — waiting for you to publish',
            'published' => 'Published',
        ],
        'published_note' => ':name published it on :date',
        'hand_ins' => '{0} No hand-ins yet|{1} One hand-in|[2,*] :count hand-ins',
        'primary_only' => 'Publishing and unpublishing belong to the cohort\'s primary coordinator alone. You can read here.',
        'no_primary' => 'No primary coordinator has been chosen for this cohort, so nothing can be published until the general supervisor chooses one.',
        'waiting_available' => 'It cannot be published before the general supervisor makes it available.',
        'publish' => 'Publish the project to the trainees',
        'unpublish' => 'Unpublish the project',
        'confirm_title' => 'Unpublishing the project',
        'confirm_body' => ':count arrived from the trainees. Unpublishing locks the project for them — they no longer see its page or hand in — and earlier hand-ins are kept for the trainer to grade.',
        'confirm_action' => 'Yes, unpublish it',
        'guide_title' => 'Project guide',
        'guide_intro' => 'Each language is published on its own, and English never before Arabic. A trainee sees it once both the guide and the project are published.',
        'guide_view' => 'Read the guide',
        'guide_publish' => 'Publish the guide',
        'guide_unpublish' => 'Unpublish the guide',
        'guide_needs_arabic' => 'Publish the Arabic guide first.',
        'published' => 'The project was published; the trainees were sent a notice and an e-mail.',
        'unpublished' => 'The project was unpublished. Earlier hand-ins are kept.',
        'guide_published' => 'The guide was published.',
        'guide_unpublished' => 'The guide was unpublished.',
        'unchanged' => 'Nothing changed — it already was as you asked.',
        'errors' => [
            'not_available' => 'The general supervisor withdrew the project before you published it, so it was not published. Reload the page.',
            'guide_not_available' => 'The general supervisor withdrew the guide before you published it, so it was not published. Reload the page.',
            'needs_arabic' => 'The English guide is never published before the Arabic one. Publish the Arabic guide first.',
            'confirm_unpublish' => 'The project has hand-ins. Confirm unpublishing from the confirmation message.',
        ],
    ],

];
