<?php

/**
 * The digital ID card. Mirrors lang/ar/card.php key for key.
 * The public page a scanned QR opens is verify.php, and it shows far less
 * than this screen (BR-25).
 *
 * @see BR-25 · PRD §9.6
 */

return [

    'print' => 'Print or save as PDF',
    'print_note' => 'Print this page, or save it as a PDF from the print dialogue. The code opens the public verification page.',
    // The printable sheet's heading and expiry row (D-65).
    'title' => 'Participant card',
    'expires_on' => 'Valid until',
    'subtitle' => 'Your digital identity in the programme',

    'role' => 'Role',
    'number' => 'Card number',
    'issued_on' => 'Issue date',
    'qr_alt' => 'A QR code that opens the verification page for this card',
    'share_title' => 'My card at Athar Training Centre',
    'download_png' => 'Download the card as an image',
    'download_pdf' => 'Download the card as PDF',
    'verify_link' => 'Verification link',
    'scan_count' => 'Times scanned',
    'scan_count_hint' => 'A count only — who scanned the code, and from where, is never recorded.',

    'status' => [
        'title' => 'Card status',
        'expires_with_cohort' => 'Valid until :date, the day your cohort ends.',
    ],

    'disclosure' => [
        'title' => 'What a person who scans the code sees',
        'shown' => 'They see your first and family name only, the programme and cohort, your role, the issue date, and whether the card is valid.',
        'never_shown_title' => 'And never see',
        'never_shown_body' => 'Your email · your phone number · your grades · your attendance rate · any internal identifier.',
    ],

    'tilt' => [
        'title' => 'Card motion',
        'body' => 'The card tilts with the pointer by no more than :degrees degrees, and stops entirely when your device is set to reduce motion.',
    ],

    'empty_title' => 'Your card has not been created yet',
    'empty_body' => 'It is created automatically once your account is activated and you are enrolled. Complete your account details so it carries your name and photo.',
    'error_title' => 'Your card could not be shown',
    'error_body' => 'Something went wrong while fetching it. Try again shortly — your card and its code are unaffected.',

    'state' => [
        'valid' => 'Valid',
        'revoked' => 'Revoked',
    ],

];
