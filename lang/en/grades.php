<?php

/**
 * Evaluation and grades. Mirrors lang/ar/grades.php key for key.
 *
 * @see BR-11, BR-12, BR-13, BR-14 · PRD §9.15
 */

return [

    'subtitle' => 'Assignments :assignments + project :project = :total',

    'score' => 'Score',
    'out_of' => 'of :max',
    'points' => '{0} points|{1} point|[2,*] points',
    'feedback' => 'Trainer feedback',
    'trainer_feedback' => 'Feedback from :trainer',
    'recorded_score' => 'Recorded score',
    'recorded_on' => 'Recorded on :date',
    'revised_title' => 'This score was revised',
    'revision_reason' => 'Reason for revising the score',
    'revision_reason_hint' => 'Revising a recorded score requires a written reason. It is written to the audit log and the participant is notified.',
    'feedback_required_min' => 'Feedback must be at least :min characters — say what went well and what to improve.',
    'feedback_ok' => 'Feedback meets the minimum length.',
    'locked_hint' => 'This item opens in its own time, and its score appears here once recorded.',

    // Flash notices after recording or revising a score
    'recorded' => 'The score was recorded and the participant was notified.',
    'revised' => 'The score was revised, the reason was written to the audit log, and the participant was notified.',

    // Column headings for the exported grade sheet
    'export' => [
        'item' => 'Item',
        'score' => 'Score',
        'feedback' => 'Trainer feedback',
    ],

    'total' => [
        'title' => 'Total score',
        'current' => 'Your score so far',
        'recorded_so_far' => 'Recorded so far, out of :available points',
        'empty_title' => 'No score recorded yet',
        'empty_body' => 'Your total appears here as soon as the first item is graded, and updates after every score.',
    ],

    'assignments_part' => 'Assignments',
    'project_part' => 'Final project',
    'project_graded' => 'Project graded',
    'project_not_graded' => 'Project not graded yet',
    'pass_score' => 'Pass mark :score',
    'points_still_available' => ':points points are still available to you',
    'passing' => 'Above the pass mark',
    'not_yet_passing' => 'Below the pass mark so far',
    'export_pdf' => 'Download my grade sheet as PDF',
    'trend_title' => 'How your scores moved across the weeks',

    'latest' => [
        'title' => 'Latest grades',
        'empty_title' => 'No grades yet',
        'empty_body' => 'Your three most recent scores appear here, with a link to the full grade sheet.',
    ],

    'trainer' => [
        'empty_title' => 'Nothing is waiting to be graded',
        'empty_body' => 'You are all caught up. New submissions appear here as soon as they arrive.',
    ],

    'errors' => [
        'score_required' => 'Enter the score before saving.',
        'score_above_max' => 'The score cannot exceed the maximum for this item, which is :max.',
        'score_below_zero' => 'The score cannot be below zero.',
        'feedback_required' => 'Feedback is required — a score cannot be recorded without it.',
        'feedback_too_short' => 'That feedback is too short. Say what the participant did well and what to improve.',
        'assignment_max_score' => 'An assignment maximum is between 1 and 50 points, because all assignments together are worth 50.',
        'revision_reason_required' => 'Write why you are revising the score. The reason is written to the audit log and the participant is notified.',

        /*
        | Raised by App\Exceptions\GradingException on the server. The
        | FormRequest refuses first; these are what the service says when the
        | endpoint is called directly (BR-12, BR-13, BR-14).
        */

        'score_out_of_range' => 'The score for this item lies between zero and :max. Correct the value and save again.',
        'already_evaluated' => 'A score has already been recorded for this submission. Use Revise the score and write a reason.',
        'max_score_missing' => 'The maximum score for this item has not been set. Set it in the assignment settings and record the score again.',
        'submission_not_found' => 'This submission no longer exists. Reopen the submissions list.',
        'not_enrolled' => 'This participant is not in a cohort assigned to you.',
    ],

    'items_empty_title' => 'Nothing has been graded yet',
    'items_empty_body' => 'Every score your trainer records will appear here with the full feedback, grouped by week.',
    'error_title' => 'Your grades could not be shown',
    'error_body' => 'Something went wrong while fetching them. Try again shortly — your grades are safe and unaffected.',

    'state' => [
        'graded' => 'Graded',
        'pending' => 'Under review',
        'locked' => 'Not open yet',
    ],

];
