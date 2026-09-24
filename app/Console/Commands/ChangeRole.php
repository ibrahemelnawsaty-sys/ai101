<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\PermissionException;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Permissions\RoleGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Change the role of an existing account from the console.
 *
 * WHY THIS EXISTS (D-117)
 * Only the system administrator changes a role in the interface, and the role
 * did not exist before D-117 — so on the day it ships nobody can appoint the
 * first one. `athar:make-user` creates new accounts only, and the owner chose
 * to turn an existing account into the system administrator rather than add a
 * new one. This is that key, and the same door stays shut behind it:
 *
 *  · BR-32 — the last active general supervisor, and the last active system
 *    administrator, keep their role. Asked of RoleGate, which records the
 *    refusal in the trail, exactly as the interface refuses it.
 *  · a reason is required and written to the trail with the before and the
 *    after, under the same action name the interface writes (art. 8), before
 *    the change is saved.
 *  · a deleted account is not found; nothing is created.
 *
 * @see BR-28, BR-32 · PRD §4.2, §4.3 · CONSTITUTION art. 8, art. 22 · D-117
 */
final class ChangeRole extends Command
{
    /** The same floor the interface's reason field enforces. */
    private const REASON_MIN = 10;

    /** @var string */
    protected $signature = 'athar:change-role
        {email : Login address of an existing account}
        {role : admin (general supervisor), system_admin, trainer, coordinator or participant}
        {--reason= : Why the role changes; it is written to the audit trail}
        {--force : Do not ask for confirmation}';

    /** @var string */
    protected $description = 'Change the role of an existing account, keeping one active general supervisor and one active system administrator.';

    public function handle(RoleGate $gate, AuditLogger $audit): int
    {
        $role = UserRole::tryFrom((string) $this->argument('role'));

        if ($role === null) {
            $this->error('Unknown role: '.(string) $this->argument('role').'. Expected one of: '.implode(', ', UserRole::values()));

            return self::FAILURE;
        }

        /** @var User|null $user */
        $user = User::query()
            ->where('email', mb_strtolower(trim((string) $this->argument('email'))))
            ->first();

        if ($user === null) {
            $this->error('No account uses this address. Nothing was changed.');

            return self::FAILURE;
        }

        $before = $user->role;

        if ($before === $role) {
            $this->info('The account already holds the role '.$role->value.'. Nothing was changed.');

            return self::SUCCESS;
        }

        try {
            $gate->assertNotLastActiveHolder($user);
        } catch (PermissionException) {
            $this->error('Refused: this is the last active account holding the role '.$before->value.'.');
            $this->line('The platform always keeps one active general supervisor and one active system administrator (BR-32).');
            $this->line('Give another active account that role first, then run this again.');

            return self::FAILURE;
        }

        $reason = $this->reason();

        if ($reason === null) {
            $this->error('A reason of at least '.self::REASON_MIN.' characters is required. Nothing was changed.');

            return self::FAILURE;
        }

        if ($user->status !== UserStatus::Active) {
            $this->warn('Note: the account is '.$user->status->value.'; it keeps that status.');
        }

        if ($this->option('force') !== true
            && ! $this->confirm('Change '.(string) $user->getAttribute('email').' from '.$before->value.' to '.$role->value.'?', false)) {
            $this->line('Nothing was changed.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user, $before, $role, $reason, $audit): void {
            // Written before the change is saved (art. 8), under the name the
            // interface uses, so the audit screen reads both the same way.
            $audit->log('user.role_changed', $user, ['role' => $before->value], [
                'role' => $role->value,
                'reason' => $reason,
                'via' => 'console',
            ]);

            $user->setAttribute('role', $role->value);
            $user->save();
        });

        $this->info('Role changed: '.(string) $user->getAttribute('email').' is now '.$role->value.'.');
        $this->line('It takes effect on the account\'s next request (BR-28); no sign-in is needed.');

        return self::SUCCESS;
    }

    /** The reason given, or asked for; null when it is too short to mean anything. */
    private function reason(): ?string
    {
        $given = $this->option('reason');

        $reason = is_string($given) && $given !== ''
            ? $given
            : (string) $this->ask('Reason for the change (written to the audit trail)');

        $reason = trim($reason);

        return mb_strlen($reason) >= self::REASON_MIN ? $reason : null;
    }
}
