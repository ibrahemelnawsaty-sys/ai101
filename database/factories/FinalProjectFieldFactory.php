<?php

declare(strict_types=1);

/**
 * One field of a final project's hand-in form (D-121).
 *
 * The default is a required one-line text field; the states give the other
 * types their own settings so a test names what it needs rather than
 * assembling a row by hand.
 *
 * @see D-121
 */

namespace Database\Factories;

use App\Enums\SubmissionFieldType;
use App\Enums\SubmissionFileFormat;
use App\Models\FinalProject;
use App\Models\FinalProjectField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinalProjectField>
 */
final class FinalProjectFieldFactory extends Factory
{
    /** @var class-string<FinalProjectField> */
    protected $model = FinalProjectField::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'final_project_id' => FinalProject::factory(),
            'type' => SubmissionFieldType::Text,
            'label' => fake('en_US')->words(2, true),
            'description' => null,
            'tips' => null,
            'is_required' => true,
            'accepted_formats' => null,
            'max_kilobytes' => null,
            'max_files' => null,
            'position' => 1,
        ];
    }

    public function optional(): self
    {
        return $this->state(fn (array $attributes): array => ['is_required' => false]);
    }

    public function url(): self
    {
        return $this->state(fn (array $attributes): array => ['type' => SubmissionFieldType::Url]);
    }

    public function github(): self
    {
        return $this->state(fn (array $attributes): array => ['type' => SubmissionFieldType::Github]);
    }

    public function textarea(): self
    {
        return $this->state(fn (array $attributes): array => ['type' => SubmissionFieldType::Textarea]);
    }

    /**
     * An upload field.
     *
     * @param  list<SubmissionFileFormat>  $formats
     */
    public function file(array $formats = [SubmissionFileFormat::Pdf], int $maxFiles = 1, ?int $maxKilobytes = null): self
    {
        return $this->state(fn (array $attributes): array => [
            'type' => SubmissionFieldType::File,
            'accepted_formats' => array_map(static fn (SubmissionFileFormat $f): string => $f->value, $formats),
            'max_files' => $maxFiles,
            'max_kilobytes' => $maxKilobytes,
        ]);
    }
}
