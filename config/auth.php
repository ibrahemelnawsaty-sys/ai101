<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication defaults
    |--------------------------------------------------------------------------
    |
    | Server-side Laravel sessions, stored in the database (Constitution art.
    | 9 and art. 10). There is no token guard and no API guard: the platform
    | has no public API surface in this release.
    |
    */

    'defaults' => [
        'guard' => 'web',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password reset
    |--------------------------------------------------------------------------
    |
    | There is deliberately no `passwords` broker here, and no
    | `password_reset_tokens` table in the schema.
    |
    | The platform issues its own verification and reset links through
    | `email_tokens` (PROJECT-CONTRACT §4): the token is stored hashed, carries
    | its own expiry, and is burned on first use. Configuring the framework
    | broker as well would mean two reset paths with different security
    | properties - one of them unaudited - which art. 6 forbids.
    |
    | A call to Password::broker() therefore throws instead of quietly opening
    | a second door (art. 7).
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Password confirmation timeout
    |--------------------------------------------------------------------------
    |
    | Three hours before a sensitive screen asks for the password again.
    |
    */

    'password_timeout' => 10800,

];
