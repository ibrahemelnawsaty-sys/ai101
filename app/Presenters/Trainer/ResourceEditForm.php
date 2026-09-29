<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Enums\ResourceType;
use App\Models\Resource;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * The values the training-kit edit drawer opens on (FR-RES-10).
 *
 * `hasAddress` says whether the address is data for this item: a link or a
 * video has one, a file has none and the drawer draws no field for it. That is
 * decided here, from the type, and not by the template (art. 13). The stored
 * path, the size and the download count are not published: the drawer edits
 * data, and none of these is data (art. 24).
 *
 * @see BR-23 · FR-RES-10 · PRD §9.12 · CONSTITUTION art. 5, art. 13, art. 24 · D-136
 */
final class ResourceEditForm extends ViewModel
{
    public static function from(Resource $resource): self
    {
        $type = $resource->getAttribute('type');
        $type = $type instanceof ResourceType ? $type : ResourceType::tryFrom((string) $type);

        return new self([
            'id' => (string) $resource->getKey(),
            'title' => (string) $resource->getAttribute('title'),
            'description' => (string) ($resource->getAttribute('description') ?? ''),
            'weekId' => Present::text($resource->getAttribute('week_id')),
            'sessionId' => Present::text($resource->getAttribute('session_id')),
            'typeLabel' => $type?->label() ?? '—',
            'hasAddress' => $type === ResourceType::Link || $type === ResourceType::Video,
            'url' => (string) ($resource->getAttribute('external_url') ?? ''),
        ]);
    }
}
