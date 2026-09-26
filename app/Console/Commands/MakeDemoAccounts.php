<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EmailTokenType;
use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Events\PasswordChanged;
use App\Http\Requests\Concerns\ProfileFieldRules;
use App\Models\Cohort;
use App\Models\EmailToken;
use App\Models\Enrollment;
use App\Models\Profile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Journey\JourneyEvaluator;
use App\Services\Permissions\RoleResolver;
use App\Services\Time\Clock;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Provision the accounts a reviewer needs to walk the platform: a system
 * administrator, a general supervisor, a trainer and an enrolled participant
 * (D-117 split the one administrator into the first two).
 *
 * `athar:make-user` creates a single admin or trainer, and refuses the
 * participant role (D-69): a trainee needs an enrolment in a cohort and journey
 * state before any screen has anything on it, and this command is the only
 * console path that seats one — for the demo account only. On a fresh deploy
 * that is a chicken-and-egg: you cannot open the
 * admin panel until an admin exists, and the participant screens are empty until
 * someone enrols them. This does all of it in one step.
 *
 * Safety, because this is normally run against a live host:
 *   - it refuses to run in production without --force, and says so
 *   - passwords are generated and printed ONCE unless you pass your own
 *   - it is idempotent: an existing address is reused, never silently rewritten
 *   - it prints the exact command to remove what it made
 *
 * These are demonstration accounts with known addresses. Remove them before the
 * cohort opens to real trainees (PRD §12.6).
 *
 * --reset-passwords (D-120) is the way back to accounts whose printed passwords
 * were not kept. Nothing else reaches them: a second run leaves an existing
 * password alone, and a reset link goes to an address with no inbox (D-117).
 *
 * @see PRD §9.2, §4.1 · CONSTITUTION art. 24 · BR-29 · D-117, D-120
 */
final class MakeDemoAccounts extends Command
{
    use ProfileFieldRules;

    /** @var string */
    protected $signature = 'athar:demo-accounts
        {--force : Required in production, where creating known logins is a deliberate act}
        {--remove : Delete the demo accounts instead of creating them}
        {--reset-passwords : Give the demo accounts that already exist one new password; nothing is created}
        {--password= : One password for all of them. Omit to have strong ones generated, or with --reset-passwords to be asked for it without echo (preferred)}
        {--domain=athar-demo.test : Address domain for the created accounts}';

    /** @var string */
    protected $description = 'Create a system administrator, a general supervisor, a trainer and an enrolled participant for walking the platform.';

