<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Password hashing
    |--------------------------------------------------------------------------
    |
    | bcrypt at cost 12 (Constitution art. 9). argon2id is the preferred
    | algorithm where the host provides it, but libsodium/argon2 support cannot
    | be assumed on shared hosting and no extension can be installed there
    | (art. 10) - so bcrypt is the committed default and HASH_DRIVER is the
    | single switch if the host is later confirmed to support argon2id.
    |
    | The test suite drops the cost to 4 through phpunit.xml. Production never
    | goes below 12.
    |
    */

    'driver' => env('HASH_DRIVER', 'bcrypt'),

    'bcrypt' => [
        'rounds' => (int) env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rehash on login
    |--------------------------------------------------------------------------
    |
    | When the cost changes, a password is transparently re-hashed the next
    | time its owner signs in.
    |
    */

    'rehash_on_login' => true,

];
