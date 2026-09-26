<?php

declare(strict_types=1);

namespace App\Presenters\Tickets;

use App\Enums\SupportTicketLevel;
use App\Enums\SupportTicketStatus;
use App\Models\SupportTicket;
use App\Models\SupportTicketEntry;
use App\Models\User;
use App\Presenters\Concerns\PresentsPeople;
use App\Services\Tickets\TicketRouting;
use App\Services\Tickets\TicketWorkflow;
use App\Services\Time\RiyadhFormatter;
use App\Support\ViewModel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One support ticket's page (D-124): its header, its timeline, and what the
 * person reading it may do — each decided here from the policy, never in the
 * template (art. 5). The forms the page does not offer are refused by their
 * routes anyway.
 *
 * @see D-124 · PROJECT-CONTRACT §16 · CONSTITUTION art. 5, art. 17
 */
final class TicketPage extends ViewModel
{
    use PresentsPeople;

    /**
     * @param  Collection<int, SupportTicketEntry>  $entries
     */
    public static function from(SupportTicket $ticket, Collection $entries, User $viewer, bool $staffView, CarbonImmutable $now): self
    {
        $formatter = app(RiyadhFormatter::class);
        $routing = app(TicketRouting::class);

        $closed = TicketWorkflow::isClosedAt($ticket, $now);
        $status = $closed ? SupportTicketStatus::Closed : $ticket->status;
        $closesAt = $closed ? null : TicketWorkflow::closesAt($ticket);
        // Due but not yet swept: it closed at the end of its day.
        $closedAt = $ticket->closed_at ?? ($closed ? TicketWorkflow::closesAt($ticket) : null);
        $cohort = self::related($ticket, 'cohort');

        $lines = [];

        foreach ($entries as $entry) {
            $line = TicketEntryLine::from($entry, $staffView);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        $canReturn = $viewer->can('returnDown', $ticket);
        $below = $ticket->level->below();
        $returnsToCoordinator = $canReturn && $below === SupportTicketLevel::Coordinator;
        $coordinatorOptions = $returnsToCoordinator || $viewer->can('assign', $ticket)
            ? self::coordinatorOptions($ticket, $routing)
            : [];

        $canAct = $viewer->can('note', $ticket);

        return new self([
            'id' => (string) $ticket->getKey(),
            'number' => (string) $ticket->number,
            'subject' => (string) $ticket->subject,
            'category' => $ticket->category->label(),
            'statusLabel' => $status->label(),
            'statusVariant' => $status->variant(),
            'where' => $closed ? null : $ticket->level->label(),
            'openedAt' => $ticket->getAttribute('created_at') instanceof \DateTimeInterface
                ? $formatter->dateTime($ticket->getAttribute('created_at'))
                : '',
            'isStaffView' => $staffView,
            'opener' => $staffView ? self::personName(self::related($ticket, 'opener')) : null,
            'cohort' => $staffView && $cohort !== null ? (string) $cohort->getAttribute('name') : null,
            'holder' => $staffView && $ticket->level === SupportTicketLevel::Coordinator && ! $closed
                ? self::personName(self::related($ticket, 'assignee'))
                : null,
            'isClosed' => $closed,
            'resolvedNote' => $closesAt === null ? null : (string) __(
                $staffView ? 'support.show.resolved_note_staff' : 'support.show.resolved_note',
                ['when' => $formatter->dateTime($closesAt)],
            ),
            'closedNote' => $closed && $closedAt instanceof \DateTimeInterface ? (string) __(
                $staffView ? 'support.show.closed_note_staff' : 'support.show.closed_note',
                ['when' => $formatter->dateTime($closedAt)],
            ) : null,
            'entries' => $lines,

            // The participant.
            'canReply' => $viewer->can('reply', $ticket),
            'canClose' => $viewer->can('close', $ticket),
            'replyReopens' => $ticket->status === SupportTicketStatus::Resolved && ! $closed,

            // The support team.
            'canNote' => $canAct,
            'canWriteToParticipant' => $viewer->can('writeToParticipant', $ticket),
            'canResolve' => $viewer->can('resolve', $ticket),
            'canEscalate' => $viewer->can('escalate', $ticket),
            'escalateTo' => $ticket->level->above()?->label(),
            'canReturn' => $canReturn,
            'returnTo' => $below?->label(),
            'returnsToCoordinator' => $returnsToCoordinator,
            'canAssign' => $viewer->can('assign', $ticket),
            'coordinatorOptions' => $coordinatorOptions,
            'assignOptions' => array_values(array_filter(
                $coordinatorOptions,
                static fn (array $option): bool => $option['value'] !== (string) $viewer->getKey(),
            )),
            'defaultCoordinator' => $routing->primaryIdOf($ticket),
            'followOnly' => $staffView && ! $canAct && ! $closed,
            'hoursText' => trans_choice('support.count.hours', TicketWorkflow::autoCloseHours(), ['count' => TicketWorkflow::autoCloseHours()]),
        ]);
    }

    /**
     * The cohort's coordinators who can act, the primary one marked.
     *
     * @return list<array{value: string, label: string}>
     */
    private static function coordinatorOptions(SupportTicket $ticket, TicketRouting $routing): array
    {
        $ids = $routing->coordinatorIds($ticket);

        if ($ids === []) {
            return [];
        }

        $primaryId = $routing->primaryIdOf($ticket);
        $people = User::query()->with('profile')->whereKey($ids)->get()->keyBy(static fn (User $user): string => (string) $user->getKey());
        $options = [];

        foreach ($ids as $id) {
            $person = $people->get($id);

            if (! $person instanceof User) {
                continue;
            }

            $name = self::personName($person);

            $options[] = [
                'value' => $id,
                'label' => $id === $primaryId ? (string) __('support.actions.primary_mark', ['name' => $name]) : $name,
            ];
        }

        return $options;
    }
}