    public function handle(JourneyEvaluator $journey, RoleResolver $roles, AuditLogger $audit): int
    {
        $resetting = $this->option('reset-passwords') === true;

        if ($resetting && $this->option('remove') === true) {
            $this->error('--reset-passwords and --remove cannot be used together. Choose one.');

            return self::FAILURE;
        }

        if (app()->isProduction() && $this->option('force') !== true) {
            $this->error($resetting
                ? 'This sets one known password on the demo accounts of a live host.'
                : 'This creates accounts with known addresses on a live host.');
            $this->line('Re-run with --force if that is what you intend.');

            return self::FAILURE;
        }

        // Lower-cased and trimmed, as athar:change-role treats an address: the
        // database compares addresses without case, and so must the report.
        $domain = mb_strtolower(trim((string) $this->option('domain')));

        // The addresses are the roster's, so adding a person to the file adds
        // them to --remove too; a hand-kept second list forgot the supervisor.
        $addresses = array_column($this->roster($domain), 'email');

        if ($this->option('remove') === true) {
            return $this->remove($addresses, $roles, $audit);
        }

        if ($resetting) {
            return $this->resetPasswords($addresses, $domain, $audit);
        }

        $cohort = Cohort::query()->orderByDesc('start_date')->first();

        if (! $cohort instanceof Cohort) {
            $this->error('No cohort exists, so a participant cannot be enrolled.');
            $this->line('Run `php artisan migrate --seed` first, or create a cohort in the admin panel.');

            return self::FAILURE;
        }

        $shared = $this->option('password');
        $shared = is_string($shared) && $shared !== '' ? $shared : null;

        $people = $this->roster($domain);

        $rows = [];

        /** @var list<string> $mismatches */
        $mismatches = [];

        foreach ($people as $person) {
            $password = $shared ?? Str::password(16, true, true, false);

            $result = DB::transaction(function () use ($person, $password, $cohort, $journey): array {
                $existing = User::query()->where('email', $person['email'])->first();

                if ($existing instanceof User) {
                    // Reuse rather than rewrite: silently resetting a password on a
                    // live host is the kind of surprise this command must not spring.
                    $this->ensureEnrolment($existing, $person['role'], $cohort, $journey);

                    return ['user' => $existing, 'password' => null, 'created' => false];
                }

                $user = new User;
                $user->setAttribute('id', (string) Str::uuid());
                $user->setAttribute('email', $person['email']);
                $user->setAttribute('password_hash', $password);   // 'hashed' cast
                $user->setAttribute('role', $person['role']->value);
                $user->setAttribute('status', UserStatus::Active->value);
                // Verified at creation on purpose: there is no inbox behind these
                // addresses, so a verification link would strand the account.
                $user->setAttribute('email_verified_at', Clock::now());
                $user->setAttribute('locale', (string) config('athar.locales.default', 'ar'));
                $user->setAttribute('failed_login_count', 0);
                $user->save();

                Profile::query()->create([
                    'user_id' => $user->getKey(),
                    'first_name_ar' => $person['first_name_ar'],
                    'second_name_ar' => $person['second_name_ar'],
                    'third_name_ar' => $person['third_name_ar'],
                    'last_name_ar' => $person['last_name_ar'],
                    'first_name_en' => $person['first_name_en'],
                    'second_name_en' => $person['second_name_en'],
                    'third_name_en' => $person['third_name_en'],
                    'last_name_en' => $person['last_name_en'],
                    'phone' => $this->freePhone($person['phone']),
                    'gender' => $person['gender'],
                ]);

                $this->ensureEnrolment($user, $person['role'], $cohort, $journey);

                return ['user' => $user, 'password' => $password, 'created' => true];
            });

            // The ACCOUNT's role, not the file's: a reused account keeps the
            // role it has, and printing the roster's would claim a change this
            // command never makes (D-117 — `athar:change-role` makes it).
            $actual = $result['user']->role;

            $rows[] = [
                $actual->value,
                $person['email'],
                $result['password'] ?? '(unchanged — account already existed)',
                $result['created'] ? 'created' : 'reused',
            ];

            if ($actual !== $person['role']) {
                $mismatches[] = $person['email'].' is '.$actual->value.', the roster says '.$person['role']->value
                    .': php artisan athar:change-role '.$person['email'].' '.$person['role']->value;
            }
        }

        $this->newLine();
        $this->table(['role', 'email', 'password', 'status'], $rows);
        $this->newLine();
        $this->warn('Passwords are shown once and are not recoverable. Copy them now.');
        $this->line('Cohort used: '.(string) $cohort->getAttribute('name'));

        foreach ($mismatches as $mismatch) {
            $this->warn($mismatch);
        }
        $this->newLine();
        $this->line('Remove these accounts when the review is over:');
        $this->line('  php artisan athar:demo-accounts --remove   (or delete them from the admin panel)');

        return self::SUCCESS;
    }

    /**
     * The demo roster, read from database/seeders/data/demo-accounts.json.
     *
     * It lives there rather than in this file because it is seed data, and
     * because Arabic names in app/ are refused by gate G5. Same reason the
     * seeders keep their content in JSON.
     *
     * @return list<array{role: UserRole, email: string, phone: string, gender: string,
     *     first_name_ar: string, second_name_ar: string, third_name_ar: string, last_name_ar: string,
     *     first_name_en: string, second_name_en: string, third_name_en: string, last_name_en: string}>
     */
    private function roster(string $domain): array
    {
        $path = database_path('seeders/data/demo-accounts.json');

        $decoded = is_file($path)
            ? json_decode((string) file_get_contents($path), true)
            : null;

        if (! is_array($decoded) || ! isset($decoded['people']) || ! is_array($decoded['people'])) {
            throw new \RuntimeException('demo-accounts.json is missing or unreadable at '.$path);
        }

        $people = [];

        foreach ($decoded['people'] as $person) {
            if (! is_array($person)) {
                continue;
            }

            $role = UserRole::tryFrom((string) ($person['role'] ?? ''));

            if ($role === null) {
                continue;
            }

            // Rebuilt field by field rather than mutated, so the shape the rest
            // of this class relies on is stated once and cannot drift with the file.
            $people[] = [
                'role' => $role,
                'email' => ((string) ($person['local_part'] ?? '')).'@'.$domain,
                'phone' => (string) ($person['phone'] ?? ''),
                'gender' => (string) ($person['gender'] ?? 'male'),
                'first_name_ar' => (string) ($person['first_name_ar'] ?? ''),
                'second_name_ar' => (string) ($person['second_name_ar'] ?? ''),
                'third_name_ar' => (string) ($person['third_name_ar'] ?? ''),
                'last_name_ar' => (string) ($person['last_name_ar'] ?? ''),
                'first_name_en' => (string) ($person['first_name_en'] ?? ''),
                'second_name_en' => (string) ($person['second_name_en'] ?? ''),
                'third_name_en' => (string) ($person['third_name_en'] ?? ''),
                'last_name_en' => (string) ($person['last_name_en'] ?? ''),
            ];
        }

        return $people;
    }

