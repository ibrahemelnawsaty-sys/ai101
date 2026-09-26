<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * What one field of the final-project hand-in asks for (D-121).
 *
 * A closed list on purpose: every case has its own server rule in
 * SubmitFinalProjectRequest, its own control on the hand-in form and its own
 * rendering on the grading panel. A value outside it is a field no screen knows
 * how to collect, so the request refuses it (CONSTITUTION art. 7).
 *
 * @see PROJECT-CONTRACT.md §3 · D-121
 */
enum SubmissionFieldType: string
{
    use HasEnumValues;

    /** Any HTTPS address — a live demo, a video, a document. */
    case Url = 'url';

    /** A repository address that must start with https://github.com/ (D-110). */
    case Github = 'github';

    /** One or more uploaded files, with the formats and limits the field sets. */
    case File = 'file';

    /** One line of text. */
    case Text = 'text';

    /** Free text over several lines. */
    case Textarea = 'textarea';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.submission_field_type.'.$this->value);
    }

    public function isFile(): bool
    {
        return $this === self::File;
    }

    /** A link type: rendered as an anchor, typed left to right. */
    public function isLink(): bool
    {
        return $this === self::Url || $this === self::Github;
    }

    /**
     * The longest value a participant may type. Files have no length; the
     * addresses keep D-110's ceilings (500 for the live link, 255 for GitHub).
     */
    public function maxLength(): int
    {
        return match ($this) {
            self::Url, self::Text => 500,
            self::Github => 255,
            self::Textarea => 5000,
            self::File => 0,
        };
    }
}
