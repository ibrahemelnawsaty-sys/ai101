<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Profile;
use App\Models\User;
use App\Services\Journey\JourneyEvaluator;
use App\Services\Time\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Provision the three accounts a reviewer needs to walk the platform: an admin,
 * a trainer and an enrolled participant.
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
 * @see PRD §9.2, §4.1 · CONSTITUTION art. 24
 */
final class MakeDemoAccounts extends Command
{
    /** @var string */
    protected $signature = 'athar:demo-accounts
        {--force : Required in production, where creating known logins is a deliberate act}
        {--remove : Delete the three demo accounts instead of creating them}
        {--password= : Use one password for all three; omit to have strong ones generated}
        {--domain=athar-demo.test : Address domain for the created accounts}';

    /** @var string */
    protected $description = 'Create an admin, a trainer and an enrolled participant for walking the platform.';

    public function handle(JourneyEvaluator $journey): int
    {
        if (app()->isProduction() && $this->option('force') !== true) {
            $this->error('This creates accounts with known addresses on a live host.');
            $this->line('Re-run with --force if that is what you intend.');

            return self::FAILURE;
        }

        $domain = (string) $this->option('domain');

        $addresses = [
            'admin@'.$domain,
            'trainer@'.$domain,
            'student@'.$domain,
        ];

        if ($this->option('remove') === true) {
            return $this->remove($addresses);
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

            $rows[] = [
                $person['role']->value,
                $person['email'],
                $result['password'] ?? '(unchanged — account already existed)',
                $result['created'] ? 'created' : 'reused',
            ];
        }

        $this->newLine();
        $this->table(['role', 'email', 'password', 'status'], $rows);
        $this->newLine();
        $this->warn('Passwords are shown once and are not recoverable. Copy them now.');
        $this->line('Cohort used: '.(string) $cohort->getAttribute('name'));
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
     * @param  list<string>  $addresses
     */
    private function remove(array $addresses): int
    {
        $users = User::query()->whereIn('email', $addresses)->get();

        if ($users->isEmpty()) {
            $this->info('Nothing to remove: none of the demo addresses exist.');

            return self::SUCCESS;
        }

        foreach ($users as $user) {
            Enrollment::query()->where('user_id', $user->getKey())->delete();
            $user->delete();   // soft delete, per PRD §7.8
            $this->line('removed  '.(string) $user->getAttribute('email'));
        }

        $this->info('Removed '.$users->count().' demo account(s).');

        return self::SUCCESS;
    }

    /**
     * A participant with no enrolment sees an empty platform, and a trainer with
     * none has no cohort to be scoped to (BR-23).
     */
    private function ensureEnrolment(User $user, UserRole $role, Cohort $cohort, JourneyEvaluator $journey): void
    {
        if ($role === UserRole::Admin) {
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
