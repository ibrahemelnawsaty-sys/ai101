<?php

declare(strict_types=1);

namespace App\Services\FinalProject;

use App\Models\ProjectSubmission;

/**
 * The receipt code of a final-project hand-in (D-122): `FP-XXXX-XXXX`, one per
 * version, read aloud or typed from a phone without doubt.
 *
 * Random, never sequential: the code names a hand-in in a URL (the QR's
 * receipt page), so it must not let anyone count or walk the others. The page
 * behind it asks the policy anyway (art. 5, art. 22). Eight characters from an
 * alphabet with no 0/O, 1/I or L gives 31^8 ≈ 8.5 × 10^11 codes; the column is
 * unique, and a code already taken is simply drawn again.
 *
 * @see BR-22, BR-23 · FR-NOTIF-15 · PRD §9.14.2 · D-122 · CONSTITUTION art. 7, art. 22
 */
final class ReceiptCodes
{
    public const PREFIX = 'FP';

    /** No 0/O, 1/I or L: a code is read from a screen and typed from memory. */
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /** Two groups of four after the prefix. */
    public const GROUP = 4;

    /** What a well-formed code looks like, for routes and forms alike. */
    public const PATTERN = 'FP-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}-[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{4}';

    private const MAX_ATTEMPTS = 5;

    /** A fresh code, not checked against the table. */
    public static function generate(): string
    {
        $alphabet = self::ALPHABET;
        $last = strlen($alphabet) - 1;
        $characters = '';

        for ($i = 0; $i < self::GROUP * 2; $i++) {
            $characters .= $alphabet[random_int(0, $last)];
        }

        return self::PREFIX.'-'.substr($characters, 0, self::GROUP).'-'.substr($characters, self::GROUP);
    }

    public static function isWellFormed(string $code): bool
    {
        return preg_match('/^'.self::PATTERN.'$/', $code) === 1;
    }

    /**
     * A code no hand-in carries yet. Five draws that all collide mean the
     * generator is broken, not unlucky — so the hand-in fails loudly rather
     * than being saved without its receipt (art. 7).
     */
    public function unused(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $code = self::generate();

            if (! ProjectSubmission::query()->where('receipt_code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not draw an unused receipt code.');
    }
}
