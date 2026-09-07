<?php

/**
 * The ten journey steps. Mirrors lang/ar/journey.php key for key.
 * A step completes from real data only — never by hand (BR-21).
 *
 * @see BR-20, BR-21, BR-31 · PRD §9.7 · PROJECT-CONTRACT §9
 */

return [

    'subtitle' => 'Ten steps that complete themselves from your record',
    'auto_only' => 'There is no manual tick — every step completes from your attendance and your submissions.',
    'completed_of' => 'You have completed :completed of :total steps',
    'show_details' => 'Show step details',

    'progress' => [
        'title' => 'Journey progress',
        'completed_steps' => 'steps completed',
        'current_step' => 'Your current step: :step',
    ],

    'status' => [
        'completed' => 'Completed',
        'current' => 'Current step',
        'locked' => 'Upcoming',
    ],

    'steps' => [
        1 => [
            'title' => 'Registration in the programme',
            'rule' => 'Completes automatically once your account is activated and you are enrolled.',
        ],
        2 => [
            'title' => 'Attending the orientation session',
            'rule' => 'Completes when you check in to the orientation session.',
            'action' => 'Go to attendance',
        ],
        3 => [
            'title' => 'Week one and its assignment',
            'rule' => 'Completes by attending the sessions of the week and submitting all its mandatory assignments.',
            'action' => 'Go to assignments',
        ],
        4 => [
            'title' => 'Week two and its assignment',
            'rule' => 'Completes by attending the sessions of the week and submitting all its mandatory assignments.',
            'action' => 'Go to assignments',
        ],
        5 => [
            'title' => 'Week three and its assignment',
            'rule' => 'Completes by attending the sessions of the week and submitting all its mandatory assignments.',
            'action' => 'Go to assignments',
        ],
        6 => [
            'title' => 'Week four and its assignment',
            'rule' => 'Completes by attending the sessions of the week and submitting all its mandatory assignments.',
            'action' => 'Go to assignments',
        ],
        7 => [
            'title' => 'Submitting the final project',
            'rule' => 'Completes when you submit your final project.',
            'action' => 'Go to the final project',
        ],
        8 => [
            'title' => 'Grading of the final project',
            'rule' => 'Completes when a score is recorded for your final project.',
            'action' => 'Go to grades',
        ],
        9 => [
            'title' => 'The closing ceremony',
            'rule' => 'Completes when you check in to the closing ceremony.',
            'action' => 'Go to the schedule',
        ],
        10 => [
            'title' => 'Programme completion certificate',
            'rule' => 'Issued once both the attendance rate and the pass mark are met.',
            'action' => 'Go to the certificate',
        ],
    ],

    'empty_title' => 'Your journey has not started yet',
    'empty_body' => 'Your journey steps are built automatically once your account is activated and you are enrolled.',
    'error_title' => 'Your journey could not be shown',
    'error_body' => 'Something went wrong while working out your steps. Try again shortly — your progress is safe and unaffected.',

];
