<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\CohortStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FaqEntryRequest;
use App\Http\Requests\Admin\UpdateLandingRequest;
use App\Models\Cohort;
use App\Models\LandingSetting;
use App\Presenters\Admin\LandingSettings;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

/**
 * Landing-page settings (PRD §9.1, §9.18).
 *
 * This screen is the answer to BR-31: the hero sentence, the questions and
 * answers, the seat figure and the registration switch are all data, edited by
 * the centre, never strings in a template. Turning registration off here closes
 * the public form on the next request — the check is re-read on every
 * registration attempt, not cached into the page (Art. 5).
 *
 * @see BR-31, BR-36 · PRD §9.1, §9.18 · CONSTITUTION Art. 5, Art. 6
 */
final class LandingController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function edit(): View
    {
        $this->authorize('update', new LandingSetting());

        $cohort = $this->currentCohort();

        return view('admin.landing', [
            'contextLabel' => $cohort?->getAttribute('name'),
            'settings' => LandingSettings::from($cohort?->landingSetting()->first(), $cohort),
            'errorState' => null,
        ]);
    }

    public function update(UpdateLandingRequest $request): RedirectResponse
    {
        $cohort = $this->currentCohort();

        if ($cohort === null) {
            return back()->withErrors(['hero_title' => __('admin.landing.no_cohort')]);
        }

        $setting = LandingSetting::query()->firstOrNew(['cohort_id' => $cohort->getKey()]);

        $before = $setting->exists
            ? $this->audit->snapshot($setting, ['is_registration_open', 'seats_remaining_override'])
            : null;

        $setting->fill(array_merge($request->columns(), ['cohort_id' => $cohort->getKey()]));

        $this->audit->log(
            action: 'landing.updated',
            entity: $setting,
            before: $before,
            after: $this->audit->snapshot($setting, ['is_registration_open', 'seats_remaining_override']),
        );

        $setting->save();

        return back()->with('status', __('admin.landing.saved'));
    }

    /**
     * Add one question and answer.
     *
     * The FAQ is a JSON column on `landing_settings`, not a table of its own
     * (PROJECT-CONTRACT §4), so an entry is addressed by the key stored beside
     * it rather than by a database id. The key is generated here and never
     * taken from the browser, so one entry can never overwrite another.
     */
    public function storeFaq(FaqEntryRequest $request): RedirectResponse
    {
        $cohort = $this->currentCohort();

        if ($cohort === null) {
            return back()->withErrors(['question' => __('admin.landing.no_cohort')]);
        }

        $setting = LandingSetting::query()->firstOrNew(['cohort_id' => $cohort->getKey()]);

        $entries = $this->entries($setting);
        $entries[] = array_merge($request->entry(), ['key' => (string) Str::uuid()]);

        $this->persistFaq($setting, $cohort->getKey(), $entries, 'landing.faq_added');

        return back()->with('status', __('admin.landing.faq_saved'));
    }

    public function updateFaq(FaqEntryRequest $request, string $entry): RedirectResponse
    {
        $cohort = $this->currentCohort();

        if ($cohort === null) {
            return back()->withErrors(['question' => __('admin.landing.no_cohort')]);
        }

        $setting = LandingSetting::query()->firstOrNew(['cohort_id' => $cohort->getKey()]);

        $entries = array_map(
            static fn (array $row): array => ($row['key'] ?? null) === $entry
                ? array_merge($row, $request->entry())
                : $row,
            $this->entries($setting)
        );

        $this->persistFaq($setting, $cohort->getKey(), $entries, 'landing.faq_updated');

        return back()->with('status', __('admin.landing.faq_saved'));
    }

    public function destroyFaq(string $entry): RedirectResponse
    {
        $this->authorize('update', new LandingSetting());

        $cohort = $this->currentCohort();

        if ($cohort === null) {
            return back();
        }

        $setting = LandingSetting::query()->firstOrNew(['cohort_id' => $cohort->getKey()]);

        $entries = array_values(array_filter(
            $this->entries($setting),
            static fn (array $row): bool => ($row['key'] ?? null) !== $entry
        ));

        $this->persistFaq($setting, $cohort->getKey(), $entries, 'landing.faq_removed');

        return back()->with('status', __('admin.landing.faq_removed'));
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function persistFaq(LandingSetting $setting, mixed $cohortId, array $entries, string $action): void
    {
        $before = $setting->exists ? ['faq_count' => count($this->entries($setting))] : null;

        $setting->fill(['cohort_id' => $cohortId, 'faq' => $entries]);

        $this->audit->log($action, $setting, $before, ['faq_count' => count($entries)]);

        $setting->save();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entries(LandingSetting $setting): array
    {
        $faq = $setting->getAttribute('faq');

        if (! is_array($faq)) {
            return [];
        }

        return array_values(array_filter($faq, 'is_array'));
    }

    /**
     * The cohort the landing page is currently about: the open one, else the
     * next one starting.
     */
    private function currentCohort(): ?Cohort
    {
        /** @var Cohort|null $cohort */
        $cohort = Cohort::query()
            ->with('landingSetting')
            ->whereIn('status', [CohortStatus::Open->value, CohortStatus::Upcoming->value])
            ->orderBy('start_date')
            ->first();

        return $cohort;
    }
}
