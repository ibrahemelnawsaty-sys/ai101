<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Gender;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Requests\Concerns\ProfileFieldRules;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Time\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Create a platform account from the console.
 *
 * This exists because `ProgramSeeder` — the only seeder allowed to run against
 * production (deploy/README.md §7) — creates the programme, its cohort, the
 * weeks, the journey steps and the landing settings, and deliberately creates
 * no users. A freshly deployed platform therefore has no account at all, and
 * nobody can reach the admin panel to enter the content that BR-31 says lives
 * in the database rather than in code. Without this command the first deploy
 * is a locked door with the key inside.
 *
 * It is not a convenience wrapper around a seeder: the demo seeders must never
 * touch production, and hand-written SQL would bypass the field rules that
 * PRD §9.2.1 fixes. The rules come from ProfileFieldRules, the same trait the
 * registration form and the admin's own create-account screen use — the trait's
 * own docblock names "admin-created accounts" as one of the four callers it
 * exists to keep identical.
 *
 * `email_verified_at` is set at creation. That is deliberate and it is not a
 * shortcut: `D-02` is open, no mail provider is approved, and MAIL_MAILER
 * discards every message. An account left unverified here could never be
 * verified by any means available today.
 *
 * @see BR-31, BR-36 · PRD §9.2.1, §4.2 · CONSTITUTION art. 5, art. 6, art. 8
 * @see D-02 (mail provider, open) · D-11 (registration mechanism, open)
 */
final class MakeUser extends Command
{
    use ProfileFieldRules;

    /** @var string */
    protected $signature = 'athar:make-user
        {--role= : admin, trainer or participant}
        {--email= : Login address}
        {--password= : Omit to be prompted without echo, which is preferred}
        {--phone= : Saudi mobile, 05XXXXXXXX or 9665XXXXXXXX}
        {--gender= : male or female}
        {--first-ar= : Given name, Arabic}
        {--father-ar= : Father name, Arabic}
        {--grandfather-ar= : Grandfather name, Arabic}
        {--family-ar= : Family name, Arabic}
        {--first-en= : Given name, Latin}
        {--father-en= : Father name, Latin}
        {--grandfather-en= : Grandfather name, Latin}
        {--family-en= : Family name, Latin}';

    /** @var string */
    protected $description = 'Create an admin, trainer or participant account. Needed on a fresh deploy, where no account exists.';

