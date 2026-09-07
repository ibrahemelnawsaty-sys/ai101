{{--
    Grades — total out of 100 (assignments 50 + project 50), then every graded item
    with its trainer feedback rendered IN FULL and never truncated.

    A participant never sees another participant's score; the query is scoped to the
    authenticated user in the controller and re-checked by the policy.

    @see PRD §9.15 · BR-11, BR-12, BR-13, BR-14, BR-15, BR-16
--}}
@extends('layouts.app')

@section('title', __('nav.grades'))
@section('subtitle', __('grades.subtitle', ['assignments' => 50, 'project' => 50, 'total' => 100]))

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('grades.error_title')"
            :description="__('grades.error_body')"
            :action-label="__('app.retry')" :action-href="route('grades')" />
    @else

        {{-- Total ------------------------------------------------------------- --}}
        @if (is_null($total))
            <div class="gtotal">
                <x-ui.skeleton height="var(--s12)" width="var(--d-3)" variant="on-dark" />
                <x-ui.skeleton height="var(--s3)" width="100%" variant="on-dark" class="u-mt-4" />
            </div>
        @else
            <div class="gtotal">
                <div class="gtotal__hd">
                    <div class="gtotal__main">
                        <div class="gtotal__l">{{ __('grades.total.current') }}</div>
                        <div class="gtotal__n">
                            <span class="u-num">{{ $total->finalScore }}</span><small> / {{ $total->grandTotal }}</small>
                        </div>
                    </div>
                    <div class="gtotal__split">
                        {{ __('grades.assignments_part') }} ·
                        <b><span class="u-num">{{ $total->assignmentsScore }}</span> / <span class="u-num">{{ $total->assignmentsTotal }}</span></b><br>
                        {{ __('grades.project_part') }} ·
                        <b>{{ $total->projectGraded ? $total->projectScore . ' / ' . $total->projectTotal : __('grades.project_not_graded') }}</b>
                    </div>
                </div>

                <x-ui.progress-bar :value="$total->finalScore" :max="$total->grandTotal"
                    variant="on-dark" :label="__('grades.total.title')" />

                <div class="gtotal__ft">
                    <span>{{ __('grades.pass_score', ['score' => $total->passScore]) }}</span>
                    <span>{{ __('grades.points_still_available', ['points' => $total->remainingPoints]) }}</span>
                </div>

                <p class="gtotal__verdict">
                    @if ($total->passes)
                        <x-ui.pill variant="success" icon="check">{{ __('grades.passing') }}</x-ui.pill>
                    @else
                        <x-ui.pill variant="warning" icon="clock">{{ __('grades.not_yet_passing') }}</x-ui.pill>
                    @endif
                </p>
            </div>
        @endif

        <div class="toolbar u-mt-4">
            <x-ui.button variant="secondary" size="sm" icon="down"
                :href="route('grades.export')">{{ __('grades.export_pdf') }}</x-ui.button>
        </div>

        {{-- Per-item -------------------------------------------------------------- --}}
        @if (is_null($items))
            @for ($i = 0; $i < 4; $i++)
                <div class="gitem">
                    <div class="gitem__hd">
                        <x-ui.skeleton height="var(--s6)" width="var(--s21)" rounded="full" />
                        <x-ui.skeleton height="var(--s4)" width="46%" />
                        <x-ui.skeleton height="var(--s5)" width="var(--s15)" />
                    </div>
                    <x-ui.skeleton height="var(--s2)" width="100%" class="u-mt-2" />
                    <x-ui.skeleton height="var(--s12)" width="100%" class="u-mt-2" rounded="md" />
                </div>
            @endfor
        @elseif ($items->isEmpty())
            <x-ui.empty-state icon="badge"
                :title="__('grades.items_empty_title')"
                :description="__('grades.items_empty_body')"
                :action-label="__('nav.assignments')" :action-href="route('assignments.index')" />
        @else
            @foreach ($items as $weekTitle => $weekItems)
                <section class="gweek">
                    <h2 class="gweek__t">{{ $weekTitle }}</h2>

                    @foreach ($weekItems as $item)
                        <article class="gitem {{ $item->isGraded ? '' : 'gitem--pending' }}">
                            <div class="gitem__hd">
                                <x-ui.pill :variant="$item->statusVariant" :icon="$item->statusIcon">{{ $item->statusLabel }}</x-ui.pill>
                                <b>{{ $item->title }}</b>
                                <span class="gitem__sc">
                                    @if ($item->isGraded)
                                        <span class="u-num">{{ $item->score }}</span><small class="u-num"> / {{ $item->maxScore }}</small>
                                    @else
                                        —<small class="u-num"> / {{ $item->maxScore }}</small>
                                    @endif
                                </span>
                            </div>

                            @if ($item->isGraded)
                                <x-ui.progress-bar :value="$item->score" :max="$item->maxScore"
                                    variant="primary" :label="$item->title" size="sm" />

                                <div class="note">
                                    <b>{{ __('grades.trainer_feedback', ['trainer' => $item->graderName]) }}</b>
                                    {{-- Full text. Never clamped, never truncated, never behind a "read more". --}}
                                    <p class="note__body">{{ $item->feedback }}</p>
                                    <span class="note__meta u-num">{{ \App\Support\Dates::longDate($item->gradedAt) }}</span>
                                </div>

                                @if ($item->revisionReason)
                                    <div class="note note--warn">
                                        <b>{{ __('grades.revised_title') }}</b>
                                        <p class="note__body">{{ $item->revisionReason }}</p>
                                    </div>
                                @endif
                            @elseif ($item->isLocked)
                                <p class="gitem__hint">{{ __('grades.locked_hint') }}</p>
                            @endif
                        </article>
                    @endforeach
                </section>
            @endforeach

            {{-- Progress across the weeks --}}
            @if ($trend && $trend->isNotEmpty())
                <x-ui.card class="u-mt-4" icon="chart" :title="__('grades.trend_title')">
                    <ul class="trend" role="list">
                        @foreach ($trend as $point)
                            <li class="trend__i">
                                <span class="trend__l">{{ $point->label }}</span>
                                <x-ui.progress-bar :value="$point->percent" variant="primary" size="sm"
                                    :label="$point->label" />
                                <span class="trend__v u-num">{{ $point->percent }}%</span>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
        @endif
    @endif
@endsection
