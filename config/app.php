<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application name
    |--------------------------------------------------------------------------
    |
    | Used by the framework for notification subjects and log context. The
    | Arabic names shown to visitors are not here: they come from
    | config/athar.php and lang/ar/*.php (Constitution art. 6 and art. 15).
    |
    */

    'name' => env('APP_NAME', 'Athar'),

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Debug mode
    |--------------------------------------------------------------------------
    |
    | APP_DEBUG=false in production, without exception (Constitution art. 12).
    | The default here is false so a missing variable fails safe rather than
    | exposing stack traces (art. 7).
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | BR-36: the single source of every absolute link - certificate
    | verification pages, digital-card QR targets, e-mail buttons and signed
    | download URLs. Must carry https:// and no trailing slash.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application timezone
    |--------------------------------------------------------------------------
    |
    | UTC, always, and deliberately NOT read from the environment: storage is
    | UTC and display is Asia/Riyadh through Clock::riyadh(). A stray
    | APP_TIMEZONE in a .env file must never be able to shift what is written
    | to the database (Constitution art. 11, BR-07).
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Locale
    |--------------------------------------------------------------------------
    |
    | Arabic is the reference locale and also the fallback. The request locale
    | is settled by App\Http\Middleware\SetLocaleAndDirection against the
    | allow-list in config/athar.php.
    |
    */

    'locale' => env('APP_LOCALE', 'ar'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'ar'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'ar_SA'),

    /*
    |--------------------------------------------------------------------------
    | Encryption key
    |--------------------------------------------------------------------------
    |
    | Signs sessions, encrypted cookies, signed download URLs and the digital
    | card token. Rotating it invalidates every live session and every signed
    | link that has not yet expired.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => array_filter(
        explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
    ),

    /*
    |--------------------------------------------------------------------------
    | Maintenance mode
    |--------------------------------------------------------------------------
    |
    | The `file` driver only: the cache driver would need the database to be
    | reachable, which is exactly what is often being repaired (art. 7).
    |
    */

    'maintenance' => [
        'driver' => 'file',
        'store' => 'database',
    ],

];
