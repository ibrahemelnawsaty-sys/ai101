<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\CohortStatus;
use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;

/**
 * The public programme directory.
 *
 * The platform is a directory of training programmes and AI 101 is only its
 * first, but until now the only public entry point was that one programme's
 * landing page. A second programme could be published from the admin panel and
 * no visitor would ever find it.
 *
 * SCOPE IS THE WHOLE POINT OF THIS CONTROLLER. `Program::published()` is the
 * only reason a draft or archived programme cannot leak onto a public page,
 * and a public listing is exactly where an unscoped query does the most damage
 * (Article 5). The scope is applied in the query, not filtered in the view.
 *
 * Every figure shown is counted in the database rather than in PHP: the cohort
 * count is a `withCount` with its own status constraint, so the page issues one
 * query for any number of programmes and cannot fall into N+1 (Article 20).
 *
 * @see BR-31, BR-36 · CONSTITUTION Art. 5, Art. 17, Art. 20 · D-39
 */
final class ProgramDirectoryController extends Controller
{
    /** Objectives and audience lines shown on a card before it gets long. */
    private const PREVIEW_LINES = 3;

    public function index(): View
    {
        $programs = $this->publishedPrograms();

        return view('public.programs', [
            'screen' => 'programs',
            'screenState' => ScreenState::of($programs === []),
            'programs' => $programs,
            'pageTitle' => __('pages.programs.title'),
            'pageDescription' => __('pages.programs.meta_description'),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function publishedPrograms(): array
    {
        $programs = Program::query()
            ->published()
            ->withCount([
                // Only cohorts a visitor could actually act on. A programme
                // with nothing but finished cohorts still belongs in the
                // directory, and its count honestly reads zero.
                'cohorts' => static fn ($query) => $query->whereIn('status', [
                    CohortStatus::Open->value,
                    CohortStatus::Upcoming->value,
                ]),
            ])
            ->orderBy('name_ar')
            ->get();

        return array_values(
            $programs
                ->map(fn (Program $program): array => [
                    'name' => (string) $program->getAttribute('name_ar'),
                    'name_en' => (string) $program->getAttribute('name_en'),
                    'slug' => (string) $program->getAttribute('slug'),
                    'description' => $program->getAttribute('description'),
                    'banner_url' => $program->getAttribute('banner_url'),
                    'open_cohorts' => (int) ($program->getAttribute('cohorts_count') ?? 0),
                    'objectives' => $this->preview($program->getAttribute('objectives')),
                    'audience' => $this->preview($program->getAttribute('target_audience')),
                ])
                ->all(),
        );
    }

    /**
     * The first few lines of a JSON list, as plain strings.
     *
     * The column holds a list of sentences. A card that printed all of them
     * would be a page of its own, so the directory shows the opening few and
     * the programme's own page carries the rest.
     *
     * @return list<string>
     */
    private function preview(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $lines = [];

        foreach ($value as $line) {
            if (is_string($line) && $line !== '') {
                $lines[] = $line;
            }

            if (count($lines) === self::PREVIEW_LINES) {
                break;
            }
        }

        return $lines;
    }
}
