<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

/**
 * The words the live password checklist and strength meter show, handed to the
 * page as a JSON island so no Arabic sits in a .js file (Article 6).
 *
 * WHY A CONTROLLER BUILDS THIS, AND NOT THE VIEW
 * The reset screen built it inline as a nested, multi-line array inside
 * `@json(...)`. Blade's directive parser stops at the first closing parenthesis
 * it can pair, so the array compiled to broken PHP and the screen was a 500 for
 * everyone — every recovery link opened the error page (D-67). The registration
 * screen had hit the same wall and moved its copy here first (D-46); the two
 * screens now read one method, so they cannot drift apart again.
 *
 * @see PRD §9.2.2, §9.3.3 · D-46, D-67
 */
trait PasswordMeterCopy
{
    /**
     * @return array{met: string, unmet: string, strength: list<string>}
     */
    protected function passwordMeterCopy(): array
    {
        return [
            'met' => (string) __('auth.register.rule_met'),
            'unmet' => (string) __('auth.register.rule_unmet'),
            'strength' => [
                (string) __('auth.register.strength_0'),
                (string) __('auth.register.strength_1'),
                (string) __('auth.register.strength_2'),
                (string) __('auth.register.strength_3'),
            ],
        ];
    }
}
