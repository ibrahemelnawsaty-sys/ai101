<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasEnumValues;

/**
 * Kind of artefact an evaluation is attached to.
 *
 * @see PROJECT-CONTRACT.md §3
 */
enum EvaluationEntity: string
{
    use HasEnumValues;

    case Assignment = 'assignment';
    case FinalProject = 'final_project';

    /**
     * Human label, resolved from lang/{locale}/enums.php.
     */
    public function label(): string
    {
        return __('enums.evaluation_entity.'.$this->value);
    }
}
