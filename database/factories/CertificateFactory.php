<?php

declare(strict_types=1);

/**
 * Certificates. The serial number follows ATHAR-AI101-2026-0001 (PRD §9.17) and
 * `verify_code` is a long random value — never a user id, because the
 * verification page is public (BR-25).
 *
 * @see PRD §7.6, §9.17 · BR-25, BR-26 · PROJECT-CONTRACT §4, §8
 */

namespace Database\Factories;

use App\Models\Certificate;
use App\Models\Cohort;
use App\Models\User;
use App\Services\Certificates\SerialNumberGenerator;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Certificate>
 */
final class CertificateFactory extends Factory
{
    /** @var class-string<Certificate> */
    protected $model = Certificate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'cohort_id' => Cohort::factory(),
            'serial_number' => 'ATHAR-AI101-2026-'.fake()->unique()->numerify('####'),
            // BR-25: the code must be one the platform would actually issue —
            // the public page validates its shape before it queries, so a
            // hand-rolled random string is a fixture no verification can find.
            'verify_code' => app(SerialNumberGenerator::class)->verifyCode(),
            'issued_at' => Clock::now(),
            'issued_by' => null,
            'file_url' => null,
            'final_score' => 85.00,
            'attendance_rate' => 92.00,
            'revoked_at' => null,
        ];
    }

    /**
     * Revocation is a timestamp only; the reason for it is written to
     * `audit_logs` by the service that revokes (Constitution art. 8).
     */
    public function revoked(): self
    {
        return $this->state(fn (array $attributes): array => [
            'revoked_at' => Clock::now(),
        ]);
    }

    public function results(float $finalScore, float $attendanceRate): self
    {
        return $this->state(fn (array $attributes): array => [
            'final_score' => $finalScore,
            'attendance_rate' => $attendanceRate,
        ]);
    }
}
