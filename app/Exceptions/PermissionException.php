<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Authorization failures raised by the permission services.
 *
 * @see BR-22, BR-23, BR-28, BR-33 · CONSTITUTION art. 22
 */
final class PermissionException extends DomainException
{
    public static function forbidden(): self
    {
        return new self('errors.forbidden', [], 403);
    }

    /** BR-23 — a trainer may only reach cohorts assigned to them. */
    public static function cohortOutOfScope(): self
    {
        return new self('errors.cohort_out_of_scope', [], 403);
    }

    public static function roleRequired(): self
    {
        return new self('errors.role_required', [], 403);
    }

    public static function inactiveAccount(): self
    {
        return new self('errors.inactive_account', [], 403);
    }

    /** BR-33 — impersonation is strictly read only. */
    public static function readOnlyImpersonation(): self
    {
        return new self('errors.impersonation_read_only', [], 403);
    }
}
