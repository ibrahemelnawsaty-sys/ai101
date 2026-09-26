<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Support ticket refusals the workflow raises after the policy already let
 * the request through — the ticket moved on between the page and the click,
 * or a choice the form offered is no longer valid (D-124).
 *
 * @see D-124 · CONSTITUTION art. 7, art. 15
 */
final class SupportTicketException extends DomainException
{
    /** Closed, or its day after "resolved" ran out: nothing more is written. */
    public static function closed(): self
    {
        return new self('support.errors.closed', [], 409);
    }

    /** Someone else acted first: it is no longer where this action expects. */
    public static function movedOn(): self
    {
        return new self('support.errors.moved_on', [], 409);
    }

    /** The coordinator chosen cannot take it: not an active coordinator of the cohort. */
    public static function notACoordinator(): self
    {
        return new self('support.errors.not_a_coordinator', [], 422);
    }

    /** Several coordinators could take it and none is primary: one must be named. */
    public static function chooseCoordinator(): self
    {
        return new self('support.errors.coordinator', [], 422);
    }

    /** Returning to the coordinator level needs a coordinator in the cohort. */
    public static function noCoordinator(): self
    {
        return new self('support.errors.no_coordinator', [], 409);
    }
}
