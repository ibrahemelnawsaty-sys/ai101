<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default connection
    |--------------------------------------------------------------------------
    |
    | MySQL 8 / MariaDB 10.6+, InnoDB, utf8mb4_unicode_ci (Constitution art. 9).
    | Production, staging and local development all run on `mysql`; nothing
    | else is deployable.
    |
    | The `sqlite` connection below exists for ONE purpose: letting the test
    | suite boot and run without a database server. It is never a deployment
    | target, and it is not a substitute for MySQL when a test is asserting a
    | schema guarantee. BR-06 is proven by a composite unique key (SQLite has
    | one too, so it carries over) but BR-12 and BR-13 are proven by CHECK
    | constraints that the migrations add with `ALTER TABLE`, guarded by the
    | driver name. Those tests are tagged `->group('mysql')` and skip
    | themselves on SQLite with a stated reason, so a green SQLite run is never
    | read as proof that the production constraint exists.
    |
    | See phpunit.xml for the two supported ways to run the suite.
    |
    */

    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'athar'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            // Every table is InnoDB: the platform needs transactions, foreign
            // keys and row-level locking for attendance and grading.
            'engine' => 'InnoDB',
            // Strict mode on. A truncated Arabic name or an out-of-range score
            // must be an error, never a silent trim (art. 7).
            'strict' => true,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
        | Tests only. `:memory:` gives every test a schema of its own, built by
        | the same migrations that build MySQL, and torn down with the process.
        |
        | `foreign_key_constraints` is on: an ON DELETE rule that is declared in
        | a migration but not enforced by the engine running the tests would let
        | a broken cascade pass unnoticed (art. 29).
        */
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', ':memory:'),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration repository
    |--------------------------------------------------------------------------
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis
    |--------------------------------------------------------------------------
    |
    | Absent by design. Shared hosting offers no guaranteed Redis and art. 10
    | forbids assuming one; cache, sessions and the queue all run on the
    | database. Anything reaching for the Redis connection should fail loudly.
    |
    */

];
