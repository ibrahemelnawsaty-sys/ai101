{{--
    Programme schedule — two views (weekly accordion, visual calendar) with a toggle
    whose choice is remembered for the next visit. Server time in Asia/Riyadh only.
    Below 768px the calendar collapses into a chronological card list (handled in CSS).

    @see PRD §9.8 · BR-07, BR-24
--}}
@extends('layouts.app')

@section('title', __('nav.schedule'))
@section('subtitle', __('schedule.subtitle'))

@section('content')
    <div x-data="atharSchedule({ initial: @js($preferredView), storageKey: 'athar.schedule.view' })">

        <div class="toolbar">
            <div class="segmented" role="tablist" aria-label="{{ __('schedule.view_switch') }}">
                <button type="button" role="tab"
                    x-bind:aria-selected="view === 'accordion'"
                    x-bind:class="{ 'is-on': view === 'accordion' }"
                    x-on:click="select('accordion')"
                    class="segmented__b">{{ __('schedule.view_accordion') }}</button>
                <button type="button" role="tab"
                    x-bind:aria-selected="view === 'calendar'"
                    x-bind:class="{ 'is-on': view === 'calendar' }"
                    x-on:click="select('calendar')"
                    class="segmented__b">{{ __('schedule.view_calendar') }}</button>
            </div>

            <form method="GET" action="{{ route('schedule') }}" class="toolbar__filters">
                <x-ui.select name="week" :label="__('schedule.filter_week')" :options="$weekOptions" :value="request('week')" />
                <x-ui.select name="type" :label="__('schedule.filter_type')" :options="$typeOptions" :value="request('type')" />
                <x-ui.select name="attendance" :label="__('schedule.filter_attendance')" :options="$attendanceOptions" :value="request('attendance')" />
                <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
            </form>

            <div class="toolbar__end">
                <x-ui.button variant="secondary" size="sm" icon="cal"
                    :href="route('schedule.ics')">{{ __('schedule.export_ics') }}</x-ui.button>
                <x-ui.button variant="secondary" size="sm" icon="down"
                    :href="route('schedule.pdf')">{{ __('schedule.export_pdf') }}</x-ui.button>
            </div>
        </div>

        @if ($errorState ?? false)
            <x-ui.empty-state variant="error" icon="warn"
                :title="__('schedule.error_title')"
                :description="__('schedule.error_body')"
                :action-label="__('app.retry')" :action-href="route('schedule')" />
        @elseif (is_null($weeks))
            <div class="wk">
                @for ($i = 0; $i < 4; $i++)
                    <div class="wk__i">
                        <div class="wk__hd">
                            <x-ui.skeleton height="var(--s9)" width="var(--s9)" rounded="md" />
                            <div class="wk__t">
                                <x-ui.skeleton height="var(--s4)" width="46%" />
                                <x-ui.skeleton height="var(--s3)" width="30%" class="u-mt-1" />
                            </div>
                            <x-ui.skeleton height="var(--s6)" width="var(--s22)" rounded="full" />
                        </div>
                    </div>
                @endfor
            </div>
        @elseif ($weeks->isEmpty())
            <x-ui.empty-state icon="cal"
                :title="__('schedule.empty_title')"
                :description="__('schedule.empty_body')" />
        @else
            {{-- View 1 · weekly accordion. The current week is open by default. --}}
            <div class="wk" x-show="view === 'accordion'" x-cloak>
                @foreach ($weeks as $week)
                    <details class="wk__i {{ $week->isCurrent ? 'is-current' : '' }}" @if ($week->isCurrent) open @endif>
                        <summary class="wk__hd">
                            <div class="wk__no {{ $week->isCurrent ? '' : 'wk__no--muted' }}">
                                <span class="u-num">{{ $week->paddedIndex }}</span>
                            </div>
                            <div class="wk__t">
                                <b>{{ $week->title }}</b>
                                <span>
                                    <span class="u-num">{{ \App\Support\Dates::shortRange($week->startsOn, $week->endsOn) }}</span>
                                    · {{ trans_choice('schedule.session_count', $week->sessionCount, ['count' => $week->sessionCount]) }}
                                </span>
                            </div>
                            @if ($week->isCurrent)
                                <x-ui.pill variant="live">{{ __('schedule.current_week') }}</x-ui.pill>
                            @else
                                <x-ui.pill :variant="$week->attendanceVariant" :icon="$week->attendanceIcon">
                                    <span class="u-num">{{ $week->attendedCount }}</span>/<span class="u-num">{{ $week->sessionCount }}</span>
                                    {{ __('attendance.short_label') }}
                                </x-ui.pill>
                            @endif
                        </summary>

                        <div class="wk__body">
                            @if ($week->sessions->isEmpty())
                                <x-ui.empty-state icon="cal" size="sm"
                                    :title="__('schedule.week_empty_title')"
                                    :description="__('schedule.week_empty_body')" />
                            @else
                                <div class="tscroll">
                                    <table class="atable">
                                        <caption class="sr">{{ __('schedule.table_caption', ['week' => $week->title]) }}</caption>
                                        <thead>
                                            <tr>
                                                <th scope="col">{{ __('schedule.col_date') }}</th>
                                                <th scope="col">{{ __('schedule.col_time') }}</th>
                                                <th scope="col">{{ __('schedule.col_title') }}</th>
                                                <th scope="col">{{ __('schedule.col_topic') }}</th>
                                                <th scope="col">{{ __('schedule.col_trainer') }}</th>
                                                <th scope="col">{{ __('schedule.col_status') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($week->sessions as $session)
                                                <tr class="{{ $session->isNext ? 'is-next' : '' }} {{ $session->isCancelled ? 'is-cancelled' : '' }}">
                                                    <td class="u-nowrap">{{ \App\Support\Dates::longDate($session->startsAt) }}</td>
                                                    <td class="u-num u-nowrap">{{ \App\Support\Dates::timeRange12($session->startsAt, $session->endsAt) }}</td>
                                                    <td>{{ $session->title }}</td>
                                                    <td>{{ $session->topic }}</td>
                                                    <td>{{ $session->trainerName ?? __('app.not_assigned') }}</td>
                                                    <td>
                                                        @if ($session->isNext && ! $session->isCancelled)
                                                            <x-ui.pill variant="primary" icon="clock">
                                                                <x-ui.countdown :until="$session->startsAt" :server-now="$serverNow" compact />
                                                            </x-ui.pill>
                                                        @else
                                                            <x-ui.pill :variant="$session->statusVariant" :icon="$session->statusIcon">{{ $session->statusLabel }}</x-ui.pill>
                                                        @endif
                                                    </td>
                                                </tr>
                                                @if ($session->isCancelled)
                                                    <tr class="atable__note">
                                                        <td colspan="6">
                                                            {{ __('schedule.cancelled_reason', ['reason' => $session->cancellationReason]) }}
                                                            @if ($session->replacementStartsAt)
                                                                · {{ __('schedule.replacement_at', ['when' => \App\Support\Dates::dateTime($session->replacementStartsAt)]) }}
                                                            @endif
                                                        </td>
                                                    </tr>
                                                @endif
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>

            {{-- View 2 · visual calendar. Collapses to a card list under 768px. --}}
            <div class="cal" x-show="view === 'calendar'" x-cloak>
                <div class="cal__nav">
                    <x-ui.button variant="secondary" size="sm" icon="chev-prev"
                        :href="route('schedule', ['week' => $calendar->previousWeekIndex, 'view' => 'calendar'])"
                        :disabled="! $calendar->previousWeekIndex">{{ __('schedule.previous_week') }}</x-ui.button>
                    <b>{{ $calendar->rangeLabel }}</b>
                    <x-ui.button variant="secondary" size="sm"
                        :href="route('schedule', ['view' => 'calendar'])">{{ __('schedule.today') }}</x-ui.button>
                    <x-ui.button variant="secondary" size="sm" icon="chev-next"
                        :href="route('schedule', ['week' => $calendar->nextWeekIndex, 'view' => 'calendar'])"
                        :disabled="! $calendar->nextWeekIndex">{{ __('schedule.next_week') }}</x-ui.button>
                </div>

                @if ($calendar->days->isEmpty())
                    <x-ui.empty-state icon="cal"
                        :title="__('schedule.calendar_empty_title')"
                        :description="__('schedule.calendar_empty_body')" />
                @else
                    <div class="cal__grid">
                        @foreach ($calendar->days as $day)
                            <div class="cal__day {{ $day->isToday ? 'is-today' : '' }}">
                                <div class="cal__dayhd">
                                    <b>{{ $day->weekdayLabel }}</b>
                                    <span class="u-num">{{ \App\Support\Dates::shortDate($day->date) }}</span>
                                </div>
                                @forelse ($day->sessions as $session)
                                    <a class="cal__ev cal__ev--{{ $session->type }}"
                                        href="{{ route('schedule', ['session' => $session->id, 'view' => 'calendar']) }}">
                                        <span class="u-num">{{ \App\Support\Dates::time12($session->startsAt) }}</span>
                                        <b>{{ $session->title }}</b>
                                        <span>{{ $session->topic }}</span>
                                        <x-ui.pill :variant="$session->statusVariant" size="sm">{{ $session->statusLabel }}</x-ui.pill>
                                    </a>
                                @empty
                                    <p class="cal__none">{{ __('schedule.no_sessions_that_day') }}</p>
                                @endforelse
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Side panel with the selected session's full detail. --}}
                @if ($selectedSession)
                    <aside class="cal__panel" aria-label="{{ __('schedule.session_details') }}">
                        <h3>{{ $selectedSession->topic }}</h3>
                        <p class="u-num">{{ \App\Support\Dates::longDate($selectedSession->startsAt) }} · {{ \App\Support\Dates::timeRange12($selectedSession->startsAt, $selectedSession->endsAt) }}</p>
                        <p>{{ $selectedSession->description }}</p>
                        <x-ui.button variant="secondary" size="sm"
                            :href="route('schedule.session.ics', $selectedSession->id)">{{ __('schedule.add_to_calendar') }}</x-ui.button>
                        @if ($selectedSession->resources->isNotEmpty())
                            <ul class="cal__res">
                                @foreach ($selectedSession->resources as $resource)
                                    <li><a href="{{ route('resources.index', ['highlight' => $resource->id]) }}">{{ $resource->title }}</a></li>
                                @endforeach
                            </ul>
                        @endif
                    </aside>
                @endif
            </div>
        @endif

        <p class="footnote">{{ __('schedule.timezone_note') }}</p>
    </div>
@endsection
