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
    'submission_intro' => 'Three items, and the submission is not complete without all of them, plus an optional idea logo.',
    'live_url' => 'A working project link',
    'live_url_hint' => 'The live web page, published and running from any device, with no errors at grading time.',
    'github_url' => 'Public GitHub repository',
    'github_url_hint' => 'The full project code pushed to GitHub, with a README explaining the project and how to run it.',
    'presentation_file' => 'Project presentation',
    'presentation_file_hint' => 'No more than 10 slides: the problem, the idea, the technology used, how it works, the results, and future development.',
    'logo_file' => 'Idea logo (optional)',
    'logo_file_hint' => 'A logo image, if your project has one — entirely optional.',
    'description_field' => 'Project description',
    'description_placeholder' => 'Explain the idea, what you built, and how to run it',
    'submit_action' => 'Submit the project',
    'submission_closed_title' => 'Submission of the final project is closed',
    'evaluation_title' => 'Your project evaluation',

    'submitted' => 'We received your project. We recorded the time and notified your trainer.',

    'errors' => [
        'not_available' => 'The final project has not been opened yet. You will be notified the moment it is.',
        'live_url_required' => 'A working project link is required.',
        'github_url_required' => 'A public GitHub repository link is required.',
        'presentation_required' => 'The presentation is required to complete the submission.',
    ],

    'error_title' => 'The final project could not be shown',
    'error_body' => 'Something went wrong while fetching it. Try again shortly — your submission is safe and unaffected.',

    'closed_deadline' => 'The final project deadline has passed.',
    'closed_locked' => 'Final project submission is not open for you right now. Contact your trainer if you think this is wrong.',

];
