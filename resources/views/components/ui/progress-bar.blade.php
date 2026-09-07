{{--
    ProgressBar

    FILLS FROM THE RIGHT. The fill is pinned to `inset-inline-start`, which in an
    RTL flow is the right edge, and grows by `inline-size` — so growth runs
    right-to-left with no direction-specific CSS at all (Article 16).

    It animates once, over 800ms with the ease-out curve, when it first scrolls
    into view. Under prefers-reduced-motion the bar is simply drawn at its final
    width with no transition at all (Article 18).

    The value is a number the SERVER computed — an attendance rate from
    CertificateEligibility, a score from ScoreCalculator, a journey percentage
    from JourneyEvaluator. Nothing is calculated here (Article 5, Article 6).

    Numerals stay Latin and tabular (Article 15).

    @see PRD §5.8, §5.9, §9.7, §9.15, §9.17 · CONSTITUTION Articles 5, 6, 15, 16, 18

    Props
      variant       default | brand | success | warning | error
      size          sm | md | lg
      state         default | loading | indeterminate
      value         the server's number — a percentage unless `max` is given
      max           the ceiling `value` is measured against (a score out of 50,
                    seats out of a capacity). Default 100, i.e. value IS the
                    percentage. The division happens here so a screen can pass
                    the two raw numbers it already has; nothing else about the
                    number is decided here (Article 5).
      label         what the bar measures
      threshold     0–100 — draws the pass mark (pass_score, min_attendance_rate)
      thresholdLabel  accessible text for that mark
      showValue     print the percentage next to the label
      meta          small print under the bar

    Slots
      meta      replaces the generated meta row
--}}


@if ($state === 'loading')
    <div {{ $attributes->class(['ui-progress']) }} role="status" aria-live="polite">
        <span class="ui-sr">{{ __('ui.skeleton.label') }}</span>
        <span class="ui-sk ui-sk-bar" aria-hidden="true"></span>
    </div>
@else
    <div
        {{ $attributes->class([
            'ui-progress',
            'ui-progress--' . $variant => $variant !== 'default',
            'ui-progress--' . $size => $size !== 'md',
            'ui-progress--indeterminate' => $isIndeterminate,
        ]) }}
        @unless ($isIndeterminate)
            x-data="uiProgress({ value: {{ $percent }} })"
        @endunless
    >
        @if ($label !== null || $showValue)
            <div class="ui-progress__head">
                <span id="{{ $labelId }}">{{ $label ?? __('ui.progress.label') }}</span>
                @if ($showValue && ! $isIndeterminate)
                    <span class="ui-progress__value ui-num">{{ __('app.ratio.percent', ['value' => $printed]) }}</span>
                @endif
            </div>
        @endif

        <div
            class="ui-progress__track"
            role="progressbar"
            @if ($isIndeterminate)
                aria-busy="true"
            @else
                aria-valuenow="{{ $printed }}"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-valuetext="{{ __('app.ratio.percent', ['value' => $printed]) }}"
            @endif
            @if ($labelId) aria-labelledby="{{ $labelId }}" @else aria-label="{{ __('ui.progress.label') }}" @endif
        >
            @if ($isIndeterminate)
                <span class="ui-progress__fill"></span>
            @else
                {{-- inset-inline-start pins the fill to the RIGHT edge in RTL. --}}
                <span
                    class="ui-progress__fill"
                    style="inline-size: 0%;"
                    x-bind:style="'inline-size: ' + shown + '%'"
                ></span>
            @endif

            @if ($mark !== null)
                <span
                    class="ui-progress__threshold"
                    style="inset-inline-start: {{ $mark }}%;"
                    role="img"
                    aria-label="{{ $thresholdLabel ?? __('ui.progress.threshold', ['value' => rtrim(rtrim(number_format($mark, 1, '.', ''), '0'), '.')]) }}"
                ></span>
            @endif
        </div>

        @isset($meta)
            <div class="ui-progress__meta">{{ $meta }}</div>
        @endisset
    </div>
@endif
