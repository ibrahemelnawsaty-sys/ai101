<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Public\HomeController;
use App\Http\Requests\Admin\PreviewLandingRequest;
use App\Http\Requests\Admin\PublishLandingRequest;
use App\Http\Requests\Admin\ResetLandingRequest;
use App\Models\Cohort;
use App\Models\LandingContent;
use App\Models\LandingSetting;
use App\Models\User;
use App\Presenters\Admin\LandingEditor;
use App\Services\Audit\AuditLogger;
use App\Services\Landing\LandingCatalog;
use App\Services\Landing\LandingOverrides;
use App\Services\Time\RiyadhFormatter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View as ViewFactory;
use Illuminate\Support\Str;

/**
 * The landing-page content editor — the "Landing page content" tab (PRD §9.1, §9.18).
 *
 * This screen is the answer to BR-31 for the whole page: every sentence a
 * visitor reads, in Arabic and in English, plus this cohort's countdown
 * switch, seat figure, hero copy and questions. Four endpoints:
 *
 *   edit     the editor, modelled on the approved Sajaya editor (D-114);
 *   update   ONE publish of everything the draft changed, in one transaction;
 *   preview  the real landing page rendered with the draft — nothing stored;
 *   reset    a section's texts, or the page's, back to the lang files.
 *
 * The registration switch left this editor for the general supervisor's
 * registrations screen (D-117): the owner put opening, closing and accepting
 * in one person's hands. The editor shows where it stands and cannot move it
 * (PublishLandingRequest refuses it) — and the first publish that creates a
 * cohort's settings row leaves the switch on, as the missing row did, rather
 * than closing the registration by default behind the supervisor's back.
 *
 * @see BR-31, BR-33, BR-36 · PRD §9.1, §9.18 · CONSTITUTION Art. 5, Art. 6, Art. 8 · D-114, D-117
 */
