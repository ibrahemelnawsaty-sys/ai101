{{--
    My journey — ten steps, evaluated entirely from real data by JourneyEvaluator.
    There is no manual "mark as complete" control for the participant anywhere.
    completed = filled teal · current = violet with a pulsing ring · locked = grey with a padlock.

    @see PRD §9.7 · BR-20, BR-21
--}}
@extends('layouts.app')

@section('title', __('nav.journey'))
@section('subtitle', __('journey.subtitle'))

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('journey.error_title')"
            :description="__('journey.error_body')"
            :action-label="__('app.retry')" :action-href="route('participant.journey')" />
    @elseif (is_null($steps))
        <x-ui.card>
            <x-ui.skeleton height="var(--s5)" width="38%" />
            <x-ui.skeleton height="var(--s2)" width="100%" class="u-mt-4" />
        </x-ui.card>
        <div class="jn u-mt-4">
            @for ($i = 0; $i < 10; $i++)
                <div class="jn__i">
                    <div class="jn__d"></div>
                    <div class="jn__b">
                        <x-ui.skeleton height="var(--s4)" width="55%" />
                        <x-ui.skeleton height="var(--s3)" width="72%" class="u-mt-1" />
                    </div>
                </div>
            @endfor
        </div>
    @elseif ($steps->isEmpty())
        <x-ui.empty-state icon="route"
            :title="__('journey.empty_title')"
            :description="__('journey.empty_body')"
            :action-label="__('nav.schedule')" :action-href="route('schedule')" />
    @else
        <x-ui.card>
            <div class="jsummary">
                <div class="jsummary__t">
                    <b>{{ __('journey.completed_of', ['completed' => $progress->completedSteps, 'total' => $progress->totalSteps]) }}</b>
                    <span>{{ __('journey.auto_only') }}</span>
                </div>
                <div class="jsummary__n u-num">{{ $progress->percent }}%</div>
            </div>
            <x-ui.progress-bar :value="$progress->percent" variant="primary" :label="__('journey.progress.title')" />
        </x-ui.card>

        <div class="jn u-mt-4">
            {{-- The connector fill height mirrors the completed ratio; it is decorative only. --}}
            <div class="jn__f" style="--jn-fill: {{ $progress->percent }}%" aria-hidden="true"></div>

            <ol class="jn__list">
            @foreach ($steps as $step)
                <li class="jn__i {{ $step->statusClass }}">
                    <div class="jn__d" aria-hidden="true">
                        <x-ui.icon :name="$step->statusIcon" />
                    </div>
                    <div class="jn__b">
                        <b>
                            <span class="u-num">{{ $step->index }}</span> · {{ $step->title }}
                            <span class="sr">{{ $step->statusLabel }}</span>
                        </b>
                        <span>{{ $step->summary }}</span>

                        {{-- Week steps expand to show each training day and each assignment. --}}
                        @if ($step->isCurrent && $step->details->isNotEmpty())
                            <div class="jn__x">
                                @foreach ($step->details as $detail)
                                    <div class="jn__day">
                                        <x-ui.pill :variant="$detail->variant" :icon="$detail->icon">{{ $detail->statusLabel }}</x-ui.pill>
                                        <b>{{ \App\Support\Dates::longDate($detail->occursAt) }}</b>
                                        — {{ $detail->title }}
                                    </div>
                                @endforeach

                                @if ($step->actionRoute)
                                    <div class="jn__act">
                                        <x-ui.button variant="primary" size="sm"
                                            :href="$step->actionRoute">{{ $step->actionLabel }}</x-ui.button>
                                    </div>
                                @endif
                            </div>
                        @elseif (! $step->isLocked && $step->details->isNotEmpty())
                            <details class="jn__more">
                                <summary>{{ __('journey.show_details') }}</summary>
                                <div class="jn__x">
                                    @foreach ($step->details as $detail)
                                        <div class="jn__day">
                                            <x-ui.pill :variant="$detail->variant" :icon="$detail->icon">{{ $detail->statusLabel }}</x-ui.pill>
                                            <b>{{ \App\Support\Dates::longDate($detail->occursAt) }}</b>
                                            — {{ $detail->title }}
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>
                </li>
            @endforeach
            </ol>
        </div>
    @endif
@endsection
