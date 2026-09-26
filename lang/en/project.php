<?php

/**
 * The final project tab. Mirrors lang/ar/project.php key for key.
 * Nothing about the project reaches the browser before the trainer unlocks it.
 *
 * @see PRD §9.14 · CONSTITUTION art. 5
 */

return [

    'subtitle_locked' => 'Opens in the final week, when the programme administration makes it available',
    'subtitle_open' => 'The project brief, its grading criteria and its deadline',

    'locked_title' => 'The final project opens in its own time',
    'locked_body' => 'The programme administration opens this tab in the final week, and you get a notification and an email the moment it opens. Until then, keep going with your weekly assignments.',
    'locked_body_with_date' => 'The programme administration opens this tab around :date, and you get a notification and an email the moment it opens. Until then, keep going with your weekly assignments.',

    'requirements' => 'Requirements',
    'criteria' => 'Grading criteria',
    'criterion' => 'Criterion',
    'criterion_points' => 'Points',
    'total' => 'Total',
    'attachments' => 'Files from your trainer',
    'time_left' => 'Time left to submit',

    'criteria_empty_title' => 'The grading criteria are not published yet',
    'criteria_empty_body' => 'Your trainer adds the criteria and their points before the deadline, and they appear here.',

    'your_submission' => 'Your project submission',
    'submission_intro' => 'Fill in the items below, then submit. Items marked with an asterisk are required, and the submission is not complete without them.',
    'upload_limits' => '{1} One file at most in a single submission, :size in total.|{2} Two files at most in a single submission, :size in total.|[3,*] :count files at most in a single submission, :size in total.',
    'field_formats' => 'Accepted formats: :formats',
    'field_limits' => 'Up to :size per file · :count',
    'optional_mark' => '(optional)',
    'fields_empty_title' => 'What to hand in has not been set yet',
    'fields_empty_body' => 'The programme administration sets what the final project asks for, and it appears here the moment it does. Contact your trainer if this takes long.',
    'legacy_files_label' => 'Submitted files',
    'submit_action' => 'Submit the project',
    'submission_closed_title' => 'Submission of the final project is closed',
    'evaluation_title' => 'Your project evaluation',

    'submitted' => 'We received your project. We recorded the time, notified your trainer and sent the hand-in receipt to your e-mail.',

    'receipt' => [
        'title' => 'Final project hand-in receipt',
        'code_label' => 'Receipt code',
        'code_hint' => 'Keep this code; it is the proof that we received your project.',
        'qr_label' => 'QR code for hand-in receipt :code',
        'qr_hint' => 'Scan it to open this receipt after signing in.',
        'next_title' => 'Next step',
        'next_pending' => 'Wait for your project\'s evaluation. You get a notification and an e-mail the moment your grade is recorded.',
        'next_graded' => 'Your project has been evaluated — your grade and your trainer\'s note are in the grades tab.',
        'open' => 'View the receipt',
        'open_grading' => 'Open the hand-in in the grading panel',
        'open_project' => 'Open the final project',
        'version' => 'Version :version',
        'late' => 'Handed in after the deadline',
        'details' => [
            'project' => 'Project',
            'participant' => 'Participant',
            'submitted_at' => 'Handed in at',
            'version' => 'Version',
            'items' => 'Items handed in',
        ],
        'notice_title' => 'We received your final project — receipt code :code',
        'notice_body' => 'We recorded the time of your “:project” hand-in and told your trainer. Next step: wait for the evaluation; you get a notification and an e-mail the moment your grade is recorded.',
    ],

    'errors' => [
        'not_available' => 'The final project has not been opened yet. You will be notified the moment it is.',
        'no_fields' => 'You cannot submit yet: what to hand in has not been set for this project. Contact your trainer.',
        'field_required' => '“:field” is required to complete the submission.',
        'field_invalid' => 'What was sent for “:field” could not be read. Enter it again, then submit.',
        'field_url' => '“:field” must be a full link starting with https:// — copy it from the address bar as it is.',
        'field_github' => '“:field” must be a repository link starting with https://github.com/ — copy it from the repository page.',
        'field_too_long' => '“:field” is longer than allowed (:max characters). Shorten it, then submit again.',
        'field_file_type' => 'A file in “:field” is not in an accepted format. Accepted formats: :formats.',
        'field_file_size' => 'A file in “:field” is larger than allowed (:size). Make it smaller or compress it, then upload it again.',
        'field_file_count' => '{1} “:field” accepts one file only. Keep one file, then submit again.|[2,*] “:field” accepts :count files at most. Remove the extra ones, then submit again.',
    ],

    'default_fields' => [
        'live_url' => [
            'label' => 'A working project link',
            'description' => 'A public link that opens and works without signing in.',
            'tips' => [
                'Open it in another browser and try it before you submit.',
                'You can publish it on one of the free platforms.',
            ],
        ],
        'github_url' => [
            'label' => 'A public GitHub repository',
            'description' => 'With the project code and a README file.',
            'tips' => [
                'The README explains the problem, the solution and how to run it.',
                'No API keys and no personal data.',
            ],
        ],
        'presentation_file' => [
            'label' => 'A presentation',
            'description' => 'At most 10 slides to present your project at the closing ceremony.',
            'tips' => [
                'Put your project link on the last slide.',
            ],
        ],
        'logo_file' => [
            'label' => 'Idea logo',
            'description' => 'Your project logo, if it has one — entirely optional.',
            'tips' => [],
        ],
        'description' => [
            'label' => 'Project description',
            'description' => 'Explain your idea, what you built and how we run it.',
            'tips' => [],
        ],
    ],

    'error_title' => 'The final project could not be shown',
    'error_body' => 'Something went wrong while fetching it. Try again shortly — your submission is safe and unaffected.',

    'closed_deadline' => 'The final project deadline has passed.',
    'closed_locked' => 'Final project submission is not open for you right now. Contact your trainer if you think this is wrong.',

];
