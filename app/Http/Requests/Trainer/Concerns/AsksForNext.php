<?php

declare(strict_types=1);

namespace App\Http\Requests\Trainer\Concerns;

/**
 * The «save and go to the next» flag of the two grading forms.
 *
 * It is validated like every other field (art. 22): a value that is not a
 * boolean is refused instead of being read as "no", so a stale or crafted form
 * is told instead of silently landing somewhere else. It carries no authority
 * of its own — where the trainer lands is chosen by GradingQueue inside the
 * hand-in the policy already approved.
 *
 * @see FR-ASGN-29 · CONSTITUTION art. 22 · D-136
 */
trait AsksForNext
{
    /**
     * @return array<string, list<string>>
     */
    protected function nextRules(): array
    {
        return ['next' => ['sometimes', 'boolean']];
    }

    public function wantsNext(): bool
    {
        return $this->boolean('next');
    }
}
