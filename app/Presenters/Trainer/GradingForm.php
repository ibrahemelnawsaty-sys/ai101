<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Models\Submission;
use App\Models\User;
use App\Presenters\Concerns\PresentsPeople;
use App\Presenters\Concerns\PresentsVariants;
use App\Presenters\Shared\FileLink;
use App\Support\ViewModel;

/**
 * The quick-grading panel the submissions board opens on `?submission={id}`
 * (PRD §9.15). The key is QUERY_KEY, read by the board and written by every
 * link into it — the profile's grade button still sent `grade` after the board
 * was renamed, and opened the board with the panel shut (D-72).
 *
 * The panel is a form, so every value it publishes is a form value: `score` is
 * what pre-fills the number input, `feedback` what pre-fills the textarea when
 * a recorded grade is being amended, `maxScore` the input's own ceiling.
 *
 * None of that is the rule. BR-12 (the ceiling), BR-13 (feedback of at least
 * ten characters) and BR-14 (an amendment needs a written reason) are enforced
 * by the FormRequest and by EvaluationRecorder; the attributes here mirror them
 * so the person typing is told early, and a direct POST that ignores them is
 * still refused (art. 5).
 *
 * `isGraded` is what makes the panel show the amendment notice and the
 * mandatory reason field — a presentation decision taken here, on the server,
 * from whether an evaluation exists.
 *
 * @see BR-12, BR-13, BR-14, BR-19, BR-23 · PRD §9.11.3, §9.15 · CONSTITUTION art. 5, art. 24
 */
final class GradingForm extends ViewModel
{
    /** The one spelling of the query key that opens this panel. */
    public const QUERY_KEY = 'submission';

    use PresentsPeople;
    use PresentsVariants;

    public static function from(Submission $submission): self
    {
        $user = self::related($submission, 'user');
        $assignment = self::related($submission, 'assignment');
        $evaluation = self::related($submission, 'latestEvaluation');

        $rawScore = self::attr($evaluation, 'score');
        $isGraded = $rawScore !== null;

        $maxScore = self::attr($assignment, 'max_score');

        return new self([
            'id' => (string) $submission->getKey(),
            'participantId' => $user instanceof User ? (string) $user->getKey() : null,
            'participantName' => self::personName($user),
            'name' => self::personName($user),
            'email' => self::personEmail($user),
            'fullNameAr' => self::personNameAr($user),
            'fullNameEn' => self::personNameEn($user),
            'assignmentTitle' => self::text($assignment, 'title'),

            'files' => FileLink::collection($submission->getAttribute('files')),
            'githubUrl' => self::stringOrNull($submission->getAttribute('github_url')),
            'note' => self::stringOrNull($submission->getAttribute('note')),
            'version' => (int) $submission->getAttribute('version'),
            'submittedAt' => $submission->getAttribute('submitted_at'),
            'isLate' => (bool) $submission->getAttribute('is_late'),

            'isGraded' => $isGraded,
            // BR-14 revises an EVALUATION, not a submission, so the form needs
            // its id to address the endpoint at all. The evaluation was already
            // resolved above and its key was kept private, which is why the
            // revision form posted to the record endpoint and was refused.
            'evaluationId' => $evaluation === null ? null : (string) $evaluation->getKey(),
            // Which of the two endpoints the panel's form addresses. Decided
            // here rather than in the template: the answer needs the evaluation,
            // and a Blade island carrying that decision is business logic in a
            // view (art. 13).
            'isRevision' => $isGraded && $evaluation !== null,
            // The number input is pre-filled with the recorded mark when a
            // grade is being amended, and left empty otherwise — a zero would
            // read as "I decided zero" rather than "nothing decided yet".
            'score' => $isGraded ? self::score((float) $rawScore) : null,
            'feedback' => self::stringOrNull(self::attr($evaluation, 'feedback')),
            'maxScore' => self::score((float) ($maxScore ?? 0)),
            'scoreMax' => self::score((float) ($maxScore ?? 0)),
        ]);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
