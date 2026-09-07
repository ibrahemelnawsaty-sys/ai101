{{--
    Timeline

    A vertical rail with exactly the three states the PRD names: completed,
    current, locked. The rail sits on the inline-END side, hugging the RIGHT
    edge in RTL, and its teal fill grows downward to the completed proportion
    (Article 16).

    Every step's status arrives already decided. For the ten-step journey that
    decision is JourneyEvaluator's alone, computed from real attendance,
    submissions and evaluations — a step is never marked by hand and never
    marked by the browser (BR-21, Article 5, Article 6).

    A locked step is rendered as plain text with no link, because a locked step
    has nowhere to go; the server would refuse the route anyway (Article 7).

    @see PRD §5.8, §5.9, §9.7 · BR-20, BR-21 · CONSTITUTION Articles 5, 6, 7, 15, 16, 17, 18

    Props
      variant   default
      size      sm | md | lg     reserved
      state     default | loading | empty
      items     array of ['title','meta','status','href','icon']
                status: completed | current | locked
      percent   0–100, how far the rail's fill reaches (server-computed)
      label     accessible name for the list

    Slots
      empty     the screen's own empty copy
--}}


@if ($state === 'loading')
    <div {{ $attributes->class(['ui-timeline']) }}>
        <x-ui.skeleton shape="timeline" :count="4" />
    </div>

@elseif ($state === 'empty' || $total === 0)
    <div {{ $attributes->class(['ui-timeline']) }}>
        @isset($empty)
            {{ $empty }}
        @else
            <x-ui.empty-state
                size="sm"
                icon="i-route"
                :title="__('app.states.empty_title')"
                :description="__('app.states.empty_body')"
            />
        @endisset
    </div>

@else
    {{-- role="list" on a <div>: the rail is a sibling of the steps, and a
         stray <span> inside an <ol> would be invalid markup. --}}
    <div
        {{ $attributes->class(['ui-timeline']) }}
        role="list"
        aria-label="{{ $listLabel }}"
        x-data="uiTimeline({ percent: {{ $fill }} })"
    >
        <span class="ui-timeline__rail" aria-hidden="true">
            <span class="ui-timeline__rail-fill" x-bind:style="'block-size: ' + shown + '%'"></span>
        </span>

        @foreach ($steps as $step)
            @php
                $status = in_array($step['status'] ?? 'locked', ['completed', 'current', 'locked'], true)
                    ? $step['status']
                    : 'locked';
                $isLocked = $status === 'locked';
                $link = ! $isLocked ? ($step['href'] ?? null) : null;
            @endphp

            <div class="ui-timeline__item ui-timeline__item--{{ $status }}" role="listitem">
                <span class="ui-timeline__dot" aria-hidden="true">
                    <svg class="ui-icon" focusable="false">@php $stepIcon = $step['icon'] ?? $statusIcon[$status]; @endphp<use href="#{{ str_starts_with($stepIcon, 'i-') ? $stepIcon : 'i-'.$stepIcon }}"/></svg>
                </span>

                @if ($link)
                    <a class="ui-timeline__link" href="{{ $link }}">
                        <span class="ui-timeline__box">
                            <span class="ui-timeline__title">{{ $step['title'] ?? '' }}</span>
                            {{-- Status is never colour-only: it is spelled out here. --}}
                            <span class="ui-sr">{{ $statusText[$status] }}</span>
                            @if (! empty($step['meta']))
                                <span class="ui-timeline__meta">{{ $step['meta'] }}</span>
                            @endif
                        </span>
                    </a>
                @else
                    <div class="ui-timeline__box">
                        <span class="ui-timeline__title">{{ $step['title'] ?? '' }}</span>
                        <span class="ui-sr">{{ $statusText[$status] }}</span>
                        @if (! empty($step['meta']))
                            <span class="ui-timeline__meta">{{ $step['meta'] }}</span>
                        @endif
                    </div>
                @endif
            </div>
        @endforeach

        {{ $slot }}
    </div>
@endif
