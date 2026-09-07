{{--
    Attendance — check-in / check-out with a live countdown, the personal log table,
    and the circular attendance-rate indicator.

    The button state below is a REFLECTION of the server decision, never the source of it.
    $window is computed by App\Services\Attendance\AttendanceWindow against Clock::now();
    the POST endpoints re-evaluate the window on every request and reject out-of-window
    calls with 422 regardless of what this page rendered.

    Rate colours follow PRD §9.9.6 exactly: >85 ok · 70–85 warn · <70 bad.

    @see PRD §9.9 · BR-01, BR-02, BR-03, BR-04, BR-05, BR-06, BR-07, BR-08, BR-09
--}}
@extends('layouts.app')

@section('title', __('nav.attendance'))
@section('subtitle', __('attendance.server_time_note'))

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('attendance.error_title')"
            :description="__('attendance.error_body')"
            :action-label="__('app.retry')" :action-href="route('attendance.index')" />
    @else

        {{-- Current or next session card ------------------------------------- --}}
        @if (is_null($window))
            <div class="att">
                <x-ui.skeleton height="var(--s4)" width="var(--s22)" />
                <x-ui.skeleton height="var(--s5)" width="52%" class="u-mt-2" />
                <x-ui.skeleton height="var(--s3)" width="40%" class="u-mt-1" />
                <x-ui.skeleton height="var(--s15)" width="var(--d-6)" rounded="md" class="u-mt-4" />
            </div>
        @elseif ($window->hasNoSession)
            <div class="att">
                <x-ui.empty-state icon="cal"
                    :title="__('attendance.no_session_title')"
                    :description="__('attendance.no_session_body')"
                    :action-label="__('nav.schedule')" :action-href="route('schedule')" />
            </div>
        @else
            <div class="att">
                <div class="att__ses">
                    {{ $window->isToday ? __('attendance.todays_session') : __('attendance.next_session') }}
                    <b>{{ $window->sessionTitle }}</b>
                    <span class="u-num">{{ \App\Support\Dates::longDate($window->startsAt) }}</span>
                    ·
                    <span class="u-num">{{ \App\Support\Dates::timeRange12($window->startsAt, $window->endsAt) }}</span>
                </div>

                @if ($window->isCancelled)
                    <p class="att__msg att__msg--bad" role="status">
                        <x-ui.icon name="warn" />
                        {{ __('attendance.messages.session_cancelled') }}
                    </p>
                @else
                    {{--
                        Two independent buttons. Each posts to its own endpoint; the endpoint,
                        not this markup, decides. A disabled button here is a courtesy, not a guard.
                    --}}
                    <div class="att__acts">
                        <form method="POST" action="{{ route('attendance.checkIn', $window->sessionId) }}">
                            @csrf
                            <button type="submit" class="checkin"
                                data-state="{{ $window->checkInState }}"
                                @disabled(! $window->canCheckIn)
                                aria-describedby="attendance-message">
                                @if ($window->checkInState === 'done')
                                    <x-ui.icon name="check" />
                                    {{ __('attendance.checked_in_at', ['time' => \App\Support\Dates::time12($window->checkedInAt)]) }}
                                @elseif ($window->checkInState === 'open')
                                    {{ __('attendance.check_in') }}
                                @elseif ($window->checkInState === 'wait')
                                    {{ __('attendance.opens_in') }}
                                    <x-ui.countdown :until="$window->checkInOpensAt" :server-now="$serverNow" compact />
                                @else
                                    {{ __('attendance.check_in_closed') }}
                                @endif
                            </button>
                        </form>

                        <form method="POST" action="{{ route('attendance.checkOut', $window->sessionId) }}">
                            @csrf
                            <button type="submit" class="checkin checkin--out"
                                data-state="{{ $window->checkOutState }}"
                                @disabled(! $window->canCheckOut)
                                aria-describedby="attendance-message">
                                @if ($window->checkOutState === 'done')
                                    <x-ui.icon name="check" />
                                    {{ __('attendance.checked_out_at', ['time' => \App\Support\Dates::time12($window->checkedOutAt)]) }}
                                @elseif ($window->checkOutState === 'open')
                                    {{ __('attendance.check_out') }}
                                @elseif ($window->checkOutState === 'wait')
                                    {{ __('attendance.check_out_opens_in') }}
                                    <x-ui.countdown :until="$window->checkOutOpensAt" :server-now="$serverNow" compact />
                                @else
                                    {{ __('attendance.check_out_closed') }}
                                @endif
                            </button>
                        </form>
                    </div>

                    <p class="att__msg" id="attendance-message" role="status" aria-live="polite">
                        {{ session('attendance.message') ?? $window->explanation }}
                    </p>

                    <div class="att__legend">
                        <x-ui.pill variant="neutral">{{ __('attendance.legend.present') }}</x-ui.pill>
                        <x-ui.pill variant="neutral">{{ __('attendance.legend.late') }}</x-ui.pill>
                        <x-ui.pill variant="neutral">{{ __('attendance.legend.absent') }}</x-ui.pill>
                    </div>
                @endif
            </div>
        @endif

        {{-- Rate indicator + numeric summary ---------------------------------- --}}
        <div class="dgrid u-mt-4">
            <x-ui.card icon="chart" :title="__('attendance.rate.title')">
                @if (is_null($summary))
                    <x-ui.skeleton height="var(--d-2)" width="var(--d-2)" rounded="full" />
                    <x-ui.skeleton height="var(--s3)" width="70%" class="u-mt-4" />
                @elseif ($summary->totalSessions === 0)
                    <x-ui.empty-state icon="cal"
                        :title="__('attendance.rate.empty_title')"
                        :description="__('attendance.rate.empty_body')" />
                @else
                    {{--
                        pathLength="100" lets the dash array be the percentage directly,
                        so no arithmetic is needed in the template.
                    --}}
                    <div class="arate arate--{{ $summary->rateVariant }}"
                        role="img"
                        aria-label="{{ __('attendance.rate.aria', ['rate' => $summary->ratePercent]) }}">
                        <svg viewBox="0 0 120 120" aria-hidden="true">
                            <circle class="arate__bg" cx="60" cy="60" r="52" pathLength="100"></circle>
                            <circle class="arate__fg" cx="60" cy="60" r="52" pathLength="100"
                                stroke-dasharray="{{ $summary->ratePercent }} 100"></circle>
                        </svg>
                        <div class="arate__v">
                            <b class="u-num">{{ $summary->ratePercent }}%</b>
                            <span>{{ __('attendance.rate.label') }}</span>
                        </div>
                    </div>

                    <dl class="attsum">
                        <div><dt>{{ __('attendance.summary.total') }}</dt><dd class="u-num">{{ $summary->totalSessions }}</dd></div>
                        <div><dt>{{ __('enums.attendance_status.present') }}</dt><dd class="u-num">{{ $summary->presentCount }}</dd></div>
                        <div><dt>{{ __('enums.attendance_status.late') }}</dt><dd class="u-num">{{ $summary->lateCount }}</dd></div>
                        <div><dt>{{ __('enums.attendance_status.absent') }}</dt><dd class="u-num">{{ $summary->absentCount }}</dd></div>
                        <div><dt>{{ __('enums.attendance_status.excused') }}</dt><dd class="u-num">{{ $summary->excusedCount }}</dd></div>
                        <div><dt>{{ __('enums.attendance_status.incomplete') }}</dt><dd class="u-num">{{ $summary->incompleteCount }}</dd></div>
                    </dl>

                    @if ($summary->isNearMinimum)
                        <div class="note note--warn" role="status">
                            <b>{{ __('attendance.near_minimum_title') }}</b>
                            {{ __('attendance.near_minimum_body', ['rate' => $summary->minimumRatePercent]) }}
                        </div>
                    @endif
                @endif
            </x-ui.card>

            {{-- Personal attendance log ---------------------------------------- --}}
            <x-ui.card class="dc--span" icon="cal" :title="__('attendance.log.title')">
                <x-slot:action>
                    <x-ui.button variant="secondary" size="sm" icon="down"
                        :href="route('attendance.export')">{{ __('attendance.log.export_pdf') }}</x-ui.button>
                </x-slot:action>

                <form method="GET" action="{{ route('attendance.index') }}" class="toolbar__filters">
                    <x-ui.select name="week" :label="__('schedule.filter_week')" :options="$weekOptions" :value="request('week')" />
                    <x-ui.select name="status" :label="__('attendance.filter_status')" :options="$statusOptions" :value="request('status')" />
                    <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
                </form>

                @if (is_null($records))
                    <div class="tscroll">
                        <table class="atable">
                            <tbody>
                                @for ($i = 0; $i < 6; $i++)
                                    <tr>
                                        <td><x-ui.skeleton height="var(--s3)" width="var(--d-2)" /></td>
                                        <td><x-ui.skeleton height="var(--s3)" width="var(--s20)" /></td>
                                        <td><x-ui.skeleton height="var(--s3)" width="var(--s16)" /></td>
                                        <td><x-ui.skeleton height="var(--s3)" width="var(--s16)" /></td>
                                        <td><x-ui.skeleton height="var(--s6)" width="var(--s18)" rounded="full" /></td>
                                    </tr>
                                @endfor
                            </tbody>
                        </table>
                    </div>
                @elseif ($records->isEmpty())
                    <x-ui.empty-state icon="user"
                        :title="__('attendance.log.empty_title')"
                        :description="__('attendance.log.empty_body')" />
                @else
                    <div class="tscroll">
                        <table class="atable">
                            <caption class="sr">{{ __('attendance.log.title') }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('attendance.col_session') }}</th>
                                    <th scope="col">{{ __('attendance.col_date') }}</th>
                                    <th scope="col">{{ __('attendance.col_check_in') }}</th>
                                    <th scope="col">{{ __('attendance.col_check_out') }}</th>
                                    <th scope="col">{{ __('attendance.col_status') }}</th>
                                    <th scope="col">{{ __('attendance.col_note') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($records as $record)
                                    <tr>
                                        <td>{{ $record->sessionTitle }}</td>
                                        <td class="u-num u-nowrap">{{ \App\Support\Dates::shortDate($record->sessionDate) }}</td>
                                        <td class="u-num">{{ $record->checkedInAt ? \App\Support\Dates::time12($record->checkedInAt) : '—' }}</td>
                                        <td class="u-num">{{ $record->checkedOutAt ? \App\Support\Dates::time12($record->checkedOutAt) : '—' }}</td>
                                        <td>
                                            <x-ui.pill :variant="$record->statusVariant" :icon="$record->statusIcon">{{ $record->statusLabel }}</x-ui.pill>
                                        </td>
                                        <td>{{ $record->note ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <x-ui.pagination :paginator="$records" />
                    <p class="footnote">{{ __('attendance.log.footnote') }}</p>
                @endif
            </x-ui.card>
        </div>
    @endif
@endsection
