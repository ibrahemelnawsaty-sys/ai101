<?php

/**
 * Transactional email copy. Mirrors lang/ar/emails.php key for key.
 * Templates are RTL Arabic HTML with externally hosted images — never inline
 * base64, which Gmail and Outlook strip (PRD §9.16).
 *
 * @see BR-29, BR-30, BR-36 · PRD §9.2.3, §9.3.3, §9.16, §9.16.1
 */

return [

    'common' => [
        'greeting' => 'Hello :name,',
        'greeting_neutral' => 'Hello,',
        'sign_off' => 'The Athar Training Centre team',
        'tagline_note' => ':program — cohort :cohort',
        // A LABEL for the detail strip, not a sentence.
        'cohort_label' => 'Cohort',
        'view_in_platform' => 'Open the platform',
        'contact_line' => 'For any question, write to us at :email',
        'why_receiving' => 'You are receiving this because you are enrolled in :program.',
        'preferences_link' => 'You can choose what reaches you from your account settings.',
        'security_notice' => 'This is a security message about your account, and it always reaches you.',
        'link_fallback' => 'If the button does not work, copy this link into your browser:',
        'rights' => 'All rights reserved to Athar Training Centre.',
        'time_note' => 'All times are Riyadh time.',
    ],

    /*
     * The invitation. Key parity with lang/ar/emails.php is enforced by
     * tests/Feature/Mail/LetterContractTest.php.
     */
    'invitation' => [
        'subject' => 'Your account for :program is ready',
        'preheader' => 'Your sign-in details for :program, and one step between you and the start.',
        'heading' => 'Welcome to Athar',
        'body' => 'An account has been created for you on :program, in :cohort, and your place is confirmed. Your timetable, your assignments, the programme material and your digital card are waiting for you inside.',
        'program_label' => 'Programme',
        'credentials_title' => 'Sign-in details',
        'email_label' => 'E-mail address',
        'password_label' => 'Temporary password',
        'temporary_note' => 'This is a one-time temporary password. You will be asked to set your own the moment you first sign in. It stops working on :date.',
        'cta' => 'Sign in to Athar',
        'next_steps' => 'If it stops working before you have used it, choose "Forgot your password" on the sign-in page and a fresh link reaches you straight away.',
        'not_you' => 'You received this because the programme administration created an account for this address. If you were not expecting it, do not use the password and tell us at :email.',
    ],
    'verify' => [
        'subject' => 'Activate your account on the Athar platform',
        'preheader' => 'The activation link is valid for 24 hours and works once.',
        'heading' => 'One step and your registration is complete',
        'body' => 'Thank you for registering in :program. Press the button below to activate your account, and we will create your digital card and build your journey steps straight away.',
        'cta' => 'Activate my account',
        'expiry_note' => 'The link is valid for 24 hours from the time it was sent, and works once only.',
        'ignore_note' => 'If you did not ask to register, ignore this message and no account will be created.',
    ],

    'welcome' => [
        'subject' => 'Welcome to :program',
        'preheader' => 'A summary of your programme and the date of your orientation session.',
        'heading' => 'Welcome. Your seat is reserved',
        'body' => 'Your account is active and you are enrolled in cohort :cohort. Your dashboard holds the session schedule, your digital card, and your ten journey steps.',
        'intro_session' => 'Orientation session: :datetime',
        'cta' => 'Open my dashboard',
    ],

    'password_reset' => [
        'subject' => 'Set a new password',
        'preheader' => 'The link is valid for 30 minutes and works once.',
        'heading' => 'A request to set a new password',
        'body' => 'We received a request to set a new password for your account. Press the button below to choose one.',
        'cta' => 'Set a new password',
        'expiry_note' => 'The link is valid for 30 minutes, and works once only.',
        'ignore_note' => 'If you did not ask for this, ignore the message and nothing about your account changes.',
    ],

    'password_changed' => [
        'subject' => 'Your account password was changed',
        'preheader' => 'If this was not you, write to us immediately.',
        'heading' => 'Password changed',
        'body' => 'Your account password was changed at :datetime, and every active session on your devices was ended.',
        'not_you' => 'If this was not you, write to us immediately at :email.',
    ],

    'new_device_login' => [
        'subject' => 'A sign-in from a new device',
        'preheader' => 'Check the details to make sure it was you.',
        'heading' => 'A new sign-in to your account',
        'body' => 'Someone signed in to your account at :datetime from the address :ip.',
        'not_you' => 'If this was not you, change your password immediately and write to us at :email.',
        'cta' => 'Change my password',
    ],

    'email_change' => [
        'subject' => 'Confirm your new email address',
        'preheader' => 'Nothing changes until you open this link.',
        'heading' => 'Confirm the new address',
        'body' => 'You asked to change the email on your account to this address. Press the button to confirm the change.',
        'cta' => 'Confirm the new address',
        'expiry_note' => 'The link is valid for 24 hours and works once.',
    ],

    'enrollment_approved' => [
        'subject' => 'Your enrolment in cohort :cohort was approved',
        'preheader' => 'Your seat is reserved. Here is what comes next.',
        'heading' => 'Your request was approved',
        'body' => 'The team approved your enrolment in cohort :cohort. Open your dashboard for the schedule and your digital card.',
        'cta' => 'Open my dashboard',
    ],

    'enrollment_rejected' => [
        'subject' => 'About your enrolment request for :program',
        'preheader' => 'The details of the decision and what you can do next.',
        'heading' => 'The enrolment request was not approved',
        'body' => 'We reviewed your request and could not approve it for this cohort. Reason: :reason',
        'next_steps' => 'We would be glad to receive your request for the next cohort, and you are welcome to write to us at :email with any question.',
    ],

    'session_reminder' => [
        'subject' => 'Reminder: session :session :when',
        'preheader' => 'Session details and the join link are in your dashboard.',
        'heading' => 'Your next session',
        'body' => 'Session «:session» starts at :datetime Riyadh time with :trainer.',
        'cta' => 'Open the live sessions tab',
        'when_24h' => 'tomorrow',
        'when_1h' => 'in an hour',
    ],

    'session_changed' => [
        'subject' => 'Session :session has moved',
        'preheader' => 'Check the new time in the schedule.',
        'heading' => 'An update to your cohort schedule',
        'body' => 'Session «:session» has moved. Reason: :reason',
        'replacement' => 'Replacement date: :datetime',
        'cta' => 'Open the schedule',
    ],

    'assignment_published' => [
        'subject' => 'New assignment: :assignment',
        'preheader' => 'The deadline is :datetime.',
        'heading' => 'A new assignment has been published',
        'body' => 'Your trainer published «:assignment», worth up to :max. The deadline is :datetime.',
        'cta' => 'Open the assignment',
    ],

    'assignment_due_reminder' => [
        'subject' => 'The deadline for :assignment is near',
        'preheader' => ':countdown left before submission closes.',
        'heading' => 'A reminder about the deadline',
        'body' => 'We have not received your submission for «:assignment» yet, and :countdown remains before the deadline.',
        'cta' => 'Submit the assignment',
    ],

    'grade_recorded' => [
        'subject' => 'Your score for :item was recorded',
        'preheader' => 'The score and the trainer feedback are in your dashboard.',
        'heading' => 'Your score has arrived',
        'body' => 'Your trainer recorded :score of :max for «:item», with full written feedback.',
        'cta' => 'Read the feedback',
    ],

    'grade_revised' => [
        'subject' => 'Your score for :item was revised',
        'preheader' => 'The new score and the reason for the change.',
        'heading' => 'A change to your score',
        'body' => 'Your score for «:item» is now :score of :max. Reason for the change: :reason',
        'cta' => 'View the grades',
    ],

    'final_project_unlocked' => [
        'subject' => 'The final project is open',
        'preheader' => 'The brief and the grading criteria are available now.',
        'heading' => 'The final project is available now',
        'body' => 'The final project tab is open in your dashboard. Read the brief and the grading criteria. The deadline is :datetime.',
        'cta' => 'Open the final project',
    ],

    'announcement' => [
        'subject' => 'A new announcement in :program',
        'preheader' => ':excerpt',
        'heading' => 'An announcement from the programme team',
        'cta' => 'Read the announcement',
    ],

    'message_received' => [
        'subject' => 'New message from :name',
        'preheader' => ':excerpt',
        'heading' => 'You have a new message',
        'body' => ':name sent a message in :thread.',
        'cta' => 'Open the conversation',
    ],

    'attendance_low' => [
        'subject' => 'Your attendance has dropped below the required rate',
        'preheader' => 'There is still time to bring it back up.',
        'heading' => 'A note about your attendance',
        'body' => 'Your attendance is now :current% and the certificate requires :required%. Attending the coming sessions raises it.',
        'cta' => 'View my attendance log',
    ],

    'attendance_low_final' => [
        'subject' => 'Your attendance ended below the required rate',
        'preheader' => 'No sessions remain in this programme.',
        'heading' => 'A note about your attendance',
        'body' => 'Your attendance is :current% and the certificate requires :required%. No sessions remain in this programme. If an absence was excused, raise it with your trainer.',
        'cta' => 'View my attendance log',
    ],

    'session_cancelled' => [
        'subject' => 'Session :session has been cancelled',
        'preheader' => 'The reason is inside.',
        'heading' => 'A session in your cohort was cancelled',
        'body' => 'Session «:session» has been cancelled. Reason: :reason. Any replacement will appear in your schedule.',
        'cta' => 'Open the schedule',
    ],

    'certificate_issued' => [
        'subject' => 'Your certificate from Athar has been issued',
        'preheader' => 'Download it and share the verification link.',
        'heading' => 'Congratulations on completing the programme',
        'body' => 'You met both the attendance and the score condition, and your certificate has been issued with serial number :serial.',
        'cta' => 'Open my certificate',
    ],

    'waitlist_confirmation' => [
        'subject' => 'We will write to you when the next cohort opens',
        'preheader' => 'Your address is on the waiting list.',
        'heading' => 'We have your address',
        'body' => 'Registration for this cohort has closed. We will write to you as soon as the next one opens, before it is announced publicly.',
    ],

];
