<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Submission;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * One entry of the hand-in history (PRD §9.11.2).
 *
 * BR-19 keeps every earlier version, so this list is the proof: a resubmission
 * adds a row here, it never overwrites one.
 *
 * @see BR-19, BR-22 · PRD §9.11.2
 */
final class SubmissionVersionPresenter extends ViewModel
{
    public static function from(Submission $submission): self
    {
        $files = $submission->getAttribute('files');

        return new self([
            'number' => (int) $submission->getAttribute('version'),
            'submittedAt' => Present::toDateTime($submission->getAttribute('submitted_at')),
            'fileCount' => is_array($files) ? count($files) : 0,
        ]);
    }
}
