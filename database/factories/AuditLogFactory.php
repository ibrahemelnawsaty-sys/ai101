<?php

declare(strict_types=1);

/**
 * Audit trail entries. Append-only: the table has `created_at` and nothing else
 * to update, and the model exposes no update or delete (Constitution art. 8).
 *
 * @see PRD §7.6 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
final class AuditLogFactory extends Factory
{
    /** @var class-string<AuditLog> */
    protected $model = AuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => User::factory()->admin(),
            'action' => 'entity.updated',
            'entity_type' => null,
            'entity_id' => null,
            'before' => null,
            'after' => null,
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'created_at' => Clock::now(),
        ];
    }

    public function action(string $action): self
    {
        return $this->state(fn (array $attributes): array => ['action' => $action]);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function change(string $entityType, string $entityId, ?array $before, ?array $after): self
    {
        return $this->state(fn (array $attributes): array => [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before' => $before,
            'after' => $after,
        ]);
    }
}
