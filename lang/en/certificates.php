<?php

/**
 * Certificates. Mirrors lang/ar/certificates.php key for key. Both conditions
 * apply together — meeting one never compensates for the other (BR-26).
 *
 * @see BR-24, BR-25, BR-26 · PRD §9.17 · PROJECT-CONTRACT §8
 */

return [

    'subtitle_issued' => 'Issued after meeting both the attendance and the score condition',
    'subtitle_pending' => 'Issued after meeting both the attendance rate and the pass mark',

    'fields' => [
        'holder' => 'Participant name',
        'program' => 'Programme',
        'cohort' => 'Cohort',
        'hours' => 'Training hours',
        'serial_number' => 'Serial number',
        'issued_at' => 'Issue date',
        'final_score' => 'Final score',
        'attendance_rate' => 'Attendance rate',
        'verify_url' => 'Public verification link',
    ],

    'status' => [
        'issued' => 'Issued',
        'revoked' => 'Revoked',
        'pending_issue' => 'Awaiting issue',
        'not_eligible' => 'Conditions not met',
    ],

    'actions' => [
        'preview' => 'View the certificate',
        'download_pdf' => 'Download the certificate as PDF',
        'copy_verify_url' => 'Copy the verification link',
        'share_linkedin' => 'Share on LinkedIn',
    ],

    'eligibility' => [
        'title' => 'Conditions for issue',
        'met' => 'Met',
        'not_met' => 'Not met',
        'attendance' => 'Attendance rate',
        'attendance_requirement' => 'Minimum :required%',
        'attendance_current' => 'Currently :current%',
        'score' => 'Final score',
        'score_requirement' => 'Pass mark :required of :max',
        'score_current' => 'Currently :current of :max',
        'both_required' => 'Both conditions apply together, and meeting one does not compensate for the other.',
        'all_met' => 'Every participant meets both conditions',
    ],

    'states' => [
        'empty_title' => 'Your certificate has not been issued yet',
        'empty_body' => 'It is issued once both the attendance rate and the pass mark are met.',
        'error_title' => 'The certificate could not be shown',
        'error_body' => 'Something went wrong while fetching it. Try again shortly.',
    ],

    'doc_heading' => 'Certificate of programme completion',
    'doc_completed' => 'has successfully completed the requirements of the training programme',
    'hours' => '{0} training hours|{1} training hour|[2,*] training hours',
    'serial_number' => 'Serial number',
    'issued_on' => 'Issue date',
    'final_score' => 'Final score',

    'download_pdf' => 'Download the certificate as PDF',
    'copy_verify_link' => 'Copy the verification link',
    'share_linkedin' => 'Share on LinkedIn',
    'share_text' => 'I completed the Foundations of Artificial Intelligence programme at Athar Training Centre.',

    'public_verify_title' => 'Public verification link',
    'public_verify_body' => 'Share this link with an employer. It opens a public page that confirms the certificate, its holder, the programme and the date — and nothing else: no email, no phone number, no grades.',

    'conditions_met' => 'You have met the certificate conditions',
    'conditions_pending' => 'Certificate conditions',
    'met' => 'Met',
    'not_met' => 'Not met',
    'min_attendance' => 'Minimum :rate%',
    'why_not_title' => 'What is still missing',

    // Flash notices after issuing or revoking
    'issued' => 'The certificate was issued, and its holder received a notification and an email.',
    'revoked' => 'The certificate was revoked, and the verification page now shows it as revoked.',
    'both_required' => 'Both conditions apply together, and meeting one does not compensate for the other.',

    'errors' => [
        'not_eligible' => 'The certificate conditions are not met yet: the attendance rate and the pass mark are both required.',
        'already_issued' => 'A certificate has already been issued to this participant in this cohort.',
        'not_enrolled' => 'This participant is not enrolled in this cohort.',
        'revoked' => 'This certificate has been revoked.',
        'serial_generation_failed' => 'A serial number could not be generated right now. Try again shortly.',
        'override_reason_required' => 'Write why you are overriding the conditions, in at least :min characters. The reason is written to the audit log.',
    ],

    'reasons' => [
        'attendance_below_minimum' => 'Your attendance is :current%, below the required :required%.',
        'score_below_pass' => 'Your final score is :current of :max, below the pass mark of :required.',
        'not_enrolled' => 'There is no active enrolment for you in this cohort.',
        'cohort_not_completed' => 'The cohort has not finished yet, and certificates are issued after it does.',
        'project_not_graded' => 'Your final project has not been graded yet.',
    ],

    'tvtc_title' => 'Certificate from the Technical and Vocational Training Corporation',
    'tvtc_ready' => 'Your TVTC certificate is ready to download',
    'tvtc_pending_title' => 'Awaiting issue by the authority',
    'tvtc_pending_body' => 'This certificate is issued by the authority after the cohort ends. We upload it here as soon as we receive it and notify you.',
    'tvtc_pending_pill' => 'Awaiting issue',

    'revoked_title' => 'This certificate has been revoked',
    'revoked_body' => 'It is no longer valid. Contact us if you believe this is a mistake and we will review it.',
    'not_issued_title' => 'Your certificate has not been issued yet',
    'not_issued_body' => 'It is issued once both the attendance rate and the pass mark are met. The two conditions below show what is missing.',
    'error_title' => 'The certificate could not be shown',
    'error_body' => 'Something went wrong while fetching it. Try again shortly — your certificate is safe and unaffected.',

    'admin' => [
        'title' => 'Issue certificates',
        'issue_one' => 'Issue the certificate',
        'issue_all_eligible' => 'Issue to everyone eligible',
        'revoke' => 'Revoke the certificate',
        'reissue' => 'Reissue',
        'eligible_list' => 'Participants who meet the conditions',
        'not_eligible_list' => 'Participants who do not, and why',
        'reasons_column' => 'What is missing',
        'stat_revoked' => 'Revoked certificates',
        'none_ineligible_title' => 'Nobody falls short of the conditions',
        'select_all' => 'Select everyone eligible',
        'select_candidate' => 'Select :name for issue',
        'revoke_reason' => 'Reason for revoking',
        'revoke_reason_hint' => 'The reason is written to the audit log, and the verification page then shows the certificate as revoked.',
        'override' => 'Override the conditions manually',
        'override_reason' => 'Reason for the override',
        'override_reason_hint' => 'The reason is written to the audit log, and issue is impossible without it.',
        'issued_count' => ':count certificates issued.',
        'revoked_done' => 'The certificate was revoked, and the verification page now shows it as revoked.',
        'serial_format_hint' => 'Serial number format: ATHAR-AI101-2026-0001',
        'empty_title' => 'Nobody meets the conditions yet',
        'empty_body' => 'The eligible list appears once attendance is complete and final scores are recorded.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin actions and the exported certificates report
    |--------------------------------------------------------------------------
    */

    'bulk_issued' => 'Certificates were issued to every eligible participant in this cohort. New certificates: :count.',
    'reissued' => 'The previous certificate was revoked and a replacement was issued. The change is recorded in the audit log.',

    'export' => [
        'serial' => 'Serial number',
        'holder' => 'Certificate holder',
        'cohort' => 'Cohort',
        'score' => 'Final score',
        'attendance' => 'Attendance rate',
        'revoked' => 'Revoked on',
    ],

];
