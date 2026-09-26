<?php

declare(strict_types=1);

/**
 * A support ticket (D-124), for tests that need one in a given state without
 * walking it there. Anything that tests the walk itself goes through
 * TicketWorkflow, the one writer.
 *
 * @see D-124 · PROJECT-CONTRACT §4
 */

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Tickets\TicketNumbers;
use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicket>
 */
final class SupportTicketFactory extends Factory
{
    /** @var class-string<SupportTicket> */
    protected $model = SupportTicket::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => TicketNumbers::generate(),
            'opener_id' => User::factory(),
            'cohort_id' => Cohort::factory(),
            'category' => 'platform',
            'subject' => $this->faker->sentence(4),
            'status' => 'open',
            'level' => 'coordinator',
            'assignee_id' => null,
            'last_activity_at' => Clock::now(),
        ];
    }
}
