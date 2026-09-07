{{--
    Assignments index — grouped by week in collapsible sections, current week open.
    Colour of the remaining-time indicator: normal above 48h, warning under 48h,
    danger under 6h. The variant is decided on the server (Clock::now()), never in JS.

    @see PRD §9.11.1 · BR-17, BR-18
--}}
@extends('layouts.app')

@section('title', __('nav.assignments'))
@section('subtitle', __('assignments.subtitle'))

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('assignments.error_title')"
            :description="__('assignments.error_body')"
            :action-label="__('app.retry')" :action-href="route('assignments.index')" />
    @else
        {{-- Header summary ---------------------------------------------------- --}}
        @if (is_null($summary))
            <div class="dgrid dgrid--stats">
                @for ($i = 0; $i < 3; $i++)
                    <x-ui.card><x-ui.skeleton height="var(--s7)" width="var(--s15)" /><x-ui.skeleton height="var(--s3)" width="80%" class="u-mt-2" /></x-ui.card>
                @endfor
            </div>
        @else
            <div class="dgrid dgrid--stats">
                <x-ui.stat-card
                    :value="$summary->mandatoryCompleted . ' / ' . $summary->mandatoryTotal"
                    :label="__('assignments.summary.mandatory_done')" />
                <x-ui.stat-card variant="primary"
                    :value="$summary->earnedScore . ' / ' . $summary->assignmentsTotal"
                    :label="__('assignments.summary.score_so_far')" />
                <x-ui.stat-card :variant="$summary->pendingCount > 0 ? 'warning' : 'success'"
                    :value="$summary->pendingCount"
                    :label="__('assignments.summary.pending')" />
            </div>
        @endif

        {{-- Weekly groups ------------------------------------------------------ --}}
        @if (is_null($weeks))
            <div class="wk u-mt-4">
                @for ($i = 0; $i < 4; $i++)
                    <div class="wk__i">
                        <div class="wk__hd">
                            <x-ui.skeleton height="var(--s9)" width="var(--s9)" rounded="md" />
                            <div class="wk__t">
                                <x-ui.skeleton height="var(--s4)" width="44%" />
                                <x-ui.skeleton height="var(--s3)" width="28%" class="u-mt-1" />
                            </div>
                        </div>
                    </div>
                @endfor
            </div>
        @elseif ($weeks->isEmpty())
            <x-ui.empty-state icon="file"
                :title="__('assignments.empty_title')"
                :description="__('assignments.empty_body')"
                :action-label="__('nav.schedule')" :action-href="route('schedule')" />
        @else
            <div class="wk u-mt-4">
                @foreach ($weeks as $week)
                    <details class="wk__i {{ $week->isCurrent ? 'is-current' : '' }}" @if ($week->isCurrent) open @endif>
                        <summary class="wk__hd">
                            <div class="wk__no {{ $week->isCurrent ? '' : 'wk__no--muted' }}">
                                <span class="u-num">{{ $week->paddedIndex }}</span>
                            </div>
                            <div class="wk__t">
                                <b>{{ $week->title }}</b>
                                <span>{{ trans_choice('assignments.count', $week->assignments->count(), ['count' => $week->assignments->count()]) }}</span>
                            </div>
                            @if ($week->isCurrent)
                                <x-ui.pill variant="live">{{ __('schedule.current_week') }}</x-ui.pill>
                            @endif
                        </summary>

                        <div class="wk__body">
                            @if ($week->assignments->isEmpty())
                                <x-ui.empty-state icon="file" size="sm"
                                    :title="__('assignments.week_empty_title')"
                                    :description="__('assignments.week_empty_body')" />
                            @else
                                @foreach ($week->assignments as $assignment)
                                    <div class="row">
                                        <x-ui.pill :variant="$assignment->statusVariant" :icon="$assignment->statusIcon">
                                            {{ $assignment->statusLabel }}
                                        </x-ui.pill>
                                        <div class="row__m">
                                            <b>{{ $assignment->title }}</b>
                                            <span>
                                                <span class="u-num">{{ \App\Support\Dates::dateTime($assignment->dueAt) }}</span>
                                                · {{ $assignment->isMandatory ? __('assignments.mandatory') : __('assignments.optional') }}
                                                · <span class="u-num">{{ $assignment->maxScore }}</span> {{ trans_choice('grades.points', $assignment->maxScore) }}
                                            </span>
                                        </div>
                                        <div class="row__e">
                                            @if ($assignment->isGraded)
                                                <b class="row__score"><span class="u-num">{{ $assignment->score }}</span><small class="u-num"> / {{ $assignment->maxScore }}</small></b>
                                            @else
                                                <x-ui.pill :variant="$assignment->urgencyVariant" :icon="$assignment->urgencyIcon">
                                                    {{ $assignment->remainingLabel }}
                                                </x-ui.pill>
                                            @endif
                                            <x-ui.button variant="{{ $assignment->isSubmitted ? 'secondary' : 'primary' }}" size="sm"
                                                :href="route('assignments.show', $assignment->id)">
                                                {{ $assignment->isSubmitted ? __('app.details') : __('assignments.submit_now') }}
                                            </x-ui.button>
                                        </div>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>
        @endif
    @endif
@endsection
