{{--
    Landing page.

    Ported section for section from the product-owner-approved reference at
    docs/04-design/ref-landing.html. Every visible string that describes the
    programme comes from the database and is edited from the admin panel (BR-31);
    only interface chrome resolves through __() (Constitution art. 15).

    @see BR-07, BR-11, BR-25, BR-26, BR-31, BR-36 · PRD §9.1.1, §9.1.2, §9.1.3

    Variables supplied by App\Http\Controllers\Public\HomeController@index:
      $landing        array   admin-managed content, keys documented per section below
      $cohort         array   seats/dates/thresholds of the cohort open for registration
      $serverNowIso   string  Clock::now()->toIso8601String() — the ONLY clock reference (BR-07)
      $copyrightYear  int     Clock::riyadh()->year
      $screen         string  the Article 17 screen name
      $screenState    string  App\Support\ScreenState: normal | loading | empty | error
--}}
@extends('layouts.public')

@section('content')

@include('partials.public-header')

{{-- ============================================================
     State: error - Constitution art. 17
     ============================================================ --}}
@if (($screenState ?? 'normal') === 'error')

    <section class="sec sec--state" aria-live="polite">
        <div class="wrap">
            <x-ui.empty-state
                variant="error"
                icon="i-warn"
                :title="__('landing.states.error_title')"
                :description="__('landing.states.error_body')">
                <x-ui.button variant="primary" size="lg" :href="route('home')">
                    {{ __('landing.states.error_action') }}
                </x-ui.button>
            </x-ui.empty-state>
        </div>
    </section>

{{-- ============================================================
     State: loading - skeleton shaped like the content, never a bare spinner
     ============================================================ --}}
@elseif (($screenState ?? 'normal') === 'loading')

    <div class="sec">
        <div class="wrap skel-hero">
            <x-ui.skeleton shape="chip" :label="__('landing.states.loading_label')"/>
            <x-ui.skeleton shape="title"/>
            <x-ui.skeleton shape="title"/>
            <x-ui.skeleton shape="text" :lines="2"/>
            <div class="skel-row">
                <x-ui.skeleton shape="chip" :count="4"/>
            </div>
            <x-ui.skeleton shape="stat" :count="4"/>
            <div class="grid g3 skel-cards">
                <x-ui.skeleton shape="card" :count="3"/>
            </div>
        </div>
    </div>

{{-- ============================================================
     State: empty - no programme has been published yet
     ============================================================ --}}
@elseif (($screenState ?? 'normal') === 'empty' || blank($landing ?? null))

    <section class="sec sec--state">
        <div class="wrap">
            <x-ui.empty-state
                icon="i-file"
                :title="__('landing.states.empty_page_title')"
                :description="__('landing.states.empty_page_body')">
                <x-ui.button variant="secondary" size="lg" :href="'mailto:'.config('athar.email')">
                    {{ __('landing.states.empty_page_action') }}
                </x-ui.button>
            </x-ui.empty-state>
        </div>
    </section>

@else
{{-- ============================================================
     State: normal
     ============================================================ --}}

{{-- Side progress rail. Decorative navigation shortcut; every stop keeps a screen-reader label. --}}
<nav class="trace" aria-label="{{ __('landing.meta.page_trail') }}">
    <div class="trace__rail">
        <div class="trace__fill" id="traceFill"></div>
        <div class="trace__stops">
            <a class="trace__stop" href="#about" data-label="{{ __('landing.nav.about') }}"><svg aria-hidden="true"><use href="#i-chevup"/></svg><span class="sr">{{ __('landing.nav.about') }}</span></a>
            <a class="trace__stop" href="#lab" data-label="{{ __('landing.nav.lab') }}"><svg aria-hidden="true"><use href="#i-chevup"/></svg><span class="sr">{{ __('landing.nav.lab') }}</span></a>
            <a class="trace__stop" href="#goals" data-label="{{ __('landing.nav.goals') }}"><svg aria-hidden="true"><use href="#i-chevup"/></svg><span class="sr">{{ __('landing.nav.goals') }}</span></a>
            <a class="trace__stop" href="#learn" data-label="{{ __('landing.nav.learn') }}"><svg aria-hidden="true"><use href="#i-chevup"/></svg><span class="sr">{{ __('landing.nav.learn') }}</span></a>
            <a class="trace__stop" href="#timeline" data-label="{{ __('landing.nav.timeline') }}"><svg aria-hidden="true"><use href="#i-chevup"/></svg><span class="sr">{{ __('landing.nav.timeline') }}</span></a>
            <a class="trace__stop" href="#faq" data-label="{{ __('landing.nav.faq') }}"><svg aria-hidden="true"><use href="#i-chevup"/></svg><span class="sr">{{ __('landing.nav.faq') }}</span></a>
        </div>
    </div>
</nav>

{{-- Scroll HUD: the "model accuracy" ring climbs with scroll depth. Purely decorative. --}}
<div class="hud" id="hud" aria-hidden="true" data-label-start="{{ __('landing.sim.js.model_stage_start') }}">
    <div class="hud__ring">
        <svg viewBox="0 0 34 34" width="34" height="34">
            <defs>
                <linearGradient id="hudGrad" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="var(--violet-500)"/>
                    <stop offset="1" stop-color="var(--teal-500)"/>
                </linearGradient>
            </defs>
            <circle class="hud__bg" cx="17" cy="17" r="14"/>
            <circle class="hud__fg" id="hudFg" cx="17" cy="17" r="14"/>
        </svg>
        <div class="hud__pct u-num" id="hudPct">0</div>
    </div>
    <div>{{ __('landing.sim.js.model_accuracy') }} · <b id="hudLabel">{{ __('landing.sim.js.model_stage_start') }}</b></div>
