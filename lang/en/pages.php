<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
|  Secondary public pages
|--------------------------------------------------------------------------
|
|  About · Contact · Programme directory.
|
|  English is structural parity only: the audience is Saudi and Arabic is the
|  shipped locale (D-19). Keys exist so a missing-key report stays meaningful
|  and so the day a second locale is switched on nothing has to be invented.
|
|  Same deliberate limit as the Arabic file: nothing here claims anything about
|  the centre that the repository does not already state. Founding dates,
|  graduate counts, partners and achievements are the centre's to supply - D-14.
|
|  Contact details are NOT here. They come from config/athar.php <- .env (BR-36).
|
*/

return [

    'shared' => [
        'back_home' => 'Back to the home page',
    ],

    'about' => [
        'title' => 'About Athar',
        'meta_description' => 'Athar centre and its training platform.',
        'lead' => 'The training platform of Athar centre: one place holding the programme, its materials, its sessions, its attendance, its assessment and its certificate.',

        'sections' => [
            [
                'heading' => 'What this platform is',
                'paragraphs' => [
                    'A platform that runs the whole training programme end to end: the schedule, the live sessions, attendance check-in, assignments and their submissions, the resource library, the final project, the grades, then the certificate and a participant card verifiable through a public link.',
                    'Everything a participant sees about their own progress is computed on the server from their own records - never typed in by hand and never estimated.',
                ],
            ],
            [
                'heading' => 'How we work',
                'paragraphs' => [
                    'Three principles govern how this platform is built, and each one shows on every screen:',
                ],
                'items' => [
                    'Server time is the only reference - attendance windows are never measured by a browser clock.',
                    'A certificate is issued on conditions stated in advance and computed, not on a judgement call.',
                    'A participant\'s files are served only behind a permission check and a short-lived link.',
                ],
            ],
        ],

        'empty_title' => 'This page has no content yet',
        'empty_body' => 'The centre profile is managed from the admin panel and has not been filled in yet. Write to us in the meantime and we will answer any question.',
        'error_title' => 'This page could not be displayed',
        'error_body' => 'Something went wrong while loading the content. Refresh in a moment, and write to us if it keeps happening.',
    ],

    'contact' => [
        'title' => 'Contact us',
        'meta_description' => 'How to reach Athar centre: WhatsApp and email.',
        'lead' => 'Pick whichever channel suits you and we will get back to you.',

        'whatsapp_label' => 'WhatsApp',
        'whatsapp_hint' => 'Fastest for short questions about the programme and registration.',
        'whatsapp_action' => 'Open a chat',
        'whatsapp_prefill' => 'Hello, I have a question about the AI foundation programme.',

        'email_label' => 'Email',
        'email_hint' => 'For anything needing attachments or detail.',
        'email_action' => 'Send a message',

        'faq_label' => 'Your answer may already be there',
        'faq_hint' => 'Common questions about the programme, registration and the certificate.',
        'faq_action' => 'Go to the FAQ',

        'form_unavailable_title' => 'There is no contact form on this page.',
        'form_unavailable_body' => 'The channels above are what is available today, and every one of them reaches us immediately. We chose them over a form that might not arrive.',
    ],

    'programs' => [
        'title' => 'Programme directory',
        'meta_description' => 'Training programmes published by Athar centre.',
        'lead' => 'Programmes published right now. Each has its own page, details and certificate conditions.',

        'open_action' => 'Details and registration',
        'objectives_label' => 'Key objectives',
        'audience_label' => 'Who it is for',
        'cohorts_choice' => '{0} no open cohorts|{1} one cohort|[2,*] :count cohorts',

        'empty_title' => 'No programme has been published yet',
        'empty_body' => 'Programmes are published from the admin panel and none has been published so far. Come back shortly, or write to us and we will tell you as soon as the first one is live.',
        'empty_action' => 'Write to us',
        'error_title' => 'The programme list could not be displayed',
        'error_body' => 'Something went wrong while fetching the programmes. Refresh in a moment, and write to us if it keeps happening.',
        'error_action' => 'Refresh the page',
    ],

];
