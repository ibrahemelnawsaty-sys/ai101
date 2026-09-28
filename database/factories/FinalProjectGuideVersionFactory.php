<?php

declare(strict_types=1);

/**
 * One saved version of a guide page (D-127).
 *
 * @see D-127
 */

namespace Database\Factories;

use App\Models\FinalProjectGuide;
use App\Models\FinalProjectGuideVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinalProjectGuideVersion>
 */
final class FinalProjectGuideVersionFactory extends Factory
{
    /** @var class-string<FinalProjectGuideVersion> */
    protected $model = FinalProjectGuideVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $html = '<!doctype html><html><head><title>Guide</title></head><body><h1>Guide</h1></body></html>';

        return [
            'final_project_guide_id' => FinalProjectGuide::factory(),
            'version' => 1,
            'html' => $html,
            'sha256' => hash('sha256', $html),
            'bytes' => strlen($html),
            'source' => FinalProjectGuideVersion::SOURCE_EDITOR,
            'restored_from' => null,
            'created_by' => null,
        ];
    }
}
