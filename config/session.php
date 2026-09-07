<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Session driver
    |--------------------------------------------------------------------------
    |
    | Database sessions (Constitution art. 9). Storing them server-side is what
    | lets BR-29 work: changing a password invalidates every other live session
    | by deleting its rows, which a cookie-only session could never guarantee.
    |
    */

    'driver' => env('SESSION_DRIVER', 'database'),

    'lifetime' => (int) env('SESSION_LIFETIME', 120),

    'expire_on_close' => false,

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    |
    | Session payloads are encrypted at rest. On shared hosting the database
    | is not exclusively ours, and a payload carries the authenticated user id
    | and the account-preview state (BR-33).
    |
    */

    'encrypt' => (bool) env('SESSION_ENCRYPT', true),

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | The table is `user_sessions`, NOT `sessions`: `sessions` is a domain
    | table in this platform - the training sessions of PRD §7.3.
    |
    */

    'files' => storage_path('framework/sessions'),

    'connection' => env('SESSION_CONNECTION'),

    'table' => 'user_sessions',

    'store' => env('SESSION_STORE'),

    'lottery' => [2, 100],

    /*
    |--------------------------------------------------------------------------
    | Session cookie
    |--------------------------------------------------------------------------
    |
    | HttpOnly, Secure, SameSite=Lax - all three are mandated by art. 24.
    | `secure` defaults to null so that plain-HTTP local development still
    | works; production sets SESSION_SECURE_COOKIE=true and the deployment
    | checklist verifies it.
    |
    */

    'cookie' => env(
        'SESSION_COOKIE',
        Str::slug((string) env('APP_NAME', 'athar'), '_').'_session'
    ),

    'path' => env('SESSION_PATH', '/'),

    'domain' => env('SESSION_DOMAIN'),

    'secure' => env('SESSION_SECURE_COOKIE'),

    'http_only' => (bool) env('SESSION_HTTP_ONLY', true),

    'same_site' => env('SESSION_SAME_SITE', 'lax'),

    'partitioned' => false,

];
