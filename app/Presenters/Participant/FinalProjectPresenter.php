<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\FinalProject;
use App\Presenters\Support\Present;
use App\Support\ViewModel;
use Illuminate\Support\Collection;

/**
 * The final-project brief (PRD §9.14).
 *
 * This object is built only after the controller has established that the
 * project is unlocked for this account. While it is locked nothing of the brief
 * is constructed at all, so nothing of it can reach the page source — that is
 * BR-16 stated as a contract between the controller and the template, and this
 * presenter is on the far side of it.
 *
 * @see BR-15, BR-16, BR-22, BR-36 · PRD §9.14
 */
final class FinalProjectPresenter extends ViewModel
{
    public static function from(FinalProject $project): self
    {
        $maxFiles = (int) config('athar.uploads.max_files');
        $maxBytes = (int) config('athar.uploads.max_kilobytes') * 1024;

        return new self([
            'title' => (string) $project->getAttribute('title'),
            'description' => Present::text($project->getAttribute('brief')),
            'requirements' => new Collection(Present::stringList($project->getAttribute('requirements'))),
            // `final_projects` carries no marking-rubric column
            // (PROJECT-CONTRACT §4), so the criteria table renders its own
            // empty state until one exists — see the batch report.
            'criteria' => new Collection,
            'maxScore' => (int) $project->getAttribute('max_score'),
            'attachments' => FilePresenter::collect($project->getAttribute('attachments')),
            'dueAt' => Present::toDateTime($project->getAttribute('due_at')),
            'maxFiles' => $maxFiles,
            'maxFileBytes' => $maxBytes,
            'maxFileSizeLabel' => Present::fileSize($maxBytes) ?? '',
        ]);
    }
}