</div>

{{-- ============================================================
     Hero
     $landing['hero'] = eyebrow, title_lead, title_gradient, subtitle, chips[{icon,label}]
     ============================================================ --}}
<section class="hero" id="top">
    <div class="bp" id="bpGrid" aria-hidden="true"></div>
    <div class="hero__glow" aria-hidden="true"></div>
    <div class="hero__glow hero__glow--2" aria-hidden="true"></div>

    <div class="hero__in">
        <span class="eyebrow">
            <svg aria-hidden="true"><use href="#i-chevup"/></svg>
            {{ data_get($landing, 'hero.eyebrow') }}
            @if (data_get($cohort, 'is_registration_open'))
                · <span class="newdot"><i aria-hidden="true"></i> {{ __('landing.hero.registration_open') }}</span>
            @endif
        </span>

        <h1>
            <span class="mega">
                <span class="mega__ghost" aria-hidden="true">{{ data_get($landing, 'hero.title_lead') }}</span>
                {{-- data-decode splits on WORD boundaries only: Arabic letters must stay joined (art. 16 bis) --}}
                <span class="mega__real" data-decode>{{ data_get($landing, 'hero.title_lead') }}</span>
            </span>
            <span class="grad" data-wipe data-delay="560">{{ data_get($landing, 'hero.title_gradient') }}</span>
        </h1>

        <p class="hero__sub">{{ data_get($landing, 'hero.subtitle') }}</p>

        @if (filled(data_get($landing, 'hero.chips')))
            <ul class="hero__chips">
                @foreach (data_get($landing, 'hero.chips') as $chip)
                    <li class="chip">
                        <svg aria-hidden="true"><use href="#i-{{ data_get($chip, 'icon', 'spark') }}"/></svg>
                        <span>{{ data_get($chip, 'label') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif

        <div class="hero__acts">
            @if (data_get($cohort, 'is_registration_open'))
                <x-ui.button variant="primary" size="lg" :href="route('register')" class="mag">
                    <span class="mag__t">
                        {{ __('landing.hero.register') }}
                        <svg aria-hidden="true" class="ic ic--flip"><use href="#i-chev"/></svg>
                    </span>
                </x-ui.button>
            @endif
            <x-ui.button variant="secondary" size="lg" href="#lab" class="mag">
                <span class="mag__t">{{ __('landing.hero.try_lab') }}</span>
            </x-ui.button>
        </div>

        {{-- ---------------------------------------------------------
             Countdown and remaining seats.
             Server time is the sole reference (BR-07): the server prints its own instant
             and the deadline, the browser measures the skew once and counts down from that.
             The open/closed state itself is rendered by the server, never by the browser clock.
             --------------------------------------------------------- --}}
        <div class="cd" id="countdown">

            {{--
                The Alpine `countdown` component (resources/js/app.js) ticks on the
                server-corrected clock: `<html data-server-now>` fixes the offset once,
                so moving the system clock changes nothing. `finished` only swaps the
                display; whether registration is actually open was decided in PHP and
                is carried by `$cohort['is_registration_open']`.
            --}}
            @if (data_get($cohort, 'is_registration_open'))
                <div class="cd__live"
                     x-data="countdown({ target: '{{ data_get($cohort, 'registration_closes_at_iso') }}' })"
                     x-cloak>
                    <div class="cd__hd"><i class="cd__dot" aria-hidden="true"></i> {{ __('landing.hero.countdown_title') }}</div>
                    <div class="cd__grid" aria-live="off">
                        <div class="cd__cell"><div class="cd__n u-num" x-text="days">--</div><div class="cd__l">{{ __('landing.hero.days') }}</div></div>
                        <div class="cd__cell"><div class="cd__n u-num" x-text="hours">--</div><div class="cd__l">{{ __('landing.hero.hours') }}</div></div>
                        <div class="cd__cell"><div class="cd__n u-num" x-text="minutes">--</div><div class="cd__l">{{ __('landing.hero.minutes') }}</div></div>
                        <div class="cd__cell"><div class="cd__n u-num" x-text="seconds">--</div><div class="cd__l">{{ __('landing.hero.seconds') }}</div></div>
                    </div>
                </div>
            @endif

            {{-- Registration closed: collect an e-mail for the next cohort (PRD 9.1.2) --}}
            @unless (data_get($cohort, 'is_registration_open'))
            <div class="cd__closed" id="cdClosed">
                <div class="cd__hd cd__hd--closed">
                    <svg aria-hidden="true"><use href="#i-info"/></svg>
                    {{ __('landing.hero.registration_closed_title') }}
                </div>
                <p class="cd__closed-body">{{ __('landing.hero.registration_closed_body') }}</p>

                <form class="cd__wait" method="POST" action="{{ route('waitlist.store') }}">
                    @csrf
                    <x-ui.input
                        type="email"
                        name="email"
                        ltr
                        autocomplete="email"
                        required
                        :label="__('landing.hero.waitlist_email')"
                        :placeholder="__('landing.hero.waitlist_email_placeholder')"
                        :error="$errors->first('email')"/>
                    <x-ui.button variant="primary" type="submit">
                        {{ __('landing.hero.waitlist_submit') }}
                    </x-ui.button>
                </form>

                @if (session('waitlist_done'))
                    <p class="cd__wait-done" role="status">{{ __('landing.hero.waitlist_done') }}</p>
                @endif
            </div>
            @endunless

            @if (! is_null(data_get($cohort, 'seats_total')))
                <div class="seats">
                    <div class="seats__row">
                        <span>{{ __('landing.hero.seats_label') }}</span>
                        <span>
                            <b class="u-num">{{ data_get($cohort, 'seats_remaining') }}</b>
                            {{ __('landing.hero.seats_of') }}
                            <b class="u-num">{{ data_get($cohort, 'seats_total') }}</b>
                        </span>
                    </div>
                    {{-- RTL bar: fills from the right; app.js grows inline-size in an RTL flow --}}
                    <div class="bar"
                         role="progressbar"
                         aria-label="{{ __('landing.hero.seats_progress') }}"
                         aria-valuemin="0"
                         aria-valuemax="100"
                         aria-valuenow="{{ data_get($cohort, 'seats_taken_percent', 0) }}">
                        <div class="bar__fill" data-fill="{{ data_get($cohort, 'seats_taken_percent', 0) }}"></div>
                    </div>
                </div>
            @endif
        </div>
    </div>
</section>

{{-- ============================================================
     Topic ticker. Decorative, duplicated twice for a seamless marquee.
     $landing['ticker'] = string[]
     ============================================================ --}}
@if (filled(data_get($landing, 'ticker')))
    <div class="tick" aria-hidden="true">
        <div class="tick__track">
            @for ($set = 0; $set < 2; $set++)
                <div class="tick__set">
                    @foreach (data_get($landing, 'ticker') as $topic)
                        <span class="tick__i">{{ $topic }}<svg aria-hidden="true"><use href="#i-chevup"/></svg></span>
                    @endforeach
                </div>
            @endfor
        </div>
    </div>
@endif

{{-- ============================================================
     Trust bar
     $landing['trust'] = [{value:int, suffix:?string, label:string}]
     ============================================================ --}}
<section class="trust">
    <div class="wrap">
        @forelse (data_get($landing, 'trust', []) as $stat)
            @if ($loop->first)<div class="trust__grid">@endif
            <div class="trust__i rv">
                <div class="trust__n">
                    <span class="odo u-num" data-count="{{ data_get($stat, 'value') }}"></span>
                    @if (filled(data_get($stat, 'suffix')))
                        <span class="suf">{{ data_get($stat, 'suffix') }}</span>
                    @endif
                </div>
                <div class="trust__l">{{ data_get($stat, 'label') }}</div>
            </div>
            @if ($loop->last)</div>@endif
        @empty
            <p class="sec__empty">{{ __('landing.states.empty_trust') }}</p>
        @endforelse
    </div>
</section>

{{-- ============================================================
     About the programme
     $landing['about'] = kicker, title, paragraphs[], tags[], cards[{icon,title,body}]
     ============================================================ --}}
<section class="sec" id="about">
    <div class="wrap">
        <div class="grid g2 about__grid">
            <div class="rv">
                <span class="kicker"><svg aria-hidden="true"><use href="#i-chevup"/></svg> {{ data_get($landing, 'about.kicker') }}</span>
                <h2 class="h2--display">{{ data_get($landing, 'about.title') }}</h2>

                @foreach (data_get($landing, 'about.paragraphs', []) as $paragraph)
                    <p class="lead">{{ $paragraph }}</p>
                @endforeach

                @if (filled(data_get($landing, 'about.tags')))
                    <div class="tagrow">
                        @foreach (data_get($landing, 'about.tags') as $tag)
                            <span class="dtag">{{ $tag }}</span>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="rv">
                <div class="grid g2 about__cards">
                    @foreach (data_get($landing, 'about.cards', []) as $card)
                        <div class="card card--dark tilt">
                            <div class="tilt__in">
                                <div class="card__ic"><svg aria-hidden="true"><use href="#i-{{ data_get($card, 'icon', 'spark') }}"/></svg></div>
                                <h3>{{ data_get($card, 'title') }}</h3>
                                <p>{{ data_get($card, 'body') }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     Lab section - train a model by hand (approved interactive section).
     A linear classifier trained entirely in the browser: no server, no library, no data leaves the page.
     ============================================================ --}}
<section class="sec sec--lab" id="lab">
    <div class="wrap">
        <div class="sec__hd sec__hd--center rv">
            <span class="kicker"><svg aria-hidden="true"><use href="#i-chevup"/></svg> {{ __('landing.lab.kicker') }}</span>
            <h2 class="h2--display">{{ __('landing.lab.title') }}</h2>
            <p class="lead">{{ __('landing.lab.lead') }}</p>
        </div>

        <div class="lab rv" id="lab-widget">
            <div class="lab__grid">
                <div class="lab__stage" id="labStage" role="application" aria-label="{{ __('landing.lab.canvas_label') }}">
                    <canvas id="labCanvas"></canvas>
                    <p class="lab__hint" id="labHint">{{ __('landing.lab.hint') }}</p>
                    <noscript>
                        <p class="lab__fallback">{{ __('landing.lab.unavailable') }}</p>
                    </noscript>
                </div>

                <div class="lab__side">
                    <div>
                        <div class="lab__prompt" id="labPrompt">{{ __('landing.lab.class_prompt') }}</div>
                        <div class="lab__team" role="group" aria-labelledby="labPrompt">
                            <button type="button" class="lab__b" data-cls="a" aria-pressed="true">
                                <i class="lab__sw lab__sw--a" aria-hidden="true"></i>
                                <span>{{ __('landing.lab.class_a') }}</span>
                                <span class="u-num lab__count" id="cntA">0</span>
                            </button>
                            <button type="button" class="lab__b" data-cls="b" aria-pressed="false">
                                <i class="lab__sw lab__sw--b" aria-hidden="true"></i>
                                <span>{{ __('landing.lab.class_b') }}</span>
                                <span class="u-num lab__count" id="cntB">0</span>
                            </button>
                        </div>
                    </div>

                    <div class="lab__stat">
                        <div class="lab__row"><span>{{ __('landing.lab.accuracy') }}</span><b class="u-num" id="labAcc">—</b></div>
                        <div class="lab__acc"><div class="lab__accf" id="labAccBar"></div></div>
                        <div class="lab__row"><span>{{ __('landing.lab.examples') }}</span><b class="u-num" id="labN">0</b></div>
                        <div class="lab__row"><span>{{ __('landing.lab.epochs') }}</span><b class="u-num" id="labEp">0</b></div>
                    </div>

                    <p class="lab__note">{{ __('landing.lab.note') }}</p>

                    <div class="lab__acts">
                        <x-ui.button variant="secondary" size="sm" id="labSeed" class="mag">
                            <span class="mag__t">{{ __('landing.lab.seed') }}</span>
                        </x-ui.button>
                        <x-ui.button variant="ghost" size="sm" id="labClear">
                            {{ __('landing.lab.clear') }}
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     Programme goals
     $landing['goals'] = kicker, title, lead, items[{icon,title,body}]
     ============================================================ --}}
