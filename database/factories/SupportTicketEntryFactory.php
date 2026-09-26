<?php

declare(strict_types=1);

/**
 * One line of a support ticket's timeline (D-124).
 *
 * @see D-124 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\SupportTicket;
use App\Models\SupportTicketEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicketEntry>
 */
final class SupportTicketEntryFactory extends Factory
{
    /** @var class-string<SupportTicketEntry> */
    protected $model = SupportTicketEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'support_ticket_id' => SupportTicket::factory(),
            'position' => 1,
            'actor_id' => null,
            'type' => 'note',
            'body' => $this->faker->sentence(8),
            'is_internal' => false,
        ];
    }

    public function internal(): self
    {
        return $this->state(fn (array $attributes): array => ['is_internal' => true]);
    }
}
