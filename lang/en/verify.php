<?php

declare(strict_types=1);

/*
 * The two public verification pages: the digital card and the certificate.
 * Mirrors lang/ar/verify.php key for key. Both pages are public, require no
 * sign-in, and disclose only the minimum: first and family name · programme ·
 * cohort · role or date · status. Never an email, a phone number, a score, or
 * an internal identifier.
 *
 * @see BR-25 · PRD §9.17
 */

return [

    'shared' => [
        'issuer' => 'Athar Training Centre',
        'verified_by' => 'An official verification page from Athar Training Centre',
        'privacy_note' => 'This public page never discloses an email address, a phone number, a score, or any internal identifier.',
        'back_home' => 'Back to the home page',
        'checked_at' => 'Checked at',
        'print' => 'Print this page',
        'name' => 'Name',
        'program' => 'Programme',
        'cohort' => 'Cohort',
        'status' => 'Status',
    ],

    'card' => [
        'page_title' => 'Verify a participant card',
        'heading' => 'Valid training card',
        'role' => 'Role',
        'issued_at' => 'Issue date',
        'status_valid' => 'Valid · verified',
        'status_revoked' => 'Revoked',
        'revoked_title' => 'This card has been revoked',
        'revoked_body' => 'Athar revoked this card and it is no longer usable. Write to us at the address below if you need to check further.',
        'not_found_title' => 'No card matches this code',
        'not_found_body' => 'The code is wrong or has expired. Make sure the whole QR code was scanned, or ask the holder to show it again.',
        'loading' => 'Verifying the card',
        'error_title' => 'The card cannot be verified right now',
        'error_body' => 'A temporary problem got in the way. Try again in a moment, and contact us if it keeps happening — we will confirm the status by hand.',
        'error_action' => 'Try again',
    ],

    'certificate' => [
        'page_title' => 'Verify a certificate',
        'heading' => 'Valid certificate',
        'serial' => 'Serial number',
        'issued_at' => 'Issue date',
        'status_valid' => 'Valid · verified',
        'status_revoked' => 'Revoked',
        'revoked_title' => 'This certificate has been revoked',
        'revoked_body' => 'Athar revoked this certificate and it is no longer valid. A copy of it does not represent the current standing of its holder.',
        'revoked_at' => 'Revoked on',
        'not_found_title' => 'No certificate matches this code',
        'not_found_body' => 'The verification code is wrong or incomplete. Copy it in full from the certificate exactly as printed, or scan the QR code on it.',
        'loading' => 'Verifying the certificate',
        'error_title' => 'The certificate cannot be verified right now',
        'error_body' => 'A temporary problem got in the way. Try again in a moment, and contact us if it keeps happening — we will confirm the status by hand.',
        'error_action' => 'Try again',
    ],

];