<section class="sec sec--pale" id="goals">
    <div class="wrap">
        <div class="sec__hd rv">
            <span class="kicker"><svg aria-hidden="true"><use href="#i-chevup"/></svg> {{ data_get($landing, 'goals.kicker') }}</span>
            <h2>{{ data_get($landing, 'goals.title') }}</h2>
            <p class="lead">{{ data_get($landing, 'goals.lead') }}</p>
        </div>

        @forelse (data_get($landing, 'goals.items', []) as $goal)
            @if ($loop->first)<div class="grid g3">@endif
            <div class="card goal tilt rv">
                <span class="goal__n u-num" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                <div class="tilt__in">
                    <div class="card__ic"><svg aria-hidden="true"><use href="#i-{{ data_get($goal, 'icon', 'spark') }}"/></svg></div>
                    @if (filled(data_get($goal, 'title')))<h3>{{ data_get($goal, 'title') }}</h3>@endif
                    @if (filled(data_get($goal, 'body')))<p>{{ data_get($goal, 'body') }}</p>@endif
                </div>
            </div>
            @if ($loop->last)</div>@endif
        @empty
            <p class="sec__empty">{{ __('landing.states.empty_goals') }}</p>
        @endforelse
    </div>
</section>

{{-- ============================================================
     Target audience
     $landing['audience'] = kicker, title, lead, items[{title,body}]
     ============================================================ --}}
