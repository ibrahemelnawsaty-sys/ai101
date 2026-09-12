{{--
    Password recovery - step two: choose the new password.

    The same rules as registration apply, with the same live checklist and the same
    four-level strength meter (PRD §9.3.3). Everything here is convenience: the binding
    check is ResetPasswordRequest on the server, and the server is what invalidates
    every other session afterwards (BR-29) - the browser is told about it, never asked.

    The token travels in a hidden field and is single-use for thirty minutes; an
    exhausted or expired token is answered by the server with $tokenValid = false and
    a screen that offers a fresh link rather than a dead form.

    @see BR-29, BR-30 · PRD §9.3.3 · Constitution art. 5, 15, 17, 18, 24

    Variables from App\Http\Controllers\Auth\PasswordResetController@reset:
      $state         string  'ok' | 'loading' | 'empty' | 'error'
                             'empty' = recovery temporarily switched off by the admin
      $token         string  the single-use reset token from the signed link
      $email         string  the address the token was issued for (read-only echo)
      $tokenValid    bool    false once the token is spent, expired or unknown
      $passwordCopy  array   the checklist and meter words, built by the
                             PasswordMeterCopy trait. Passed as ONE variable on
                             purpose: a nested multi-line array written inside
                             the json directive compiles to broken PHP, and this
                             screen was a 500 for everyone because of it (D-67).

    Alpine component (resources/js/auth.js, shared with the registration wizard):
      passwordStrength({ minLength: 8 }) exposing
        password, passwordConfirmation, strength (0..3), strengthLabel,
        rules { length, upper, lower, digit, symbol }, metLabel, unmetLabel,
        submitting, scorePassword(), onSubmit($event)
--}}
@extends('layouts.auth')

@section('pageTitle', __('auth.reset.title'))

@section('content')

