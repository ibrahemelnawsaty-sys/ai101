{{--
    Trainer sessions — schedule management for the trainer's own cohorts.
    Cancelling a session requires a reason and notifies the whole cohort (PRD §9.8.2).
    The Zoom URL is stored here but is never emitted to a participant page before its
    window opens; that guard lives in the participant controller.

    @see PRD §9.8, §9.10 · BR-23, BR-24, BR-27
--}}
@extends('layouts.app')

@section('title', __('trainer.sessions.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('trainer.sessions.error_title')"
            :description="__('trainer.sessions.error_body')"
            :action-label="__('app.retry')" :action-href="route('trainer.sessions')" />
    @else

        <div class="toolbar">
            <form method="GET" action="{{ route('trainer.sessions') }}" class="toolbar__filters">
                <x-ui.select name="week" :label="__('schedule.filter_week')" :options="$weekOptions" :value="request('week')" />
                <x-ui.select name="status" :label="__('trainer.sessions.filter_status')" :options="$statusOptions" :value="request('status')" />
                <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
            </form>
            <div class="toolbar__end">
                <x-ui.button variant="primary" size="sm"
                    :href="route('trainer.sessions', ['edit' => 'new'])">{{ __('trainer.sessions.create') }}</x-ui.button>
            </div>
        </div>

        <x-ui.card class="dc--span" flush>
            @if (is_null($sessions))
                <div class="tscroll">
                    <table class="atable">
                        <tbody>
                            @for ($i = 0; $i < 6; $i++)
                                <tr>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-1)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s24)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-3)" /></td>
                                    <td><x-ui.skeleton height="var(--s6)" width="var(--s18)" rounded="full" /></td>
                                    <td><x-ui.skeleton height="var(--s9)" width="var(--d-1)" /></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            @elseif ($sessions->isEmpty())
                <x-ui.empty-state icon="cal"
                    :title="__('trainer.sessions.empty_title')"
                    :description="__('trainer.sessions.empty_body')"
                    :action-label="__('trainer.sessions.create')"
                    :action-href="route('trainer.sessions', ['edit' => 'new'])" />
            @else
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('trainer.sessions.title') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('schedule.col_date') }}</th>
                                <th scope="col">{{ __('schedule.col_time') }}</th>
                                <th scope="col">{{ __('schedule.col_topic') }}</th>
                                <th scope="col">{{ __('trainer.sessions.col_type') }}</th>
                                <th scope="col">{{ __('schedule.col_status') }}</th>
                                <th scope="col">{{ __('trainer.sessions.col_link') }}</th>
                                <th scope="col"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sessions as $session)
                                <tr @class(['is-cancelled' => $session->isCancelled])>
                                    <td class="u-nowrap">{{ \App\Support\Dates::longDate($session->startsAt) }}</td>
                                    <td class="u-num u-nowrap">{{ \App\Support\Dates::timeRange12($session->startsAt, $session->endsAt) }}</td>
                                    <th scope="row">{{ $session->topic }}</th>
                                    <td>{{ $session->typeLabel }}</td>
                                    <td><x-ui.pill :variant="$session->statusVariant" :icon="$session->statusIcon">{{ $session->statusLabel }}</x-ui.pill></td>
                                    <td>
                                        @if ($session->hasMeetingUrl)
                                            <x-ui.pill variant="success" icon="check">{{ __('trainer.sessions.link_set') }}</x-ui.pill>
                                        @else
                                            <x-ui.pill variant="warning" icon="warn">{{ __('trainer.sessions.link_missing') }}</x-ui.pill>
                                        @endif
                                    </td>
                                    <td class="u-nowrap">
                                        <x-ui.button variant="secondary" size="sm"
                                            :href="route('trainer.sessions', ['edit' => $session->id])">{{ __('app.edit') }}</x-ui.button>
                                        <x-ui.button variant="secondary" size="sm"
                                            :href="route('trainer.attendance', ['session' => $session->id])">{{ __('nav.attendance') }}</x-ui.button>
                                        @unless ($session->isCancelled)
                                            <x-ui.button variant="danger" size="sm"
                                                :href="route('trainer.sessions', ['cancel' => $session->id])">{{ __('trainer.sessions.cancel') }}</x-ui.button>
                                        @endunless
                                    </td>
                                </tr>
                                @if ($session->isCancelled)
                                    <tr class="atable__note">
                                        <td colspan="7">{{ __('schedule.cancelled_reason', ['reason' => $session->cancellationReason]) }}</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-ui.pagination :paginator="$sessions" />
            @endif
        </x-ui.card>

        {{-- Session editor -------------------------------------------------------- --}}
        @if ($editing)
            <x-ui.card class="dc--span u-mt-4" icon="cal"
                :title="$editing->exists ? __('trainer.sessions.edit_title') : __('trainer.sessions.create')">

                <form method="POST"
                    action="{{ $editing->exists ? route('trainer.sessions.update', $editing->id) : route('trainer.sessions.store') }}">
                    @csrf
                    @if ($editing->exists)
                        @method('PATCH')
                    @endif

                    <x-ui.input name="topic" required :label="__('schedule.col_topic')"
                        :value="old('topic', $editing->topic)" />

                    <x-ui.textarea name="description" rows="4"
                        :label="__('trainer.sessions.description')"
                        :value="old('description', $editing->description)" />

                    <div class="f2">
                        <x-ui.select name="week_id" required :label="__('schedule.filter_week')"
                            :options="$weekOptions" :value="old('week_id', $editing->weekId)" />
                        <x-ui.select name="type" required :label="__('trainer.sessions.col_type')"
                            :options="$typeOptions" :value="old('type', $editing->type)" />
                        <x-ui.select name="trainer_id" required :label="__('schedule.col_trainer')"
                            :options="$trainerOptions" :value="old('trainer_id', $editing->trainerId)" />
                    </div>

                    <div class="f2">
                        <x-ui.input name="date" type="date" dir="ltr" required
                            :label="__('schedule.col_date')" :value="old('date', $editing->dateValue)" />
                        <x-ui.input name="start_time" type="time" dir="ltr" required
                            :label="__('trainer.sessions.start_time')" :value="old('start_time', $editing->startTimeValue)" />
                        <x-ui.input name="end_time" type="time" dir="ltr" required
                            :label="__('trainer.sessions.end_time')"
                            :hint="__('trainer.sessions.end_after_start')"
                            :value="old('end_time', $editing->endTimeValue)" />
                    </div>

                    <x-ui.input name="meeting_url" type="url" dir="ltr"
                        :label="__('trainer.sessions.meeting_url')"
                        :hint="__('trainer.sessions.meeting_url_hint')"
                        :value="old('meeting_url', $editing->meetingUrl)" />

                    <x-ui.input name="meeting_passcode" dir="ltr"
                        :label="__('live.passcode')"
                        :value="old('meeting_passcode', $editing->meetingPasscode)" />

                    <p class="footnote">{{ __('app.riyadh_time_hint') }}</p>

                    <div class="row__acts">
                        <x-ui.button variant="primary" type="submit">{{ __('app.save_changes') }}</x-ui.button>
                        <x-ui.button variant="ghost" :href="route('trainer.sessions')">{{ __('app.cancel') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif

        {{-- Cancellation, reason required ------------------------------------------ --}}
        @if ($cancelling)
            <x-ui.card class="dc--span u-mt-4" icon="warn" :title="__('trainer.sessions.cancel_title')">
                <div class="note note--warn">
                    <b>{{ __('trainer.sessions.cancel_notice_title') }}</b>
                    {{ __('trainer.sessions.cancel_notice_body') }}
                </div>

                <form method="POST" action="{{ route('trainer.sessions.cancel', $cancelling->id) }}">
                    @csrf

                    <p><b>{{ $cancelling->topic }}</b> ·
                        <span class="u-num">{{ \App\Support\Dates::dateTime($cancelling->startsAt) }}</span></p>

                    <x-ui.textarea name="cancel_reason" rows="3" required minlength="10"
                        :label="__('trainer.sessions.cancel_reason')"
                        :hint="__('trainer.sessions.cancel_reason_hint', ['min' => 10])" />

                    {{-- The "replacement session" field was removed in D-42. It was sent
                         by this form, validated by nothing, read by no controller and
                         stored in no column: the trainer filled it in and it vanished
                         without a word. A field that silently discards what a user typed
                         is worse than no field (art. 7). Restore it the day the feature
                         exists end to end. --}}

                    <div class="row__acts">
                        <x-ui.button variant="danger" type="submit">{{ __('trainer.sessions.confirm_cancel') }}</x-ui.button>
                        <x-ui.button variant="ghost" :href="route('trainer.sessions')">{{ __('app.back') }}</x-ui.button>
                    </div>
                </form>
            </x-ui.card>
        @endif
    @endif
@endsection
