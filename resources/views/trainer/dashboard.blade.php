{{--
    The trainer's own information dashboard (PR-5, batch 2): the next session,
    the cohort's attendance standing (BR-26), the BR-11 totals warning, and
    how much grading is still waiting — weekly tasks and the final project
    are authored elsewhere now (D-110, D-111), but the trainer still grades
    both, so the queue counts still belong here.

    Four states: error · loading skeleton shaped like the content · empty
    (a cohort with nothing in it yet, or no cohort at all) · normal.

    @see BR-11, BR-26 · D-110, D-111 · CONSTITUTION Art. 5, Art. 6, Art. 17
--}}
@extends('layouts.app')

@section('title', __('trainer.dashboard.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('trainer.dashboard.error_title')"
            :description="__('trainer.dashboard.error_body')"
            :action-label="__('app.retry')" :action-href="route('trainer.dashboard')" />
    @else

        @if ($totalsWarning)
            <div class="note note--warn u-mb-4" role="status">
                <b>{{ __('trainer.grading.totals_warning_title') }}</b>
                {{ __('trainer.grading.totals_warning_body', ['current' => $totalsWarning->current, 'expected' => $totalsWarning->expected]) }}
            </div>
        @endif

        {{-- The cohort's own counters (BR-26) -------------------------------------- --}}
        <div class="dgrid dgrid--stats">
            <x-ui.stat-card :value="$stats->total" :label="__('trainer.dashboard.stat_participants')" />
            <x-ui.stat-card :variant="$stats->averageAttendanceVariant"
                :value="$stats->averageAttendance . '%'"
                :label="__('trainer.dashboard.stat_avg_attendance')" />
            <x-ui.stat-card variant="error" :value="$stats->atRisk"
                :label="__('trainer.dashboard.stat_at_risk')" />
            <x-ui.stat-card :variant="$ungradedWeeklyTasks > 0 ? 'warning' : 'success'"
                :value="$ungradedWeeklyTasks"
                :label="__('trainer.dashboard.stat_ungraded_weekly')" />
            <x-ui.stat-card :variant="$ungradedFinalProject > 0 ? 'warning' : 'success'"
                :value="$ungradedFinalProject"
                :label="__('trainer.dashboard.stat_ungraded_project')" />
        </div>

        {{-- Next session ------------------------------------------------------------ --}}
        <x-ui.card class="dc--span u-mt-4" icon="video" :title="__('trainer.dashboard.next_session_title')">
            <x-slot:action>
                <a href="{{ route('trainer.sessions') }}">{{ __('app.view_all') }}</a>
            </x-slot:action>

            @if (is_null($nextSession))
                <x-ui.empty-state icon="cal"
                    :title="__('trainer.dashboard.next_session_empty_title')"
                    :description="__('trainer.dashboard.next_session_empty_body')" />
            @else
                <div class="row row--plain">
                    <div class="row__m">
                        <b>{{ $nextSession->topic }}</b>
                        <span>
                            <span class="u-num">{{ \App\Support\Dates::longDate($nextSession->startsAt) }}</span>
                            ·
                            <span class="u-num">{{ \App\Support\Dates::time12($nextSession->startsAt) }}</span>
                            —
                            <span class="u-num">{{ \App\Support\Dates::time12($nextSession->endsAt) }}</span>
                        </span>
                        <span>
                            {{ $nextSession->typeLabel }} ·
                            @if ($nextSession->isInPerson)
                                {{ $nextSession->hasLocation ? $nextSession->deliveryModeLabel : __('trainer.dashboard.next_session_no_location') }}
                            @else
                                {{ $nextSession->hasMeetingUrl ? $nextSession->deliveryModeLabel : __('trainer.dashboard.next_session_no_link') }}
                            @endif
                        </span>
                    </div>
                    <div class="row__e">
                        <x-ui.pill :variant="$nextSession->statusVariant">{{ $nextSession->statusLabel }}</x-ui.pill>
                    </div>
                </div>
            @endif
        </x-ui.card>
    @endif
@endsection