    public function handle(AuditLogger $audit): int
    {
        $role = $this->resolveRole();

        if ($role === null) {
            return self::FAILURE;
        }

        $payload = $this->collect();
        $validator = Validator::make($payload, $this->rules());

        $validator->after(function (\Illuminate\Validation\Validator $validator) use ($payload): void {
            if ($this->isBlacklistedPassword($payload['password'] ?? null)) {
                $validator->errors()->add('password', 'This password is on the common-password blacklist.');
            }
        });

        if ($validator->fails()) {
            $this->newLine();
            $this->error('The account was not created. Fix these and run the command again:');

            foreach ($validator->errors()->all() as $message) {
                $this->line('  - '.$message);
            }

            return self::FAILURE;
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        try {
            $user = DB::transaction(function () use ($validated, $role): User {
                // setAttribute throughout: no mass assignment of console input.
                $user = new User;
                $user->setAttribute('email', mb_strtolower((string) $validated['email']));
                $user->setAttribute('password_hash', $validated['password']); // 'hashed' cast
                $user->setAttribute('role', $role);
                $user->setAttribute('status', UserStatus::Active);
                $user->setAttribute('email_verified_at', Clock::now());
                $user->setAttribute('locale', 'ar');
                $user->save();

                $columns = $this->toProfileColumns($validated);
                $columns['user_id'] = $user->getKey();

                Profile::query()->create($columns);

                return $user;
            });
        } catch (Throwable $e) {
            $this->error('Nothing was written. The transaction was rolled back.');
            $this->line('  '.$e->getMessage());

            return self::FAILURE;
        }

        // Outside the transaction on purpose: a failure to write the audit row
        // must be loud, but it must not destroy an account that already exists
        // and that the operator has been told about.
        $audit->log(
            action: 'user.created.console',
            entity: $user,
            after: ['email' => $user->getAttribute('email'), 'role' => $role->value],
        );

        $this->newLine();
        $this->info('Account created.');
        $this->table(
            ['Field', 'Value'],
            [
                ['id', (string) $user->getKey()],
                ['email', (string) $user->getAttribute('email')],
                ['role', $role->value],
                ['status', UserStatus::Active->value],
                ['email verified', 'yes - set at creation, see D-02'],
            ],
        );

        if ($role === UserRole::Participant) {
            $this->newLine();
            $this->warn('A participant account alone cannot use the platform: it still needs an enrolment in a cohort. Create that from the admin panel.');
        }

        return self::SUCCESS;
    }

    private function resolveRole(): ?UserRole
    {
        $given = $this->option('role');

        $value = is_string($given) && $given !== ''
            ? $given
            : $this->choice('Role', UserRole::values(), 'admin');

        $role = UserRole::tryFrom((string) $value);

        if ($role === null) {
            $this->error('Unknown role: '.$value.'. Expected one of: '.implode(', ', UserRole::values()));
        }

        return $role;
    }

    /**
     * @return array<string, mixed>
     */
    private function collect(): array
    {
        $gender = $this->option('gender');

        return [
            'email' => $this->tidy($this->prompt('email', 'Email')),
            'password' => $this->promptPassword(),
            'phone' => $this->canonicalPhone($this->prompt('phone', 'Mobile (05XXXXXXXX)')),
            'gender' => is_string($gender) && $gender !== ''
                ? $gender
                : $this->choice('Gender', Gender::values(), null),

            'first_name_ar' => $this->tidy($this->prompt('first-ar', 'Given name (Arabic)')),
            'father_name_ar' => $this->tidy($this->prompt('father-ar', 'Father name (Arabic)')),
            'grandfather_name_ar' => $this->tidy($this->prompt('grandfather-ar', 'Grandfather name (Arabic)')),
            'family_name_ar' => $this->tidy($this->prompt('family-ar', 'Family name (Arabic)')),

            'first_name_en' => $this->tidy($this->prompt('first-en', 'Given name (Latin)')),
            'father_name_en' => $this->tidy($this->prompt('father-en', 'Father name (Latin)')),
            'grandfather_name_en' => $this->tidy($this->prompt('grandfather-en', 'Grandfather name (Latin)')),
            'family_name_en' => $this->tidy($this->prompt('family-en', 'Family name (Latin)')),
        ];
    }

    /**
     * The rules are the trait's, not this command's. A console account that
     * accepted a weaker password than the registration form would make the
     * form's rules decorative (art. 5).
     *
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $rules = [
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', $this->passwordRules()],
            'phone' => array_merge($this->phoneRules(), ['unique:profiles,phone']),
            'gender' => ['required', 'string', 'in:'.implode(',', Gender::values())],
        ];

        foreach ($this->arabicNameFields() as $field) {
            $rules[$field] = $this->arabicNameRules();
        }

        foreach ($this->latinNameFields() as $field) {
            $rules[$field] = $this->latinNameRules();
        }

        return $rules;
    }

    private function prompt(string $option, string $question): string
    {
        $given = $this->option($option);

        if (is_string($given) && $given !== '') {
            return $given;
        }

        return (string) $this->ask($question);
    }

    private function promptPassword(): string
    {
        $given = $this->option('password');

        if (is_string($given) && $given !== '') {
            return $given;
        }

        $first = (string) $this->secret('Password (not echoed)');
        $again = (string) $this->secret('Repeat password');

        if ($first !== $again) {
            $this->error('The two passwords do not match.');

            return '';
        }

        return $first;
    }
}
