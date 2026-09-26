<?php

declare(strict_types=1);

namespace App\Services\Tickets;

use App\Models\SupportTicket;
use App\Services\FinalProject\ReceiptCodes;

/**
 * A support ticket's number (D-124): `TK-XXXX-XXXX`, the shape of the
 * final-project receipt code (D-122), read aloud or typed from a phone
 * without doubt.
 *
 * Random, never sequential: a sequence would tell anyone how many tickets the
 * platform has and let them walk the others. The number names a ticket for
 * people; the page behind it asks the policy anyway (art. 5, art. 22).
 *
 * @see D-124 · D-122 · CONSTITUTION art. 7, art. 22
 */
final class TicketNumbers
{
    public const PREFIX = 'TK';

    private const MAX_ATTEMPTS = 5;

    /** A fresh number, not checked against the table. */
    public static function generate(): string
    {
        $alphabet = ReceiptCodes::ALPHABET;
        $last = strlen($alphabet) - 1;
        $characters = '';

        for ($i = 0; $i < ReceiptCodes::GROUP * 2; $i++) {
            $characters .= $alphabet[random_int(0, $last)];
        }

        return self::PREFIX.'-'.substr($characters, 0, ReceiptCodes::GROUP).'-'.substr($characters, ReceiptCodes::GROUP);
    }

    /**
     * A number no ticket carries yet. Five draws that all collide mean the
     * generator is broken, not unlucky — the ticket fails loudly rather than
     * being saved without its number (art. 7).
     */
    public function unused(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $number = self::generate();

            if (! SupportTicket::query()->where('number', $number)->exists()) {
                return $number;
            }
        }

        throw new \RuntimeException('Could not draw an unused ticket number.');
    }
}
