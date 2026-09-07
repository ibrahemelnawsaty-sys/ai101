<?php

declare(strict_types=1);

namespace App\Presenters\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place a person becomes a name on a screen.
 *
 * Eight trainer and administration screens print "the participant" and each of
 * them had its own fallback chain: Arabic full name, then English, then the
 * email. Three of those chains disagreed, so the same person read differently
 * on the roster and on the grading panel. The chain lives here now.
 *
 * The profile relation is read through `related()`, so a presenter handed a
 * user whose profile was not eager-loaded renders the email rather than firing
 * an N+1 query — `Model::preventLazyLoading()` would throw outside production
 * anyway, and a report that quietly issues one query per row is the defect
 * art. 19 exists to prevent.
 *
 * @see PRD §4.5.1, §8 · CONSTITUTION art. 6, art. 15, art. 19
 */
trait PresentsPeople
{
    use PresentsFormValues;

    /** The name a screen prints for a person: Arabic, then English, then email. */
    protected static function personName(?Model $user): string
    {
        if (! $user instanceof Model) {
            return '—';
        }

        $profile = self::related($user, 'profile');

        foreach (['full_name_ar', 'full_name_en'] as $attribute) {
            $value = self::attr($profile, $attribute);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $email = self::attr($user, 'email');

        return is_string($email) && $email !== '' ? $email : '—';
    }

    /** The Arabic quadruple name, or the placeholder when it is not filled in. */
    protected static function personNameAr(?Model $user): string
    {
        return self::text(self::related($user, 'profile'), 'full_name_ar');
    }

    /** The Latin quadruple name, printed `dir="ltr"` by every screen that shows it. */
    protected static function personNameEn(?Model $user): string
    {
        return self::text(self::related($user, 'profile'), 'full_name_en');
    }

    protected static function personEmail(?Model $user): string
    {
        return self::text($user, 'email');
    }

    protected static function personPhone(?Model $user): string
    {
        return self::text(self::related($user, 'profile'), 'phone');
    }

    /** A user id as a string, or null — never an integer and never an object. */
    protected static function personId(?Model $user): ?string
    {
        return $user instanceof User ? (string) $user->getKey() : null;
    }
}