    /**
     * `phone` is unique across the platform (PRD §7.1) and the seeded cohort
     * already occupies parts of the 05… range, so walk forward to the first
     * number nobody holds rather than colliding on a fixed one.
     */
    private function freePhone(string $preferred): string
    {
        $candidate = $preferred;
        $suffix = (int) substr($preferred, -7);

        while (Profile::query()->where('phone', $candidate)->exists()) {
            $suffix++;
            $candidate = '05'.str_pad((string) $suffix, 8, '0', STR_PAD_LEFT);
            $candidate = substr($candidate, 0, 10);
        }

        return $candidate;
    }

    /**
     * Soft-delete the demo accounts. Their attendance, submissions and audit
     * rows stay, because PRD §7.8 keeps those regardless of who created them.
     *
     * BR-32 holds here as it holds everywhere (D-117): an account that is the
     * last active general supervisor or the last active system administrator
     * is kept, and said so. D-117 made the demo `admin@` the live platform's
     * system administrator, so an unguarded --remove — the very step this
     * command tells the operator to take — would have emptied both roles. Each
     * removal is written to the trail before it happens (art. 8).
     *
     * @param  list<string>  $addresses
     */
    private function remove(array $addresses, RoleResolver $roles, AuditLogger $audit): int
    {
        $users = User::query()->whereIn('email', $addresses)->get();

        if ($users->isEmpty()) {
            $this->info('Nothing to remove: none of the demo addresses exist.');

            return self::SUCCESS;
        }

        $removed = 0;

        foreach ($users as $user) {
            // Asked afresh for each account: removing one supervisor can make
            // the next one the last.
            if ($roles->isLastActiveHolder($user)) {
                $this->warn('kept     '.(string) $user->getAttribute('email')
                    .' — the last active '.$user->role->value.' (BR-32). Give another active account that role first.');

                continue;
            }

            DB::transaction(function () use ($user, $audit): void {
                $audit->log('user.deleted', $user, ['status' => $user->status->value], [
                    'status' => UserStatus::Deleted->value,
                    'reason' => 'athar:demo-accounts --remove',
                    'via' => 'console',
                ]);

                Enrollment::query()->where('user_id', $user->getKey())->delete();
                $user->delete();   // soft delete, per PRD §7.8
            });

            $removed++;
            $this->line('removed  '.(string) $user->getAttribute('email'));
        }

        $this->info('Removed '.$removed.' demo account(s).');

        return self::SUCCESS;
    }