final class LandingController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LandingCatalog $catalog,
        private readonly LandingOverrides $overrides,
    ) {}

    public function edit(): View
    {
        $this->authorize('update', new LandingSetting);
        $this->authorize('viewAny', LandingContent::class);

        $cohort = $this->currentCohort();

        return view('admin.landing', [
            'contextLabel' => $cohort?->getAttribute('name'),
            'editor' => LandingEditor::from(
                $this->catalog,
                $this->published($this->actor()),
                $cohort,
                $cohort?->landingSetting,
                [
                    'publish' => route('admin.landing.update'),
                    'preview' => route('admin.landing.preview'),
                    'reset' => route('admin.landing.reset'),
                    'home' => route('home'),
                ],
            ),
            'errorState' => null,
        ]);
    }

    /**
     * Publish the draft: the texts that changed, and the cohort's settings and
     * questions when the editor touched them. All or nothing.
     */
    public function update(PublishLandingRequest $request): JsonResponse|RedirectResponse
    {
        $actor = $this->actor();
        $cohort = $this->currentCohort();
        $settings = $request->settingColumns();
        $faq = $request->faq();

        if ($cohort === null && ($settings !== null || $faq !== null)) {
            return $this->refuse($request, 'settings', __('admin.landing.no_cohort'));
        }

        // The editor names the cohort it was showing. If another cohort has
        // become the featured one since, its settings are not this draft's to
        // overwrite.
        $shown = $request->cohortId();

        if ($cohort !== null && $shown !== null && $shown !== (string) $cohort->getKey()
            && ($settings !== null || $faq !== null)) {
            return $this->refuse($request, 'cohort_id', __('admin.landing_editor.errors.cohort_changed'), 409);
        }

        $texts = $request->texts();

        $count = DB::transaction(function () use ($texts, $settings, $faq, $cohort, $actor): int {
            $count = $this->publishTexts($texts, $actor);

            if ($cohort !== null && ($settings !== null || $faq !== null)) {
                $count += $this->publishSettings($cohort, $settings, $faq, $actor);
            }

            return $count;
        });

        $this->overrides->forget();

        $message = trans_choice('admin.landing_editor.published', $count, ['count' => $count]);

        if (! $request->expectsJson()) {
            return back()->with('status', $message);
        }

        $cohort = $this->currentCohort();

        return response()->json([
            'message' => $message,
            'state' => $this->state($actor, $cohort),
        ]);
    }

    /**
     * The real landing page, rendered with the editor's draft and never
     * stored. POST only, fetched by the editor's script: a GET that read a
     * draft from the address would let a crafted link show made-up copy on a
     * real admin address. The editor writes the page into its frame through
     * srcdoc, so this response is never framed and keeps the platform's
     * X-Frame-Options: DENY like every other.
     */
    public function preview(PreviewLandingRequest $request, RiyadhFormatter $formatter): Response
    {
        /** @var HomeController $home */
        $home = app(HomeController::class);
        $cohort = $home->featuredCohort();

        $settings = $request->settingColumns();
        $faq = $request->faq();

        if ($cohort !== null && ($settings !== null || $faq !== null)) {
            // A copy of the row, never the row: the draft is laid on an
            // unsaved clone that dies with this request (Art. 5 — a preview
            // changes nothing).
            $current = $cohort->landingSetting;
            $draft = $current instanceof LandingSetting ? clone $current : new LandingSetting;
            $draft->fill(array_merge($settings ?? [], $faq === null ? [] : ['faq' => $faq]));
            $cohort->setRelation('landingSetting', $draft);
        }

        $texts = $request->texts($this->catalog);

        if ($texts !== null) {
            $this->overrides->preview($texts);
        }

        $this->useLanguage($request->lang());

        try {
            $html = $home->render($cohort, $formatter, preview: true)->render();
        } finally {
            $this->overrides->endPreview();
        }

        return response($html)
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** A section's texts, or all of them, back to the lang files. */
    public function reset(ResetLandingRequest $request): JsonResponse|RedirectResponse
    {
        $actor = $this->actor();
        $section = $request->section();
        $keys = $section === null ? null : $this->catalog->sectionKeys($section);

        $count = DB::transaction(function () use ($actor, $keys, $section): int {
            $query = LandingContent::query()->visibleTo($actor);

            if ($keys !== null) {
                $query->whereIn('key', $keys);
            }

            $rows = $query->get();

            if ($rows->isEmpty()) {
                return 0;
            }

            $before = [];

            foreach ($rows as $row) {
                $before[(string) $row->getAttribute('key')] = ['ar' => $row->getAttribute('ar'), 'en' => $row->getAttribute('en')];
            }

            $this->audit->record(
                action: 'landing.content_reset',
                entityType: 'landing_content',
                entityId: null,
                before: ['section' => $section, 'texts' => $before],
                after: ['section' => $section, 'texts' => []],
            );

            LandingContent::query()->visibleTo($actor)->whereIn('key', array_keys($before))->delete();

            return count($before);
        });

        $this->overrides->forget();

        $message = $section === null
            ? trans_choice('admin.landing_editor.reset_all_done', $count, ['count' => $count])
            : trans_choice('admin.landing_editor.reset_section_done', $count, ['count' => $count]);

        if (! $request->expectsJson()) {
            return back()->with('status', $message);
        }

        return response()->json([
            'message' => $message,
            'state' => $this->state($actor, $this->currentCohort()),
        ]);
    }

    /**
     * @param  array<string, array<string, string|null>>  $texts  only the languages sent
     */
    private function publishTexts(array $texts, User $actor): int
    {
        if ($texts === []) {
            return 0;
        }

        $existing = LandingContent::query()
            ->visibleTo($actor)
            ->whereIn('key', array_keys($texts))
            ->get()
            ->keyBy('key');

        $before = [];
        $after = [];

        foreach ($texts as $key => $values) {
            $row = $existing->get($key);
            $old = $row instanceof LandingContent
                ? ['ar' => $row->getAttribute('ar'), 'en' => $row->getAttribute('en')]
                : ['ar' => null, 'en' => null];

            // A language that was not sent keeps its published value.
            $values = array_merge($old, $values);

            if ($old === $values) {
                continue;
            }

            $before[$key] = $old;
            $after[$key] = $values;
        }

        if ($after === []) {
            return 0;
        }

        // Logged before the rows change, inside the same transaction (Art. 8).
        $this->audit->record(
            action: 'landing.content_published',
            entityType: 'landing_content',
            entityId: null,
            before: ['texts' => $before],
            after: ['texts' => $after],
        );

        foreach ($after as $key => $values) {
            $row = $existing->get($key);

            if ($values['ar'] === null && $values['en'] === null) {
                $row?->delete();

                continue;
            }

            $row ??= new LandingContent(['key' => $key]);
            $row->fill([
                'ar' => $values['ar'],
                'en' => $values['en'],
                'updated_by' => (string) $actor->getKey(),
            ]);
            $row->save();
        }

        return count($after);
    }

    /**
     * @param  array<string, mixed>|null  $settings
     * @param  list<array{key: string|null, question: string, answer: string}>|null  $faq
     */
    private function publishSettings(Cohort $cohort, ?array $settings, ?array $faq, User $actor): int
    {
        $setting = LandingSetting::query()
            ->visibleTo($actor)
            ->firstOrNew(['cohort_id' => $cohort->getKey()]);
        $count = 0;
        $touched = false;
        $tracked = ['is_registration_open', 'countdown_enabled', 'seats_remaining_override', 'hero_title', 'hero_text', 'about_body'];

        if ($settings !== null) {
            $before = $setting->exists ? $this->audit->snapshot($setting, $tracked) : null;

            if (! $setting->exists) {
                // A missing row did not close the form (LandingSetting::switchIsOn);
                // the row that replaces it must not either. The switch is the
                // supervisor's to move, never a side effect of editing a headline.
                $setting->setAttribute('is_registration_open', true);
            }

            $setting->fill(array_merge($settings, ['cohort_id' => $cohort->getKey()]));

            if ($setting->isDirty()) {
                $touched = true;
                $this->audit->log(
                    action: 'landing.updated',
                    entity: $setting,
                    before: $before,
                    after: $this->audit->snapshot($setting, $tracked),
                );
                $count += count(array_intersect(array_keys($setting->getDirty()), $tracked));
            }
        }

        if ($faq !== null) {
            $stored = $this->storedFaq($setting);
            $known = array_column($stored, null, 'key');
            $entries = [];

            $used = [];

            foreach ($faq as $entry) {
                // A key is kept only when THIS row already holds it, and only
                // once; every other one is generated here, so the browser can
                // never aim an entry at another's key or give two entries one.
                $key = $entry['key'] !== null && isset($known[$entry['key']]) && ! isset($used[$entry['key']])
                    ? $entry['key']
                    : (string) Str::uuid();

                $used[$key] = true;
                $entries[] = ['key' => $key, 'question' => $entry['question'], 'answer' => $entry['answer']];
            }

            if ($entries !== $stored) {
                $this->audit->log(
                    action: 'landing.faq_published',
                    entity: $setting,
                    before: ['faq' => $stored],
                    after: ['faq' => $entries],
                );

                $setting->fill(['cohort_id' => $cohort->getKey(), 'faq' => $entries]);
                $touched = true;
                $count++;
            }
        }

        if ($touched) {
            $setting->save();
        }

        return $count;
    }

    /**
     * @return list<array{key: string, question: string, answer: string}>
     */
    private function storedFaq(LandingSetting $setting): array
    {
        $faq = $setting->getAttribute('faq');
        $entries = [];

        foreach (is_array($faq) ? $faq : [] as $row) {
            if (is_array($row) && is_string($row['key'] ?? null)) {
                $entries[] = [
                    'key' => $row['key'],
                    'question' => (string) ($row['question'] ?? ''),
                    'answer' => (string) ($row['answer'] ?? ''),
                ];
            }
        }

        return $entries;
    }

    /**
     * What the editor holds as "published" after a write, so it can drop the
     * published part of its draft without reloading the page.
     *
     * @return array<string, mixed>
     */
    private function state(User $actor, ?Cohort $cohort): array
    {
        return LandingEditor::publishedState($this->published($actor), $cohort, $cohort?->landingSetting);
    }

    /**
     * @return array<string, array{ar: string|null, en: string|null}>
     */
    private function published(User $actor): array
    {
        $published = [];

        foreach (LandingContent::query()->visibleTo($actor)->get(['key', 'ar', 'en']) as $row) {
            $published[(string) $row->getAttribute('key')] = [
                'ar' => $row->getAttribute('ar'),
                'en' => $row->getAttribute('en'),
            ];
        }

        return $published;
    }

    /**
     * Render the preview in the language the editor is looking at. English
     * copy is stored and previewed even while the public site serves Arabic
     * only (config athar.locales.supported).
     */
    private function useLanguage(string $lang): void
    {
        /** @var list<string> $rtl */
        $rtl = (array) config('athar.locales.rtl', ['ar']);

        App::setLocale($lang);
        ViewFactory::share('locale', $lang);
        ViewFactory::share('direction', in_array($lang, $rtl, true) ? 'rtl' : 'ltr');
    }

    private function refuse(PublishLandingRequest $request, string $field, string $message, int $status = 422): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'errors' => [$field => [$message]]], $status);
        }

        return back()->withErrors([$field => $message]);
    }

    private function actor(): User
    {
        $user = request()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * The cohort the landing page is currently about: the open one, else the
     * next one starting, else the one running now.
     */
    private function currentCohort(): ?Cohort
    {
        // Cohort::featured() is the one place that decides this. When the rule
        // lived here as well, the two copies drifted (BR-31).
        return Cohort::featured(static fn ($query) => $query->with(['landingSetting', 'program']));
    }
}
