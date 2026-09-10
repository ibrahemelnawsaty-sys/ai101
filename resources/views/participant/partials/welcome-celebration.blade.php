{{--
    The one-time welcome, shown on the first dashboard after an invited trainee
    has set their own password.

    IT FIRES ONCE, AND ONLY ONCE. The controller reads a session flash with
    `pull()`, so the render that shows this consumes it: a refresh, a back
    button, a second visit and a pasted URL all get nothing. That is why it is
    not a query parameter — `?welcome=1` would be replayable by anyone — and not
    a column, which would have to be reset for the next cohort.

    prefers-reduced-motion IS HONOURED BY NOT BINDING THE EFFECT AT ALL, not by
    shortening it. `atharWelcome` checks `reduced()` before it ever calls
    `confetti()`, so a reader who has asked for stillness gets a still panel —
    and that panel is a real welcome, not the absence of one. The words, the
    seal and the button are identical in both cases; only the falling pieces are
    withheld.

    NO ARABIC TEXT IS SPLIT. The heading is one element; nothing here wraps a
    span around each character. Arabic letters join to their neighbours, and a
    per-character split severs those joins, so a word renders as a row of
    disconnected shapes with visible gaps mid-word (Article 16-bis). An example
    cannot be written here: Arabic in a comment is itself a G3/G5 failure, and
    this very line was the one blocking row the gate reported.

    NO YELLOW AND NO GOLD. The pieces take their colours from `--confetti-1..4`,
    which resolve to violet-500, teal-500, violet-300 and teal-700 — checked
    against tokens.css, not assumed.

    @see PRD §9.5 · CONSTITUTION Art. 14, Art. 16-bis, Art. 18 · D-63

    Variables: $name (string, may be empty)
--}}
{{-- No x-cloak: if the bundle never loads, the panel simply stays on screen,
     which is the correct failure. Cloaking it would hide the welcome from
     exactly the reader whose JavaScript is blocked. --}}
<section class="wel" role="status" aria-live="polite"
         x-data="atharWelcome()" x-init="start()"
         x-show="open" x-transition.opacity.duration.400ms>

    {{-- The confetti host is a child of the panel and is positioned against it,
         which is why the panel carries `position: relative`. Empty for a reader
         who has asked for reduced motion: nothing is appended to it at all. --}}
    <div class="cf" x-ref="stage" aria-hidden="true"></div>

    <div class="wel__in">
        <x-ui.logo variant="mark" size="md" tone="light" class="wel__seal" />

        <h2 class="wel__ttl">
            @if ($name === '')
                {{ __('dashboard.welcome_first.title_plain') }}
            @else
                {{ __('dashboard.welcome_first.title', ['name' => $name]) }}
            @endif
        </h2>

        <p class="wel__lead">{{ __('dashboard.welcome_first.lead') }}</p>

        <div class="wel__acts">
            {{-- The profile, not the journey: the journey screen is empty for an
                 account whose cohort has not started, and a celebration must not
                 open onto an empty state (Article 17). The profile is never
                 empty — it is the trainee's own record. --}}
            <x-ui.button variant="primary" :href="route('profile')">{{ __('dashboard.welcome_first.cta') }}</x-ui.button>
            <x-ui.button variant="ghost" size="sm" type="button"
                x-on:click="dismiss()">{{ __('dashboard.welcome_first.dismiss') }}</x-ui.button>
        </div>
    </div>
</section>
