<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CohortStatus;
use App\Models\Cohort;
use App\Services\Messages\ThreadProvisioner;
use Illuminate\Console\Command;

/**
 * Give everyone already enrolled the conversations PRD §9.13 promises — for
 * the accounts that joined before the platform created them (D-82).
 *
 * Idempotent: a second run changes nothing, so it is safe to run again after
 * any import. Run once after deploying D-82.
 *
 * @see PRD §9.13 · D-82
 */
final class ProvisionMessages extends Command
{
    /** @var string */
    protected $signature = 'athar:provision-messages';

    /** @var string */
    protected $description = 'Create each cohort\'s announcement channel, group and trainer conversations, and add everyone enrolled.';

    public function handle(ThreadProvisioner $threads): int
    {
        $cohorts = Cohort::query()
            ->whereIn('status', [CohortStatus::Open->value, CohortStatus::Running->value, CohortStatus::Completed->value])
            ->orderBy('start_date')
            ->get();

        $added = 0;

        foreach ($cohorts as $cohort) {
            $added += $threads->provisionCohort($cohort);
        }

        $this->components->info(sprintf('Provisioned %d cohort(s); %d member(s) added to their channels.', $cohorts->count(), $added));

        return self::SUCCESS;
    }
}
