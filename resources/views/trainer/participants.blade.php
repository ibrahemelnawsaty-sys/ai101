{{--
    Trainer · cohort participants.

    The trainer sees the people in THEIR cohorts and nobody else: the roster is
    produced by a query already scoped by the cohort.scope middleware and the
    policy (BR-23). This template never filters — it only renders what the
    server decided the trainer may see.

    The full profile panel shows programme data only, which is exactly what
    the PRD §4.2 matrix grants a trainer: the full profile of a user, restricted
    to their own cohorts.

    Four states: error · loading skeleton shaped like the table · empty · normal.

    @see PRD §3.2, §4.2, §9.9.7 · BR-22, BR-23, BR-28
--}}
@extends('layouts.app')

@section('title', __('trainer.participants.title'))
@section('subtitle', $contextLabel ?? '')

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('trainer.participants.error_title')"
            :description="__('trainer.participants.error_body')"
            :action-label="__('app.retry')" :action-href="route('trainer.participants')" />
    @else

        {{-- Cohort counters ------------------------------------------------------ --}}
        <div class="dgrid dgrid--stats">
            @if (is_null($stats))
                @for ($i = 0; $i < 4; $i++)
                    <x-ui.card>
                        <x-ui.skeleton height="var(--s7)" width="var(--s14)" />
                        <x-ui.skeleton height="var(--s3)" width="70%" class="u-mt-2" />
                    </x-ui.card>
                @endfor
            @else
                <x-ui.stat-card :value="$stats->total" :label="__('trainer.participants.stat_total')" />
                <x-ui.stat-card variant="brand" :value="$stats->activeCount"
                    :label="__('trainer.participants.stat_active')" />
                <x-ui.stat-card :variant="$stats->averageAttendanceVariant"
                    :value="$stats->averageAttendance . '%'"
                    :label="__('trainer.participants.stat_avg_attendance')" />
                <x-ui.stat-card variant="error" :value="$stats->atRisk"
                    :label="__('trainer.participants.stat_at_risk')" />
            @endif
        </div>

        {{-- Roster ---------------------------------------------------------------- --}}
        <x-ui.card class="dc--span u-mt-4" flush>
            <div class="tablebar">
                <form method="GET" action="{{ route('trainer.participants') }}" class="toolbar__filters">
                    <x-ui.search-input name="q" :value="request('q')"
                        :placeholder="__('trainer.participants.search')" />
                    <x-ui.select clearable name="state" :label="__('trainer.participants.filter_state')"
                        :options="$stateOptions" :value="request('state')" />
                    <x-ui.button icon="filter" variant="secondary" size="sm" type="submit">{{ __('app.apply_filters') }}</x-ui.button>
                </form>
                <div class="toolbar__end">
                    {{-- D-117 — no export from inside an account preview. --}}
                    @unless ($impersonation ?? null)
                        <x-ui.button icon="download" variant="secondary" size="sm"
                            :href="route('trainer.participants.export', request()->query())">{{ __('app.export_excel') }}</x-ui.button>
                    @endunless
                </div>
            </div>

            @if (is_null($rows))
                <div class="tscroll">
                    <table class="atable">
                        <tbody>
                            @for ($i = 0; $i < 8; $i++)
                                <tr>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-2)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--d-3)" /></td>
                                    <td><x-ui.skeleton height="var(--s2)" width="var(--d-1)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s16)" /></td>
                                    <td><x-ui.skeleton height="var(--s3)" width="var(--s16)" /></td>
                                    <td><x-ui.skeleton height="var(--s6)" width="var(--s18)" rounded="full" /></td>
                                    <td><x-ui.skeleton height="var(--s9)" width="var(--s24)" /></td>
                                </tr>
                            @endfor
                        </tbody>
                    </table>
                </div>
            @elseif ($rows->isEmpty())
                <x-ui.empty-state icon="users"
                    :title="request()->hasAny(['q', 'state']) ? __('trainer.participants.no_match_title') : __('trainer.participants.empty_title')"
                    :description="request()->hasAny(['q', 'state']) ? __('trainer.participants.no_match_body') : __('trainer.participants.empty_body')"
                    :action-label="request()->hasAny(['q', 'state']) ? __('app.clear_filters') : null"
                    :action-href="request()->hasAny(['q', 'state']) ? route('trainer.participants') : null" />
            @else
                <div class="tscroll">
                    <table class="atable atable--stack" role="table">
                        <caption class="sr">{{ __('trainer.participants.roster_caption') }}</caption>
                        <thead role="rowgroup">
                            <tr role="row">
                                <th scope="col" role="columnheader">{{ __('trainer.col_participant') }}</th>
                                <th scope="col" role="columnheader">{{ __('trainer.participants.col_contact') }}</th>
                                <th scope="col" role="columnheader">{{ __('attendance.rate.label') }}</th>
                                <th scope="col" role="columnheader">{{ __('trainer.participants.col_submissions') }}</th>
                                <th scope="col" role="columnheader">{{ __('grades.total.title') }}</th>
                                <th scope="col" role="columnheader">{{ __('trainer.participants.col_state') }}</th>
                                <th scope="col" role="columnheader"><span class="sr">{{ __('app.actions.label') }}</span></th>
                            </tr>
                        </thead>
                        <tbody role="rowgroup">
                            @foreach ($rows as $row)
                                <tr role="row" @class(['is-selected' => $row->id === ($selected->id ?? null)])>
                                    <th scope="row" role="rowheader" class="atable__lead">
                                        <span class="cellpair">
                                            <x-ui.avatar size="sm" :name="$row->name" />
                                            {{ $row->name }}
                                        </span>
                                    </th>
                                    <td role="cell" data-label="{{ __('trainer.participants.col_contact') }}" dir="ltr" class="u-ltr">{{ $row->email }}</td>
                                    <td role="cell" data-label="{{ __('attendance.rate.label') }}">
                                        <x-ui.progress-bar :value="$row->attendancePercent" size="sm"
                                            :variant="$row->attendanceVariant"
                                            :label="__('attendance.rate.label')" />
                                        <span class="u-num">{{ $row->attendancePercent }}%</span>
                                    </td>
                                    <td role="cell" data-label="{{ __('trainer.participants.col_submissions') }}" class="u-nowrap"><span class="u-num">{{ $row->submittedCount }} / {{ $row->assignmentsCount }}</span></td>
                                    <td role="cell" data-label="{{ __('grades.total.title') }}">
                                        @if ($row->hasScore)
                                            <b class="row__score u-num"><span>{{ $row->score }}</span><small> / {{ $row->scoreMax }}</small></b>
                                        @else
                                            <span class="u-muted">—</span>
                                        @endif
                                    </td>
                                    <td role="cell" data-label="{{ __('trainer.participants.col_state') }}">
                                        <x-ui.pill :variant="$row->stateVariant" :icon="$row->stateIcon">{{ $row->stateLabel }}</x-ui.pill>
                                    </td>
                                    <td role="cell" class="u-nowrap">
                                        <x-ui.button icon="eye" :icon-only="true" variant="secondary" size="sm" :context="$row->name"
                                            :href="route('trainer.participants', array_merge(request()->query(), ['view' => $row->id]))">{{ __('trainer.participants.view_profile') }}</x-ui.button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <x-ui.pagination :paginator="$rows" />
            @endif
        </x-ui.card>

        {{-- Full profile of one participant ---------------------------------------- --}}
        @if ($selected)
            <x-ui.card class="dc--span u-mt-4" icon="user"
                :title="__('trainer.participants.profile_title', ['name' => $selected->name])">

                <div class="f2">
                    <div>
                        <h3 class="abrief__sub">{{ __('trainer.participants.section_identity') }}</h3>
                        <dl class="deflist">
                            <div><dt>{{ __('profile.full_name_ar') }}</dt><dd>{{ $selected->fullNameAr }}</dd></div>
                            <div><dt>{{ __('profile.full_name_en') }}</dt><dd dir="ltr">{{ $selected->fullNameEn }}</dd></div>
                            <div><dt>{{ __('profile.email') }}</dt><dd dir="ltr">{{ $selected->email }}</dd></div>
                            <div><dt>{{ __('profile.phone') }}</dt><dd dir="ltr">{{ $selected->phone }}</dd></div>
                            <div><dt>{{ __('trainer.participants.enrolled_at') }}</dt><dd class="u-when">{{ \App\Support\Dates::longDate($selected->enrolledAt) }}</dd></div>
                        </dl>
                    </div>

                    <div>
                        <h3 class="abrief__sub">{{ __('trainer.participants.section_progress') }}</h3>

                        <x-ui.progress-bar :value="$selected->attendancePercent"
                            :variant="$selected->attendanceVariant"
                            :label="__('attendance.rate.label')" />
                        <div class="pmeta">
                            <span>{{ trans_choice('attendance.rate.of_total', $selected->attendedSessions, ['attended' => $selected->attendedSessions, 'total' => $selected->totalSessions]) }}</span>
                            <span class="u-num">{{ $selected->attendancePercent }}%</span>
                        </div>

                        <x-ui.progress-bar :value="$selected->journeyPercent" variant="brand"
                            :label="__('journey.progress.title')" class="u-mt-4" />
                        <div class="pmeta">
                            <span>{{ __('journey.progress.current_step', ['step' => $selected->currentStepTitle]) }}</span>
                            <span class="u-num">{{ $selected->journeyPercent }}%</span>
                        </div>

                        <x-ui.progress-bar :value="$selected->score" :max="$selected->scoreMax" variant="brand"
                            :label="__('grades.total.title')" class="u-mt-4" />
                        <div class="pmeta">
                            <span>{{ __('grades.pass_score', ['score' => $selected->passScore]) }}</span>
                            <span class="u-num">{{ $selected->score }} / {{ $selected->scoreMax }}</span>
                        </div>
                    </div>
                </div>

                <h3 class="abrief__sub">{{ __('trainer.participants.section_submissions') }}</h3>

                @if ($selected->submissions->isEmpty())
                    <x-ui.empty-state icon="file"
                        :title="__('trainer.participants.submissions_empty_title')"
                        :description="__('trainer.participants.submissions_empty_body')" />
                @else
                    <div class="tscroll">
                        <table class="atable atable--stack" role="table">
                            <caption class="sr">{{ __('trainer.participants.section_submissions') }}</caption>
                            <thead role="rowgroup">
                                <tr role="row">
                                    <th scope="col" role="columnheader">{{ __('trainer.assignments.col_title') }}</th>
                                    <th scope="col" role="columnheader">{{ __('trainer.submissions.col_submitted_at') }}</th>
                                    <th scope="col" role="columnheader">{{ __('trainer.submissions.col_state') }}</th>
                                    <th scope="col" role="columnheader">{{ __('grades.score') }}</th>
                                    <th scope="col" role="columnheader"><span class="sr">{{ __('app.actions.label') }}</span></th>
                                </tr>
                            </thead>
                            <tbody role="rowgroup">
                                @foreach ($selected->submissions as $submission)
                                    <tr role="row">
                                        <th scope="row" role="rowheader" class="atable__lead">{{ $submission->assignmentTitle }}</th>
                                        <td role="cell" data-label="{{ __('trainer.submissions.col_submitted_at') }}" class="u-when u-nowrap">
                                            {{ $submission->submittedAt ? \App\Support\Dates::dateTime($submission->submittedAt) : '—' }}
                                        </td>
                                        <td role="cell" data-label="{{ __('trainer.submissions.col_state') }}">
                                            <x-ui.pill :variant="$submission->stateVariant" :icon="$submission->stateIcon">{{ $submission->stateLabel }}</x-ui.pill>
                                        </td>
                                        <td role="cell" data-label="{{ __('grades.score') }}">
                                            @if ($submission->isGraded)
                                                <b class="row__score u-num"><span>{{ $submission->score }}</span><small> / {{ $submission->maxScore }}</small></b>
                                            @else
                                                <span class="u-muted">—</span>
                                            @endif
                                        </td>
                                        <td role="cell" class="u-nowrap">
                                            @if ($submission->hasSubmission)
                                                <x-ui.button variant="secondary" size="sm"
                                                    :href="$submission->gradeHref">{{ $submission->isGraded ? __('trainer.submissions.revise') : __('trainer.submissions.grade') }}</x-ui.button>
                                            @else
                                                <span class="u-muted">{{ __('trainer.assignments.not_submitted') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="row__acts">
                    <x-ui.button variant="secondary" size="sm"
                        :href="$selected->attendanceHref">{{ __('nav.attendance') }}</x-ui.button>
                    <x-ui.button icon="x" variant="ghost" size="sm"
                        :href="route('trainer.participants', request()->except('view'))">{{ __('app.close') }}</x-ui.button>
                </div>

                <p class="footnote">{{ __('trainer.participants.scope_note') }}</p>
            </x-ui.card>
        @endif
    @endif
@endsection
