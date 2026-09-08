{{--
    Registration wizard - three numbered steps (PRD 9.2.1).

    Everything the wizard does in the browser is convenience only: the binding
    validation, the uniqueness checks and the rate limit all run again on the server
    in the FormRequest (Constitution art. 5). Without JavaScript the three steps simply
    render one under the other and the form posts normally.

    Deliberate: the two password fields are NEVER written to sessionStorage. Only the
    name and contact steps survive a reload, as PRD 9.2.2 asks.

    @see BR-30, BR-36 · PRD §9.2.1, §9.2.2, §9.2.3 · Constitution art. 5, 15, 16, 17, 18

    Variables from App\Http\Controllers\Auth\RegisterController@create:
      $state  string  'ok' | 'loading' | 'empty' | 'error'
                      'empty' = registration temporarily switched off by the admin
      $registrationOpen  bool  whether a cohort is currently accepting registrations
--}}
@extends('layouts.auth')

@section('pageTitle', __('auth.register.title'))

@section('content')

<div class="authcard authcard--wide">

    @if (($state ?? 'ok') === 'error')

        <x-ui.empty-state
            variant="error"
            icon="i-warn"
            :title="__('auth.shared.error_title')"
            :description="__('auth.shared.error_body')">
            <x-ui.button variant="secondary" :href="route('register')">
                {{ __('auth.shared.error_action') }}
            </x-ui.button>
        </x-ui.empty-state>

    @elseif (($state ?? 'ok') === 'loading')

        <div class="skel-form">
            <x-ui.skeleton shape="chip" :count="3" :label="__('auth.shared.loading')"/>
            <x-ui.skeleton shape="title"/>
            <x-ui.skeleton shape="text" :lines="1"/>
            <div class="skel-grid">
                <x-ui.skeleton shape="bar" :count="4"/>
            </div>
            <x-ui.skeleton shape="button"/>
        </div>

    @elseif (($state ?? 'ok') === 'empty')

        <x-ui.empty-state
            icon="i-lock"
            :title="__('auth.states.empty_register_title')"
            :description="__('auth.states.empty_register_body')">
            <x-ui.button variant="secondary" :href="route('home')">
                {{ __('auth.states.empty_action') }}
            </x-ui.button>
        </x-ui.empty-state>

    @elseif (! ($registrationOpen ?? true))

        <x-ui.empty-state
            icon="i-cal"
            :title="__('auth.register.closed_title')"
            :description="__('auth.register.closed_body')">
            <x-ui.button variant="primary" :href="route('home')">
                {{ __('auth.register.closed_action') }}
            </x-ui.button>
        </x-ui.empty-state>

    @else

        <div class="wiz"
             x-data="registerWizard({ storageKey: 'athar.register.v1', totalSteps: 3, startStep: {{ $errors->any() ? 3 : 1 }} })"
             x-cloak>

            <header class="authcard__hd">
                <h1>{{ __('auth.register.title') }}</h1>
                <p>{{ __('auth.register.subtitle') }}</p>
            </header>

            {{-- Progress indicator. Also the accessible name of the current step. --}}
            <ol class="wiz__steps" aria-label="{{ __('auth.register.progress_label') }}">
                <li class="wiz__step" :class="{ 'is-on': step === 1, 'is-done': step > 1 }"
                    :aria-current="step === 1 ? 'step' : false">
                    <span class="wiz__no u-num" aria-hidden="true">1</span>
                    <span class="wiz__label">{{ __('auth.register.step_1') }}</span>
                </li>
                <li class="wiz__step" :class="{ 'is-on': step === 2, 'is-done': step > 2 }"
                    :aria-current="step === 2 ? 'step' : false">
                    <span class="wiz__no u-num" aria-hidden="true">2</span>
                    <span class="wiz__label">{{ __('auth.register.step_2') }}</span>
                </li>
                <li class="wiz__step" :class="{ 'is-on': step === 3 }"
                    :aria-current="step === 3 ? 'step' : false">
                    <span class="wiz__no u-num" aria-hidden="true">3</span>
                    <span class="wiz__label">{{ __('auth.register.step_3') }}</span>
                </li>
            </ol>

            <p class="wiz__count u-num" aria-live="polite" x-text="stepLabel"></p>

            {{-- Restored-from-storage notice --}}
            <div class="note note--info" role="status" x-show="restored" x-cloak>
                <svg aria-hidden="true"><use href="#i-info"/></svg>
                <div>
                    <b>{{ __('auth.register.restored_title') }}</b>
                    <p>{{ __('auth.register.restored_body') }}</p>
                </div>
                <button type="button" class="note__close" @click="restored = false"
                        aria-label="{{ __('auth.register.restored_dismiss') }}">
                    <svg aria-hidden="true"><use href="#i-chev"/></svg>
                </button>
            </div>

            {{-- Server-side error summary --}}
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
            <script type="application/json" id="registerCopy">@json($registerCopy, JSON_UNESCAPED_UNICODE)</script>

            <form method="POST"
                  action="{{ route('register') }}"
                  class="form"
                  novalidate
                  @submit="onSubmit($event)">
                @csrf

                {{-- ==========================================================
                     Step 1 - names
                     ========================================================== --}}
                <section class="wiz__pane" x-show="step === 1" x-cloak
                         aria-labelledby="step1Title">
                    <h2 id="step1Title" class="wiz__title">{{ __('auth.register.step_1') }}</h2>
                    <p class="wiz__hint">{{ __('auth.register.step_1_hint') }}</p>

                    <fieldset class="fieldset">
                        <legend>{{ __('auth.register.ar_names') }}</legend>
                        <div class="grid-fields">
                            <x-ui.input name="first_name_ar" required maxlength="20"
                                        :label="__('auth.register.first_name_ar')"
                                        :placeholder="__('auth.register.ph_first_ar')"
                                        :value="old('first_name_ar')"
                                        :error="$errors->first('first_name_ar')"
                                        x-model="data.first_name_ar" data-rule="name_ar" @blur="validateField($event)"/>

                            <x-ui.input name="father_name_ar" required maxlength="20"
                                        :label="__('auth.register.father_name_ar')"
                                        :placeholder="__('auth.register.ph_father_ar')"
                                        :value="old('father_name_ar')"
                                        :error="$errors->first('father_name_ar')"
                                        x-model="data.father_name_ar" data-rule="name_ar" @blur="validateField($event)"/>

                            <x-ui.input name="grandfather_name_ar" required maxlength="20"
                                        :label="__('auth.register.grandfather_name_ar')"
                                        :placeholder="__('auth.register.ph_grandfather_ar')"
                                        :value="old('grandfather_name_ar')"
                                        :error="$errors->first('grandfather_name_ar')"
                                        x-model="data.grandfather_name_ar" data-rule="name_ar" @blur="validateField($event)"/>

                            <x-ui.input name="family_name_ar" required maxlength="20"
                                        :label="__('auth.register.family_name_ar')"
                                        :placeholder="__('auth.register.ph_family_ar')"
                                        :value="old('family_name_ar')"
                                        :error="$errors->first('family_name_ar')"
                                        x-model="data.family_name_ar" data-rule="name_ar" @blur="validateField($event)"/>
                        </div>
                    </fieldset>

                    <fieldset class="fieldset">
                        <legend>{{ __('auth.register.en_names') }}</legend>
                        <div class="grid-fields">
                            <x-ui.input name="first_name_en" ltr required maxlength="20"
                                        :label="__('auth.register.first_name_en')"
                                        :placeholder="__('auth.register.ph_first_en')"
                                        :value="old('first_name_en')"
                                        :error="$errors->first('first_name_en')"
                                        x-model="data.first_name_en" data-rule="name_en"
                                        @blur="capitalise($event); validateField($event)"/>

                            <x-ui.input name="father_name_en" ltr required maxlength="20"
                                        :label="__('auth.register.father_name_en')"
                                        :placeholder="__('auth.register.ph_father_en')"
                                        :value="old('father_name_en')"
                                        :error="$errors->first('father_name_en')"
                                        x-model="data.father_name_en" data-rule="name_en"
                                        @blur="capitalise($event); validateField($event)"/>

                            <x-ui.input name="grandfather_name_en" ltr required maxlength="20"
                                        :label="__('auth.register.grandfather_name_en')"
                                        :placeholder="__('auth.register.ph_grandfather_en')"
                                        :value="old('grandfather_name_en')"
                                        :error="$errors->first('grandfather_name_en')"
                                        x-model="data.grandfather_name_en" data-rule="name_en"
                                        @blur="capitalise($event); validateField($event)"/>

                            <x-ui.input name="family_name_en" ltr required maxlength="20"
                                        :label="__('auth.register.family_name_en')"
                                        :placeholder="__('auth.register.ph_family_en')"
                                        :value="old('family_name_en')"
                                        :error="$errors->first('family_name_en')"
                                        x-model="data.family_name_en" data-rule="name_en"
                                        @blur="capitalise($event); validateField($event)"/>
                        </div>
                    </fieldset>
                </section>

                {{-- ==========================================================
                     Step 2 - contact details
                     ========================================================== --}}
                <section class="wiz__pane" x-show="step === 2" x-cloak
                         aria-labelledby="step2Title">
                    <h2 id="step2Title" class="wiz__title">{{ __('auth.register.step_2') }}</h2>
                    <p class="wiz__hint">{{ __('auth.register.step_2_hint') }}</p>

                    <div class="phone">
                        <span class="phone__cc" aria-hidden="true" dir="ltr">+966</span>
                        <x-ui.input name="phone" type="tel" ltr inputmode="numeric"
                                    autocomplete="tel" required maxlength="10"
                                    :label="__('auth.register.phone')"
                                    :placeholder="__('auth.register.phone_placeholder')"
                                    :hint="__('auth.register.phone_hint')"
                                    :value="old('phone')"
                                    :error="$errors->first('phone')"
                                    x-model="data.phone" data-rule="phone"
                                    @input="digitsOnly($event)" @blur="validateField($event)"/>
                    </div>

                    <x-ui.input name="email" type="email" ltr inputmode="email"
                                autocomplete="email" required
                                :label="__('auth.shared.email')"
                                :placeholder="__('auth.shared.email_placeholder')"
                                :value="old('email')"
                                :error="$errors->first('email')"
                                x-model="data.email" data-rule="email"
                                @blur="lowercase($event); validateField($event)"/>

                    {{-- Paste is blocked here on purpose (PRD 9.2.1): it defeats the check. --}}
                    <x-ui.input name="email_confirmation" type="email" ltr inputmode="email"
                                autocomplete="off" required
                                :label="__('auth.register.email_confirm')"
                                :placeholder="__('auth.register.email_confirm_placeholder')"
                                :hint="__('auth.register.email_confirm_hint')"
                                :error="$errors->first('email_confirmation')"
                                x-model="data.email_confirmation" data-rule="email_confirm"
                                @paste.prevent @drop.prevent
                                @blur="lowercase($event); validateField($event)"/>

                    {{-- Gender is a required choice with no default: an unanswered radio
                         group is rejected by the FormRequest, not pre-filled here.
                         x-ui.radio does not forward attributes to its inputs, so the
                         wizard listens for the change event as it bubbles out instead. --}}
                    <div @change="data.gender = $event.target.value">
                        <x-ui.radio
                            name="gender"
                            required
                            :legend="__('auth.register.gender')"
                            :value="old('gender')"
                            :error="$errors->first('gender')"
                            :options="$genderOptions"/>
                    </div>
                </section>

                {{-- ==========================================================
                     Step 3 - password and consent
                     ========================================================== --}}
                <section class="wiz__pane" x-show="step === 3" x-cloak
                         aria-labelledby="step3Title">
                    <h2 id="step3Title" class="wiz__title">{{ __('auth.register.step_3') }}</h2>
                    <p class="wiz__hint">{{ __('auth.register.step_3_hint') }}</p>

                    <x-ui.input name="password" type="password" ltr
                                autocomplete="new-password" required
                                aria-describedby="pwRules"
                                :label="__('auth.shared.password')"
                                :error="$errors->first('password')"
                                x-model="password" @input="scorePassword()"/>

                    <div class="pw">
                        <div class="pw__meter" role="img"
                             :aria-label="strengthLabel"
                             :data-level="strength">
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

                    <x-ui.input name="password_confirmation" type="password" ltr
                                autocomplete="new-password" required
                                :label="__('auth.register.password_confirm')"
                                :error="$errors->first('password_confirmation')"
                                x-model="passwordConfirmation"/>

                    {{-- Consent is a hard requirement (PRD §9.2.1) and is re-checked in
                         RegisterRequest; the two documents open in a new tab so the
                         half-filled wizard is never lost. --}}
                    <x-ui.checkbox
                        name="terms"
                        value="1"
                        required
                        class="check--terms"
                        :checked="(bool) old('terms')"
                        :error="$errors->first('terms')"
                        x-model="data.terms">
                        {{ __('auth.register.terms_accept_before') }}
                        <a href="{{ route('terms') }}" target="_blank" rel="noopener">{{ __('auth.register.terms_link') }}</a>
                        {{ __('auth.register.terms_accept_and') }}
                        <a href="{{ route('privacy') }}" target="_blank" rel="noopener">{{ __('auth.register.privacy_link') }}</a>
                    </x-ui.checkbox>
                </section>

                {{-- ==========================================================
                     Wizard navigation
                     ========================================================== --}}
                <p class="form__note">{{ __('auth.shared.required_hint') }}</p>

                <div class="wiz__nav">
                    <x-ui.button variant="ghost" type="button" x-show="step > 1" @click="prev()" x-cloak>
                        <svg aria-hidden="true" class="ic ic--flip"><use href="#i-chev"/></svg>
                        {{ __('auth.register.back') }}
                    </x-ui.button>

                    <x-ui.button variant="primary" size="lg" type="button"
                                 x-show="step < totalSteps" @click="next()" x-cloak>
                        {{ __('auth.register.next') }}
                    </x-ui.button>

                    <x-ui.button variant="primary" size="lg" type="submit"
                                 x-show="step === totalSteps"
                                 ::disabled="submitting">
                        <span x-show="! submitting">{{ __('auth.register.submit') }}</span>
                        <span x-show="submitting" x-cloak>{{ __('auth.shared.processing') }}</span>
                    </x-ui.button>
                </div>
            </form>

            <p class="authcard__alt">
                {{ __('auth.register.have_account') }}
                <a href="{{ route('login') }}">{{ __('auth.register.go_login') }}</a>
            </p>
        </div>

    @endif

</div>

@endsection
