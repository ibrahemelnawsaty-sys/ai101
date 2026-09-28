<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a press of an "available" or "publish" button did (D-127), so the
 * screen can say exactly that instead of a generic "saved".
 *
 * Every refusal here is the service re-checking, under a row lock, what the
 * policy already checked: a second tab may have changed the state between the
 * page load and the press (CONSTITUTION art. 7 — refuse, never pass).
 *
 * @see D-127
 */
enum PublicationOutcome: string
{
    /** The state changed. */
    case Changed = 'changed';

    /** It already was in the state asked for; nothing was written. */
    case Unchanged = 'unchanged';

    /** Publishing asked of something the general supervisor has not made available. */
    case NotAvailable = 'not_available';

    /** Making a guide language available that has no page saved yet. */
    case NoContent = 'no_content';

    /** Publishing the English page before the Arabic one is out. */
    case NeedsPrimaryLocale = 'needs_primary_locale';
}
