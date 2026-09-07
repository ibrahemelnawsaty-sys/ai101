<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Attendance\AttendanceReconciler;
use App\Services\Time\Clock;
use App\Services\Time\RiyadhFormatter;
use Illuminate\Console\Command;
use Throwable;

/**
 * The every-fifteen-minutes attendance reconciliation (PRD §9.9.5).
 *
 *  · BR-08 a participant who never checked in to a session that has ended
 *          becomes `absent`
 *  · BR-09 a participant who checked in but never checked out, once the
 *          check-out window has closed, becomes `incomplete` and the session
 *          trainer is notified
 *  · the attendance rate of every affected enrolment is recomputed from the
 *    rows that actually exist - it is derived data, never entered by hand
 *
 * Shared hosting has no daemon and no supervisor (art. 10): the schedule entry
 * in routes/console.php is driven by a one-minute cPanel cron running
 * `schedule:run`, and this command is written to finish well inside a single
 * PHP execution slot. The look-back window bounds the work; `--days` widens it
 * for a one-off catch-up after a maintenance window.
 *
 * The command is idempotent: a second run over the same period converts
 * nothing, because there is nothing left to convert.
 *
 * @see BR-07, BR-08, BR-09 · PRD §9.9.5 · CONSTITUTION art. 10, art. 11
 */
final class ReconcileAttendance extends Command
{
    /** @var string */
    protected $signature = 'attendance:reconcile
        {--days= : How many days back to revisit; defaults to the configured look-back}';

    /** @var string */
    protected $description = 'Mark absentees (BR-08) and incomplete attendance (BR-09), then recompute attendance rates.';

    public function handle(AttendanceReconciler $reconciler, RiyadhFormatter $formatter): int
    {
        $days = $this->lookbackOption();

        if ($days === false) {
            $this->components->error('The --days option must be a positive whole number.');

            return self::INVALID;
        }

        $startedAt = Clock::now();

        try {
            $result = $reconciler->run($startedAt, $days);
        } catch (Throwable $e) {
            // Fail safe (art. 7): a partial pass must not be reported as a
            // success, and the next cron tick retries from the same data.
            $this->components->error('Attendance reconciliation failed: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $finishedAt = Clock::now();

        $this->components->info(sprintf(
            'Reconciled %d session(s): %d absent, %d incomplete, %d cohort(s), %d enrolment(s) updated.',
            $result['sessions'],
            $result['absent'],
            $result['incomplete'],
            $result['cohorts'],
            $result['enrollments'],
        ));

        // Console output stays language neutral: it is read in a cron log, not
        // by a participant, so it carries the machine-readable UTC instant.
        $this->line(sprintf(
            '  started %s, took %s',
            $formatter->iso($startedAt),
            $formatter->duration($finishedAt->getTimestamp() - $startedAt->getTimestamp()),
        ));

        return self::SUCCESS;
    }

    /**
     * @return int|null|false null = use the configured default, false = invalid
     */
    private function lookbackOption(): int|null|false
    {
        $option = $this->option('days');

        if ($option === null || $option === '') {
            return null;
        }

        if (! is_string($option) || preg_match('/^[1-9][0-9]*$/', $option) !== 1) {
            return false;
        }

        return (int) $option;
    }
}
