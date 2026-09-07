<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default queue connection
    |--------------------------------------------------------------------------
    |
    | The database. Shared hosting has no supervisor and no long-lived worker,
    | so a cPanel cron entry runs `schedule:run` every minute and the schedule
    | drains the queue with `queue:work --stop-when-empty` (art. 10).
    |
    | Consequences that jobs must respect:
    |   - a job may wait up to a minute before it starts;
    |   - a job must finish well inside the host's execution limit;
    |   - nothing may assume a worker is already warm.
    |
    | Redis and SQS connections are absent on purpose.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            // Comfortably longer than the 50-second cap the scheduled worker
            // runs under, so a slow job is never picked up twice.
            'retry_after' => 120,
            'after_commit' => true,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job batching
    |--------------------------------------------------------------------------
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed jobs
    |--------------------------------------------------------------------------
    |
    | Kept in the database so a failed certificate render or notification can
    | be inspected and retried. Pruned weekly by the schedule.
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],

];
