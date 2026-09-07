<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rules\Password;

/**
 * The field rules that PRD §9.2.1 fixes for names, phone, email and password.
 * They live in one trait because registration, admin-created accounts, profile
 * editing and password reset must all enforce exactly the same thing — a rule
 * that exists in only one of those four places is not a rule.
 *
 * @see PRD §9.2.1, §9.3.3, §9.4 · CONSTITUTION Art. 5, Art. 6
 */
trait ProfileFieldRules
{
    /**
     * Arabic letters only, 2 to 20 characters. Single spaces between words are
     * tolerated because compound given names are normal in Arabic and §9.2.1
     * asks for surplus whitespace to be trimmed rather than rejected. Tatweel
     * (U+0640), Arabic-Indic digits, punctuation and Latin letters are refused.
     */
    protected const ARABIC_NAME_PATTERN =
        '/^[\x{0621}-\x{063F}\x{0641}-\x{064A}]+(?: [\x{0621}-\x{063F}\x{0641}-\x{064A}]+)*$/u';

    /** Latin letters only, 2 to 20 characters, single internal spaces allowed. */
    protected const LATIN_NAME_PATTERN = '/^[A-Za-z]+(?: [A-Za-z]+)*$/';

    /** Saudi mobile: 05XXXXXXXX, or 9665XXXXXXXX before normalisation. */
    protected const PHONE_PATTERN = '/^(?:05[0-9]{8}|9665[0-9]{8})$/';

    /** @return list<string> */
    protected function arabicNameRules(): array
    {
        return ['required', 'string', 'min:2', 'max:20', 'regex:'.self::ARABIC_NAME_PATTERN];
    }

    /** @return list<string> */
    protected function latinNameRules(): array
    {
        return ['required', 'string', 'min:2', 'max:20', 'regex:'.self::LATIN_NAME_PATTERN];
    }

    /** @return list<string> */
    protected function phoneRules(): array
    {
        return ['required', 'string', 'regex:'.self::PHONE_PATTERN];
    }

    /**
     * Password: at least 8 characters carrying an upper-case letter, a
     * lower-case letter, a digit and a symbol, and not one of the very common
     * passwords listed in config. The blacklist is local by design — a shared
     * host must not make an outbound call in the middle of a registration.
     */
    protected function passwordRules(): Password
    {
        return Password::min(8)->mixedCase()->numbers()->symbols();
    }

    /** @return list<string> */
    protected function arabicNameFields(): array
    {
        return ['first_name_ar', 'father_name_ar', 'grandfather_name_ar', 'family_name_ar'];
    }

    /** @return list<string> */
    protected function latinNameFields(): array
    {
        return ['first_name_en', 'father_name_en', 'grandfather_name_en', 'family_name_en'];
    }

    /**
     * The form speaks of father / grandfather / family; the `profiles` table
     * calls the same four parts first / second / third / last. The mapping is
     * declared once, here, so no caller has to remember it and no controller
     * hands the wrong key to a model.
     *
     * @return array<string, string> form field => profiles column
     */
    protected function profileNameColumnMap(): array
    {
        return [
            'first_name_ar' => 'first_name_ar',
            'father_name_ar' => 'second_name_ar',
            'grandfather_name_ar' => 'third_name_ar',
            'family_name_ar' => 'last_name_ar',
            'first_name_en' => 'first_name_en',
            'father_name_en' => 'second_name_en',
            'grandfather_name_en' => 'third_name_en',
            'family_name_en' => 'last_name_en',
        ];
    }

    /**
     * Validated payload rewritten with `profiles` column names. Only keys the
     * request actually carried are returned, so a partial edit stays partial.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function toProfileColumns(array $validated): array
    {
        $columns = [];

        foreach ($this->profileNameColumnMap() as $field => $column) {
            if (array_key_exists($field, $validated)) {
                $columns[$column] = $validated[$field];
            }
        }

        foreach (['phone', 'gender', 'bio', 'city', 'education_level', 'birth_date'] as $field) {
            if (array_key_exists($field, $validated)) {
                $columns[$field] = $validated[$field];
            }
        }

        return $columns;
    }

    /** Collapse runs of whitespace and trim, per PRD §9.2.1. */
    protected function tidy(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', $value);

        return trim($collapsed ?? $value);
    }

    /**
     * Reduce a Saudi mobile number to its single canonical form (05XXXXXXXX)
     * so that "0512345678" and "966512345678" cannot both be registered. The
     * uniqueness rule in §9.2.1 is only real once the two forms collapse.
     */
    protected function canonicalPhone(mixed $value): ?string
    {
        $digits = is_string($value) ? preg_replace('/\D+/', '', $value) : null;

        if (! is_string($digits) || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '9665') && strlen($digits) === 12) {
            return '0'.substr($digits, 3);
        }

        return $digits;
    }

    /** True when the password is on the short local blacklist (PRD §9.2.1). */
    protected function isBlacklistedPassword(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        /** @var list<string> $blacklist */
        $blacklist = (array) config('athar.security.password_blacklist', [
            'password', 'password1', 'passw0rd', '12345678', '123456789',
            'qwerty123', 'iloveyou', 'admin123', 'welcome1', 'letmein1',
        ]);

        return in_array(mb_strtolower($value), array_map('mb_strtolower', $blacklist), true);
    }
}
