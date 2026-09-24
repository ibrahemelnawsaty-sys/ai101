<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Platform role of a user account.
 *
 * D-117 split what PRD §4.1 calls the "system administrator" into two roles,
 * and the value `admin` kept every power the PRD gives that title except three:
 *
 *  · `admin`        — the general supervisor: programmes,
 *                     cohorts, sessions, attendance, tasks, the final project,
 *                     registrations, certificates, broadcasts, reports, the
 *                     audit trail and the platform settings.
 *  · `system_admin` — the system administrator: the accounts
 *                     (the directory, invitations, roles, status, deletion,
 *                     password resets), the account preview (BR-33, BR-35) and
 *                     the landing-page content (BR-31) — and nothing else.
 *
 * The value `admin` was kept, not renamed, on purpose: every `role:admin` guard
 * and every policy that means "the supervisor" already reads it, and renaming it
 * would touch over sixty checks for no difference a person can see. Where the
 * PRD says "system administrator" for anything outside those three areas, read
 * `admin`.
 *
 * @see PROJECT-CONTRACT.md §3 · PRD §4.1 · D-105, D-117
 */
enum UserRole: string
{
    use HasEnumValues;

    case Admin = 'admin';
    case SystemAdmin = 'system_admin';
    case Trainer = 'trainer';
    case Coordinator = 'coordinator';
    case Participant = 'participant';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.user_role.'.$this->value);
    }
}
