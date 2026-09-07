<?php

declare(strict_types=1);

/**
 * Attendance records. Every instant comes from Clock (BR-07); the IP address
 * and user agent are always stored (PROJECT-CONTRACT §6).
 *
 * @see PRD §7.4 · BR-01 … BR-09 · PROJECT-CONTRACT §4, §6
 */

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\Session;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
final class AttendanceFactory extends Factory
{
    /** @var class-string<Attendance> */
    protected $model = Attendance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $checkIn = Clock::now();

        return [
            'session_id' => Session::factory(),
            'user_id' => User::factory(),
            'check_in_at' => $checkIn,
            'check_out_at' => $checkIn->addHours(2),
            'status' => 'present',
            'is_manual' => false,
            'edited_by' => null,
            'edit_reason' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }

    public function present(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'present']);
    }

    public function late(): self
    {
        return $this->state(fn (array $attributes): array => ['status' => 'late']);
    }

    /**
     * Never checked in at all — no instants recorded.
     */
    public function absent(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'absent',
            'check_in_at' => null,
            'check_out_at' => null,
        ]);
    }

    public function excused(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'excused',
            'check_in_at' => null,
            'check_out_at' => null,
            'is_manual' => true,
            'edit_reason' => 'Excused absence approved by the trainer.',
        ]);
    }

    /**
     * Checked in but never checked out (BR-05 leaves the record incomplete).
     */
    public function incomplete(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'incomplete',
            'check_out_at' => null,
        ]);
    }

    public function manuallyEditedBy(User $editor, string $reason): self
    {
        return $this->state(fn (array $attributes): array => [
            'is_manual' => true,
            'edited_by' => $editor->getKey(),
            'edit_reason' => $reason,
        ]);
    }
}
