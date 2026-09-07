<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Review state of a participant submission.
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum SubmissionStatus: string
{
    use HasEnumValues;

    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Graded = 'graded';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.submission_status.'.$this->value);
    }
}
