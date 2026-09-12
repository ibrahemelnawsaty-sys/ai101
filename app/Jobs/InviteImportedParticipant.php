<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\UserRole;
use App\Models\Cohort;
use App\Services\Credentials\AccountInviter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * One imported row becomes one account, on the queue.
 *
 * WHY THE QUEUE AND NOT THE REQUEST
 * Every account costs a bcrypt hash, and `config/hashing.php` sets twelve
 * rounds — a quarter of a second each, by design, because that is what makes a
 * stolen hash expensive. Sixty rows is a quarter of a minute of pure hashing
 * before a single insert, and the cap is two hundred. Shared hosting cuts a
 * long request off rather than letting it finish, and it does so without
 * telling anybody which rows were reached.
 *
 * So the request validates everything, answers immediately, and the per-minute
 * cron does the work. Nothing is left half-done, because nothing was ever
 * attempted inside the request.
 *
 * IDEMPOTENT, BECAUSE THE QUEUE RETRIES. A job that timed out mid-send comes
 * back, and an administrator who pressed confirm twice would otherwise get two
 * accounts and two letters for one person. `AccountInviter` already refuses to
 * seat somebody twice; this refuses to create them twice.
 *
 * PRIMITIVES ONLY. A queued job carrying an Eloquent model re-fetches it when
 * it runs and throws if the row has moved on — and this one may run minutes
 * after it was pushed.
 *
 * @see PRD §4.2 · CONSTITUTION Art. 10 · D-63
 */
final class InviteImportedParticipant implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300];

    /**
     * @param  array<string, mixed>  $profileColumns  already mapped to `profiles` column names
     */
    public function __construct(
        private readonly string $email,
        private readonly array $profileColumns,
        private readonly string $cohortId,
    ) {}

    public function handle(AccountInviter $inviter): void
    {
        // Checked here rather than trusted from the preview: the preview ran in
        // an earlier request, and an address that was free then may have been
        // taken since — by the other half of a double-clicked import, by the
        // single-account form, or by this very job on a previous attempt.
        //
        // Deleted accounts count: `users.email` is unique across them, so a
        // deleted address slipped past this check and failed the insert, and
        // the job went to failed_jobs three times over (D-84).
        $exists = \App\Models\User::withTrashed()
            ->where('email', $this->email)
            ->exists();

        if ($exists) {
            return;
        }

        /** @var Cohort|null $cohort */
        $cohort = Cohort::query()->with('program')->whereKey($this->cohortId)->first();

        if ($cohort === null) {
            // The cohort was removed between the upload and the drain. Creating
            // an account outside every cohort is the exact failure this whole
            // batch of work exists to end, so the row is refused and said so.
            Log::warning('import.cohort_missing', [
                'cohort_id' => $this->cohortId,
            ]);

            return;
        }

        $inviter->invite(
            email: $this->email,
            role: UserRole::Participant,
            profileColumns: $this->profileColumns,
            cohort: $cohort,
        );
    }
}
