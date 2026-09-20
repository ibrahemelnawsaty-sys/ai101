{{--
    The invitation link: finish the account and choose the first password.

    WHY THIS SCREEN EXISTS (D-85)
    An invitation used to carry a temporary password, which meant an
    administrator had to know eleven facts about a person before inviting them.
    It now carries a name, an address and a link. Everything else is asked here,
    of the person who knows it.

    THE ADDRESS IS SHOWN AND CANNOT BE CHANGED. It is the address the
    administrator invited and the one the letter reached, so it is rendered
    read-only — and, more to the point, the server never reads a posted `email`
    at all: `AcceptInvitationRequest` has no such field.

    THE NAME IS OFFERED BACK, EDITABLE. Whatever the administrator typed is
    pre-filled so it can be corrected rather than retyped, and the parts nobody
    filled may be filled now or left empty.

    A spent, expired or unknown link renders the refusal state instead of the
    form — never saying which of the three it was (BR-30).

    Four states: error · loading · empty · normal, and the refusal above them.

    @see PRD §9.2.1, §9.4 · BR-29, BR-30 · Constitution art. 5, 15, 17, 18 · D-85

    Variables from App\Http\Controllers\Auth\InvitationController@show:
      $state         string  'ok' | 'loading' | 'empty' | 'error'
      $token         string  the single-use invitation token from the link
      $tokenValid    bool    false once the link is spent, expired or unknown
      $email         string  the address the invitation was sent to (read-only)
      $known         array   the name parts the administrator already typed
      $genderOptions array   value/label pairs for the radio group
      $passwordCopy  array   the checklist and meter words (PasswordMeterCopy)
--}}
@extends('layouts.auth')

@section('pageTitle', __('auth.invitation.title'))

@section('content')

<div class="authcard">

    @if (($state ?? 'ok') === 'error')

        <x-ui.empty-state
            variant="error"
            icon="i-warn"
            :title="__('auth.shared.error_title')"
            :description="__('auth.shared.error_body')">
            <x-ui.button variant="secondary" :href="route('login')">
                {{ __('auth.invitation.go_login') }}
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

    @elseif (! ($tokenValid ?? true))

        {{-- Spent, expired or unknown. Never says which — that would be a probe. --}}
        <x-ui.empty-state
            icon="i-lock"
            :title="__('auth.invitation.invalid_title')"
            :description="__('auth.invitation.invalid_body')">
            <x-ui.button variant="primary" :href="route('login')">
                {{ __('auth.invitation.go_login') }}
            </x-ui.button>
        </x-ui.empty-state>

    @else

        <header class="authcard__hd">
            <h1>{{ __('auth.invitation.title') }}</h1>
            <p>{{ __('auth.invitation.subtitle') }}</p>
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
              action="{{ route('invitation.store', ['token' => $token ?? '']) }}"
              class="form"
              novalidate
              x-data="passwordStrength({ minLength: 8 })"
              @submit="onSubmit($event)">
            @csrf

            <input type="hidden" name="token" value="{{ $token ?? '' }}">

            {{-- Read-only, and not a field the server reads: the address belongs
                 to the invitation. --}}
            <x-ui.input
                type="email"
                name="invited_email"
                ltr
                autocomplete="username"
                state="readonly"
                :value="$email ?? ''"
                :label="__('auth.shared.email')"
                :hint="__('auth.invitation.email_locked')"/>

            <fieldset class="fieldset">
                <legend>{{ __('auth.register.ar_names') }}</legend>
                <p class="form__note">{{ __('auth.invitation.name_hint') }}</p>
                <div class="grid-fields">
                    <x-ui.input name="first_name_ar" required maxlength="20"
                        :label="__('auth.register.first_name_ar')"
                        :value="old('first_name_ar', $known['first_name_ar'] ?? '')"
                        :error="$errors->first('first_name_ar')"/>
                    <x-ui.input name="father_name_ar" maxlength="20"
                        :label="__('auth.register.father_name_ar')"
                        :value="old('father_name_ar', $known['father_name_ar'] ?? '')"
                        :error="$errors->first('father_name_ar')"/>
                    <x-ui.input name="grandfather_name_ar" maxlength="20"
                        :label="__('auth.register.grandfather_name_ar')"
                        :value="old('grandfather_name_ar', $known['grandfather_name_ar'] ?? '')"
                        :error="$errors->first('grandfather_name_ar')"/>
                    <x-ui.input name="family_name_ar" maxlength="20"
                        :label="__('auth.register.family_name_ar')"
                        :value="old('family_name_ar', $known['family_name_ar'] ?? '')"
                        :error="$errors->first('family_name_ar')"/>
                </div>
            </fieldset>

            {{-- Latin names read left-to-right inside the box while their labels
                 stay right-to-left (art. 16). Optional: the certificate prints
                 them when they are there. --}}
            <fieldset class="fieldset">
                <legend>{{ __('auth.register.en_names') }}</legend>
                <p class="form__note">{{ __('auth.invitation.en_hint') }}</p>
                <div class="grid-fields">
                    <x-ui.input name="first_name_en" ltr maxlength="20"
                        :label="__('auth.register.first_name_en')" :value="old('first_name_en')"
                        :error="$errors->first('first_name_en')"/>
                    <x-ui.input name="father_name_en" ltr maxlength="20"
                        :label="__('auth.register.father_name_en')" :value="old('father_name_en')"
                        :error="$errors->first('father_name_en')"/>
                    <x-ui.input name="grandfather_name_en" ltr maxlength="20"
                        :label="__('auth.register.grandfather_name_en')" :value="old('grandfather_name_en')"
                        :error="$errors->first('grandfather_name_en')"/>
                    <x-ui.input name="family_name_en" ltr maxlength="20"
                        :label="__('auth.register.family_name_en')" :value="old('family_name_en')"
                        :error="$errors->first('family_name_en')"/>
                </div>
            </fieldset>

            <x-ui.input
                name="phone"
                type="tel"
                ltr
                inputmode="numeric"
                required
                :label="__('auth.register.phone')"
                :value="old('phone')"
                :hint="__('auth.register.phone_hint')"
                :error="$errors->first('phone')"/>

            <div>
                <x-ui.radio
                    name="gender"
                    required
                    :legend="__('auth.register.gender')"
                    :value="old('gender')"
                    :error="$errors->first('gender')"
                    :options="$genderOptions"/>
            </div>

            <x-ui.input
                name="password"
                type="password"
                ltr
                autocomplete="new-password"
                required
                aria-describedby="pwRules"
                :label="__('auth.invitation.password')"
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

            <x-ui.button
                variant="primary"
                size="lg"
                type="submit"
                class="form__submit"
                ::disabled="submitting">
                <span x-show="! submitting">{{ __('auth.invitation.submit') }}</span>
                <span x-show="submitting" x-cloak>{{ __('auth.shared.processing') }}</span>
            </x-ui.button>

            <p class="form__note">{{ __('auth.shared.required_hint') }}</p>
        </form>

    @endif

</div>

@endsection
