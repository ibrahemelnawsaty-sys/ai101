{{--
    Trainer · cohort reports.

    Same figures as the admin reports screen, but restricted to the cohorts the
    trainer is assigned to: PRD §4.2 grants a trainer attendance and grade
    reports for their own cohorts only.
    Every number arrives already computed and already scoped; this template
    performs no aggregation of its own.

    The bars are plain progress bars filled from the RIGHT — no charting
    library, no canvas, nothing that would break RTL or reduced motion.

    Four states: error · loading skeleton shaped like the cards · empty · normal.

    @see PRD §4.2, §9.9.7, §9.15 · BR-11, BR-23, BR-26, BR-28
--}}
@extends('layouts.app')

@section('title', __('trainer.reports.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('trainer.reports.error_title')"
            :description="__('trainer.reports.error_body')"
            :action-label="__('app.retry')" :action-href="route('trainer.reports')" />
    @else

        <div class="toolbar">
            <form method="GET" action="{{ route('trainer.reports') }}" class="toolbar__filters">
                <x-ui.select name="cohort" :label="__('trainer.reports.filter_cohort')"
                    :options="$cohortOptions" :value="request('cohort')" />
                <x-ui.select name="week" :label="__('schedule.filter_week')"
                    :options="$weekOptions" :value="request('week')" />
                <x-ui.button variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
            </form>
            <div class="toolbar__end">
                <x-ui.button variant="secondary" size="sm"
                    :href="route('trainer.reports.export', request()->query())">{{ __('app.export_excel') }}</x-ui.button>
            </div>
        </div>

        {{-- Headline figures ------------------------------------------------------ --}}
        <div class="dgrid dgrid--stats u-mt-4">
            @if (is_null($summary))
                @for ($i = 0; $i < 5; $i++)
                    <x-ui.card>
                        <x-ui.skeleton height="var(--s7)" width="var(--s15)" />
                        <x-ui.skeleton height="var(--s3)" width="72%" class="u-mt-2" />
                    </x-ui.card>
                @endfor
            @else
                <x-ui.stat-card :value="$summary->participants"
                    :label="__('trainer.reports.stat_participants')" />
                <x-ui.stat-card :variant="$summary->attendanceVariant"
                    :value="$summary->averageAttendance . '%'"
                    :label="__('trainer.reports.stat_avg_attendance')" />
                <x-ui.stat-card variant="brand"
                    :value="$summary->averageScore . ' / ' . $summary->scoreMax"
                    :label="__('trainer.reports.stat_avg_score')" />
                <x-ui.stat-card :variant="$summary->submissionRateVariant"
                    :value="$summary->submissionRate . '%'"
                    :label="__('trainer.reports.stat_submission_rate')" />
                <x-ui.stat-card variant="brand" :value="$summary->completionRate . '%'"
                    :label="__('trainer.reports.stat_completion_rate')" />
            @endif
        </div>

        {{-- Attendance per session ------------------------------------------------- --}}
        <x-ui.card class="dc--2 u-mt-4" icon="chart" :title="__('trainer.reports.attendance_by_session')">
            @if (is_null($attendanceBySession))
                @for ($i = 0; $i < 5; $i++)
                    <div class="row row--sk">
                        <x-ui.skeleton height="var(--s3)" width="var(--d-1)" />
                        <x-ui.skeleton height="var(--s2)" width="100%" />
                        <x-ui.skeleton height="var(--s3)" width="var(--s10)" />
                    </div>
                @endfor
            @elseif ($attendanceBySession->isEmpty())
                <x-ui.empty-state icon="cal"
                    :title="__('trainer.reports.attendance_empty_title')"
                    :description="__('trainer.reports.attendance_empty_body')" />
            @else
                <ul class="trend" role="list">
                    @foreach ($attendanceBySession as $point)
                        <li class="trend__i">
                            <span class="trend__l">{{ $point->label }}</span>
                            <x-ui.progress-bar :value="$point->percent" size="sm"
                                :variant="$point->variant" :label="$point->label" />
                            <span class="trend__v u-num">{{ $point->percent }}%</span>
                        </li>
                    @endforeach
                </ul>
                <p class="footnote">{{ __('trainer.reports.attendance_note') }}</p>
            @endif
        </x-ui.card>

        {{-- Submission rate per assignment ----------------------------------------- --}}
        <x-ui.card class="dc--2 u-mt-4" icon="file" :title="__('trainer.reports.submission_by_assignment')">
            @if (is_null($submissionsByAssignment))
                @for ($i = 0; $i < 5; $i++)
                    <div class="row row--sk">
                        <x-ui.skeleton height="var(--s3)" width="var(--d-1)" />
                        <x-ui.skeleton height="var(--s2)" width="100%" />
                        <x-ui.skeleton height="var(--s3)" width="var(--s10)" />
                    </div>
                @endfor
            @elseif ($submissionsByAssignment->isEmpty())
                <x-ui.empty-state icon="file"
                    :title="__('trainer.reports.submissions_empty_title')"
                    :description="__('trainer.reports.submissions_empty_body')" />
            @else
                <ul class="trend" role="list">
                    @foreach ($submissionsByAssignment as $point)
                        <li class="trend__i">
                            <span class="trend__l">{{ $point->label }}</span>
                            <x-ui.progress-bar :value="$point->percent" size="sm"
                                :variant="$point->variant" :label="$point->label" />
                            <span class="trend__v u-num">{{ $point->percent }}%</span>
                        </li>
                    @endforeach
                </ul>
                <p class="footnote">{{ __('trainer.reports.submission_note') }}</p>
            @endif
        </x-ui.card>

        {{-- Participants below the certificate threshold (BR-26) -------------------- --}}
        <x-ui.card class="dc--span u-mt-4" icon="warn" :title="__('trainer.attendance.at_risk_title')">
            @if (is_null($atRisk))
                <div class="tscroll">
                    <table class="atable">
                        <tbody>
                            @for ($i = 0; $i < 4; $i++)
                                <tr>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-2)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s15)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s15)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-4)" /></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            @elseif ($atRisk->isEmpty())
                <x-ui.empty-state variant="success" icon="check"
                    :title="__('trainer.attendance.at_risk_empty_title')"
                    :description="__('trainer.attendance.at_risk_empty_body')" />
            @else
                <div class="tscroll">
                    <table class="atable">
                        <caption class="sr">{{ __('trainer.attendance.at_risk_title') }}</caption>
                        <thead>
                            <tr>
                                <th scope="col">{{ __('trainer.col_participant') }}</th>
                                <th scope="col">{{ __('attendance.rate.label') }}</th>
                                <th scope="col">{{ __('grades.total.title') }}</th>
                                <th scope="col">{{ __('trainer.reports.col_reason') }}</th>
                                <th scope="col"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($atRisk as $person)
                                <tr>
                                    <th scope="row">
                                        <span class="cellpair">
                                            <x-ui.avatar size="sm" :name="$person->name" />
                                            {{ $person->name }}
                                        </span>
                                    </th>
                                    <td>
                                        <x-ui.pill :variant="$person->attendanceVariant" icon="warn">
                                            <span class="u-num">{{ $person->attendancePercent }}%</span>
                                        </x-ui.pill>
                                    </td>
                                    <td class="u-num u-nowrap">{{ $person->score }} / {{ $person->scoreMax }}</td>
                                    <td>
                                        <ul class="note__list">
                                            @foreach ($person->reasons as $reason)
                                                <li>{{ $reason }}</li>
                                            @endforeach
                                        </ul>
                                    </td>
                                    <td class="u-nowrap">
                                        <x-ui.button variant="secondary" size="sm"
                                            :href="route('trainer.participants', ['view' => $person->id])">{{ __('trainer.participants.view_profile') }}</x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="footnote">{{ __('certificates.both_required') }}</p>
            @endif
        </x-ui.card>

        <p class="footnote">{{ __('trainer.reports.scope_note') }}</p>
    @endif
@endsection
