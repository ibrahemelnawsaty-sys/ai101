<?php

/**
 * Error pages and error notices. Mirrors lang/ar/errors.php key for key.
 * Every message says what happened and what to do next, with no blame and no
 * technical jargon. A raw HTTP status text never reaches a participant.
 *
 * @see PRD §11.1, §8 · CONSTITUTION art. 7, art. 15, art. 17
 */

return [

    'pages' => [
        '401' => [
            'title' => 'Your session has ended',
            'body' => 'Your session has ended. Please sign in again.',
            'action' => 'Sign in',
        ],
        '403' => [
            'title' => 'You do not have access to this page.',
            'body' => 'This page belongs to another role, or to something that is not part of your account. Contact us if you think this is a mistake.',
            'action' => 'Back to my dashboard',
        ],
        '404' => [
            'title' => 'The page you are looking for does not exist.',
            'body' => 'The link may have changed, or the page may have been removed. Try one of these instead.',
            'action' => 'Back to my dashboard',
            'suggestions' => 'Links that may help',
        ],
        '405' => [
            'title' => 'This request is not supported',
            'body' => 'You reached this page in an unexpected way. Go back and try again.',
            'action' => 'Go back',
        ],
        '419' => [
            'title' => 'The page has expired',
            'body' => 'It was left open for a long time. Reload it and send your details again.',
            'action' => 'Reload the page',
        ],
        '429' => [
            'title' => 'Too many requests in a short time',
            'body' => 'Wait a moment and try again. This protects your account and everyone else.',
            'action' => 'Try again',
            'retry_after' => 'Try again in :countdown',
        ],
        '500' => [
            'title' => 'Something unexpected happened',
            'body' => 'Something unexpected happened. Our team is on it. Try again shortly.',
            'action' => 'Try again',
        ],
        '503' => [
            'title' => 'The platform is under maintenance',
            'body' => 'We are running a short update. Come back in a little while — your data is safe.',
            'action' => 'Try again',
        ],
    ],

    'block_unavailable' => 'This part could not be loaded',
    'block_unavailable_hint' => 'The rest of your dashboard is working normally. Try again to load this part on its own.',

    'network' => 'We could not reach the server. Check your connection and try again.',
    'server' => 'Something unexpected happened. Our team is on it. Try again shortly.',
    'timeout' => 'The request took longer than expected. Try again.',
    'session_expired' => 'Your session has ended. Please sign in again.',
    'forbidden' => 'You do not have access to this page.',
    'not_found' => 'The page you are looking for does not exist.',
    'validation_summary' => 'Check the fields marked in red, then send the form again.',
    'unknown' => 'That did not go through. Try again, and contact us if it keeps happening.',

    'upload' => [
        'too_large' => 'The file is larger than the :limit megabyte limit.',
        'forbidden_type' => 'This file format is not allowed, for security reasons.',
        'too_many' => 'That is more than the :limit files allowed.',
        'failed' => 'The file could not be uploaded. Check your connection and try again.',
        'cancelled' => 'The upload was cancelled.',
        'empty_file' => 'This file is empty. Choose a file with content in it.',
        'link_expired' => 'The download link has expired. Reopen the page for a fresh one.',
    ],

    'throttle' => [
        'login' => 'Too many sign-in attempts. Try again in :countdown',
        'register' => 'Too many registration attempts from this connection. Try again in an hour.',
        'password' => 'Too many reset requests. Try again in an hour.',
        'attendance' => 'Too many requests in a short time. Wait a moment and try again.',
        'upload' => 'You have reached the upload limit for this hour. Try again later.',
        'messages' => 'You have sent a lot of messages in a short time. Wait a moment, then carry on.',
        'generic' => 'Too many requests in a short time. Wait a moment and try again.',
    ],

    'denied' => [
        'not_your_resource' => 'This item does not belong to your account.',
        'out_of_scope' => 'This item is outside your cohort.',
        'read_only_mode' => 'This action is disabled in preview mode.',
        'locked_feature' => 'This section is locked for now. It opens in its own time.',
        'window_closed' => 'The time available for this action has passed.',
        'logged' => 'This attempt has been recorded for audit.',
    ],

    'cohort_out_of_scope' => 'This item belongs to another cohort, and you only ever see what belongs to yours.',
    'role_required' => 'This action belongs to another role on the platform. Contact us if you think this is a mistake.',
    'inactive_account' => 'Your account is not active right now. Contact us to activate it, then try again.',
    'impersonation_read_only' => 'You are in account preview mode, which is read only. End the preview to perform any action.',

    'file' => [
        'too_large' => 'The file is larger than the :max megabyte limit. Compress or split it, then upload again.',
        'empty' => 'This file is empty. Choose a file with content in it and upload again.',
        'unreadable' => 'The file could not be read. Check your connection and upload again.',
        'mime_not_allowed' => 'This file format is not allowed. Upload a document, an image, or an archive.',
        'executable_rejected' => 'This kind of file is not allowed, for security reasons. Upload a document or an image instead.',
        'sniff_unavailable' => 'The file type could not be verified right now, so it was not uploaded, to keep the platform safe. Try again shortly.',
        'not_found' => 'The file you are looking for no longer exists. Reopen the page for the latest version.',
        'invalid_path' => 'That file link is not valid. Reopen the page and try the download again.',
        'signed_route_missing' => 'A download link could not be created right now. Try again shortly.',
    ],

    'time' => [
        'unreadable' => 'The time of this session could not be read precisely, so the action was refused to protect your record. Contact us so we can correct the session time.',
    ],

];
