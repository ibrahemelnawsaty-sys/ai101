<?php

declare(strict_types=1);

/**
 * Console routes and the task schedule (Laravel 12 keeps both here).
 *
 * Shared hosting has no supervisor and no long-lived daemon, so a single cPanel
 * cron entry running every minute drives everything:
 *
 *     * * * * * /usr/bin/php /home/<account>/athar/artisan schedule:run >> /dev/null 2>&1
 *
 * Every scheduled task therefore has to finish well inside one PHP execution
 * slot, and none of them may assume a worker is already running.
 *
 * The queue drain, and the queue pruning entries, live in bootstrap/app.php
 * next to the rest of the application wiring; the business-rule tasks live
 * here, next to the rule they serve.
 *
 * @see BR-07, BR-08, BR-09 · PRD §9.9.5 · CONSTITUTION art. 10 · deploy/README.md
 */

use App\Console\Commands\ReconcileAttendance;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Attendance reconciliation - every fifteen minutes (PRD §9.9.5)
|--------------------------------------------------------------------------
|
| BR-08 turns a no-show into `absent`, BR-09 turns a check-in without a
| check-out into `incomplete` and notifies the trainer, and the attendance rate
| of every affected enrolment is recomputed afterwards.
|
| withoutOverlapping keeps a slow pass from being started twice; the ten-minute
| expiry releases the lock if the process dies without unlocking. The lock lives
| in the `cache_locks` table, which the database cache store provides.
|
| runInBackground is deliberately NOT used: it forks a second PHP process, which
| shared hosting accounts limit aggressively. onOneServer is not used either -
| there is exactly one server, and the extra lock buys nothing.
|
*/

Schedule::command(ReconcileAttendance::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping(10);
