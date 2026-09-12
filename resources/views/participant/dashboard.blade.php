{{--
    Participant dashboard home — the nine independent cards of PRD 9.5.3.
    Every card renders its OWN skeleton, its OWN empty state and its OWN error state.
    A card whose data is null is still loading (deferred by the controller);
    a card whose key appears in $failedBlocks failed to load.

    @see PRD §9.5.3 · BR-31, BR-36
--}}
@extends('layouts.app')

@section('title', __('nav.dashboard'))
@section('subtitle', $cohortLabel ?? '')

@section('content')
    {{-- The one-time welcome, above the grid rather than inside it: it is not a
         dashboard card, it is a moment, and it is gone on the next request
         (D-63). `$celebrate` is a session value the controller removes only
         after this page has rendered (D-75). --}}
    @if ($celebrate ?? false)
        @include('participant.partials.welcome-celebration', [
            'name' => $celebrateName ?? '',
        ])
    @endif

    <div class="dgrid">

        {{-- 1 · Welcome ------------------------------------------------------ --}}
        <div class="hello is-m-order-3">
            @if (in_array('welcome', $failedBlocks ?? [], true))
                <div class="hello__in">
                    <p>{{ __('errors.block_unavailable') }}</p>
                </div>
            @elseif (is_null($welcome))
                <div class="hello__in">
                    <div class="hello__grow">
                        <x-ui.skeleton height="var(--s7)" width="45%" variant="on-dark" />
                        <x-ui.skeleton height="var(--s3)" width="75%" variant="on-dark" class="u-mt-2" />
                    </div>
                    <x-ui.skeleton height="var(--touch-min)" width="var(--s18)" variant="on-dark" />
                </div>
            @else
                <div class="hello__in">
                    <div class="hello__grow">
                        <h3>{{ __('dashboard.greeting.' . $welcome->partOfDay, ['name' => $welcome->firstName]) }}</h3>
                        <p>{{ $welcome->statusLine }}</p>
                    </div>
                    <div class="hello__pct">
                        <div class="hello__pct-n"><span class="u-num">{{ $welcome->journeyPercent }}%</span></div>
                        <div class="hello__pct-l">{{ __('dashboard.of_journey') }}</div>
                    </div>
                </div>
            @endif
        </div>

        {{-- 2 · Next session -------------------------------------------------- --}}
        <x-ui.card class="dc--2 is-m-order-1" icon="video" :title="__('dashboard.next_session.title')">
            @if (in_array('nextSession', $failedBlocks ?? [], true))
                <x-ui.empty-state variant="error" icon="warn"
                    :title="__('errors.block_unavailable')"
                    :description="__('errors.block_unavailable_hint')"
                    :action-label="__('app.retry')" :action-href="route('dashboard')" />
            @elseif (is_null($nextSession))
                <x-ui.skeleton height="var(--s5)" width="65%" />
                <x-ui.skeleton height="var(--s3)" width="80%" class="u-mt-2" />
                <x-ui.skeleton height="var(--s3)" width="40%" class="u-mt-2" />
                <x-ui.skeleton height="var(--s10)" width="100%" class="u-mt-4" />
            @elseif ($nextSession->isMissing)
                <x-ui.empty-state icon="cal"
                    :title="__('dashboard.next_session.empty_title')"
                    :description="__('dashboard.next_session.empty_body')"
                    :action-label="__('nav.schedule')" :action-href="route('schedule')" />
            @else
                <div class="row row--plain">
                    <div class="row__m">
                        <b>{{ $nextSession->title }}</b>
                        <span>
                            <span class="u-num">{{ \App\Support\Dates::longDate($nextSession->startsAt) }}</span>
                            ·
                            <span class="u-num">{{ \App\Support\Dates::time12($nextSession->startsAt) }}</span>
                            —
                            <span class="u-num">{{ \App\Support\Dates::time12($nextSession->endsAt) }}</span>
                        </span>
                        <span class="row__by">
                            <x-ui.avatar size="sm" :name="$nextSession->trainerName" />
                            {{ $nextSession->trainerName }}
                        </span>
                    </div>
                    <div class="row__e">
                        <x-ui.countdown :until="$nextSession->startsAt" :server-now="$serverNow"
                            :label="__('dashboard.next_session.starts_in')" />
                    </div>
                </div>

                <div class="row__acts">
                    {{-- The join URL is never rendered before the server opens the window (PRD §9.10). --}}
                    @if ($nextSession->joinWindowOpen)
                        <x-ui.button variant="primary" size="sm"
                            :href="route('live')">{{ __('dashboard.next_session.join') }}</x-ui.button>
                    @else
                        <x-ui.button variant="primary" size="sm" disabled
                            :title="__('dashboard.next_session.join_locked')">{{ __('dashboard.next_session.join') }}</x-ui.button>
                    @endif
                    <x-ui.button variant="secondary" size="sm"
                        :href="route('schedule.session.ics', $nextSession->id)">{{ __('schedule.add_to_calendar') }}</x-ui.button>
                    <p class="hint">
                        <x-ui.icon name="lock" />
                        {{ trans_choice('dashboard.next_session.link_hint', $nextSession->joinOpensBeforeMinutes, ['minutes' => $nextSession->joinOpensBeforeMinutes]) }}
                    </p>
                </div>
            @endif
        </x-ui.card>

        {{-- 3 · Attendance rate ----------------------------------------------- --}}
        <x-ui.card icon="user" :title="__('attendance.rate.title')" class="is-m-order-4">
            @if (in_array('attendance', $failedBlocks ?? [], true))
                <x-ui.empty-state variant="error" icon="warn"
                    :title="__('errors.block_unavailable')"
                    :description="__('errors.block_unavailable_hint')" />
            @elseif (is_null($attendance))
                <x-ui.skeleton height="var(--s9)" width="40%" />
                <x-ui.skeleton height="var(--s3)" width="70%" class="u-mt-2" />
                <x-ui.skeleton height="var(--s2)" width="100%" class="u-mt-4" />
            @elseif ($attendance->totalSessions === 0)
                <x-ui.empty-state icon="cal"
                    :title="__('attendance.rate.empty_title')"
                    :description="__('attendance.rate.empty_body')" />
            @else
                <div class="stat">
                    <div>
                        <div class="stat__n"><span class="u-num">{{ $attendance->ratePercent }}</span><small>%</small></div>
                        <div class="stat__l">{{ trans_choice('attendance.rate.of_total', $attendance->attendedSessions, ['attended' => $attendance->attendedSessions, 'total' => $attendance->totalSessions]) }}</div>
                    </div>
                </div>
                <x-ui.progress-bar :value="$attendance->ratePercent" :variant="$attendance->rateVariant"
                    :label="__('attendance.rate.title')" />
                <div class="pmeta">
                    <span>{{ __('certificates.min_attendance', ['rate' => $attendance->minimumRatePercent]) }}</span>
                    @if ($attendance->meetsMinimum)
                        <x-ui.pill variant="success" icon="check">{{ __('attendance.rate.above_minimum') }}</x-ui.pill>
                    @else
                        <x-ui.pill variant="danger" icon="warn">{{ __('attendance.rate.below_minimum') }}</x-ui.pill>
                    @endif
                </div>
            @endif
        </x-ui.card>

        {{-- 4 · Journey progress ---------------------------------------------- --}}
        <x-ui.card icon="route" :title="__('journey.progress.title')" class="is-m-order-5">
            <x-slot:action>
                <a href="{{ route('participant.journey') }}">{{ __('nav.journey') }}</a>
            </x-slot:action>

            @if (in_array('journey', $failedBlocks ?? [], true))
                <x-ui.empty-state variant="error" icon="warn" :title="__('errors.block_unavailable')" />
            @elseif (is_null($journey))
                <x-ui.skeleton height="var(--s9)" width="40%" />
                <x-ui.skeleton height="var(--s2)" width="100%" class="u-mt-4" />
            @elseif ($journey->totalSteps === 0)
                {{-- The only one of the nine cards that had no empty state, so on a
                     trainee's first day it printed «0 / 0» and a dash for the
                     current step as though they were facts. Nothing is measured
                     yet; the card says so, in copy written for it (D-65). --}}
                <x-ui.empty-state icon="route"
                    :title="__('journey.empty_title')"
                    :description="__('journey.empty_body')" />
            @else
                <div class="stat">
                    <div>
                        <div class="stat__n"><span class="u-num">{{ $journey->completedSteps }}</span><small> / {{ $journey->totalSteps }}</small></div>
                        <div class="stat__l">{{ __('journey.progress.completed_steps') }}</div>
                    </div>
                </div>
                <x-ui.progress-bar :value="$journey->percent" variant="primary" :label="__('journey.progress.title')" />
                <div class="pmeta">
                    <span>{{ __('journey.progress.current_step', ['step' => $journey->currentStepTitle]) }}</span>
                    <span class="u-num">{{ $journey->percent }}%</span>
                </div>
            @endif
        </x-ui.card>

        {{-- 5 · Total grade ---------------------------------------------------- --}}
        <x-ui.card icon="chart" :title="__('grades.total.title')" class="is-m-order-6">
            <x-slot:action>
                <a href="{{ route('grades') }}">{{ __('nav.grades') }}</a>
            </x-slot:action>

            @if (in_array('grades', $failedBlocks ?? [], true))
                <x-ui.empty-state variant="error" icon="warn" :title="__('errors.block_unavailable')" />
            @elseif (is_null($gradeSummary))
                <x-ui.skeleton height="var(--s9)" width="45%" />
                <x-ui.skeleton height="var(--s2)" width="100%" class="u-mt-4" />
            @elseif (! $gradeSummary->hasAnyEvaluation)
                <x-ui.empty-state icon="badge"
                    :title="__('grades.total.empty_title')"
                    :description="__('grades.total.empty_body')" />
            @else
                <div class="stat">
                    <div>
                        <div class="stat__n"><span class="u-num">{{ $gradeSummary->finalScore }}</span><small> / {{ $gradeSummary->grandTotal }}</small></div>
                        <div class="stat__l">{{ __('grades.total.recorded_so_far', ['available' => $gradeSummary->recordedMaximum]) }}</div>
                    </div>
                </div>
                <x-ui.progress-bar :value="$gradeSummary->finalScore" :max="$gradeSummary->grandTotal"
                    variant="primary" :label="__('grades.total.title')" />
                <div class="pmeta">
                    <span>{{ __('grades.pass_score', ['score' => $gradeSummary->passScore]) }}</span>
                    <span>{{ $gradeSummary->projectGraded ? __('grades.project_graded') : __('grades.project_not_graded') }}</span>
                </div>
            @endif
        </x-ui.card>

        {{-- 6 · Due assignments ------------------------------------------------ --}}
        <x-ui.card class="dc--2 is-m-order-2" icon="file" :title="__('assignments.due.title')">
            <x-slot:action>
                <a href="{{ route('assignments.index') }}">{{ __('app.view_all') }}</a>
            </x-slot:action>

            @if (in_array('dueAssignments', $failedBlocks ?? [], true))
                <x-ui.empty-state variant="error" icon="warn" :title="__('errors.block_unavailable')" />
            @elseif (is_null($dueAssignments))
                @for ($i = 0; $i < 2; $i++)
                    <div class="row row--sk">
                        <x-ui.skeleton height="var(--s6)" width="var(--s18)" rounded="full" />
                        <div class="row__m">
                            <x-ui.skeleton height="var(--s4)" width="60%" />
                            <x-ui.skeleton height="var(--s3)" width="45%" class="u-mt-1" />
                        </div>
                        <x-ui.skeleton height="var(--s9)" width="var(--s21)" />
                    </div>
                @endfor
            @elseif ($dueAssignments->isEmpty())
                <x-ui.empty-state icon="check"
                    :title="__('assignments.due.empty_title')"
                    :description="__('assignments.due.empty_body')" />
            @else
                @foreach ($dueAssignments as $item)
                    <div class="row">
                        <x-ui.pill :variant="$item->urgencyVariant" :icon="$item->urgencyIcon">
                            {{ $item->remainingLabel }}
                        </x-ui.pill>
                        <div class="row__m">
                            <b>{{ $item->title }}</b>
                            <span>
                                <span class="u-num">{{ \App\Support\Dates::dateTime($item->dueAt) }}</span>
                                · {{ $item->isMandatory ? __('assignments.mandatory') : __('assignments.optional') }}
                                · <span class="u-num">{{ $item->maxScore }}</span> {{ trans_choice('grades.points', $item->maxScore) }}
                            </span>
                        </div>
                        <div class="row__e">
                            <x-ui.button variant="primary" size="sm"
                                :href="route('assignments.show', $item->id)">{{ __('assignments.submit_now') }}</x-ui.button>
                        </div>
                    </div>
                @endforeach
            @endif
        </x-ui.card>

        {{-- 7 · Latest grades --------------------------------------------------- --}}
        <x-ui.card icon="badge" :title="__('grades.latest.title')" class="is-m-order-7">
            <x-slot:action>
                <a href="{{ route('grades') }}">{{ __('app.all') }}</a>
            </x-slot:action>

            @if (in_array('latestGrades', $failedBlocks ?? [], true))
                <x-ui.empty-state variant="error" icon="warn" :title="__('errors.block_unavailable')" />
            @elseif (is_null($latestGrades))
                @for ($i = 0; $i < 3; $i++)
                    <div class="row row--sk">
                        <div class="row__m">
                            <x-ui.skeleton height="var(--s4)" width="65%" />
                            <x-ui.skeleton height="var(--s3)" width="40%" class="u-mt-1" />
                        </div>
                        <x-ui.skeleton height="var(--s5)" width="var(--s12)" />
                    </div>
                @endfor
            @elseif ($latestGrades->isEmpty())
                <x-ui.empty-state icon="badge"
                    :title="__('grades.latest.empty_title')"
                    :description="__('grades.latest.empty_body')" />
            @else
                @foreach ($latestGrades as $grade)
                    <div class="row">
                        <div class="row__m">
                            <b>{{ $grade->itemTitle }}</b>
                            <span>{{ __('grades.recorded_on', ['date' => \App\Support\Dates::longDate($grade->recordedAt)]) }}</span>
                        </div>
                        <div class="row__e">
                            <b class="row__score"><span class="u-num">{{ $grade->score }}</span><small class="u-num"> / {{ $grade->maxScore }}</small></b>
                        </div>
                    </div>
                @endforeach
            @endif
        </x-ui.card>

        {{-- 8 · Latest announcements -------------------------------------------- --}}
        <x-ui.card icon="bell" :title="__('messages.announcements.latest')" class="is-m-order-8">
            <x-slot:action>
                <a href="{{ route('messages.index') }}">{{ __('nav.messages') }}</a>
            </x-slot:action>

            @if (in_array('announcements', $failedBlocks ?? [], true))
                <x-ui.empty-state variant="error" icon="warn" :title="__('errors.block_unavailable')" />
            @elseif (is_null($announcements))
                @for ($i = 0; $i < 2; $i++)
                    <div class="row row--sk">
                        <div class="row__m">
                            <x-ui.skeleton height="var(--s4)" width="70%" />
                            <x-ui.skeleton height="var(--s3)" width="35%" class="u-mt-1" />
                        </div>
                    </div>
                @endfor
            @elseif ($announcements->isEmpty())
                <x-ui.empty-state icon="bell"
                    :title="__('messages.announcements.empty_title')"
                    :description="__('messages.announcements.empty_body')" />
            @else
                @foreach ($announcements as $announcement)
                    <div class="row">
                        <div class="row__m">
                            <b>{{ $announcement->title }}</b>
                            <span>{{ __('messages.announcements.channel') }} · {{ \App\Support\Dates::relative($announcement->sentAt) }}</span>
                        </div>
                    </div>
                @endforeach
            @endif
        </x-ui.card>

        {{-- 9 · New resources ---------------------------------------------------- --}}
        <x-ui.card icon="folder" :title="__('resources.new.title')" class="is-m-order-9">
            <x-slot:action>
                <a href="{{ route('resources.index') }}">{{ __('nav.resources') }}</a>
            </x-slot:action>

            @if (in_array('resources', $failedBlocks ?? [], true))
                <x-ui.empty-state variant="error" icon="warn" :title="__('errors.block_unavailable')" />
            @elseif (is_null($newResources))
                @for ($i = 0; $i < 2; $i++)
                    <div class="row row--sk">
                        <x-ui.skeleton height="var(--s8)" width="var(--s8)" rounded="md" />
                        <div class="row__m">
                            <x-ui.skeleton height="var(--s4)" width="65%" />
                            <x-ui.skeleton height="var(--s3)" width="40%" class="u-mt-1" />
                        </div>
                    </div>
                @endfor
            @elseif ($newResources->isEmpty())
                <x-ui.empty-state icon="folder"
                    :title="__('resources.new.empty_title')"
                    :description="__('resources.new.empty_body')" />
            @else
                @foreach ($newResources as $resource)
                    <div class="row">
                        <div class="row__m">
                            <b>{{ $resource->title }}</b>
                            <span>{{ $resource->typeLabel }} · {{ \App\Support\Dates::relative($resource->addedAt) }}</span>
                        </div>
                        <div class="row__e">
                            <x-ui.button variant="secondary" size="sm"
                                :href="route('resources.index', ['highlight' => $resource->id])">{{ __('app.open') }}</x-ui.button>
                        </div>
                    </div>
                @endforeach
            @endif
        </x-ui.card>

    </div>
@endsection
