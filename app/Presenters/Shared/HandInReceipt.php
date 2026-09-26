<?php

declare(strict_types=1);

namespace App\Presenters\Shared;

use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Presenters\Concerns\PresentsPeople;
use App\Services\FinalProject\SubmissionFields;
use App\Support\QrSvg;
use App\Support\ViewModel;

/**
 * The receipt of one final-project hand-in (D-122): its code, the QR that
 * opens this same receipt, what was handed in, when, which version — and the
 * next step, which is waiting for the evaluation until a grade is recorded.
 *
 * Built only for someone the policy already let see the hand-in: its owner on
 * the project page and the receipt page, the cohort's trainer or the general
 * supervisor on the receipt page. The owner is pointed back to the project;
 * staff to the grading panel. Relations are read only when already loaded, so
 * the page stays one query per relation (art. 19).
 *
 * @see BR-22, BR-23 · FR-NOTIF-15 · PRD §9.14.2 · D-122 · PROJECT-CONTRACT §16
 */
final class HandInReceipt extends ViewModel
{
    use PresentsPeople;

    public static function from(ProjectSubmission $submission, FinalProject $project, bool $viewerOwnsIt): self
    {
        $code = (string) $submission->getAttribute('receipt_code');
        $url = route('finalProject.receipt', ['code' => $code]);
        $owner = self::related($submission, 'user');
        $isGraded = self::related($submission, 'latestEvaluation') !== null;

        $items = [];

        foreach (SubmissionFields::read($submission->getAttribute('answers')) as $answer) {
            $filled = $answer['type']->isFile() ? $answer['files'] !== [] : $answer['value'] !== null;

            if ($filled && $answer['label'] !== '') {
                $items[] = $answer['label'];
            }
        }

        return new self([
            'code' => $code,
            'url' => $url,
            'qrSvg' => QrSvg::of($url),
            'qrLabel' => (string) __('project.receipt.qr_label', ['code' => $code]),
            'projectTitle' => (string) $project->getAttribute('title'),
            'participantName' => $owner instanceof User ? self::personName($owner) : '—',
            'submittedAt' => $submission->getAttribute('submitted_at'),
            'versionLabel' => (string) __('project.receipt.version', ['version' => (int) $submission->getAttribute('version')]),
            'isLate' => (bool) $submission->getAttribute('is_late'),
            'items' => $items,
            'isGraded' => $isGraded,
            'nextStep' => (string) __($isGraded ? 'project.receipt.next_graded' : 'project.receipt.next_pending'),
            'isOwner' => $viewerOwnsIt,
            'projectUrl' => $viewerOwnsIt ? route('finalProject') : null,
            'gradesUrl' => $viewerOwnsIt && $isGraded ? route('grades') : null,
            'gradingUrl' => $viewerOwnsIt ? null : route('trainer.finalProject', [
                'cohort' => $project->getAttribute('cohort_id'),
                'grade' => $submission->getKey(),
            ]),
        ]);
    }
}