<section class="sec sec--tint" id="audience">
    <div class="wrap">
        <div class="grid g2 audience__grid">
            <div class="rv">
                <span class="kicker"><svg aria-hidden="true"><use href="#i-chevup"/></svg> {{ data_get($landing, 'audience.kicker') }}</span>
                <h2 class="h2--display">{{ data_get($landing, 'audience.title') }}</h2>
                <p class="lead">{{ data_get($landing, 'audience.lead') }}</p>
            </div>

            <div class="who rv">
                @forelse (data_get($landing, 'audience.items', []) as $item)
                    <div class="who__i">
                        <span class="who__ck" aria-hidden="true"><svg><use href="#i-check"/></svg></span>
                        <div>
                            @if (filled(data_get($item, 'title')))<b>{{ data_get($item, 'title') }}</b>@endif
                            @if (filled(data_get($item, 'body')))<span>{{ data_get($item, 'body') }}</span>@endif
                        </div>
                    </div>
                @empty
                    <p class="sec__empty">{{ __('landing.states.empty_audience') }}</p>
                @endforelse
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     What you will learn - the week deck
     $landing['weeks'] = kicker, title, items[{index,title,dates,sessions,topics[]}]
     ============================================================ --}}
<section id="learn" class="learn">
    <div class="wrap learn__hd-wrap">
        <div class="sec__hd sec__hd--center rv">
            <span class="kicker"><svg aria-hidden="true"><use href="#i-chevup"/></svg> {{ data_get($landing, 'weeks.kicker') }}</span>
            <h2 class="h2--display">{{ data_get($landing, 'weeks.title') }}</h2>
            <p class="lead">{{ __('landing.sections.deck_hint') }}</p>
        </div>
    </div>

    @forelse (data_get($landing, 'weeks.items', []) as $week)
        @if ($loop->first)
            <div class="deck" id="deck">
                <div class="deck__stick">
                    <div class="deck__cards" id="deckCards">
        @endif

        <article class="deck__c" data-i="{{ $loop->index }}">
            <span class="deck__no u-num" aria-hidden="true">{{ str_pad((string) data_get($week, 'index', $loop->iteration), 2, '0', STR_PAD_LEFT) }}</span>
            <h3>{{ data_get($week, 'title') }}</h3>
            @if (filled(data_get($week, 'dates')) || ! is_null(data_get($week, 'sessions')))
                <div class="dt">
                    @if (filled(data_get($week, 'dates')))
                        <span>{{ data_get($week, 'dates') }}</span>
                    @endif
                    @if (! is_null(data_get($week, 'sessions')))
                        @if (filled(data_get($week, 'dates'))) · @endif
                        <span>{{ trans_choice('landing.sections.sessions_choice', (int) data_get($week, 'sessions'), ['count' => data_get($week, 'sessions')]) }}</span>
                    @endif
                </div>
            @endif
            @if (filled(data_get($week, 'topics')))
                <div class="deck__tags">
                    @foreach (data_get($week, 'topics') as $topic)
                        <span class="dtag">{{ $topic }}</span>
                    @endforeach
                </div>
            @endif
        </article>

        @if ($loop->last)
                    </div>
                    <div class="deck__prog" id="deckProg" aria-hidden="true">
                        @foreach (data_get($landing, 'weeks.items', []) as $dot)
                            <i @class(['on' => $loop->first])></i>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    @empty
        <div class="wrap"><p class="sec__empty">{{ __('landing.states.empty_weeks') }}</p></div>
    @endforelse