    /**
     * One new password on the demo accounts that exist (D-120).
     *
     * Held to what the reset SCREEN does, step for step, because a console path
     * that did less would be the weak door the screen's rules exist to close
     * (art. 5): the screen's password rules and blacklist (PRD §9.2.1); the
     * lockout and any temporary-password state cleared; the trail written
     * before the save (art. 8); every session and the remember-me token ended
     * (BR-29); an unused reset or invitation link spent, as following one
     * spends it; and the security letter sent (PRD §9.3.3). The password itself
     * is never printed and never written to the trail.
     *
     * Only the roster's addresses, and only those that exist: nothing is
     * created, a removed account is not brought back with a known password, and
     * a status other than active is reported and left alone. The roster fixes
     * only the part before the @, so a wrong --domain can match real people's
     * accounts: what matched is therefore shown, and confirmed, before anything
     * is asked for or changed.
     *
     * All or nothing: one transaction, so a failure part-way leaves every
     * account as it was, and the letters go out only once it has committed.
     *
     * @param  list<string>  $addresses
     */
    private function resetPasswords(array $addresses, string $domain, AuditLogger $audit): int
    {
        $users = User::query()->whereIn('email', $addresses)->orderBy('email')->get();

        // Before any prompt: asking for a password that will not be used is a
        // question the operator should not have to answer.
        if ($users->isEmpty()) {
            $this->error('None of the demo addresses exists at @'.$domain.', so no password was changed.');
            $this->line('Pass the --domain the accounts were created with.');

            return self::FAILURE;
        }

        $this->table(['role', 'email', 'status', 'created (UTC)'], $users->map(static fn (User $user): array => [
            $user->role->value,
            (string) $user->getAttribute('email'),
            $user->status->value,
            Dates::isoUtc($user->getAttribute('created_at')),
        ])->all());

        // Asked even with --force, which only says that a live host is meant —
        // and answered "no" when nobody is there to answer (--no-interaction).
        if (! $this->confirm('Set one new password on these '.$users->count().' account(s)?', false)) {
            $this->warn('Nothing was changed.');

            return self::FAILURE;
        }

        $password = $this->newPassword();

        if ($password === null) {
            $this->error('The two passwords do not match. No password was changed.');

            return self::FAILURE;
        }

        $errors = $this->passwordErrors($password);

        if ($errors !== []) {
            $this->error('No password was changed. Fix this and run the command again:');

            foreach ($errors as $message) {
                $this->line('  - '.$message);
            }

            return self::FAILURE;
        }

        $at = Clock::now();

        try {
            /** @var list<array{user: User, ended: int}> $changed */
            $changed = DB::transaction(function () use ($users, $password, $audit, $at): array {
                // Read again, locked: an account removed while the operator was
                // answering is not handed a known password.
                $current = User::query()
                    ->whereKey($users->modelKeys())
                    ->orderBy('email')
                    ->lockForUpdate()
                    ->get();

                $changed = [];

                foreach ($current as $user) {
                    $audit->log('user.password_reset', $user, null, [
                        'reason' => 'athar:demo-accounts --reset-passwords',
                        'via' => 'console',
                    ]);

                    $user->setAttribute('password_hash', $password);   // 'hashed' cast
                    $user->setAttribute('failed_login_count', 0);
                    $user->setAttribute('locked_until', null);
                    $user->setAttribute('must_change_password', false);
                    $user->setAttribute('temp_password_expires_at', null);
                    // BR-29 — a remember-me cookie issued under the old password
                    // must not outlive it.
                    $user->setAttribute('remember_token', null);
                    $user->save();

                    $this->spendOpenLinks($user, $at);

                    $changed[] = ['user' => $user, 'ended' => $this->endSessionsOf($user)];
                }

                return $changed;
            });
        } catch (\Throwable $exception) {
            // The framework's message quotes the failed statement, and with it
            // the new hash: the log keeps it, the screen does not show it.
            report($exception);

            $this->error('No password was changed: the database refused the change ('.$exception::class.').');
            $this->line('The details are in the application log.');

            return self::FAILURE;
        }

        foreach ($changed as $row) {
            PasswordChanged::dispatch($row['user'], $at, 'console');
        }

        $this->newLine();
        $this->table(['role', 'email', 'status', 'sessions ended'], array_map(static fn (array $row): array => [
            $row['user']->role->value,
            (string) $row['user']->getAttribute('email'),
            $row['user']->status->value,
            $row['ended'],
        ], $changed));

        foreach ($changed as $row) {
            if ($row['user']->status !== UserStatus::Active) {
                $this->warn((string) $row['user']->getAttribute('email').' is '.$row['user']->status->value
                    .': the new password works once the account is active again.');
            }
        }

        $changedKeys = array_map(static fn (array $row): mixed => $row['user']->getKey(), $changed);

        foreach ($users as $user) {
            if (! in_array($user->getKey(), $changedKeys, true)) {
                $this->warn('removed   '.(string) $user->getAttribute('email').' — deleted while this ran, so it was not changed.');
            }
        }

        // Compared without case: MySQL's collation matched them that way.
        $found = $users->map(static fn (User $user): string => mb_strtolower((string) $user->getAttribute('email')))->all();

        foreach ($addresses as $address) {
            if (! in_array(mb_strtolower($address), $found, true)) {
                $this->warn('not found '.$address.' — nothing was created for it.');
            }
        }

        $this->info('New password set on '.count($changed).' demo account(s).');

        $driver = (string) config('session.driver');

        $this->line($driver === 'database'
            ? 'Their sessions were ended and their remember-me tokens cleared.'
            : 'Their remember-me tokens were cleared. Sessions are not kept in the database here (driver: '.$driver.'), so there were none to end.');

        if (count($changed) > 1) {
            $this->warn('One password now opens all '.count($changed).' accounts above, and their addresses follow one pattern:'
                .' giving out any one of these logins gives out all of them.');
        }

        $given = $this->option('password');

        if (app()->isProduction() && is_string($given) && $given !== '') {
            $this->warn('--password was typed on the command line, so it is now in this shell\'s history.'
                .' Leave it out next time to be asked for it without echo.');
        }

        return self::SUCCESS;
    }

