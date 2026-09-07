{{--
    Admin · platform reports (PRD §9.18).

    Registrations over time, average attendance, average score, submission rate
    and completion rate, with an Excel export. The figures are aggregated on the
    server; this template renders them and nothing else.

    The bars are progress bars filled from the RIGHT — no chart library, no
    canvas: an RTL-safe, reduced-motion-safe, screen-reader-readable rendering
    where every bar also carries its number in text (Article 18: colour alone
    never carries meaning).

    Four states: error · loading skeleton shaped like the cards · empty · normal.

    @see PRD §9.18, §13.2 · BR-27, BR-28
--}}
@extends('layouts.app')

@section('title', __('admin.reports.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('admin.states.error_title')"
            :description="__('admin.states.error_body')"
            :action-label="__('app.retry')" :action-href="route('admin.reports.index')" />
    @else

        <div class="toolbar">
            <form method="GET" action="{{ route('admin.reports.index') }}" class="toolbar__filters">
                <x-ui.select name="cohort" :label="__('admin.cohorts.title')"
                    :options="$cohortOptions" :value="request('cohort')" />
                <x-ui.input name="from" type="date" dir="ltr"
                    :label="__('admin.reports.range')" :value="request('from')" />
                <x-ui.input name="to" type="date" dir="ltr"
                    :label="__('app.time.to')" :value="request('to')" />
                <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
            </form>
            <div class="toolbar__end">
                <x-ui.button variant="secondary" size="sm"
                    :href="route('admin.reports.export', request()->query())">{{ __('admin.reports.export_excel') }}</x-ui.button>
            </div>
        </div>

        {{-- Headline figures -------------------------------------------------------- --}}
        <div class="dgrid dgrid--stats u-mt-4">
            @if (is_null($summary))
                @for ($i = 0; $i < 5; $i++)
                    <x-ui.card>
                        <x-ui.skeleton height="var(--s7)" width="var(--s15)" />
                        <x-ui.skeleton height="var(--s3)" width="72%" class="u-mt-2" />
                    </x-ui.card>
                @endfor
            @else
                <x-ui.stat-card :value="$summary->registrations"
                    :label="__('admin.stats.total_registered')" />
                <x-ui.stat-card :variant="$summary->attendanceVariant"
                    :value="$summary->averageAttendance . '%'"
                    :label="__('admin.reports.average_attendance')" />
                <x-ui.stat-card variant="brand"
                    :value="$summary->averageScore . ' / ' . $summary->scoreMax"
                    :label="__('admin.reports.average_score')" />
                <x-ui.stat-card :variant="$summary->submissionRateVariant"
                    :value="$summary->submissionRate . '%'"
                    :label="__('admin.reports.submission_rate')" />
                <x-ui.stat-card variant="brand" :value="$summary->completionRate . '%'"
                    :label="__('admin.reports.completion_rate')" />
            @endif
        </div>

        {{-- Registrations over time -------------------------------------------------- --}}
        <x-ui.card class="dc--2 u-mt-4" icon="chart" :title="__('admin.reports.registrations_over_time')">
            @if (is_null($registrationsOverTime))
                @for ($i = 0; $i < 6; $i++)
                    <div class="row row--sk">
                        <x-ui.skeleton height="var(--s3)" width="var(--d-1)" />
                        <x-ui.skeleton height="var(--s2)" width="100%" />
                        <x-ui.skeleton height="var(--s3)" width="var(--s9)" />
                    </div>
                @endfor
            @elseif ($registrationsOverTime->isEmpty())
                <x-ui.empty-state icon="chart"
                    :title="__('admin.reports.empty_title')"
                    :description="__('admin.reports.empty_body')" />
            @else
                <ul class="trend" role="list">
                    @foreach ($registrationsOverTime as $point)
                        <li class="trend__i">
                            <span class="trend__l u-num">{{ $point->label }}</span>
                            <x-ui.progress-bar :value="$point->value" :max="$point->max" size="sm"
                                variant="brand" :label="$point->label" />
                            <span class="trend__v u-num">{{ $point->value }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        {{-- Attendance per cohort ------------------------------------------------------ --}}
        <x-ui.card class="dc--2 u-mt-4" icon="user" :title="__('admin.reports.average_attendance')">
            @if (is_null($attendanceByCohort))
                @for ($i = 0; $i < 4; $i++)
                    <div class="row row--sk">
                        <x-ui.skeleton height="var(--s3)" width="var(--d-1)" />
                        <x-ui.skeleton height="var(--s2)" width="100%" />
                        <x-ui.skeleton height="var(--s3)" width="var(--s9)" />
                    </div>
                @endfor
            @elseif ($attendanceByCohort->isEmpty())
                <x-ui.empty-state icon="cal"
                    :title="__('admin.reports.empty_title')"
                    :description="__('admin.reports.empty_body')" />
            @else
                <ul class="trend" role="list">
                    @foreach ($attendanceByCohort as $point)
                        <li class="trend__i">
                            <span class="trend__l">{{ $point->label }}</span>
                            <x-ui.progress-bar :value="$point->percent" size="sm"
                                :variant="$point->variant" :label="$point->label" />
                            <span class="trend__v u-num">{{ $point->percent }}%</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        {{-- Cohort table ---------------------------------------------------------------- --}}
        <x-ui.card class="dc--span u-mt-4" flush>
            @if (is_null($cohortRows))
                <div class="tscroll">
                    <table class="atable">
                        <tbody>
                            @for ($i = 0; $i < 5; $i++)
                                <tr>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-2)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s15)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s15)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s15)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s15)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s15)" /></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            @elseif ($cohortRows->isEmpty())
                <x-ui.empty-state icon="chart"
                    :title="__('admin.reports.empty_title')"
                    :description="__('admin.reports.empty_body')" />
            @else
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('admin.reports.title') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('admin.cohorts.fields.name') }}</th>
                                <th scope="col">{{ __('admin.reports.col_participants') }}</th>
                                <th scope="col">{{ __('admin.reports.average_attendance') }}</th>
                                <th scope="col">{{ __('admin.reports.average_score') }}</th>
                                <th scope="col">{{ __('admin.reports.submission_rate') }}</th>
                                <th scope="col">{{ __('admin.reports.completion_rate') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($cohortRows as $row)
                                <tr>
                                    <th scope="row">
                                        {{ $row->cohortName }}
                                        <span class="u-muted">{{ $row->programName }}</span>
                                    </th>
                                    <td class="u-num">{{ $row->participants }}</td>
                                    <td class="u-num">{{ $row->averageAttendance }}%</td>
                                    <td class="u-num u-nowrap">{{ $row->averageScore }} / {{ $row->scoreMax }}</td>
                                    <td class="u-num">{{ $row->submissionRate }}%</td>
                                    <td class="u-num">{{ $row->completionRate }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-ui.pagination :paginator="$cohortRows" />
            @endif
        </x-ui.card>
    @endif
@endsection
