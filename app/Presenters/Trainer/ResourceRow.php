<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Enums\ResourceType;
use App\Models\Resource;
use App\Presenters\Concerns\PresentsFormValues;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * One item of the training kit as the trainer manages it (PRD §9.12).
 *
 * The download count is published here and on no participant screen: PRD §9.12
 * makes it the trainer's figure alone.
 *
 * The stored path is never published. Resource hides `file_url` on the model
 * and a download goes through the guarded route that asks the policy first, so
 * this row carries the name, the type and the counts and nothing that could be
 * turned into a direct link (art. 24).
 *
 * "Archived" is the `deleted_at` stamp. App\Models\Resource does not carry the
 * SoftDeletes trait even though its table has the column, so the flag is read
 * from the column directly; teaching material is hidden, never destroyed
 * (art. 13 §11).
 *
 * @see BR-23 · PRD §9.12 · CONSTITUTION art. 5, art. 22, art. 24
 */
final class ResourceRow extends ViewModel
{
    use PresentsFormValues;

    public static function from(Resource $resource): self
    {
        $type = $resource->getAttribute('type');
        $type = $type instanceof ResourceType ? $type : ResourceType::tryFrom((string) $type);

        $week = self::related($resource, 'week');
        $session = self::related($resource, 'session');

        $archivedAt = $resource->getAttribute('deleted_at');
        $isArchived = $archivedAt !== null;

        return new self([
            'id' => (string) $resource->getKey(),
            'title' => (string) $resource->getAttribute('title'),
            'description' => Present::text($resource->getAttribute('description')),
            'type' => $type->value ?? ResourceType::File->value,
            'typeLabel' => $type?->label() ?? '—',
            'typeIcon' => self::icon($type),
            'weekTitle' => $week === null ? null : (string) $week->getAttribute('title'),
            'sessionTitle' => $session === null
                ? null
                : (string) ($session->getAttribute('topic') ?? $session->getAttribute('title')),
            'sizeLabel' => Present::fileSize($resource->getAttribute('size')) ?? '—',
            'downloadCount' => (int) $resource->getAttribute('download_count'),
            'addedAt' => $resource->getAttribute('created_at'),
            'isArchived' => $isArchived,
            'stateLabel' => $isArchived
                ? (string) __('trainer.resources.state_archived')
                : (string) __('trainer.resources.state_active'),
            // Burnt orange for archived, never yellow (art. 14); an archived
            // item is set aside, not an error, so it is not `error` either.
            'stateVariant' => $isArchived ? 'warning' : 'success',
            'stateIcon' => $isArchived ? 'folder' : 'check',
        ]);
    }

    private static function icon(?ResourceType $type): string
    {
        return match ($type) {
            ResourceType::Link => 'globe',
            ResourceType::Video => 'video',
            default => 'file',
        };
    }
}
