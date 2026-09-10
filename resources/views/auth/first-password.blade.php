{{--
    The one screen an invited account sees before anything else.

    `RequirePasswordChange` sends every request here while
    `users.must_change_password` is true, so this page has to be a complete
    place to stand: it explains why it appeared, it lets the trainee finish, and
    it lets them LEAVE. A screen with no exit turns opening the wrong invitation
    into a locked browser, and this one is reachable from a mailed link.

    THE SIGN-OUT BUTTON IS NOT DECORATION. Password recovery lives behind the
    `guest` middleware, so a signed-in trainee who clicked it would be bounced
    to the dashboard and from there straight back here. Signing out is what
    makes recovery reachable at all (D-63).

    Four states: error (the field errors) · loading (none — this form has no
    async step) · empty (not applicable) · expired.

    @see PRD §9.3.3 · BR-29 · CONSTITUTION Art. 5, Art. 17 · D-63

    Variables from App\Http\Controllers\Auth\FirstPasswordController@edit:
      $email      string
      $expiresAt  \DateTimeInterface|null
      $hasExpired bool
--}}
@extends('layouts.auth')

@section('pageTitle', __('auth.first_password.title'))

@section('content')
    <div class="authcard">
        <header class="authcard__hd">
            <h1>{{ __('auth.first_password.title') }}</h1>
            <p>{{ __('auth.first_password.subtitle') }}</p>
        </header>

        @if ($hasExpired)
            {{-- The edge case: it lapsed while this page was open. Sign-in
                 refuses a lapsed password, so this is only reachable that way. --}}
            <div class="note note--warn" role="alert">
                <svg aria-hidden="true"><use href="#i-lock"/></svg>
                <div>
                    <b>{{ __('auth.first_password.expired_title') }}</b>
                    <p>{{ __('auth.first_password.expired_body') }}</p>
                </div>
            </div>

            <form method="POST" action="{{ route('logout') }}" class="authcard__logout">
                @csrf
                <x-ui.button variant="primary" type="submit">{{ __('auth.first_password.expired_action') }}</x-ui.button>
            </form>
        @else
            @if ($errors->any())
                <div class="note note--bad" role="alert" aria-live="polite">
                    <b>{{ __('auth.shared.error_summary_title') }}</b>
                    <ul>
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="authcard__hint">
                {{ __('auth.first_password.signed_in_as') }}
                <span dir="ltr">{{ $email }}</span>
            </p>

            <form method="POST" action="{{ route('password.first.update') }}" class="form">
                @csrf
                @method('PUT')

                <x-ui.input name="current_password" type="password" required ltr
                    autocomplete="current-password"
                    :label="__('auth.first_password.current')"
                    :hint="__('auth.first_password.current_hint')" />

                <x-ui.input name="password" type="password" required ltr
                    autocomplete="new-password"
                    :label="__('auth.first_password.new')"
                    :hint="__('auth.first_password.new_hint')" />

                <x-ui.input name="password_confirmation" type="password" required ltr
                    autocomplete="new-password"
                    :label="__('auth.first_password.confirm')" />

                <div class="form__submit">
                    <x-ui.button variant="primary" type="submit">{{ __('auth.first_password.submit') }}</x-ui.button>
                </div>
            </form>

            @if ($expiresAt !== null)
                <p class="authcard__hint">
                    {{ __('auth.first_password.expires_note', ['date' => \App\Support\Dates::longDate($expiresAt)]) }}
                </p>
            @endif

            {{-- The exit. Not hidden in a menu: this page is reachable from a
                 mailed link, and somebody who opened the wrong one must be able
                 to get out without closing the browser. --}}
            <form method="POST" action="{{ route('logout') }}" class="authcard__logout">
                @csrf
                <x-ui.button variant="ghost" size="sm" type="submit">
                    {{ __('auth.first_password.sign_out') }}
                </x-ui.button>
            </form>
        @endif
    </div>
@endsection
