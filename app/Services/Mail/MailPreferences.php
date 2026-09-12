<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Presenters\Participant\PreferencePresenter;
use Illuminate\Support\Collection;

/**
 * Whether a person has agreed to receive a given letter by e-mail.
 *
 * WHY THIS EXISTS
 * The preferences screen wrote `notification_preferences.email_enabled`, and no
 * sender on the platform ever read it. A trainee who switched e-mail off for a
 * kind of letter went on receiving every one of them: the switch was
 * decorative. It was worse before D-65, when the form could not save at all —
 * but saving a preference nothing reads is only a quieter version of the same
 * broken promise (D-66).
 *
 * ONE PLACE, EVERY SENDER. Both shapes of sender come through here: the
 * single-recipient listeners ask about one person, and `CohortAudience` filters a
 * whole cohort. A rule applied in some senders and not others is the drift this
 * class exists to prevent.
 *
 * WHAT IS NEVER SUPPRESSIBLE
 *   · the types `PreferencePresenter::ALWAYS_ON` names — an enrolment decision,
 *     a certificate. The same list the screen renders as locked and the request
 *     forces on, so the three can never disagree.
 *   · security letters — a password change, a sign-in from a new device, an
 *     activation or reset link. Those are not passed through this class at all;
 *     their senders do not ask, by design, and `emails.common.security_notice`
 *     tells the reader so.
 *
 * NO ROW MEANS YES. Every channel defaults to on and is switched off explicitly
 * (PRD §9.16), so an account that has never opened the screen receives
 * everything, exactly as before.
 *
 * @see PRD §9.16, §9.16.1 · BR-33 · D-66
 */
final class MailPreferences
{
    public function allows(User $user, string $type): bool
    {
        if (in_array($type, PreferencePresenter::ALWAYS_ON, true)) {
            return true;
        }

        $enabled = NotificationPreference::query()
            ->where('user_id', $user->getKey())
            ->where('type', $type)
            ->value('email_enabled');

        return $enabled === null ? true : (bool) $enabled;
    }

    /**
     * The same rule for a whole audience, in ONE query rather than one per
     * person — a sixty-trainee cohort is sixty rows to read, not sixty trips.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    public function filter(Collection $users, string $type): Collection
    {
        if ($users->isEmpty() || in_array($type, PreferencePresenter::ALWAYS_ON, true)) {
            return $users;
        }

        $optedOut = NotificationPreference::query()
            ->whereIn('user_id', $users->map(static fn (User $u): string => (string) $u->getKey())->all())
            ->where('type', $type)
            ->where('email_enabled', false)
            ->pluck('user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ($optedOut === []) {
            return $users;
        }

        return $users
            ->reject(static fn (User $u): bool => in_array((string) $u->getKey(), $optedOut, true))
            ->values();
    }
}
