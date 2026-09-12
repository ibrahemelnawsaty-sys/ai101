<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one way a stored file leaves the platform (PRD §12.5).
 *
 * WHY THIS EXISTS
 * Files were stored carefully — type from the bytes, random names, outside the
 * web root — and then could not be read by anybody. There was no route for a
 * submitted file, so a trainer could not open a single hand-in to grade it;
 * and none for the attachments a trainer puts on an assignment or on the final
 * project, so participants could not open the brief (D-80).
 *
 * Every link is a signed URL that expires after fifteen minutes, minted only on
 * a page that already passed the permission check — and the permission is
 * asked AGAIN here, on arrival, of the file's own policy. A link forwarded to
 * someone else, or used after the person lost access, answers 403.
 *
 * The files live in each model's `files` or `attachments` column as the
 * descriptors PrivateFileService::store() wrote; the route names one by its
 * position.
 *
 * @see PRD §12.5 · BR-22, BR-23 · CONSTITUTION art. 5, art. 24 · D-80
 */
final class FileDownloadController extends Controller
{
    public function submission(Submission $submission, int $index): StreamedResponse
    {
        $this->authorize('download', $submission);

        return $this->stream($submission, 'files', $index);
    }

    public function projectSubmission(ProjectSubmission $projectSubmission, int $index): StreamedResponse
    {
        $this->authorize('download', $projectSubmission);

        return $this->stream($projectSubmission, 'files', $index);
    }

    public function assignment(Assignment $assignment, int $index): StreamedResponse
    {
        $this->authorize('view', $assignment);

        return $this->stream($assignment, 'attachments', $index);
    }

    public function finalProject(FinalProject $project, int $index): StreamedResponse
    {
        $this->authorize('view', $project);

        return $this->stream($project, 'attachments', $index);
    }

    private function stream(Model $owner, string $column, int $index): StreamedResponse
    {
        $files = $owner->getAttribute($column);
        $descriptor = is_array($files) ? ($files[$index] ?? null) : null;

        if (! is_array($descriptor)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $path = $descriptor['path'] ?? null;
        $disk = is_string($descriptor['disk'] ?? null) && $descriptor['disk'] !== '' ? $descriptor['disk'] : 'private';

        if (! is_string($path) || $path === '' || ! Storage::disk($disk)->exists($path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $name = $descriptor['original_name'] ?? $descriptor['name'] ?? basename($path);

        return Storage::disk($disk)->download($path, is_string($name) && $name !== '' ? $name : basename($path));
    }
}
