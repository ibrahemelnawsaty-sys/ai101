<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Enums\BroadcastKind;
use App\Models\Broadcast;
use App\Models\Cohort;
use App\Models\Profile;
use App\Models\User;
use App\Presenters\Support\Present;
use App\Support\Dates;
use App\Support\ViewModel;

/**
 * One line of the send history (D-87): when, to which cohort, what, to how
 * many, and by whom.
 *
 * @see PRD §9.18 · D-87
 */
final class BroadcastRow extends ViewModel
{
    public static function from(Broadcast $broadcast): self
    {
        $kind = $broadcast->getAttribute('kind');
        $kind = $kind instanceof BroadcastKind ? $kind : BroadcastKind::tryFrom((string) $kind);

        $cohort = $broadcast->getRelationValue('cohort');
        $sender = $broadcast->getRelationValue('sender');
        $profile = $sender instanceof User ? $sender->getRelationValue('profile') : null;

        return new self([
            'id' => (string) $broadcast->getKey(),
            'sentAt' => Dates::dateTime($broadcast->getAttribute('created_at')),
            'cohortName' => $cohort instanceof Cohort ? (string) $cohort->getAttribute('name') : '',
            'kindLabel' => $kind?->label() ?? '',
            'kindVariant' => match ($kind) {
                BroadcastKind::Message => 'brand',
                BroadcastKind::Sessions => 'info',
                BroadcastKind::Assignments => 'warning',
                default => 'default',
            },
            // A reminder has no subject of its own; its kind says what it was.
            'subject' => Present::text($broadcast->getAttribute('subject')) ?? ($kind?->label() ?? ''),
            'recipients' => (int) $broadcast->getAttribute('recipients'),
            'emails' => (int) $broadcast->getAttribute('emails'),
            'senderName' => $profile instanceof Profile
                ? (Present::text($profile->getAttribute('full_name_ar')) ?? '')
                : ($sender instanceof User ? (string) $sender->getAttribute('email') : ''),
        ]);
    }
}