</section>

{{-- ============================================================
     Timeline
     $landing['timeline'] = kicker, title, lead, items[{title,when}]
     ============================================================ --}}
<section class="sec sec--pale" id="timeline">
    <div class="wrap">
        <div class="sec__hd rv">
            <span class="kicker"><svg aria-hidden="true"><use href="#i-chevup"/></svg> {{ data_get($landing, 'timeline.kicker') }}</span>
            <h2>{{ data_get($landing, 'timeline.title') }}</h2>
            <p class="lead">{{ data_get($landing, 'timeline.lead') }}</p>
        </div>

        @forelse (data_get($landing, 'timeline.items', []) as $step)
            @if ($loop->first)
                <div class="tl" id="tlBox">
                    <div class="tl__line" aria-hidden="true"><div class="tl__fill" id="tlFill"></div></div>
                    <ol class="tl__row">
            @endif

            <li class="tl__i">
                <div class="tl__dot" aria-hidden="true"><svg><use href="#i-check"/></svg></div>
                <b>{{ data_get($step, 'title') }}</b>
                <span>{{ data_get($step, 'when') }}</span>
            </li>

            @if ($loop->last)
                    </ol>
                </div>
            @endif
        @empty
            <p class="sec__empty">{{ __('landing.states.empty_timeline') }}</p>
        @endforelse
    </div>
</section>

{{-- ============================================================
     Certificates
     $landing['certificates'] = kicker, title, lead,
         items[{icon,title,body,tag,rows[{label,value,tone}]}]
     ============================================================ --}}
