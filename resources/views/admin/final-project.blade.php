{{--
    Admin · the final project's settings for one cohort at a time (D-109, D-110).

    Everything here used to have no screen at all: `title`, `brief`,
    `requirements`, `due_at` and `max_score` were seed-only columns, and
    opening the tab lived on the trainer's own screen. Both moved here, fully
    separate from trainer.finalProject — that screen only reads the brief and
    grades what was handed in.

    Three states beyond loading/error: no cohort exists yet · a cohort list
    with none picked · a picked cohort's settings, existing or blank.

    @see PRD §9.14 · BR-15, BR-16, BR-23 · D-109, D-110
--}}
@extends('layouts.app')

@section('title', __('admin.final_project.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('admin.states.error_title')"
            :description="__('admin.states.error_body')"
            :action-label="__('app.retry')" :action-href="route('admin.finalProject.index')" />
    @elseif (count($cohortOptions) === 0)
        <x-ui.empty-state icon="spark"
            :title="__('admin.final_project.no_cohort_title')"
            :description="__('admin.final_project.no_cohort_body')"
            :action-label="__('admin.final_project.no_cohort_action')" :action-href="route('admin.cohorts.index')" />
    @else
        <p class="form__note">{{ __('admin.final_project.intro') }}</p>

        <x-ui.card class="dc--span u-mt-4" icon="spark">
            <form method="GET" action="{{ route('admin.finalProject.index') }}" class="toolbar__filters">
                <x-ui.select name="cohort" required :label="__('admin.final_project.fields.cohort')"
                    :options="$cohortOptions" :value="$selectedCohortId" />
                <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
            </form>
        </x-ui.card>

        @if ($settings === null)
            <x-ui.empty-state icon="spark" class="u-mt-4"
                :title="__('admin.final_project.pick_cohort_title')"
                :description="__('admin.final_project.pick_cohort_body')" />
        @else
            <x-ui.card class="dc--span u-mt-4" icon="spark"
                :title="$settings->exists ? $settings->title : __('admin.final_project.title')">

                @if ($settings->isUnlocked)
                    <x-slot:action>
                        <x-ui.pill variant="success" icon="check">{{ __('trainer.final_project.state_open') }}</x-ui.pill>
                    </x-slot:action>
                @endif

                <form method="POST" action="{{ route('admin.finalProject.store') }}">
                    @csrf
                    <input type="hidden" name="cohort_id" value="{{ $selectedCohortId }}">

                    <x-ui.input name="title" required :label="__('admin.final_project.fields.title')"
                        :value="old('title', $settings->title)" />

                    <x-ui.textarea name="brief" rows="4" required
                        :label="__('admin.final_project.fields.brief')"
                        :value="old('brief', $settings->brief)" />

                    <x-ui.textarea name="requirements" rows="4"
                        :label="__('admin.final_project.fields.requirements')"
                        :value="old('requirements', $settings->requirementsLines)" />

                    <div class="f2">
                        <x-ui.input name="due_at" type="datetime-local" dir="ltr" required
                            :label="__('admin.final_project.fields.due_at')"
                            :hint="__('app.riyadh_time_hint')"
                            :value="old('due_at', $settings->dueAtValue)" />
                        <x-ui.input name="max_score" type="number" min="1" max="1000" required
                            :label="__('admin.final_project.fields.max_score')"
                            :value="old('max_score', $settings->maxScore)" />
                    </div>

                    <x-ui.checkbox name="allow_late" value="1"
                        :checked="old('allow_late', $settings->allowLate)"
                        :label="__('admin.final_project.fields.allow_late')"
                        :hint="__('admin.final_project.allow_late_hint')" />

                    <x-ui.checkbox name="is_unlocked" value="1"
                        :checked="old('is_unlocked', $settings->isUnlocked)"
                        :label="__('admin.final_project.fields.is_unlocked')"
                        :hint="__('admin.final_project.is_unlocked_hint')" />

                    @if ($settings->isUnlocked && $settings->unlockedByName)
                        <p class="footnote">
                            {{ __('admin.final_project.unlocked_note', [
                                'name' => $settings->unlockedByName,
                                'date' => \App\Support\Dates::dateTime($settings->unlockedAt),
                            ]) }}
                        </p>
                    @endif

                    <div class="row__acts">
                        <x-ui.button variant="primary" type="submit">{{ __('admin.final_project.save') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif
    @endif
@endsection
