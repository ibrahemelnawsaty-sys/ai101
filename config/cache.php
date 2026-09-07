<?php

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default cache store
    |--------------------------------------------------------------------------
    |
    | The database. Shared hosting gives no guaranteed Redis and no memcached,
    | and assuming either is forbidden (Constitution art. 10). Redis and
    | memcached stores are therefore absent from this file entirely - a
    | CACHE_STORE=redis in some future .env should fail loudly, not silently
    | half-work.
    |
    */

    'default' => env('CACHE_STORE', 'database'),

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE', 'cache_locks'),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache key prefix
    |--------------------------------------------------------------------------
    |
    | Keeps rate-limiter counters and scheduled-task locks from colliding with
    | any other application sharing the same database user.
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'athar'), '_').'_cache_'),

];
