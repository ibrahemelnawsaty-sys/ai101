<?php

declare(strict_types=1);

/**
 * Preview (impersonation) sessions. Capped at 30 minutes and never opened on
 * another administrator (BR-33, BR-34, BR-35); those rules are enforced in the
 * service layer against `started_at`.
 *
 * @see PRD §9.20 · BR-33, BR-34, BR-35 · Constitution art. 23 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\ImpersonationSession;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImpersonationSession>
 */
final class ImpersonationSessionFactory extends Factory
{
    /** @var class-string<ImpersonationSession> */
    protected $model = ImpersonationSession::class;

    /** The hard ceiling on a preview session, in minutes (Constitution art. 23). */
    public const MAX_MINUTES = 30;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admin_id' => User::factory()->admin(),
            'target_id' => User::factory()->participant(),
            'started_at' => Clock::now(),
            'ended_at' => null,
        ];
    }

    public function ended(): self
    {
        return $this->state(fn (array $attributes): array => ['ended_at' => Clock::now()]);
    }

    /**
     * Started long enough ago that the 30-minute cap has already been passed.
     */
    public function expired(): self
    {
        return $this->state(fn (array $attributes): array => [
            'started_at' => Clock::now()->subMinutes(self::MAX_MINUTES + 1),
        ]);
    }
}