<section class="sec" id="certs">
    <div class="wrap">
        <div class="sec__hd rv">
            <span class="kicker"><svg aria-hidden="true"><use href="#i-chevup"/></svg> {{ data_get($landing, 'certificates.kicker') }}</span>
            <h2>{{ data_get($landing, 'certificates.title') }}</h2>
            <p class="lead">{{ data_get($landing, 'certificates.lead') }}</p>
        </div>

        @forelse (data_get($landing, 'certificates.items', []) as $cert)
            @if ($loop->first)<div class="grid g2">@endif

            {{-- Flips on hover or focus; both faces stack under prefers-reduced-motion --}}
            {{-- The condition is repeated rather than hoisted into a raw PHP
                 island, which gate:forbidden reports as a finding. --}}
            <div class="flip rv" @if (filled(data_get($cert, 'tag')) || filled(data_get($cert, 'rows'))) tabindex="0" @endif>
                <div class="flip__in">
                    <div class="flip__f flip__f--{{ data_get($cert, 'tone', 'violet') }}">
                        <div class="cert__seal cert__seal--{{ data_get($cert, 'tone', 'violet') }}">
                            <svg aria-hidden="true"><use href="#i-{{ data_get($cert, 'icon', 'badge') }}"/></svg>
                        </div>
                        @if (filled(data_get($cert, 'title')))<h3>{{ data_get($cert, 'title') }}</h3>@endif
                        @if (filled(data_get($cert, 'body')))<p>{{ data_get($cert, 'body') }}</p>@endif
                        {{-- Promising details behind a flip that has no back is worse
                             than showing no hint at all. --}}
                        @if (filled(data_get($cert, 'tag')) || filled(data_get($cert, 'rows')))
                            <div class="flip__hint">
                                <svg aria-hidden="true"><use href="#i-eye"/></svg>
                                {{ __('landing.sections.certificate_flip_hint') }}
                            </div>
                        @endif
                    </div>

                    <div class="flip__b">
                        @if (filled(data_get($cert, 'tag')))
                            <span class="simtag">{{ data_get($cert, 'tag') }}</span>
                        @endif
                        @foreach (data_get($cert, 'rows', []) as $row)
                            <div class="flip__row">
                                <span>{{ data_get($row, 'label') }}</span>
                                <b @class(['is-ok' => data_get($row, 'tone') === 'ok'])>{{ data_get($row, 'value') }}</b>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            @if ($loop->last)</div>@endif
        @empty
            <p class="sec__empty">{{ __('landing.states.empty_certificates') }}</p>
        @endforelse

        {{-- ------------------------------------------------------
             Certificate eligibility simulator (approved interactive section).
             Thresholds and weights come from the cohort on the server, never from code:
             pass_score, min_attendance_rate and the score totals (BR-11, BR-26).
             Indicative preview for the visitor; the binding calculation runs server-side only.
             ------------------------------------------------------ --}}
        <div class="sec__hd sec__hd--center sec__hd--sim rv">
            <span class="kicker"><svg aria-hidden="true"><use href="#i-chevup"/></svg> {{ __('landing.sim.kicker') }}</span>
            <h2 class="h2--display">{{ __('landing.sim.title') }}</h2>
            <p class="lead">{{ __('landing.sim.lead') }}</p>
        </div>

        {{--
            DOM contract with certificateSimulator() in resources/js/landing.js.

            Every threshold and every weight is an attribute filled from the cohort row,
            never a literal: pass_score, min_attendance_rate, the number of sessions, the
            number of assignments and the two point totals all live in the database and are
            edited from the admin panel (BR-11, BR-26, BR-31, BR-36).

            Every sentence the script can print is handed to it through a data-t-* attribute
            that already went through __(), so not one Arabic character lives in a .js file
            (Constitution art. 15). Where lang/ar/landing.php names a placeholder that the
            script does not supply, it is rewritten here to the name the script does supply
            (:missing -> :short, :rate -> :attendance) or resolved on the server (:max).
        --}}
        <div class="sim rv"
             id="sim"
             data-sessions="{{ data_get($cohort, 'sessions_total', 0) }}"
             data-tasks="{{ data_get($cohort, 'assignments_total', 0) }}"
             data-task-points="{{ data_get($cohort, 'assignments_points', 0) }}"
             data-project-points="{{ data_get($cohort, 'project_points', 0) }}"
             data-pass-score="{{ data_get($cohort, 'pass_score', 0) }}"
             data-min-attendance="{{ data_get($cohort, 'min_attendance_rate', 0) }}"

             data-sessions-one="{{ __('landing.sim.js.session_one') }}"
             data-sessions-two="{{ __('landing.sim.js.session_two') }}"
             data-sessions-few="{{ __('landing.sim.js.session_few') }}"
             data-sessions-many="{{ __('landing.sim.js.session_many') }}"

             data-t-on_edge="{{ __('landing.sim.js.spare_none') }}"
             data-t-spare="{{ __('landing.sim.js.spare_some') }}"
             data-t-need="{{ __('landing.sim.js.need_more') }}"
             data-t-score_over="{{ __('landing.sim.js.score_above') }}"
             data-t-score_under="{{ __('landing.sim.js.score_below', ['missing' => ':short']) }}"

             data-t-verdict_pass="{{ __('landing.sim.js.verdict_pass_title') }}"
             data-t-verdict_pass_desc="{{ __('landing.sim.js.verdict_pass_body', ['rate' => ':attendance', 'max' => data_get($cohort, 'grand_total', 100)]) }}"
             data-t-verdict_none="{{ __('landing.sim.js.verdict_none_title') }}"
             data-t-verdict_none_desc="{{ __('landing.sim.js.verdict_none_body') }}"
             data-t-verdict_attendance="{{ __('landing.sim.js.verdict_attendance_title') }}"
             data-t-verdict_attendance_desc="{{ __('landing.sim.js.verdict_attendance_body', ['rate' => ':attendance']) }}"
             data-t-verdict_score="{{ __('landing.sim.js.verdict_score_title') }}"
             data-t-verdict_score_desc="{{ __('landing.sim.js.verdict_score_body', ['rate' => ':attendance']) }}">

            <div class="sim__grid">
                <div class="sim__in">
                    {{-- Slider one: sessions attended out of the cohort's session count --}}
                    <div class="sl">
                        <div class="sl__hd">
                            <label for="simSessions">{{ __('landing.sim.q_sessions') }}</label>
                            <b><span class="u-num" id="simVSessions">{{ data_get($cohort, 'sessions_total', 0) }}</span><small class="u-num"> / {{ data_get($cohort, 'sessions_total', 0) }}</small></b>
                        </div>
                        <div class="sl__track">
                            <div class="sl__rail" aria-hidden="true"></div>
                            <div class="sl__fill" id="simFSessions" aria-hidden="true"></div>
                            <input type="range" id="simSessions" min="0" step="1"
                                   max="{{ data_get($cohort, 'sessions_total', 0) }}"
                                   value="{{ data_get($cohort, 'sessions_total', 0) }}"
                                   aria-label="{{ __('landing.sim.aria_sessions') }}">
                        </div>
                        <div class="sl__scale"><span class="u-num">{{ data_get($cohort, 'sessions_total', 0) }}</span><span class="u-num">0</span></div>
                    </div>

                    {{-- Slider two: assignments handed in --}}
                    <div class="sl">
                        <div class="sl__hd">
                            <label for="simTasks">{{ __('landing.sim.q_tasks') }}</label>
                            <b><span class="u-num" id="simVTasks">{{ data_get($cohort, 'assignments_total', 0) }}</span><small class="u-num"> / {{ data_get($cohort, 'assignments_total', 0) }}</small></b>
                        </div>
                        <div class="sl__track">
                            <div class="sl__rail" aria-hidden="true"></div>
                            <div class="sl__fill" id="simFTasks" aria-hidden="true"></div>
                            <input type="range" id="simTasks" min="0" step="1"
                                   max="{{ data_get($cohort, 'assignments_total', 0) }}"
                                   value="{{ data_get($cohort, 'assignments_total', 0) }}"
                                   aria-label="{{ __('landing.sim.aria_tasks') }}">
                        </div>
                        <div class="sl__scale"><span class="u-num">{{ data_get($cohort, 'assignments_total', 0) }}</span><span class="u-num">0</span></div>
                    </div>

                    {{-- Slider three: expected final-project score --}}
                    <div class="sl">
                        <div class="sl__hd">
                            <label for="simProject">{{ __('landing.sim.q_project') }}</label>
                            <b><span class="u-num" id="simVProject">{{ data_get($cohort, 'project_points', 0) }}</span><small class="u-num"> / {{ data_get($cohort, 'project_points', 0) }}</small></b>
                        </div>
                        <div class="sl__track">
                            <div class="sl__rail" aria-hidden="true"></div>
                            <div class="sl__fill" id="simFProject" aria-hidden="true"></div>
                            <input type="range" id="simProject" min="0" step="1"
                                   max="{{ data_get($cohort, 'project_points', 0) }}"
                                   value="{{ data_get($cohort, 'project_points', 0) }}"
                                   aria-label="{{ __('landing.sim.aria_project') }}">
                        </div>
                        <div class="sl__scale"><span class="u-num">{{ data_get($cohort, 'project_points', 0) }}</span><span class="u-num">0</span></div>
                    </div>

                    <p class="sim__note">{{ __('landing.sim.explainer') }}</p>

                    <div class="sim__tags">
                        <span class="simtag">BR-11</span>
                        <span class="simtag">BR-26</span>
                        <span class="simtag">pass_score <span class="u-num">{{ data_get($cohort, 'pass_score', 0) }}</span></span>
                        <span class="simtag">min_attendance <span class="u-num">{{ data_get($cohort, 'min_attendance_rate', 0) }}</span></span>
                    </div>
                </div>

                {{-- The two gates are announced politely; neither state is carried by colour alone. --}}
                <div class="sim__out" aria-live="polite">
                    <div class="gate" id="simGateA">
                        <div class="gate__hd">
                            <span class="gate__ic" id="simIcA" aria-hidden="true"><svg><use href="#i-check"/></svg></span>
                            <span>{{ __('landing.sim.gate_attendance') }}</span>
                        </div>
                        <div class="gate__val"><span class="u-num" id="simPctA">0</span><small>%</small></div>
                        <div class="gate__bar">
                            <div class="gate__barf" id="simBarA"></div>
                            <div class="gate__min"
                                 aria-hidden="true"
                                 style="--threshold: {{ data_get($cohort, 'min_attendance_rate', 0) }}%"></div>
                        </div>
                        <div class="gate__note" id="simNoteA">
                            {{ __('landing.sim.min_attendance') }}
                            <span class="u-num">{{ data_get($cohort, 'min_attendance_rate', 0) }}%</span>
                        </div>
                    </div>

                    <div class="gate" id="simGateB">
                        <div class="gate__hd">
                            <span class="gate__ic" id="simIcB" aria-hidden="true"><svg><use href="#i-check"/></svg></span>
                            <span>{{ __('landing.sim.gate_score') }}</span>
                        </div>
                        <div class="gate__val"><span class="u-num" id="simPctB">0</span><small class="u-num"> / {{ data_get($cohort, 'grand_total', 100) }}</small></div>
                        <div class="gate__bar">
                            <div class="gate__barf" id="simBarB"></div>
                            <div class="gate__min"
                                 aria-hidden="true"
                                 style="--threshold: {{ data_get($cohort, 'pass_score', 0) }}%"></div>
                        </div>
                        <div class="gate__note" id="simNoteB">
                            {{ __('landing.sim.pass_score') }}
                            <span class="u-num">{{ data_get($cohort, 'pass_score', 0) }}</span>
                        </div>
                    </div>

                    {{--
                        The verdict states BR-26 out loud: both conditions, no compensation.
                        It is an illustration only - CertificateEligibility on the server is
                        the sole authority on whether a certificate is ever issued.
                    --}}
                    <div class="verdict" id="simVerdict">
                        <div class="verdict__t">
                            <span class="vic" id="simVerdictIc" aria-hidden="true"><svg><use href="#i-check"/></svg></span>
                            <span id="simVerdictTitle">{{ __('landing.sim.js.verdict_pass_title') }}</span>
                        </div>
                        <div class="verdict__d" id="simVerdictDesc"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ============================================================
     Trainers
     $landing['trainers'] = kicker, title, lead, items[{initials,name,role,bio,photo_url}]
     ============================================================ --}}
{{-- The list is empty by construction, not by absence of data: HomeController
     does not read trainers from the enrolment table yet. An empty state here
     would ask the centre to fix something no admin action can fix, so the
     section stays out of the page until the list is wired. --}}
