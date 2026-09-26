<?php

declare(strict_types=1);

namespace App\Presenters\Tickets;

use App\Enums\SupportTicketEntryType;
use App\Enums\SupportTicketLevel;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketEntry;
use App\Presenters\Concerns\PresentsPeople;
use App\Services\Tickets\TicketWorkflow;
use App\Services\Time\RiyadhFormatter;
use App\Support\ViewModel;

/**
 * One line of a ticket's timeline, worded for whoever reads it (D-124):
 *
 *  · the participant reads roles, never names — "a message from the
 *    coordinator", "moved to the general supervisor" — and never an internal
 *    line (the controller does not load them; this refuses them again);
 *  · the support team reads who did what, and internal lines are marked.
 *
 * @see D-124 · PROJECT-CONTRACT §16 · CONSTITUTION art. 15
 */
final class TicketEntryLine extends ViewModel
{
    use PresentsPeople;

    public static function from(SupportTicketEntry $entry, bool $staffView): ?self
    {
        if ($entry->is_internal && ! $staffView) {
            return null;
        }

        $formatter = app(RiyadhFormatter::class);
        $created = $entry->getAttribute('created_at');
        $attachments = self::related($entry, 'attachments');
        $files = [];

        foreach (is_iterable($attachments) ? $attachments : [] as $attachment) {
            if ($attachment instanceof SupportTicketAttachment) {
                $files[] = TicketFile::from($attachment);
            }
        }

        return new self([
            'id' => (string) $entry->getKey(),
            'headline' => $staffView ? self::staffHeadline($entry) : self::participantHeadline($entry),
            'when' => $created instanceof \DateTimeInterface ? $formatter->dateTime($created) : '',
            'iso' => $created instanceof \DateTimeInterface ? $formatter->iso($created) : '',
            'body' => $entry->body,
            'link' => $entry->link_url,
            'isInternal' => $entry->is_internal,
            'isParticipant' => in_array($entry->type, [SupportTicketEntryType::Opened, SupportTicketEntryType::Reply, SupportTicketEntryType::Reopened, SupportTicketEntryType::Closed], true),
            'icon' => self::icon($entry->type),
            'files' => $files,
        ]);
    }

    private static function participantHeadline(SupportTicketEntry $entry): string
    {
        $key = 'support.entry.'.$entry->type->value;

        return (string) match ($entry->type) {
            SupportTicketEntryType::Note => __($key, ['level' => ($entry->from_level ?? SupportTicketLevel::Coordinator)->label()]),
            SupportTicketEntryType::Escalated, SupportTicketEntryType::Returned => __($key, ['to' => self::levelLabel($entry->to_level)]),
            SupportTicketEntryType::AutoClosed => __($key, ['hours' => self::hours()]),
            default => __($key),
        };
    }

    private static function staffHeadline(SupportTicketEntry $entry): string
    {
        $actor = self::related($entry, 'actor');
        $name = $actor === null ? (string) __('support.someone') : self::personName($actor);
        $target = self::related($entry, 'target');
        $targetName = $target === null ? (string) __('support.someone') : self::personName($target);
        $to = self::levelLabel($entry->to_level);

        return (string) match ($entry->type) {
            SupportTicketEntryType::Opened => __($entry->to_level === SupportTicketLevel::Admin
                ? 'support.staff_entry.opened_safety_net'
                : 'support.staff_entry.opened', ['name' => $name]),
            SupportTicketEntryType::Note => __($entry->is_internal ? 'support.staff_entry.note_internal' : 'support.staff_entry.note', ['name' => $name]),
            SupportTicketEntryType::Escalated => $entry->actor_id === null
                ? __('support.staff_entry.escalated_system', ['to' => $to])
                : __('support.staff_entry.escalated', ['to' => $to, 'name' => $name]),
            SupportTicketEntryType::Returned => $entry->target_id !== null
                ? __('support.staff_entry.returned_to', ['target' => $targetName, 'name' => $name])
                : __('support.staff_entry.returned', ['to' => $to, 'name' => $name]),
            SupportTicketEntryType::Assigned => $entry->actor_id === null
                ? __('support.staff_entry.assigned_system', ['target' => $targetName])
                : __('support.staff_entry.assigned', ['target' => $targetName, 'name' => $name]),
            SupportTicketEntryType::AutoClosed => __('support.staff_entry.auto_closed', ['hours' => self::hours()]),
            default => __('support.staff_entry.'.$entry->type->value, ['name' => $name]),
        };
    }

    private static function levelLabel(?SupportTicketLevel $level): string
    {
        return ($level ?? SupportTicketLevel::Coordinator)->label();
    }

    private static function hours(): string
    {
        $hours = TicketWorkflow::autoCloseHours();

        return trans_choice('support.count.hours', $hours, ['count' => $hours]);
    }

    private static function icon(SupportTicketEntryType $type): string
    {
        return match ($type) {
            SupportTicketEntryType::Opened => 'plus',
            SupportTicketEntryType::Reply, SupportTicketEntryType::Message => 'chat',
            SupportTicketEntryType::Note => 'file',
            SupportTicketEntryType::Escalated => 'up',
            SupportTicketEntryType::Returned, SupportTicketEntryType::Assigned => 'user',
            SupportTicketEntryType::Resolved => 'check',
            SupportTicketEntryType::Reopened => 'route',
            SupportTicketEntryType::Closed, SupportTicketEntryType::AutoClosed => 'lock',
        };
    }
}
