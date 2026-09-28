<?php

declare(strict_types=1);

/**
 * A final project's guide in one language (D-127). The default is the Arabic
 * guide, saved but neither available nor published; the states name the two
 * steps so a test says what it needs.
 *
 * @see D-127
 */

namespace Database\Factories;

use App\Models\FinalProject;
use App\Models\FinalProjectGuide;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinalProjectGuide>
 */
final class FinalProjectGuideFactory extends Factory
{
    /** @var class-string<FinalProjectGuide> */
    protected $model = FinalProjectGuide::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'final_project_id' => FinalProject::factory(),
            'locale' => FinalProjectGuide::PRIMARY_LOCALE,
            'is_available' => false,
            'is_published' => false,
        ];
    }

    public function english(): self
    {
        return $this->state(['locale' => 'en']);
    }

    public function available(): self
    {
        return $this->state(['is_available' => true]);
    }

    public function published(): self
    {
        return $this->state(['is_available' => true, 'is_published' => true]);
    }
}
