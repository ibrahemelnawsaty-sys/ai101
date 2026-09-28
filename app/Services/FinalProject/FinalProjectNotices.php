<?php

declare(strict_types=1);

namespace App\Services\FinalProject;

use App\Events\FinalProjectGuidePublished;
use App\Events\FinalProjectUnlocked;
use App\Models\Cohort;
use App\Models\FinalProject;
use App\Models\FinalProjectGuide;
use App\Models\User;
use App\Services\Cohorts\PrimaryCoordinator;
use App\Services\Mail\CohortAudience;
use App\Services\Notifications\InAppNotifier;
use App\Support\Dates;
use Illuminate\Support\Facades\Log;

/**
 * Who hears what when the final project or its guide moves one step (D-127).
 *
 *   project made available     the primary coordinator — platform
 *   guide language available   the primary coordinator — platform
 *   project published          the trainees — platform and e-mail (D-77);
 *                              when the guide goes out with it, one notice
 *                              and one letter that open the guide
 *   Arabic guide published     the trainers — platform; the trainees —
 *                              platform and e-mail with a button to the
 *                              guide, once the project is open too
 *
 * AFTER THE FACT, NEVER INSTEAD OF IT — the state already changed and was
 * written to the trail. A notice that fails to write must not turn that into
 * an error page (the same rule as CohortNotices): it is logged, and the press
 * still succeeded.
 *
 * @see D-127 · D-77, D-124 · PRD §9.14.1, §9.16.1
 */
final class FinalProjectNotices
{
    public const PROJECT_AVAILABLE = 'final_project_available';

    public const PROJECT_UNLOCKED = 'final_project_unlocked';

    public const GUIDE_AVAILABLE = 'final_project_guide_available';

    public const GUIDE_PUBLISHED = 'final_project_guide_published';

    public const GUIDE_PUBLISHED_STAFF = 'final_project_guide_published_staff';

    public function __construct(
        private readonly InAppNotifier $notifier,
        private readonly CohortAudience $audience,
        private readonly PrimaryCoordinator $primary,
    ) {}

    /** The general supervisor made the project available: its primary coordinator decides next. */
    public function projectAvailable(FinalProject $project): void
    {
        $this->toPrimaryCoordinator($project, self::PROJECT_AVAILABLE, []);
    }

    /** A guide language was made available: its primary coordinator decides next. */
    public function guideAvailable(FinalProjectGuide $guide, FinalProject $project): void
    {
        $this->toPrimaryCoordinator($project, self::GUIDE_AVAILABLE, [
            'language' => (string) __('project.guide.languages.'.$guide->locale),
        ]);
    }

    /**
     * The project opened (PRD §9.14.1): every trainee of the cohort, on the
     * platform now and by e-mail through the queue. `$withGuide` makes it the
     * one notice that also carries the guide.
     */
    public function projectPublished(FinalProject $project, bool $withGuide): void
    {
        $cohortId = (string) $project->cohort_id;
        $deadline = Dates::dateTime($project->due_at);

        $this->safely('final_project_unlocked', function () use ($cohortId, $deadline, $withGuide): void {
            $values = ['datetime' => $deadline];

            $this->notifier->notify(
                $this->participantIds($cohortId),
                self::PROJECT_UNLOCKED,
                (string) __('notifications.types.final_project_unlocked.title', $values),
                (string) __('notifications.types.final_project_unlocked.'.($withGuide ? 'body_with_guide' : 'body'), $values),
                $withGuide ? route('finalProject.guide') : route('finalProject'),
            );

            FinalProjectUnlocked::dispatch($cohortId, $deadline, $withGuide);
        });
    }

    /** The trainees can read the guide from now on: platform and e-mail. */
    public function guideToTrainees(FinalProject $project): void
    {
        $cohortId = (string) $project->cohort_id;

        $this->safely(self::GUIDE_PUBLISHED, function () use ($cohortId): void {
            $this->notifier->notify(
                $this->participantIds($cohortId),
                self::GUIDE_PUBLISHED,
                (string) __('notifications.types.final_project_guide_published.title'),
                (string) __('notifications.types.final_project_guide_published.body'),
                route('finalProject.guide'),
            );

            FinalProjectGuidePublished::dispatch($cohortId);
        });
    }

    /** The cohort's trainers can read the guide from now on: platform only. */
    public function guideToTrainers(FinalProject $project): void
    {
        $this->safely(self::GUIDE_PUBLISHED_STAFF, function () use ($project): void {
            $this->notifier->notify(
                $this->audience->trainerIds((string) $project->cohort_id),
                self::GUIDE_PUBLISHED_STAFF,
                (string) __('notifications.types.final_project_guide_published_staff.title'),
                (string) __('notifications.types.final_project_guide_published_staff.body'),
                route('finalProjectGuide.show', $project),
            );
        });
    }

    /**
     * @param  array<string, string>  $values
     */
    private function toPrimaryCoordinator(FinalProject $project, string $type, array $values): void
    {
        $this->safely($type, function () use ($project, $type, $values): void {
            $cohort = Cohort::query()->find((string) $project->cohort_id);

            if (! $cohort instanceof Cohort) {
                return;
            }

            $coordinatorId = $this->primary->idOf($cohort);

            // No primary coordinator: nobody can publish, and the general
            // supervisor's own screen already says so (D-127).
            if ($coordinatorId === null) {
                return;
            }

            $values += ['cohort' => (string) $cohort->getAttribute('name')];

            $this->notifier->notify(
                [$coordinatorId],
                $type,
                (string) __('notifications.types.'.$type.'.title', $values),
                (string) __('notifications.types.'.$type.'.body', $values),
                route('coordinator.finalProject', ['cohort' => $cohort->getKey()]),
            );
        });
    }

    /** @return list<string> */
    private function participantIds(string $cohortId): array
    {
        return array_values($this->audience->participants($cohortId)
            ->map(static fn (User $user): string => (string) $user->getKey())
            ->all());
    }

    private function safely(string $type, \Closure $send): void
    {
        try {
            $send();
        } catch (\Throwable $exception) {
            Log::warning('notice.'.$type.'_failed', ['exception' => $exception::class]);
        }
    }
}
