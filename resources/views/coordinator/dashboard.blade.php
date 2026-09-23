{{--
    The coordinator's own information dashboard (PR-5, batch 2): the next
    session, sessions still missing a join link or a location (D-109's own
    job to complete), and the excuse-request queue waiting on a decision
    (D-106). Sessions and attendance stay on the trainer's own screens
    (D-109 gave the coordinator write access to those directly); this screen
    is the one home that is genuinely the coordinator's own.

    Four states: error · loading skeleton shaped like the content · empty
    (a cohort with nothing pending, or no cohort at all) · normal.

    @see D-105, D-106, D-109 · CONSTITUTION Art. 5, Art. 6, Art. 17
--}}
@extends('layouts.app')

@section('title', __('coordinator.dashboard.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('coordinator.dashboard.error_title')"
            :description="__('coordinator.dashboard.error_body')"
            :action-label="__('app.retry')" :action-href="route('coordinator.dashboard')" />
    @else

        {{-- The coordinator's own counters ----------------------------------------- --}}
        <div class="dgrid dgrid--stats">
            <x-ui.stat-card :value="$participantCount" :label="__('coordinator.dashboard.stat_participants')" />
            <x-ui.stat-card :variant="$pendingExceptionsTotal > 0 ? 'warning' : 'success'"
                :value="$pendingExceptionsTotal"
                :label="__('coordinator.dashboard.stat_pending_exceptions')" />
        </div>

        {{-- Next session ------------------------------------------------------------ --}}
        <x-ui.card class="dc--span u-mt-4" icon="video" :title="__('coordinator.dashboard.next_session_title')">
            <x-slot:action>
                <a href="{{ route('trainer.sessions') }}">{{ __('app.view_all') }}</a>
            </x-slot:action>

            @if (is_null($nextSession))
                <x-ui.empty-state icon="cal"
                    :title="__('coordinator.dashboard.next_session_empty_title')"
                    :description="__('coordinator.dashboard.next_session_empty_body')" />
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
                                {{ $nextSession->hasLocation ? $nextSession->deliveryModeLabel : __('coordinator.dashboard.next_session_no_location') }}
                            @else
                                {{ $nextSession->hasMeetingUrl ? $nextSession->deliveryModeLabel : __('coordinator.dashboard.next_session_no_link') }}
                            @endif
                        </span>
                    </div>
                    <div class="row__e">
                        <x-ui.pill :variant="$nextSession->statusVariant">{{ $nextSession->statusLabel }}</x-ui.pill>
                    </div>
                </div>
            @endif
        </x-ui.card>

        {{-- Sessions still missing a link or a location (D-109) -------------------- --}}
        <x-ui.card class="dc--2 u-mt-4" icon="warn" :title="__('coordinator.dashboard.attention_queue_title')">
            <x-slot:action>
                <a href="{{ route('trainer.sessions') }}">{{ __('app.view_all') }}</a>
            </x-slot:action>

            @if ($sessionsNeedingAttention->isEmpty())
                <x-ui.empty-state variant="success" icon="check" size="sm"
                    :title="__('coordinator.dashboard.attention_queue_empty_title')"
                    :description="__('coordinator.dashboard.attention_queue_empty_body')" />
            @else
                @foreach ($sessionsNeedingAttention as $row)
                    <div class="row">
                        <div class="row__m">
                            <b>{{ $row->topic }}</b>
                            <span>
                                <span class="u-num">{{ \App\Support\Dates::longDate($row->startsAt) }}</span>
                                ·
                                {{ $row->isInPerson ? __('coordinator.dashboard.attention_queue_in_person') : __('coordinator.dashboard.attention_queue_online') }}
                            </span>
                        </div>
                        <div class="row__e">
                            <x-ui.button variant="secondary" size="sm"
                                :href="route('trainer.sessions', ['edit' => $row->id])">{{ __('coordinator.dashboard.attention_queue_action') }}</x-ui.button>
                        </div>
                    </div>
                @endforeach
            @endif
        </x-ui.card>

        {{-- Pending excuse requests (D-106) ------------------------------------------ --}}
        <x-ui.card class="dc--2 u-mt-4" icon="warn" :title="__('attendance.exceptions_queue.title')">
            <x-slot:action>
                <a href="{{ route('trainer.attendance') }}">{{ __('app.view_all') }}</a>
            </x-slot:action>

            @if ($pendingExceptions->isEmpty())
                <x-ui.empty-state icon="check" size="sm"
                    :title="__('attendance.exceptions_queue.empty_title')"
                    :description="__('attendance.exceptions_queue.empty_body')" />
            @else
                @foreach ($pendingExceptions as $item)
                    <div class="row">
                        <div class="row__m">
                            <b>{{ $item->participantName }}</b>
                            <span>
                                {{ $item->sessionTitle }} ·
                                <span class="u-num">{{ $item->sessionDate }}</span>
                            </span>
                        </div>
                        <div class="row__e">
                            <x-ui.pill variant="warning">{{ $item->typeLabel }}</x-ui.pill>
                        </div>
                    </div>
                @endforeach
            @endif
        </x-ui.card>
    @endif
@endsection