<div class="authcard">

    @if (($state ?? 'ok') === 'error')

        <x-ui.empty-state
            variant="error"
            icon="i-warn"
            :title="__('auth.shared.error_title')"
            :description="__('auth.shared.error_body')">
            <x-ui.button variant="secondary" :href="route('password.request')">
                {{ __('auth.shared.error_action') }}
            </x-ui.button>
        </x-ui.empty-state>

    @elseif (($state ?? 'ok') === 'loading')

        <div class="skel-form">
            <x-ui.skeleton shape="title" :label="__('auth.shared.loading')"/>
            <x-ui.skeleton shape="text" :lines="1"/>
            <x-ui.skeleton shape="bar"/>
            <x-ui.skeleton shape="bar"/>
            <x-ui.skeleton shape="text" :lines="5"/>
            <x-ui.skeleton shape="bar"/>
            <x-ui.skeleton shape="button"/>
        </div>

    @elseif (($state ?? 'ok') === 'empty')

        <x-ui.empty-state
            icon="i-lock"
            :title="__('auth.states.empty_password_title')"
            :description="__('auth.states.empty_password_body')">
            <x-ui.button variant="secondary" :href="route('home')">
                {{ __('auth.states.empty_action') }}
            </x-ui.button>
        </x-ui.empty-state>

    @elseif (! ($tokenValid ?? true))

        {{-- Spent, expired or unknown token. Never says which - that would be a probe. --}}
        <x-ui.empty-state
            icon="i-lock"
            :title="__('auth.reset.invalid_title')"
            :description="__('auth.reset.invalid_body')">
            <x-ui.button variant="primary" :href="route('password.request')">
                {{ __('auth.reset.invalid_action') }}
            </x-ui.button>
        </x-ui.empty-state>

    @else

        <header class="authcard__hd">
            <h1>{{ __('auth.reset.title') }}</h1>
            <p>{{ __('auth.reset.subtitle') }}</p>
        </header>

        @if ($errors->any())
            <div class="note note--bad" role="alert" tabindex="-1" id="serverErrors">
                <svg aria-hidden="true"><use href="#i-warn"/></svg>
                <div>
                    <b>{{ __('auth.shared.error_summary_title') }}</b>
                    <ul>
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        {{-- Live client-side copy, read from a JSON island so no Arabic sits in a .js file --}}
        <script type="application/json" id="passwordCopy">@json($passwordCopy ?? [], JSON_UNESCAPED_UNICODE)</script>

        <form method="POST"
              action="{{ route('password.update', ['token' => $token ?? '']) }}"
              class="form"
              novalidate
              x-data="passwordStrength({ minLength: 8 })"
              @submit="onSubmit($event)">
            @csrf

            <input type="hidden" name="token" value="{{ $token ?? '' }}">

            {{-- Read-only echo so the visitor sees which account they are changing. --}}
            <x-ui.input
                type="email"
                name="email"
                ltr
                autocomplete="username"
                state="readonly"
                required
                :value="old('email', $email ?? '')"
                :label="__('auth.shared.email')"
                :error="$errors->first('email')"/>

            <x-ui.input
                name="password"
                type="password"
                ltr
                autocomplete="new-password"
                autofocus
                required
                aria-describedby="pwRules"
                :label="__('auth.reset.new_password')"
                :error="$errors->first('password')"
                x-model="password" @input="scorePassword()"/>

            <div class="pw">
                <div class="pw__meter" role="img" :aria-label="strengthLabel" :data-level="strength">
                    <i></i><i></i><i></i><i></i>
                </div>
                <p class="pw__label">
                    {{ __('auth.register.strength_label') }}:
                    <b x-text="strengthLabel"></b>
                </p>
            </div>

            <ul class="pw__rules" id="pwRules" aria-live="polite">
                <li :class="{ 'is-ok': rules.length }">
                    <svg aria-hidden="true"><use href="#i-check"/></svg>
                    <span>{{ __('auth.register.rule_length') }}</span>
                    <span class="sr" x-text="rules.length ? metLabel : unmetLabel"></span>
                </li>
                <li :class="{ 'is-ok': rules.upper }">
                    <svg aria-hidden="true"><use href="#i-check"/></svg>
                    <span>{{ __('auth.register.rule_upper') }}</span>
                    <span class="sr" x-text="rules.upper ? metLabel : unmetLabel"></span>
                </li>
                <li :class="{ 'is-ok': rules.lower }">
                    <svg aria-hidden="true"><use href="#i-check"/></svg>
                    <span>{{ __('auth.register.rule_lower') }}</span>
                    <span class="sr" x-text="rules.lower ? metLabel : unmetLabel"></span>
                </li>
                <li :class="{ 'is-ok': rules.digit }">
                    <svg aria-hidden="true"><use href="#i-check"/></svg>
                    <span>{{ __('auth.register.rule_digit') }}</span>
                    <span class="sr" x-text="rules.digit ? metLabel : unmetLabel"></span>
                </li>
                <li :class="{ 'is-ok': rules.symbol }">
                    <svg aria-hidden="true"><use href="#i-check"/></svg>
                    <span>{{ __('auth.register.rule_symbol') }}</span>
                    <span class="sr" x-text="rules.symbol ? metLabel : unmetLabel"></span>
                </li>
            </ul>

            <x-ui.input
                name="password_confirmation"
                type="password"
                ltr
                autocomplete="new-password"
                required
                :label="__('auth.register.password_confirm')"
                :error="$errors->first('password_confirmation')"
                x-model="passwordConfirmation"/>

            {{-- BR-29 is enforced by the server; this only warns the visitor first. --}}
            <div class="note note--info">
                <svg aria-hidden="true"><use href="#i-info"/></svg>
                <p>{{ __('auth.reset.sessions_note') }}</p>
            </div>

            <x-ui.button
                variant="primary"
                size="lg"
                type="submit"
                class="form__submit"
                ::disabled="submitting">
                <span x-show="! submitting">{{ __('auth.reset.submit') }}</span>
                <span x-show="submitting" x-cloak>{{ __('auth.shared.processing') }}</span>
            </x-ui.button>

            <p class="form__note">{{ __('auth.shared.required_hint') }}</p>
        </form>

        <p class="authcard__alt">
            {{ __('auth.forgot.remembered') }}
            <a href="{{ route('login') }}">{{ __('auth.forgot.go_login') }}</a>
        </p>

    @endif

</div>

@endsection