@if (filled(data_get($landing, 'trainers.items')))
<section class="sec sec--pale" id="trainers">
    <div class="wrap">
        <div class="sec__hd sec__hd--center rv">
            <span class="kicker"><svg aria-hidden="true"><use href="#i-chevup"/></svg> {{ data_get($landing, 'trainers.kicker') }}</span>
            <h2>{{ data_get($landing, 'trainers.title') }}</h2>
            <p class="lead">{{ data_get($landing, 'trainers.lead') }}</p>
        </div>

        @forelse (data_get($landing, 'trainers.items', []) as $trainer)
            @if ($loop->first)<div class="grid g3">@endif
            <div class="card trainer tilt rv">
                <div class="tilt__in">
                    @if (filled(data_get($trainer, 'photo_url')))
                        <img class="trainer__img"
                             src="{{ data_get($trainer, 'photo_url') }}"
                             alt="{{ data_get($trainer, 'name') }}"
                             width="88" height="88" loading="lazy" decoding="async">
                    @else
                        <div class="trainer__av" aria-hidden="true">{{ data_get($trainer, 'initials') }}</div>
                    @endif
                    <b>{{ data_get($trainer, 'name') }}</b>
                    <span>{{ data_get($trainer, 'role') }}</span>
                    <p>{{ data_get($trainer, 'bio') }}</p>
                </div>
            </div>
            @if ($loop->last)</div>@endif
        @empty
            <p class="sec__empty">{{ __('landing.states.empty_trainers') }}</p>
        @endforelse
    </div>
