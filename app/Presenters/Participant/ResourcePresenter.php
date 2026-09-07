<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Enums\ResourceType;
use App\Models\Resource;
use App\Presenters\Support\Present;
use App\Services\Time\Clock;
use App\Support\ViewModel;
use Carbon\CarbonImmutable;

/**
 * One item of the training kit — on the resources screen, on the dashboard's
 * new-resources card, and in the schedule's session panel.
 *
 * The stored path is never published: a download goes through the guarded
 * route, which asks the policy and then streams the file off the private disk
 * (PRD §9.12, §12.5).
 *
 * The new badge means added within the last three days, measured against the
 * server clock and never the browser's (PRD §9.5.3, BR-07).
 *
 * @see BR-07, BR-22 · PRD §9.5.3, §9.12
 */
final class ResourcePresenter extends ViewModel
{
    public static function from(Resource $resource, CarbonImmutable $now): self
    {
        $type = $resource->getAttribute('type');
        $type = $type instanceof ResourceType ? $type : ResourceType::tryFrom((string) $type);

        $addedAt = Present::toDateTime($resource->getAttribute('created_at'));
        $age = $addedAt === null ? null : $now->getTimestamp() - Clock::toUtc($addedAt)->getTimestamp();

        return new self([
            'id' => (string) $resource->getKey(),
            'title' => (string) $resource->getAttribute('title'),
            'description' => Present::text($resource->getAttribute('description')),
            'type' => $type?->value ?? ResourceType::File->value,
            'typeLabel' => $type?->label() ?? '',
            'typeIcon' => self::icon($type),
            'sizeLabel' => Present::fileSize($resource->getAttribute('size')),
            'addedAt' => $addedAt,
            'isNew' => $age !== null && $age >= 0 && $age <= Present::NEW_RESOURCE_SECONDS,
            'isPreviewable' => $type === ResourceType::File
                && Present::text($resource->getAttribute('file_url')) !== null,
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
