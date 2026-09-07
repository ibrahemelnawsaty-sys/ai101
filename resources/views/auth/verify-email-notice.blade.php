{{--
    Post-registration notice: the account exists, the activation link is on its way.

    PRD §9.2.3 asks for three things on this screen: a clear success statement, the
    address the link went to, and a resend button that only becomes available after
    sixty seconds. The cooldown is anchored to a server instant (BR-07) - the browser
    counts down towards a moment the server chose, and pressing the button early is
    refused by the throttle on the server anyway (Constitution art. 5).

    The screen also carries the second outcome of PRD §9.2.3: a cohort that requires
    admin approval leaves the enrolment pending, and the visitor is told so plainly.

    @see PRD §9.2.3, §9.3.1 · Constitution art. 5, 11, 15, 17, 18

    Variables from App\Http\Controllers\Auth\EmailVerificationNoticeController@show:
      $state                 string  'ok' | 'loading' | 'empty' | 'error'
                                     'empty' = activation mail temporarily switched off
      $email                 string  the address the link was sent to
      $resendAvailableAtIso  string  Clock::now()->addSeconds(60)->toIso8601String()
      $pendingApproval       bool    the cohort requires admin approval of the enrolment

    Alpine component (resources/js/auth.js):
      resendCooldown({ availableAt }) exposing `remaining` (whole seconds) and
      `ready` (bool), ticking on the server-corrected clock from window.Athar.serverNow.
--}}
@extends('layouts.auth')

@section('content')

<div class="authcard">

    @if (($state ?? 'ok') === 'error')

        <x-ui.empty-state
            variant="error"
            icon="i-warn"
            :title="__('auth.shared.error_title')"
            :description="__('auth.shared.error_body')">
            <x-ui.button variant="secondary" :href="url()->current()">
                {{ __('auth.shared.error_action') }}
            </x-ui.button>
        </x-ui.empty-state>

    @elseif (($state ?? 'ok') === 'loading')

        <div class="skel-form">
            <x-ui.skeleton shape="avatar" :label="__('auth.shared.loading')"/>
            <x-ui.skeleton shape="title"/>
            <x-ui.skeleton shape="text" :lines="2"/>
            <x-ui.skeleton shape="text" :lines="1"/>
            <x-ui.skeleton shape="button"/>
        </div>

    @elseif (($state ?? 'ok') === 'empty')

        <x-ui.empty-state
            icon="i-bell"
            :title="__('auth.states.empty_verify_title')"
            :description="__('auth.states.empty_verify_body')">
            <x-ui.button variant="secondary" :href="route('home')">
                {{ __('auth.states.empty_action') }}
            </x-ui.button>
        </x-ui.empty-state>

    @else

        <header class="authcard__hd authcard__hd--center">
            <span class="authcard__seal" aria-hidden="true">
                <svg><use href="#i-bell"/></svg>
            </span>
            <h1>{{ __('auth.verify.title') }}</h1>
            <p>{{ __('auth.verify.subtitle') }}</p>
        </header>

        @if (session('status'))
            <div class="note note--ok" role="status">
                <svg aria-hidden="true"><use href="#i-check"/></svg>
                <p>{{ session('status') }}</p>
            </div>
        @endif

        @if ($errors->any())
            <div class="note note--bad" role="alert">
                <svg aria-hidden="true"><use href="#i-warn"/></svg>
                <p>{{ $errors->first() }}</p>
            </div>
        @endif

        @if (filled($email ?? null))
            <p class="authcard__sent">
                {{ __('auth.verify.sent_to') }}
                <b dir="ltr" class="u-ltr">{{ $email }}</b>
            </p>
        @endif

        <p class="authcard__body">{{ __('auth.verify.body') }}</p>
        <p class="authcard__hint">{{ __('auth.verify.spam_hint') }}</p>

        {{-- Enrolment awaiting admin approval - PRD §9.2.3, last bullet --}}
        @if ($pendingApproval ?? false)
            <div class="note note--info" role="status">
                <svg aria-hidden="true"><use href="#i-info"/></svg>
                <div>
                    <b>{{ __('auth.verify.pending_approval_title') }}</b>
                    <p>{{ __('auth.verify.pending_approval_body') }}</p>
                </div>
            </div>
        @endif

        {{--
            Resend, gated by a sixty-second cooldown measured against server time.
            The button is genuinely absent - not merely styled as disabled - until the
            cooldown ends, and the remaining seconds are announced politely.
        --}}
        <div class="resend" x-data="resendCooldown({ availableAt: '{{ $resendAvailableAtIso ?? '' }}' })">
            <form method="POST" action="{{ route('verification.send') }}" x-show="ready" x-cloak>
                @csrf
                <x-ui.button
                    variant="secondary"
                    size="lg"
                    type="submit"
                    class="form__submit">
                    {{ __('auth.verify.resend') }}
                </x-ui.button>
            </form>

            <p class="resend__wait" x-show="! ready" aria-live="polite">
                {{ \Illuminate\Support\Str::before(__('auth.verify.resend_wait', ['seconds' => '%%']), '%%') }}<b
                    class="u-num" x-text="remaining"></b>{{ \Illuminate\Support\Str::after(__('auth.verify.resend_wait', ['seconds' => '%%']), '%%') }}
            </p>

            {{-- Without JavaScript the cooldown cannot tick, so the button is simply always offered;
                 the server-side throttle is what actually rate-limits the resend. --}}
            <noscript>
                <form method="POST" action="{{ route('verification.send') }}">
                    @csrf
                    <x-ui.button variant="secondary" size="lg" type="submit" class="form__submit">
                        {{ __('auth.verify.resend') }}
                    </x-ui.button>
                </form>
            </noscript>
        </div>

        <p class="authcard__alt">
            <a href="{{ route('login') }}">{{ __('auth.register.go_login') }}</a>
        </p>

        @auth
            <form method="POST" action="{{ route('logout') }}" class="authcard__logout">
                @csrf
                <x-ui.button variant="ghost" size="sm" type="submit">
                    {{ __('auth.verify.logout') }}
                </x-ui.button>
            </form>
        @endauth

    @endif

</div>

@endsection