</section>
@endif

{{-- ============================================================
     FAQ - at least 8 entries, managed from the admin panel (BR-31)
     $landing['faq'] = kicker, title, items[{question,answer}]
     ============================================================ --}}
<section class="sec" id="faq">
    <div class="wrap">
        <div class="sec__hd sec__hd--center rv">
            <span class="kicker"><svg aria-hidden="true"><use href="#i-chevup"/></svg> {{ data_get($landing, 'faq.kicker') }}</span>
            <h2>{{ data_get($landing, 'faq.title') }}</h2>
            <p class="lead">
                {{ __('landing.sections.faq_contact') }}
                <a class="lead__mail" href="mailto:{{ config('athar.email') }}" dir="ltr">{{ config('athar.email') }}</a>
            </p>
        </div>

        @forelse (data_get($landing, 'faq.items', []) as $item)
            @if ($loop->first)<div class="faq">@endif
            <details class="faq__i" @if ($loop->first) open @endif>
                <summary class="faq__q">
                    <svg aria-hidden="true"><use href="#i-chev"/></svg>
                    <span>{{ data_get($item, 'question') }}</span>
                </summary>
                <div class="faq__wrap">
                    <div>
                        <div class="faq__a">{{ data_get($item, 'answer') }}</div>
                    </div>
                </div>
            </details>
            @if ($loop->last)</div>@endif
        @empty
            <p class="sec__empty">{{ __('landing.states.empty_faq') }}</p>
        @endforelse
    </div>
</section>

{{-- ============================================================
     Final call to action
     $landing['final'] = eyebrow, title, body
     ============================================================ --}}
<section class="sec sec--final">
    <div class="wrap">
        <div class="final rv">
            <div class="final__in">
                <span class="eyebrow">
                    <svg aria-hidden="true"><use href="#i-chevup"/></svg>
                    {{ data_get($landing, 'final.eyebrow') }}
                    @if (data_get($cohort, 'is_registration_open'))
                        · <span class="newdot"><i aria-hidden="true"></i> {{ __('landing.hero.registration_open') }}</span>
                    @endif
                </span>

                <h2>
                    @if (! is_null(data_get($cohort, 'seats_remaining')))
                        {{ trans_choice('landing.sections.seats_choice', (int) data_get($cohort, 'seats_remaining'), ['count' => data_get($cohort, 'seats_remaining')]) }}
                    @else
                        {{ data_get($landing, 'final.title') }}
                    @endif
                </h2>

                <p>{{ data_get($landing, 'final.body') }}</p>

                <div class="final__acts">
                    @if (data_get($cohort, 'is_registration_open'))
                        <x-ui.button variant="primary" size="lg" :href="route('register')" class="mag">
                            <span class="mag__t">{{ __('landing.final.register') }}</span>
                        </x-ui.button>
                    @endif
                    <x-ui.button variant="secondary" size="lg" href="#faq" class="mag">
                        <span class="mag__t">{{ __('landing.final.ask') }}</span>
                    </x-ui.button>
                </div>

                @if (! is_null(data_get($cohort, 'seats_total')))
                    <div class="final__seats">
                        <div class="bar"
                             role="progressbar"
                             aria-label="{{ __('landing.hero.seats_progress') }}"
                             aria-valuemin="0"
                             aria-valuemax="100"
                             aria-valuenow="{{ data_get($cohort, 'seats_taken_percent', 0) }}">
                            <div class="bar__fill" data-fill="{{ data_get($cohort, 'seats_taken_percent', 0) }}"></div>
                        </div>
                        <div class="final__seats-note">
                            <span class="u-num">{{ data_get($cohort, 'seats_taken', 0) }}</span>
                            {{ __('landing.hero.seats_of') }}
                            <span class="u-num">{{ data_get($cohort, 'seats_total') }}</span>
                            {{ __('landing.final.seats_booked_suffix') }}
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</section>

@endif

@endsection
