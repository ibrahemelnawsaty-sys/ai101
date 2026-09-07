<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Profile;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Services\Time\Clock;
use App\Support\ViewModel;
use Carbon\CarbonImmutable;

/**
 * The dashboard greeting card (PRD §9.5.3): a greeting by first name chosen by
 * the time of day, and one sentence about where the participant stands.
 *
 * The time of day is Riyadh time taken from the server clock — a browser in
 * another zone must not change the greeting (BR-07, art. 11). No Arabic is
 * written here: `partOfDay` names a key in lang/ar/dashboard.php (art. 15).
 *
 * @see BR-07, BR-20 · PRD §9.5.3 · CONSTITUTION art. 11, art. 15
 */
final class WelcomePresenter extends ViewModel
{
    private const AFTERNOON_FROM_HOUR = 12;

    private const EVENING_FROM_HOUR = 17;

    public static function from(
        User $user,
        CarbonImmutable $now,
        int $completedSteps,
        int $totalSteps,
        float $percent,
    ): self {
        return new self([
            'firstName' => self::firstName($user),
            'partOfDay' => self::partOfDay($now),
            'journeyPercent' => Present::decimal(Present::clampPercent($percent)),
            'statusLine' => self::statusLine($completedSteps, $totalSteps),
        ]);
    }

    private static function firstName(User $user): string
    {
        if ($user->relationLoaded('profile')) {
            $profile = $user->getRelation('profile');

            if ($profile instanceof Profile) {
                $first = Present::text($profile->getAttribute('first_name_ar'));

                if ($first !== null) {
                    return $first;
                }
            }
        }

        return (string) $user->getAttribute('email');
    }

    private static function partOfDay(CarbonImmutable $now): string
    {
        $hour = (int) Clock::toRiyadh($now)->format('G');

        if ($hour < self::AFTERNOON_FROM_HOUR) {
            return 'morning';
        }

        return $hour < self::EVENING_FROM_HOUR ? 'afternoon' : 'evening';
    }

    private static function statusLine(int $completed, int $total): string
    {
        if ($total === 0) {
            return (string) __('dashboard.status_line.not_started');
        }

        if ($completed >= $total) {
            return (string) __('dashboard.status_line.completed');
        }

        return (string) __('dashboard.status_line.in_progress', [
            'completed' => (string) $completed,
            'total' => (string) $total,
        ]);
    }
}
