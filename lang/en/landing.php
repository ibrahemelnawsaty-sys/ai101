<?php

declare(strict_types=1);

/*
 * Copy for the public pages: landing · terms · privacy.
 * Mirrors lang/ar/landing.php key for key. Programme content itself is not
 * here — it comes from the database and is managed from the admin panel
 * (BR-31, BR-36). What lives here is interface labels and the four states.
 *
 * @see BR-31, BR-36 · PRD §9.1
 */

return [

    'meta' => [
        'brand_alt' => 'Athar Training Centre',
        'skip_to_content' => 'Skip to content',
        'page_sections' => 'Page sections',
        'page_trail' => 'Page trail',
        'open_menu' => 'Open the navigation menu',
        'close_menu' => 'Close the navigation menu',
    ],

    'nav' => [
        'about' => 'About the programme',
        'lab' => 'Try the training',
        'learn' => 'What you will learn',
        'goals' => 'Objectives',
        'timeline' => 'Timeline',
        'certificates' => 'Certificates',
        'faq' => 'FAQ',
        'login' => 'Sign in',
        'register' => 'Register now',
    ],

    'hero' => [
        'register' => 'Register now',
        'try_lab' => 'Train a model right now',
        'countdown_title' => 'Registration closes in',
        'days' => 'days',
        'hours' => 'hours',
        'minutes' => 'minutes',
        'seconds' => 'seconds',
        'seats_label' => 'Seats remaining',
        'seats_of' => 'of',
        'seats_progress' => 'Share of seats taken',
        'registration_open' => 'Registration is open',
        'registration_closed_title' => 'Registration for this cohort has closed',
        'registration_closed_body' => 'Leave your email and we will write to you as soon as the next cohort opens, before it is announced publicly.',
        'waitlist_email' => 'Your email address',
        'waitlist_email_placeholder' => 'name@example.com',
        'waitlist_submit' => 'Tell me about the next cohort',
        'waitlist_done' => 'We have your address. We will write to you as soon as the next cohort opens.',
    ],

    /* The interactive lab: a linear classifier trained in the browser. */
    'lab' => [
        'kicker' => 'Week one in half a minute',
        'title' => 'Teach the model yourself — right now',
        'lead' => 'Click inside the canvas to place examples. The model draws the decision boundary between them instantly and works out its accuracy. This is exactly what you do in week one, but with real data.',
        'canvas_label' => 'Interactive training canvas: click to add an example to the selected class',
        'hint' => 'Click inside the canvas to add examples — switch class on the right',
        'class_prompt' => 'The class you are placing now',
        'class_a' => 'First class',
        'class_b' => 'Second class',
        'accuracy' => 'Model accuracy',
        'examples' => 'Examples',
        'epochs' => 'Training epochs',
        'note' => 'This is a simple linear classifier trained in your browser — no server, no library. Try overlapping the two classes and watch the accuracy fall: that is the first lesson about the limits of a model.',
        'seed' => 'Load an example',
        'clear' => 'Clear everything',
        'unavailable' => 'Your browser does not support this interactive canvas. It affects neither your registration nor the programme itself.',
        'add_a' => 'Add an example to the first class',
        'add_b' => 'Add an example to the second class',
    ],

    /* Certificate simulator — applies BR-11 and BR-26 with server-supplied values. */
    'sim' => [
        'kicker' => 'Before you register',
        'title' => 'Will you earn the certificate?',
        'lead' => 'Move the sliders honestly. The maths here is the same the platform uses — no estimates, no rounding.',
        'q_sessions' => 'How many sessions will you attend?',
        'q_tasks' => 'How many assignments will you submit?',
        'q_project' => 'Your estimate for the final project score',
        'aria_sessions' => 'Number of sessions you will attend',
        'aria_tasks' => 'Number of assignments you will submit',
        'aria_project' => 'Your expected score in the final project',
        'gate_attendance' => 'First condition — attendance rate',
        'gate_score' => 'Second condition — final score',
        'min_attendance' => 'Minimum',
        'pass_score' => 'Pass mark',
        'explainer' => 'The assignments and the final project split the total score between them. The certificate requires both conditions, so passing one alone is not enough.',

        // Read by JavaScript from a JSON island — no Arabic inside any JS file
        'js' => [
            'spare_none' => 'Exactly on the line — one more absence loses the condition',
            'spare_some' => 'You can still miss :sessions and keep the condition',
            'need_more' => 'You need :sessions more to reach :min%',
            'score_breakdown' => 'Assignments :tasks + project :project',
            'score_above' => 'Above the pass mark of :pass',
            'score_below' => 'You are :missing points short',
            'verdict_pass_title' => 'You meet the certificate conditions',
            'verdict_pass_body' => 'At that pace both certificates are issued automatically after the closing ceremony, with a score of :total of :max and an attendance rate of :rate%.',
            'verdict_none_title' => 'Not enough — neither condition is met',
            'verdict_none_body' => 'Attendance and score are both below the line. The programme is only four weeks long, and showing up is everything.',
            'verdict_attendance_title' => 'Your score is enough, your attendance is not',
            'verdict_attendance_body' => 'Your score of :total is above the pass mark, but attendance of :rate% is below :min%. Both conditions apply together — one never compensates for the other.',
            'verdict_score_title' => 'Your attendance is enough, your score is not',
            'verdict_score_body' => 'Attendance of :rate% is excellent, but your total of :total is below the pass mark of :pass. Attendance alone does not earn a certificate.',
            'session_one' => 'one session',
            'session_two' => 'two sessions',
            'session_few' => ':count sessions',
            'session_many' => ':count sessions',
            'model_accuracy' => 'Model accuracy',
            'model_stage_start' => 'Training begins',
            'model_stage_learn' => 'Learning the patterns',
            'model_stage_tune' => 'Tuning the weights',
            'model_stage_settle' => 'Close to settling',
            'model_stage_ready' => 'The model is ready',
        ],
    ],

    'headings' => [
        'about' => [
            'kicker' => 'About the programme',
        ],
        'goals' => [
            'kicker' => 'Objectives',
            'title' => 'What you will master',
            'lead' => 'Objectives stated before you start, measured by the final project rather than by impression.',
        ],
        'audience' => [
            'kicker' => 'Audience',
            'title' => 'Who this programme is for',
            'lead' => 'The groups the content was designed for.',
        ],
        'weeks' => [
            'kicker' => 'Modules',
            'title' => 'The four-week plan',
        ],
        'timeline' => [
            'kicker' => 'Schedule',
            'title' => 'Cohort timeline',
            'lead' => 'Week dates exactly as fixed for the current cohort.',
        ],
        'certificates' => [
            'kicker' => 'Certificates',
            'title' => 'What you receive',
            'lead' => 'Awarded once the passing conditions are met.',
        ],
        'trainers' => [
            'kicker' => 'Trainers',
            'title' => 'Who will train you',
            'lead' => 'The team assigned to this cohort.',
        ],
        'faq' => [
            'kicker' => 'Frequently asked',
            'title' => 'Common questions',
        ],
        'final' => [
            'eyebrow' => 'Next step',
            'title' => 'Start here',
            'body' => 'If you have a question before deciding, the FAQ is above and the contact channel is open.',
        ],
    ],

    'sections' => [
        'date_range' => ':from — :to',
        'deck_hint' => 'Scroll to move between the weeks',
        'week_progress' => 'Week progress',
        'sessions_count' => 'live sessions',
        'sessions_choice' => '{0} no live sessions|{1} one live session|[2,*] :count live sessions',
        'seats_choice' => '{0} no seats left|{1} one seat left|[2,*] :count seats left',
        'certificate_flip_hint' => 'Hover to see the details',
        'trainers_note' => 'Trainer details are managed from the admin panel',
        'faq_contact' => 'If your question is not answered here, write to us at',
    ],

    'final' => [
        'register' => 'Register now — free',
        'ask' => 'I have a question first',
        'seats_booked_suffix' => 'seats taken',
    ],

    'footer' => [
        'quick_links' => 'Quick links',
        'contact' => 'Contact us',
        'terms' => 'Terms and Conditions',
        'privacy' => 'Privacy Policy',
        'rights' => 'Athar Training Centre. All rights reserved.',
        'whatsapp' => 'Message us on WhatsApp',
        'whatsapp_message' => 'Hello, I have a question about :program.',
    ],

    'waitlist' => [
        'acknowledged' => 'We have your address. We will write to you as soon as the next cohort opens, before it is announced publicly.',
        'already_registered' => 'Your address is already on our list, and we will write to you as soon as the next cohort opens.',
    ],

    /* The four mandatory states (CONSTITUTION art. 17) */
    'states' => [
        'loading_label' => 'Loading the page content',
        'empty_page_title' => 'No programme has been published yet',
        'empty_page_body' => 'Programme content is managed from the admin panel and has not been published yet. Come back shortly, or write to us and we will tell you the moment registration opens.',
        'empty_page_action' => 'Write to us',
        'error_title' => 'The page content could not be shown',
        'error_body' => 'A temporary problem got in the way of loading the programme content. Refresh the page in a moment, and contact us if it keeps happening.',
        'error_action' => 'Refresh the page',
        'empty_goals' => 'The programme objectives have not been added yet.',
        'empty_audience' => 'The target audience has not been defined yet.',
        'empty_weeks' => 'The content of the weeks has not been added yet.',
        'empty_timeline' => 'The timeline for this cohort has not been set yet.',
        'empty_certificates' => 'Certificate details have not been added yet.',
        'empty_trainers' => 'No trainers have been assigned to this cohort yet.',
        'empty_faq' => 'No questions have been added yet. Write to us with yours and we will answer it.',
        'empty_trust' => 'The programme indicators have not been added yet.',
    ],

    'legal' => [
        'terms_title' => 'Terms and Conditions',
        'privacy_title' => 'Privacy Policy',
        'updated_at' => 'Last updated',
        'back_home' => 'Back to the home page',
        'empty_title' => 'This page is being prepared',
        'empty_body' => 'Its text has not been published yet. For any question about the terms or privacy, write to us and we will answer directly.',
        'error_title' => 'This page could not be shown',
        'error_body' => 'A temporary problem got in the way of loading the text. Refresh the page in a moment, and contact us if it keeps happening.',
    ],

];
