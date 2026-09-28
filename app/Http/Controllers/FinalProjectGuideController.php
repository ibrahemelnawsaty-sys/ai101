<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesActiveCohort;
use App\Models\FinalProject;
use App\Models\FinalProjectGuide;
use App\Models\FinalProjectGuideVersion;
use App\Models\User;
use App\Services\FinalProject\GuideContent;
use App\Services\FinalProject\GuideDocument;
use App\Support\ImpersonationContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Showing the final project's guide (D-127) — the page itself, served by
 * GuideDocument under a policy that lets none of its own scripts run.
 *
 *   participant()  /dashboard/final-project/guide — the trainee's own link, the
 *                  one the guide button, the notice and the e-mail open. No id
 *                  in it: the trainee's active cohort decides whose guide.
 *   show()         /final-project/{project}/guide — the staff link (general
 *                  supervisor, coordinators, trainers), `?lang=ar|en` to pick a
 *                  language explicitly (the supervisor's preview).
 *
 * Every request asks FinalProjectGuidePolicy::view; a refusal is a logged 403
 * (bootstrap/app.php logs every policy denial with the caller's IP). A guide
 * that does not exist is asked about as an empty, unpublished one, so the
 * trainee gets the same 403 either way and learns nothing from the difference.
 *
 * PREVIEW — during an account preview the page is not served: it lives
 * outside the platform's shell, where the preview banner Article 23 requires
 * cannot be. A page in the shell says so instead (D-129, open).
 *
 * LANGUAGE — the English page is shown only to someone whose interface is in
 * English AND only when that page may be shown to them; otherwise the Arabic
 * page (D-127: Arabic is the condition).
 *
 * @see D-127 · BR-15, BR-16, BR-22, BR-23 · CONSTITUTION Art. 5, Art. 22, Art. 24
 */
final class FinalProjectGuideController extends Controller
{
    use ResolvesActiveCohort;

    public function __construct(
        private readonly GuideContent $content,
        private readonly GuideDocument $document,
    ) {}

    public function participant(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $project = $this->activeCohort($user)?->finalProject()->first();

        return $this->serve($user, $project instanceof FinalProject ? $project : null, null);
    }

    public function show(Request $request, FinalProject $project): Response
    {
        /** @var User $user */
        $user = $request->user();

        $lang = $request->query('lang');

        return $this->serve($user, $project, is_string($lang) && in_array($lang, FinalProjectGuide::LOCALES, true) ? $lang : null);
    }

    private function serve(User $user, ?FinalProject $project, ?string $explicit): Response
    {
        $guide = $this->pick($user, $project, $explicit);

        $this->authorize('view', $guide);

        if (ImpersonationContext::isActive()) {
            return response()->view('final-project.guide-in-preview', [
                'backUrl' => $user->isParticipant() ? route('finalProject') : route('dashboard'),
            ]);
        }

        $page = $guide->exists ? $this->content->current($guide) : null;

        abort_unless($page instanceof FinalProjectGuideVersion, 404);

        return $this->document->respond((string) $page->html);
    }

    /**
     * The guide to show: the language asked for, or the viewer's language
     * when they may read it, else Arabic. A missing row comes back as a new,
     * unsaved one — the policy refuses it like any unpublished guide.
     */
    private function pick(User $user, ?FinalProject $project, ?string $explicit): FinalProjectGuide
    {
        $wanted = $explicit ?? (app()->getLocale() === 'en' ? 'en' : FinalProjectGuide::PRIMARY_LOCALE);

        $guides = $project === null
            ? collect()
            : FinalProjectGuide::query()->where('final_project_id', $project->getKey())->get()->keyBy('locale');

        $guide = $guides->get($wanted);

        if ($explicit === null && $wanted !== FinalProjectGuide::PRIMARY_LOCALE
            && (! $guide instanceof FinalProjectGuide || ! $user->can('view', $guide))) {
            $guide = $guides->get(FinalProjectGuide::PRIMARY_LOCALE);
            $wanted = FinalProjectGuide::PRIMARY_LOCALE;
        }

        if ($guide instanceof FinalProjectGuide) {
            return $guide;
        }

        return new FinalProjectGuide([
            'final_project_id' => $project?->getKey(),
            'locale' => $wanted,
        ]);
    }
}
