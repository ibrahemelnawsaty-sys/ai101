<?php

declare(strict_types=1);

namespace App\Services\Credentials;

/**
 * The one-time password an invited account signs in with, once.
 *
 * WHO TYPES THIS
 * Not a password manager. A trainee, by hand, reading it off an email — often
 * on the phone that is showing the email, switching between three keyboard
 * layouts to reach a symbol. Every character that can be misread is a support
 * request, and every support request on this particular string looks to the
 * trainee like "the platform rejected me".
 *
 * So the alphabet drops every glyph pair that is confusable in a proportional
 * font: `I` `l` `1`, `O` `o` `0`. What remains is still 66 characters, and at
 * twelve of them that is about 72 bits — far beyond guessing, and this password
 * is only valid until the first sign-in anyway.
 *
 * THE SYMBOLS ARE CHOSEN, NOT DEFAULTED. The platform requires one (see
 * `ProfileFieldRules::passwordRules()`: min 8, mixed case, numbers, symbols),
 * and the set here avoids every character that would be mangled somewhere on
 * the way to the reader: no quote marks (they break a CSV cell and get curled
 * by mail clients), no backslash, no angle brackets (HTML), no comma, no space,
 * and nothing a mail client will try to turn into a link.
 *
 * EACH CLASS IS GUARANTEED, NOT HOPED FOR. Drawing twelve characters at random
 * from a mixed alphabet leaves a real chance of no symbol at all — and the
 * platform's own rules would then reject the password it just generated, at the
 * moment the trainee tried to change it. One of each class is placed first and
 * the whole string is then shuffled.
 *
 * EVERY DRAW IS CRYPTOGRAPHIC. `random_int` throws rather than degrading if the
 * system has no good entropy source, and `str_shuffle`/`shuffle` are NOT
 * suitable here — they use the ordinary generator, which is seeded and
 * predictable. The shuffle below is Fisher-Yates over `random_int`.
 *
 * THE PLAINTEXT LIVES FOR ONE REQUEST. It is hashed into `password_hash`, put
 * into one queued letter, and dropped. It is never logged, never written to
 * `audit_logs`, never flashed to the session, and never shown back to the
 * administrator — an administrator who can read a trainee's password can sign
 * in as them without leaving an impersonation record.
 *
 * @see PRD §9.2, §12.1 · BR-30 · CONSTITUTION.md Article 12 · D-63
 */
final class TemporaryPassword
{
    /** No `I`, no `O`: both are read as `l` and `0` often enough to matter. */
    private const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';

    /** No `l`, no `o`. */
    private const LOWER = 'abcdefghijkmnpqrstuvwxyz';

    /** No `0`, no `1`. */
    private const DIGITS = '23456789';

    /** Reachable on a phone keyboard, and safe in a CSV cell, HTML and a URL. */
    private const SYMBOLS = '!@#$%*+=?';

    /** Twelve is long enough to be unguessable and short enough to be typed. */
    private const LENGTH = 12;

    public function generate(): string
    {
        $alphabet = self::UPPER.self::LOWER.self::DIGITS.self::SYMBOLS;

        // One from each class first, so the result cannot fail the platform's
        // own password rules.
        $characters = [
            $this->pick(self::UPPER),
            $this->pick(self::LOWER),
            $this->pick(self::DIGITS),
            $this->pick(self::SYMBOLS),
        ];

        for ($i = count($characters); $i < self::LENGTH; $i++) {
            $characters[] = $this->pick($alphabet);
        }

        return implode('', $this->shuffle($characters));
    }

    private function pick(string $alphabet): string
    {
        return $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    /**
     * Fisher-Yates over `random_int`.
     *
     * `shuffle()` and `str_shuffle()` draw from the Mt19937 generator, whose
     * state is recoverable from its output — which would make the guaranteed
     * characters' positions, and then the rest, predictable to anyone who saw
     * one generated password.
     *
     * @param  list<string>  $characters
     * @return list<string>
     */
    private function shuffle(array $characters): array
    {
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return $characters;
    }
}