    /**
     * An unused reset or invitation link would still set a password of its
     * holder's choosing after this one, and a link mailed to a demo address
     * comes back inside the bounce, to the shared mailbox. Following a link
     * spends it; setting the password here spends them the same way.
     */
    private function spendOpenLinks(User $user, CarbonImmutable $at): void
    {
        EmailToken::query()
            ->forUser($user)
            ->whereIn('type', [EmailTokenType::Reset->value, EmailTokenType::Invite->value])
            ->whereNull('used_at')
            ->update(['used_at' => $at]);
    }

    /**
     * --password when given; otherwise asked for twice without echo, which is
     * preferred — an option stays in the shell history and the process list.
     * Null when the two typed answers differ.
     */
    private function newPassword(): ?string
    {
        $given = $this->option('password');

        if (is_string($given) && $given !== '') {
            return $given;
        }

        $first = (string) $this->secret('New password for the demo accounts (not echoed)');
        $again = (string) $this->secret('Repeat it');

        return $first === $again ? $first : null;
    }

    /**
     * The reset screen's rules and blacklist (PRD §9.2.1), from the trait the
     * screen and athar:make-user use, so the three cannot drift apart.
     *
     * @return list<string>
     */
    private function passwordErrors(string $password): array
    {
        $validator = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', $this->passwordRules()]],
        );

        $validator->after(function (\Illuminate\Validation\Validator $validator) use ($password): void {
            if ($this->isBlacklistedPassword($password)) {
                $validator->errors()->add('password', 'This password is on the common-password blacklist.');
            }
        });

        return $validator->fails() ? array_values($validator->errors()->all()) : [];
    }

    /**
     * BR-29 — the sessions opened under the old password end with it. They are
     * rows in a table on shared hosting, so ending one means deleting it: the
     * query "log out everywhere" runs on the accounts screen.
     */
    private function endSessionsOf(User $user): int
    {
        if ((string) config('session.driver') !== 'database') {
            return 0;
        }

        return DB::table((string) config('session.table', 'user_sessions'))
            ->where('user_id', $user->getKey())
            ->delete();
    }

    /**
     * A participant with no enrolment sees an empty platform, and a trainer with
     * none has no cohort to be scoped to (BR-23). Neither administrative role
     * takes one: the supervisor reaches every cohort already, and the system
     * administrator must reach none (D-117) — an enrolment written here would
     * have seated them as a trainee.
     */
    private function ensureEnrolment(User $user, UserRole $role, Cohort $cohort, JourneyEvaluator $journey): void
    {
        if ($role === UserRole::Admin || $role === UserRole::SystemAdmin) {
            return;
        }

        $roleInCohort = $role === UserRole::Trainer
            ? EnrollmentRole::Trainer
            : EnrollmentRole::Participant;

        $already = Enrollment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('user_id', $user->getKey())
            ->exists();

        if (! $already) {
            Enrollment::query()->create([
                'cohort_id' => $cohort->getKey(),
                'user_id' => $user->getKey(),
                'role_in_cohort' => $roleInCohort->value,
                'enrolled_at' => Clock::now(),
                'status' => EnrollmentStatus::Active->value,
            ]);
        }

        if ($roleInCohort === EnrollmentRole::Participant) {
            // Step 1 completes on enrolment and the rest follow the data, so this
            // only writes what the evaluator already derives (BR-20, BR-21).
            $journey->sync($user, $cohort);
        }
    }
}
