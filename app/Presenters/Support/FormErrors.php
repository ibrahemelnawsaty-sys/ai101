<?php

declare(strict_types=1);

namespace App\Presenters\Support;

use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;

/**
 * The refusals of the last form post that no field prints for itself.
 *
 * A form whose refusal is about the whole request — "already issued", "nobody
 * ticked" — has no box to hang the message under, and a message with no home is
 * a message the person never reads (the button seems to do nothing). The screen
 * prints those once, in one alert; a message that belongs to a field with its own
 * error line under it (`$inline`) is left to that line, so it is not said twice.
 *
 * @see CONSTITUTION art. 5, art. 17 · D-144
 */
final class FormErrors
{
    /**
     * @param  list<string>  $inline  fields that print their own message
     * @return list<string>
     */
    public static function apart(mixed $bag, array $inline = []): array
    {
        if ($bag instanceof ViewErrorBag) {
            $bag = $bag->getBag('default');
        }

        if (! $bag instanceof MessageBag) {
            return [];
        }

        $messages = [];

        foreach ($bag->messages() as $field => $lines) {
            if (in_array((string) $field, $inline, true)) {
                continue;
            }

            foreach ($lines as $line) {
                $messages[] = (string) $line;
            }
        }

        return array_values(array_unique($messages));
    }
}
