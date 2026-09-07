<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Enums\ProgramStatus;
use App\Models\Program;
use App\Presenters\Concerns\PresentsFormValues;
use App\Support\ViewModel;

/**
 * The programme editor.
 *
 * This screen is the whole answer to BR-31: the objectives, the audience and
 * the certificates a visitor reads on the landing page are rows in a table,
 * edited here, never strings in a template. They are stored as JSON lists and
 * edited as one-item-per-line textareas, so the presenter joins them on the way
 * out and the FormRequest splits them on the way back in.
 *
 * @see BR-31, BR-36 · PRD §4.2, §7.2, §9.18
 */
final class ProgramForm extends ViewModel
{
    use PresentsFormValues;

    public static function blank(): self
    {
        return new self([
            'exists' => false,
            'id' => null,
            'name' => '',
            'slug' => '',
            'summary' => '',
            'objectivesText' => '',
            'targetAudienceText' => '',
            'certificatesText' => '',
            'hours' => '',
            'status' => ProgramStatus::Draft->value,
        ]);
    }

    public static function from(Program $program): self
    {
        $status = $program->getAttribute('status');
        $hours = $program->getAttribute('hours');

        return new self([
            'exists' => true,
            'id' => (string) $program->getKey(),
            'name' => (string) $program->getAttribute('name_ar'),
            'slug' => (string) $program->getAttribute('slug'),
            'summary' => (string) ($program->getAttribute('description') ?? ''),
            'objectivesText' => self::linesFrom($program->getAttribute('objectives')),
            'targetAudienceText' => self::linesFrom($program->getAttribute('target_audience')),
            'certificatesText' => self::linesFrom($program->getAttribute('certificates')),
            'hours' => $hours === null ? '' : (int) $hours,
            'status' => $status instanceof ProgramStatus ? $status->value : (string) $status,
        ]);
    }
}
