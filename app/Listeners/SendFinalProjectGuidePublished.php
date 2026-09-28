<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\FinalProjectGuidePublished;
use App\Mail\AtharLetter;
use App\Models\User;
use App\Services\Mail\CohortAudience;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells a cohort's trainees the final project's guide is out, by e-mail, with
 * one button that opens the guide itself (D-127 — the owner asked for a button
 * that takes them straight to the guide).
 *
 * The roster is resolved here, when the queued listener runs (D-51), and
 * everyone who switched this letter off is skipped (D-66).
 *
 * @see D-127 · D-51, D-66 · PRD §9.16.1
 */
final class SendFinalProjectGuidePublished implements ShouldQueue
{
    public const TYPE = 'final_project_guide_published';

    public function __construct(private readonly CohortAudience $audience) {}

    public function handle(FinalProjectGuidePublished $event): void
    {
        foreach ($this->audience->reachable($event->cohortId, self::TYPE) as $user) {
            $this->writeTo($user);
        }
    }

    private function writeTo(User $user): void
    {
        try {
            Mail::to((string) $user->getAttribute('email'))->send(new AtharLetter(
                copyKey: 'emails.final_project_guide_published',
                ctaUrl: route('finalProject.guide'),
            ));
        } catch (\Throwable $exception) {
            // One unreachable address must not stop the rest of the cohort
            // from being told.
            Log::warning('mail.final_project_guide_published_failed', [
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
            ]);
        }
    }
}
