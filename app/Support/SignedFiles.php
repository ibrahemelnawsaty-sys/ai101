<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Storage\PrivateFileService;
use Illuminate\Database\Eloquent\Model;

/**
 * Signed download links for the files a model carries (PRD §12.5).
 *
 * Presenters call this while building a page the viewer is already allowed to
 * see. Each link is a temporary signed URL — fifteen minutes by default, from
 * PrivateFileService, the one owner of that lifetime — and the download route
 * checks the permission again on arrival (D-80).
 *
 * @see PRD §12.5 · D-80
 */
final class SignedFiles
{
    /**
     * A builder of the link for the file at a given position.
     *
     * @return callable(int): string
     */
    public static function for(string $routeName, string $parameter, Model $owner): callable
    {
        return static fn (int $index): string => app(PrivateFileService::class)->temporaryUrl($routeName, [
            $parameter => $owner->getKey(),
            'index' => $index,
        ]);
    }
}
