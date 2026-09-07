<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Grading domain failures.
 *
 * @see BR-11, BR-12, BR-13, BR-14 · PRD §9.15.4
 */
final class GradingException extends DomainException
{
    /** BR-12 — the score must sit inside [0, max_score]. */
    public static function scoreOutOfRange(float $max): self
    {
        return new self('grades.errors.score_out_of_range', ['max' => self::number($max)], 422);
    }

    /** BR-13 — feedback is mandatory and at least 10 characters long. */
    public static function feedbackTooShort(int $minimum): self
    {
        return new self('grades.errors.feedback_too_short', ['min' => $minimum], 422);
    }

    /** BR-14 — revising a recorded score requires a written reason. */
    public static function revisionReasonRequired(int $minimum): self
    {
        return new self('grades.errors.revision_reason_required', ['min' => $minimum], 422);
    }

    public static function alreadyEvaluated(): self
    {
        return new self('grades.errors.already_evaluated', [], 409);
    }

    public static function maxScoreMissing(): self
    {
        return new self('grades.errors.max_score_missing', [], 422);
    }

    public static function submissionNotFound(): self
    {
        return new self('grades.errors.submission_not_found', [], 404);
    }

    public static function notEnrolled(): self
    {
        return new self('grades.errors.not_enrolled', [], 403);
    }

    /**
     * Renders a decimal score without trailing zeros, in Latin digits.
     */
    private static function number(float $value): string
    {
        $formatted = number_format($value, 2, '.', '');

        if (str_contains($formatted, '.')) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return $formatted === '' ? '0' : $formatted;
    }
}
