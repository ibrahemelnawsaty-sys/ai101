{{--
    CountdownTimer

    Ticks once a second toward an instant the SERVER supplied.

    BR-07 and Article 11 are the whole design here. Two instants are rendered
    into the markup by Clock: the target, and the server's own "now". The
    browser measures the gap between its clock and the server's ONCE, then uses
    its own clock only to count elapsed milliseconds. A device with a wrong date
    therefore shows the right remaining time.

    And the counter never opens anything. Reaching zero dispatches
    `ui-countdown-finished`; whether check-in is actually open is
    AttendanceWindow's decision on the next request, and only its decision
    (Article 5, Article 7).

    Numerals are Latin and zero-padded, the readout is isolated LTR so the
    digits stay in order inside an RTL line (Article 15, Article 16).

    @see PRD §5.8, §5.9, §9.9 · BR-07 · CONSTITUTION Articles 5, 7, 11, 15, 16, 18

    Props
      variant      default | compact
      size         sm | md | lg      reserved
      state        default | loading
      target       DateTimeInterface or ISO-8601 string — the moment counted to
      label        what is being counted down to
      urgentBelow  seconds under which the digits turn burnt orange
      showDays     include the days cell (default: true)

    Slots
      done      what to show once the counter reaches zero
--}}


@if ($state === 'loading' || $targetIso === null)
    <div {{ $attributes->class(['ui-countdown']) }} role="status" aria-live="polite">
        <span class="ui-sr">{{ __('ui.skeleton.label') }}</span>
        <span class="ui-sk ui-sk-title" aria-hidden="true"></span>
    </div>
@else
    <div
        {{ $attributes->class(['ui-countdown', 'ui-countdown--compact' => $isCompact]) }}
        x-data="uiCountdown({
            target: @js($targetIso),
            serverNow: @js($serverIso),
            urgentBelow: @js($urgentMs)
        })"
        x-bind:class="{ 'ui-countdown--urgent': isUrgent }"
    >
        @if ($label !== null)
            <p class="ui-countdown__head">
                <span class="ui-countdown__dot" aria-hidden="true"></span>
                <span>{{ $label }}</span>
            </p>
        @endif

        {{-- One polite live region for the whole readout, refreshed each minute
             by the browser only as often as the digits it announces change. --}}
        <div class="ui-countdown__grid" x-show="! finished" aria-live="off">
            @foreach ($cells as $cell)
                <div class="ui-countdown__cell">
                    <div class="ui-countdown__n ui-num" x-text="{{ $cell['key'] }}">00</div>
                    <div class="ui-countdown__l">{{ $cell['label'] }}</div>
                </div>
            @endforeach
        </div>

        <p class="ui-countdown__done" x-show="finished" x-cloak role="status">
            @isset($done)
                {{ $done }}
            @else
                {{ __('app.time.now') }}
            @endisset
        </p>

        {{-- The server's own reading of the clock, for anyone who asks. --}}
        <p class="ui-sr">{{ __('app.time.server_time_note') }}</p>
    </div>
@endif
