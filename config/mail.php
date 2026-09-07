<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default mailer
    |--------------------------------------------------------------------------
    |
    | SMTP through a transactional provider (PROJECT-CONTRACT §11). The
    | provider itself is not chosen yet - the deployment guide leaves the SMTP
    | block blank and says, correctly, not to invent one. Nothing here names a
    | vendor.
    |
    | `failover` is offered so that a delivery outage degrades to a written log
    | line instead of a 500 in the middle of a registration (art. 7). It is not
    | the default: silently logging real mail in production would hide a
    | genuine outage.
    |
    */

    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'scheme' => env('MAIL_SCHEME'),
            'url' => env('MAIL_URL'),
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => (int) env('MAIL_PORT', 587),
            'username' => env('MAIL_USERNAME'),
            'password' => env('MAIL_PASSWORD'),
            'encryption' => env('MAIL_ENCRYPTION', 'tls'),
            // Shared hosting caps request time; a hung SMTP handshake must not
            // hold a web request open.
            'timeout' => 15,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        'log' => [
            'transport' => 'log',
            'channel' => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers' => ['smtp', 'log'],
            'retry_after' => 60,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Global "from" and "reply to"
    |--------------------------------------------------------------------------
    |
    | The sending identity and the address shown to visitors are two different
    | things, and this is the only place that knows it: the platform sends FROM
    | the operations mailbox and asks replies to go to the public contact
    | address in config('athar.email').
    |
    | MAIL_FROM_NAME is Arabic and therefore lives in .env, never in PHP
    | source (art. 13, rule 3).
    |
    */

    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'info@athar-dev.edu.sa'),
        'name' => env('MAIL_FROM_NAME', ''),
    ],

    'reply_to' => [
        'address' => env('MAIL_REPLY_TO_ADDRESS', env('ATHAR_EMAIL', 'contact@athar-dev.edu.sa')),
        'name' => env('MAIL_REPLY_TO_NAME', ''),
    ],

];
