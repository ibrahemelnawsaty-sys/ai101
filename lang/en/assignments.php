<?php

/**
 * Performance assignments. Mirrors lang/ar/assignments.php key for key.
 *
 * @see BR-15 … BR-19 · PRD §9.11
 */

return [

    'subtitle' => 'The assignments of the four weeks and their deadlines',

    'count' => '{0} no assignments this week|{1} one assignment|[2,*] :count assignments',

    'summary' => [
        'mandatory_done' => 'Mandatory assignments completed',
        'score_so_far' => 'Assignment points so far',
        'pending' => 'Assignments waiting for you',
    ],

    'mandatory' => 'Mandatory',
    'optional' => 'Optional',
    'late' => 'Late',
    'deadline' => 'Deadline',
    'requirements' => 'Requirements',
    'trainer_attachments' => 'Files from your trainer',
    'submit_now' => 'Submit now',

    'your_submission' => 'Your submission',
    'current_submission' => 'Version :version of your submission',
    'versions_title' => 'Your earlier versions',
    'resubmit_keeps_versions' => 'Resubmitting before the deadline keeps the previous version rather than deleting it.',
    'replace_warning' => 'This will replace your previous submission',
    'drop_here' => 'Drag your files here, or pick them from your device',
    'choose_files' => 'Choose files',
    'accepted_types' => 'Every format is accepted except executables',
    'size_limit' => 'Maximum :size per file',
    'file_limit' => '{1} one file at most|[2,*] :count files at most',
    'file_count' => '{0} no files|{1} one file|[2,*] :count files',
    'github_url' => 'GitHub link',
    'github_hint' => 'The link starts with https://github.com/',
    'note_to_trainer' => 'Note for your trainer',
    'note_placeholder' => 'Optional — anything you want your trainer to notice while grading',
    'submit_action' => 'Submit the assignment',
    'save_draft' => 'Save as draft',
    'closed_title' => 'Submission is closed for this assignment',
    'submitted' => 'We received your submission. We recorded the time and notified your trainer.',

    'errors' => [
        'nothing_submitted' => 'Attach at least one file, or add a GitHub link, before submitting.',
        'too_many_files' => 'That is more files than your trainer allows for this assignment. Remove a few and try again.',
        'file_size' => 'One of your files is larger than this assignment allows. Compress or split it, then upload again.',
        'file_type' => 'This file format is not allowed, for security reasons. Save it in another format and upload again.',
        'github_url' => 'A GitHub link must start with https://github.com/ — copy it in full from the repository page.',
        'deadline_passed' => 'The deadline has passed and your trainer does not accept late submissions for this assignment.',
    ],

    'due' => [
        'title' => 'Assignments due',
        'empty_title' => 'Nothing is due right now',
        'empty_body' => 'Nothing is due right now. We will tell you as soon as a new assignment is published.',
    ],

    'unscheduled_group' => 'Assignments outside the weeks',
    'empty_title' => 'No assignments published yet',
    'empty_body' => 'Nothing is due right now. We will tell you as soon as a new assignment is published.',
    'week_empty_title' => 'No assignments this week',
    'week_empty_body' => 'Nothing has been published for this week yet.',
    'error_title' => 'Your assignments could not be shown',
    'error_body' => 'Something went wrong while fetching them. Try again shortly — your submissions are safe and unaffected.',

    'state' => [
        'not_submitted' => 'Not submitted',
        'submitted' => 'Submitted',
        'under_review' => 'Under review',
        'graded' => 'Graded',
        'late' => 'Late',
    ],

    'closed_body' => 'Submission is closed for this assignment. Contact your trainer if you think this is wrong.',

];
