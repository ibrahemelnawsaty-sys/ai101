{{--
    Public verification of a trainee digital card.

    A door steward or an employer scans the QR on the card and lands here with no
    session of any kind. The page therefore obeys BR-25 literally: it shows the first
    name, the family name, the programme, the cohort, the role, the issue date and the
    status — and NOTHING else. No e-mail, no phone number, no grade, no attendance
    figure, no internal identifier, not even the user's UUID.

    The controller decides the state. This file renders it and decides nothing:
    a revoked card is revoked because the server said so, never because of a flag
    the browser could flip (Constitution art. 5).

    @see BR-25 · PRD §9.17 · PROJECT-CONTRACT §8, §10 (route `card.verify`)
    @see Constitution art. 5, 7, 15, 16, 17, 18

    Variables from App\Http\Controllers\Public\CardVerificationController@show:
      $screen      string  the Article 17 screen name
      $screenState string  App\Support\ScreenState: normal | loading | empty | error
                          'empty' = no card matches this token (also used for an expired one)
      $card       array   ['first_name','family_name','program','cohort','role',
                           'issued_at','is_revoked']
                          Every value is already a display-ready string in Asia/Riyadh.
      $checkedAt  string  the moment of this check, formatted from Clock::riyadh()
--}}
@extends('layouts.bare')

@section('content')

<article class="verify">

    {{-- ==========================================================
         State: error — the lookup itself failed
         ========================================================== --}}
    @if (($screenState ?? 'normal') === 'error')

        <x-ui.empty-state
            variant="error"
            icon="i-warn"
            :title="__('verify.card.error_title')"
            :description="__('verify.card.error_body')">
            <x-ui.button variant="secondary" :href="url()->current()">
                {{ __('verify.card.error_action') }}
            </x-ui.button>
        </x-ui.empty-state>

    {{-- ==========================================================
         State: loading — a skeleton shaped like the credential itself
         ========================================================== --}}
    @elseif (($screenState ?? 'normal') === 'loading')

        <div class="verify__box">
            <x-ui.skeleton shape="avatar" :label="__('verify.card.loading')"/>
            <x-ui.skeleton shape="title"/>
            <x-ui.skeleton shape="row" :count="4"/>
        </div>

    {{-- ==========================================================
         State: empty — the token matches nothing
         ========================================================== --}}
    @elseif (($screenState ?? 'normal') === 'empty' || blank($card ?? null))

        <x-ui.empty-state
            icon="i-search"
            :title="__('verify.card.not_found_title')"
            :description="__('verify.card.not_found_body')">
            <x-ui.button variant="secondary" :href="route('home')">
                {{ __('verify.shared.back_home') }}
            </x-ui.button>
        </x-ui.empty-state>

    {{-- ==========================================================
         State: normal — revoked first, because that is the answer that matters
         ========================================================== --}}
    @elseif (data_get($card, 'is_revoked'))

        {{-- Status is never carried by colour alone: icon + word + role="alert" (art. 18) --}}
        <div class="verify__box verify__box--revoked" role="alert">
            <span class="verify__seal verify__seal--revoked" aria-hidden="true">
                <svg><use href="#i-warn"/></svg>
            </span>
            <h1 class="verify__title">{{ __('verify.card.revoked_title') }}</h1>
            <p class="verify__body">{{ __('verify.card.revoked_body') }}</p>

            <dl class="verify__rows">
                <div class="verify__row">
                    <dt>{{ __('verify.shared.status') }}</dt>
                    <dd class="verify__status verify__status--revoked">
                        <svg aria-hidden="true"><use href="#i-warn"/></svg>
                        <span>{{ __('verify.card.status_revoked') }}</span>
                    </dd>
                </div>
            </dl>
        </div>

    @else

        <div class="verify__box verify__box--valid">
            <span class="verify__seal" aria-hidden="true">
                <svg><use href="#i-card"/></svg>
            </span>

            <h1 class="verify__title">{{ __('verify.card.heading') }}</h1>

            <dl class="verify__rows">
                {{-- BR-25: first name and family name only. The middle names are never public. --}}
                <div class="verify__row">
                    <dt>{{ __('verify.shared.name') }}</dt>
                    <dd>{{ data_get($card, 'first_name') }} {{ data_get($card, 'family_name') }}</dd>
                </div>

                <div class="verify__row">
                    <dt>{{ __('verify.shared.program') }}</dt>
                    <dd>{{ data_get($card, 'program') }}</dd>
                </div>

                <div class="verify__row">
                    <dt>{{ __('verify.shared.cohort') }}</dt>
                    <dd>{{ data_get($card, 'cohort') }}</dd>
                </div>

                <div class="verify__row">
                    <dt>{{ __('verify.card.role') }}</dt>
                    <dd>{{ data_get($card, 'role') }}</dd>
                </div>

                <div class="verify__row">
                    <dt>{{ __('verify.card.issued_at') }}</dt>
                    <dd class="u-num">{{ data_get($card, 'issued_at') }}</dd>
                </div>

                <div class="verify__row">
                    <dt>{{ __('verify.shared.status') }}</dt>
                    <dd class="verify__status verify__status--valid">
                        <svg aria-hidden="true"><use href="#i-check"/></svg>
                        <span>{{ __('verify.card.status_valid') }}</span>
                    </dd>
                </div>
            </dl>

            @if (filled($checkedAt ?? null))
                <p class="verify__checked">
                    {{ __('verify.shared.checked_at') }}
                    <span class="u-num">{{ $checkedAt }}</span>
                </p>
            @endif
        </div>

    @endif

</article>

@endsection
