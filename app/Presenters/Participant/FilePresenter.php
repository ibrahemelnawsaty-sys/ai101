<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Presenters\Support\Present;
use App\Support\ViewModel;
use Illuminate\Support\Collection;

/**
 * One file in a list: a trainer attachment, a hand-in, a message attachment.
 *
 * The stored path never reaches the view. `downloadUrl` is a link to a guarded
 * route or nothing at all — a file is fetched through a controller that asks
 * the policy first, never by naming its place on the private disk (PRD §12.5,
 * CONSTITUTION art. 22, art. 24).
 *
 * @see BR-22 · PRD §9.11.2, §9.12, §9.13, §12.5
 */
final class FilePresenter extends ViewModel
{
    /**
     * @param  array<array-key, mixed>  $stored  one entry of a `files` or `attachments` column
     */
    public static function fromStored(array $stored): self
    {
        $name = Present::text($stored['original_name'] ?? null)
            ?? Present::text($stored['name'] ?? null)
            ?? (string) __('app.unknown');

        return new self([
            'name' => $name,
            'sizeLabel' => Present::fileSize($stored['size_bytes'] ?? $stored['size'] ?? null),
            // No signed-download route exists for hand-ins yet; a link is only
            // published when the stored entry already carries a guarded one.
            'downloadUrl' => Present::text($stored['url'] ?? null),
        ]);
    }

    /**
     * @return Collection<int, self>
     */
    public static function collect(mixed $column): Collection
    {
        if (! is_array($column)) {
            return new Collection;
        }

        $files = [];

        foreach ($column as $entry) {
            if (is_array($entry)) {
                $files[] = self::fromStored($entry);
            }
        }

        return new Collection($files);
    }
}
