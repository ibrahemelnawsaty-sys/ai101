<?php

declare(strict_types=1);

/**
 * One administrator, three trainers and sixty participants, each with a
 * profile, an enrolment and notification preferences.
 *
 * Not every participant is in the same state: 56 are active, 2 are still
 * pending and 2 have withdrawn. That is deliberate — the admin screens need
 * every enrolment status to actually exist, and `seats_taken` must be a number
 * the landing page can meaningfully show against a capacity of 60.
 *
 * Arabic personal names come from Faker's ar_SA provider for participants and
 * from the seed content for named staff; no Arabic literal appears in this file
 * (Constitution art. 13 #3).
 *
 * @see PRD §7.1, §7.2 · BR-32 · PROJECT-CONTRACT §4
 */

namespace Database\Seeders;

use App\Models\Cohort;
use App\Models\DigitalCard;
use App\Models\Enrollment;
use App\Models\NotificationPreference;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class UserSeeder extends Seeder
{
    public const PARTICIPANT_COUNT = 60;

    /** Participants whose enrolment is still awaiting approval. */
    private const PENDING_COUNT = 2;

    /** Participants who left the programme. */
    private const WITHDRAWN_COUNT = 2;

    public function run(): void
    {
        $cohort = Cohort::query()->firstOrFail();

        $this->createAdmin();
        $this->createTrainers($cohort);
        $this->createParticipants($cohort);

        $this->refreshSeatsTaken($cohort);
    }

    private function createAdmin(): void
    {
        /** @var array<string, mixed> $staff */
        $staff = SeedContent::section('staff');

        /** @var array<string, mixed> $row */
        $row = $staff['admin'];

        $admin = User::factory()->admin()->create([
            'email' => $row['email'],
            'status' => 'active',
        ]);

        $this->createProfileFrom($admin, $row);
        $this->createNotificationPreferences($admin);
    }

    private function createTrainers(Cohort $cohort): void
    {
        /** @var array<string, mixed> $staff */
        $staff = SeedContent::section('staff');

        /** @var list<array<string, mixed>> $rows */
        $rows = $staff['trainers'];

        foreach ($rows as $row) {
            $trainer = User::factory()->trainer()->create([
                'email' => $row['email'],
                'status' => 'active',
            ]);

            $this->createProfileFrom($trainer, $row);
            $this->createNotificationPreferences($trainer);

            Enrollment::factory()->trainer()->create([
                'cohort_id' => $cohort->getKey(),
                'user_id' => $trainer->getKey(),
                'enrolled_at' => SeedContent::instant(-10, '10:00:00'),
                'status' => 'active',
            ]);
        }
    }

    private function createParticipants(Cohort $cohort): void
    {
        /** @var array<string, mixed> $settings */
        $settings = SeedContent::section('participants');

        /** @var list<string> $cities */
        $cities = $settings['cities'];

        /** @var list<string> $levels */
        $levels = $settings['education_levels'];

        $activeCount = self::PARTICIPANT_COUNT - self::PENDING_COUNT - self::WITHDRAWN_COUNT;

        for ($i = 1; $i <= self::PARTICIPANT_COUNT; $i++) {
            $status = $this->enrollmentStatusFor($i, $activeCount);
            $gender = $i % 2 === 0 ? 'male' : 'female';

            $user = User::factory()->participant()->create([
                'email' => sprintf('participant%02d@athar-dev.edu.sa', $i),
                'status' => $status === 'pending' ? 'pending' : 'active',
                'email_verified_at' => $status === 'pending'
                    ? null
                    : SeedContent::instant(-9, '12:00:00'),
            ]);

            $arabic = fake('ar_SA');
            $latin = fake('en_US');

            Profile::factory()->create([
                'user_id' => $user->getKey(),
                'first_name_ar' => $arabic->firstName($gender),
                'second_name_ar' => $arabic->firstName('male'),
                'third_name_ar' => $arabic->firstName('male'),
                'last_name_ar' => $arabic->lastName(),
                'first_name_en' => $latin->firstName($gender),
                'second_name_en' => $latin->firstName('male'),
                'third_name_en' => $latin->firstName('male'),
                'last_name_en' => $latin->lastName(),
                'phone' => sprintf('05010%05d', $i),
                'gender' => $gender,
                'city' => $cities[$i % count($cities)],
                'education_level' => $levels[$i % count($levels)],
                'bio' => $settings['bio'],
            ]);

            $this->createNotificationPreferences($user);

            Enrollment::factory()->create([
                'cohort_id' => $cohort->getKey(),
                'user_id' => $user->getKey(),
                'role_in_cohort' => 'participant',
                'enrolled_at' => SeedContent::instant(-8, '09:00:00'),
                'status' => $status,
            ]);

            if ($status === 'active') {
                DigitalCard::factory()->create([
                    'user_id' => $user->getKey(),
                    'cohort_id' => $cohort->getKey(),
                    'card_number' => sprintf('AI101-%05d', $i),
                    'qr_token' => Str::lower(Str::random(64)),
                    'issued_at' => SeedContent::instant(-7, '09:00:00'),
                ]);
            }
        }
    }

    /**
     * The last few participants carry the non-active statuses so the admin
     * screens have real rows for each one.
     */
    private function enrollmentStatusFor(int $number, int $activeCount): string
    {
        if ($number <= $activeCount) {
            return 'active';
        }

        if ($number <= $activeCount + self::PENDING_COUNT) {
            return 'pending';
        }

        return 'withdrawn';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function createProfileFrom(User $user, array $row): void
    {
        Profile::factory()->create([
            'user_id' => $user->getKey(),
            'first_name_ar' => $row['first_name_ar'],
            'second_name_ar' => $row['second_name_ar'],
            'third_name_ar' => $row['third_name_ar'],
            'last_name_ar' => $row['last_name_ar'],
            'first_name_en' => $row['first_name_en'],
            'second_name_en' => $row['second_name_en'],
            'third_name_en' => $row['third_name_en'],
            'last_name_en' => $row['last_name_en'],
            'phone' => $row['phone'],
            'gender' => $row['gender'],
            'city' => $row['city'],
            'education_level' => $row['education_level'],
            // Optional on a staff row: the centre supplies a title and a
            // biography from the admin panel, and a seeded null is honest until
            // it does. PRD §9.1.1 prints each only when it exists.
            'job_title' => $row['job_title'] ?? null,
            'bio' => $row['bio'],
        ]);
    }

    private function createNotificationPreferences(User $user): void
    {
        /** @var list<array<string, mixed>> $types */
        $types = SeedContent::section('notifications');

        foreach ($types as $type) {
            NotificationPreference::factory()->create([
                'user_id' => $user->getKey(),
                'type' => $type['type'],
                'in_app_enabled' => true,
                'email_enabled' => true,
            ]);
        }
    }

    /**
     * A withdrawn enrolment releases its seat; a pending one still holds it.
     */
    private function refreshSeatsTaken(Cohort $cohort): void
    {
        $taken = Enrollment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('role_in_cohort', 'participant')
            ->whereIn('status', ['active', 'pending'])
            ->count();

        if ($taken > (int) $cohort->getAttribute('capacity')) {
            throw new \RuntimeException('The seeded cohort would be over capacity.');
        }

        $cohort->setAttribute('seats_taken', $taken);
        $cohort->save();
    }
}
