{{--
    Password recovery - step one: ask for the address.

    The response never differs between a known and an unknown address (BR-30,
    PRD §9.3.3). The controller therefore always flashes the same neutral sentence,
    and this file has no branch that could betray the difference: there is no
    "unknown e-mail" error state here at all, by design.

    @see BR-30 · PRD §9.3.3 · Constitution art. 5, 15, 17, 18, 24

    Variables from App\Http\Controllers\Auth\PasswordResetLinkController@create:
      $state  string  'ok' | 'loading' | 'empty' | 'error'
                      'empty' = recovery temporarily switched off by the admin
--}}
@extends('layouts.auth')

@section('pageTitle', __('auth.forgot.title'))

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

    @else

        <header class="authcard__hd">
            <h1>{{ __('auth.forgot.title') }}</h1>
            <p>{{ __('auth.forgot.subtitle') }}</p>
        </header>

        {{--
            The one and only outcome message. Identical whether the address exists
            or not (BR-30), and it replaces the form so the visitor is not tempted
            to submit again while the mail is on its way.
        --}}
        @if (session('status'))

            <div class="note note--ok" role="status">
                <svg aria-hidden="true"><use href="#i-check"/></svg>
                <div>
                    <p>{{ session('status') }}</p>
                    <p class="note__hint">{{ __('auth.forgot.sent_hint') }}</p>
                </div>
            </div>

            <p class="authcard__alt">
                {{ __('auth.forgot.remembered') }}
                <a href="{{ route('login') }}">{{ __('auth.forgot.go_login') }}</a>
            </p>

        @else

            {{--
                Only format and throttle errors can appear here. A "no such account"
                error is impossible by construction, which is the point of BR-30.
            --}}
            @if ($errors->any())
                <div class="note note--bad" role="alert">
                    <svg aria-hidden="true"><use href="#i-warn"/></svg>
                    <p>{{ $errors->first() }}</p>
                </div>
            @endif

            <form method="POST" action="{{ route('password.request') }}" class="form" novalidate>
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

                <x-ui.button
                    variant="primary"
                    size="lg"
                    type="submit"
                    class="form__submit">
                    {{ __('auth.forgot.submit') }}
                </x-ui.button>

                <p class="form__note">{{ __('auth.forgot.sent_hint') }}</p>
            </form>

            <p class="authcard__alt">
                {{ __('auth.forgot.remembered') }}
                <a href="{{ route('login') }}">{{ __('auth.forgot.go_login') }}</a>
            </p>

        @endif

    @endif

</div>

@endsection
