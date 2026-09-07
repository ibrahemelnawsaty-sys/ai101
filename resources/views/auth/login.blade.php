{{--
    Sign-in screen.

    Every message here is deliberately non-enumerating: a wrong e-mail and a wrong
    password produce the identical sentence, so an attacker cannot learn whether an
    address exists (BR-30). The lock-out countdown, the verification state and the
    suspension state are all decided on the server; this file only renders them.

    @see BR-29, BR-30 · PRD §9.3.1, §9.3.2 · Constitution art. 5, 17, 18, 24

    Variables from App\Http\Controllers\Auth\LoginController@create:
      $state            string  'ok' | 'loading' | 'empty' | 'error'
      $accountState     string|null  'unverified' | 'suspended' | 'locked' | null
      $lockedUntilIso   string|null  Clock instant the temporary lock lifts, ISO-8601 UTC
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
            <x-ui.button variant="secondary" :href="route('login')">
                {{ __('auth.shared.error_action') }}
            </x-ui.button>
        </x-ui.empty-state>

    @elseif (($state ?? 'ok') === 'loading')

        <div class="skel-form">
            <x-ui.skeleton shape="title" :label="__('auth.shared.loading')"/>
            <x-ui.skeleton shape="text" :lines="1"/>
            <x-ui.skeleton shape="bar"/>
            <x-ui.skeleton shape="bar"/>
            <x-ui.skeleton shape="button"/>
        </div>

    @elseif (($state ?? 'ok') === 'empty')

        <x-ui.empty-state
            icon="i-lock"
            :title="__('auth.states.empty_login_title')"
            :description="__('auth.states.empty_login_body')">
            <x-ui.button variant="secondary" :href="route('home')">
                {{ __('auth.states.empty_action') }}
            </x-ui.button>
        </x-ui.empty-state>

    @else

        <header class="authcard__hd">
            <h1>{{ __('auth.login.title') }}</h1>
            <p>{{ __('auth.login.subtitle') }}</p>
        </header>

        @if (session('status'))
            <div class="note note--ok" role="status">
                <svg aria-hidden="true"><use href="#i-check"/></svg>
                <p>{{ session('status') }}</p>
            </div>
        @endif

        {{-- Server-decided account states. The form stays reachable underneath. --}}
        @if (($accountState ?? null) === 'unverified')
            <div class="note note--warn" role="alert">
                <svg aria-hidden="true"><use href="#i-info"/></svg>
                <div>
                    <b>{{ __('auth.login.unverified_title') }}</b>
                    <p>{{ __('auth.login.unverified_body') }}</p>
                    <form method="POST" action="{{ route('verification.send') }}">
                        @csrf
                        <x-ui.button variant="secondary" size="sm" type="submit">
                            {{ __('auth.login.unverified_action') }}
                        </x-ui.button>
                    </form>
                </div>
            </div>
        @elseif (($accountState ?? null) === 'suspended')
            <div class="note note--warn" role="alert">
                <svg aria-hidden="true"><use href="#i-warn"/></svg>
                <div>
                    <b>{{ __('auth.login.suspended_title') }}</b>
                    <p>
                        {{ __('auth.login.suspended_body') }}
                        <a href="mailto:{{ config('athar.email') }}" dir="ltr">{{ config('athar.email') }}</a>
                    </p>
                </div>
            </div>
        @elseif (($accountState ?? null) === 'locked')
            <div class="note note--warn" role="alert">
                <svg aria-hidden="true"><use href="#i-lock"/></svg>
                <div>
                    <b>{{ __('auth.login.locked_title') }}</b>
                    <p>{{ __('auth.login.locked_body') }}</p>
                    {{-- The lock is a server fact. This counter only shows how long is
                         left, ticking against `<html data-server-now>` (BR-07); when it
                         reaches zero the page still has to ask the server. --}}
                    <p class="note__timer"
                       x-data="countdown({ target: '{{ $lockedUntilIso ?? '' }}' })">
                        {{ __('auth.login.locked_remaining') }}
                        <b class="u-num"><span x-text="minutes">--</span>:<span x-text="seconds">--</span></b>
                    </p>
                </div>
            </div>
        @endif

        @if ($errors->any())
            <div class="note note--bad" role="alert">
                <svg aria-hidden="true"><use href="#i-warn"/></svg>
                <p>{{ $errors->first() }}</p>
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="form" novalidate>
            @csrf

            <x-ui.input
                type="email"
                name="email"
                ltr
                inputmode="email"
                autocomplete="username"
                autofocus
                required
                :value="old('email')"
                :label="__('auth.shared.email')"
                :placeholder="__('auth.shared.email_placeholder')"
                :error="$errors->first('email')"/>

            <x-ui.input
                type="password"
                name="password"
                ltr
                autocomplete="current-password"
                required
                :label="__('auth.shared.password')"
                :error="$errors->first('password')"/>

            <div class="form__row">
                {{-- 'Remember me' extends the session to thirty days; the server decides
                     the lifetime, this box only carries the request (PRD §9.3.1). --}}
                <x-ui.checkbox
                    name="remember"
                    value="1"
                    :checked="(bool) old('remember')"
                    :label="__('auth.login.remember')"
                    :description="__('auth.login.remember_hint')"/>

                <a class="form__aside" href="{{ route('password.request') }}">{{ __('auth.login.forgot') }}</a>
            </div>

            <x-ui.button
                variant="primary"
                size="lg"
                type="submit"
                class="form__submit"
                :state="($accountState ?? null) === 'locked' ? 'disabled' : 'default'">
                {{ __('auth.login.submit') }}
            </x-ui.button>
        </form>

        <p class="authcard__alt">
            {{ __('auth.login.no_account') }}
            <a href="{{ route('register') }}">{{ __('auth.login.go_register') }}</a>
        </p>

    @endif

</div>

@endsection
