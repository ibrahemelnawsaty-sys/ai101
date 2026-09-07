<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Models\Program;
use App\Support\ViewModel;

/**
 * The programme a confirmation panel is about to archive.
 *
 * Archiving is a status change, never a deletion: the programme, its cohorts,
 * their attendance and their certificates all stay exactly where they are
 * (PRD §7.8). The panel therefore needs nothing but the name it is confirming.
 *
 * @see PRD §7.8, §9.18 · CONSTITUTION art. 13 #11
 */
final class ArchiveTarget extends ViewModel
{
    public static function from(Program $program): self
    {
        return new self([
            'id' => (string) $program->getKey(),
            'name' => (string) $program->getAttribute('name_ar'),
        ]);
    }
}
