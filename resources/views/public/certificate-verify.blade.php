{{--
    Public verification of a certificate.

    Reached from the verify link or QR printed on the certificate, with no session.
    BR-25 and PROJECT-CONTRACT §8 fix the disclosure exactly: first name, family name,
    programme, cohort, date, status - and nothing else. So no e-mail, no phone, no
    score, no attendance rate, no internal identifier, and no serial number either
    (see the open question raised with this slice).

    Revocation is a first-class state, not a badge: a revoked certificate answers the
    visitor's real question, which is whether the paper in their hand can be trusted.

    @see BR-25, BR-26 · PRD §9.17 · PROJECT-CONTRACT §8, §10 (route `certificate.verify`)
    @see Constitution art. 5, 7, 15, 16, 17, 18

    Variables from App\Http\Controllers\Public\CertificateVerificationController@show:
      $screen       string  the Article 17 screen name
      $screenState  string  App\Support\ScreenState: normal | loading | empty | error
                            'empty' = the verify code matches no certificate
      $certificate  array   ['first_name','family_name','program','cohort',
                             'issued_at','is_revoked','revoked_at']
                            Every value is a display-ready string in Asia/Riyadh.
      $checkedAt    string  the moment of this check, formatted from Clock::riyadh()
--}}
@extends('layouts.bare')

@section('content')

<article class="verify">

    {{-- ==========================================================
         State: error - the lookup itself failed
         ========================================================== --}}
    @if (($screenState ?? 'normal') === 'error')

        <x-ui.empty-state
            variant="error"
            icon="i-warn"
            :title="__('verify.certificate.error_title')"
            :description="__('verify.certificate.error_body')">
            <x-ui.button variant="secondary" :href="url()->current()">
                {{ __('verify.certificate.error_action') }}
            </x-ui.button>
        </x-ui.empty-state>

    {{-- ==========================================================
         State: loading - skeleton in the shape of the certificate record
         ========================================================== --}}
    @elseif (($screenState ?? 'normal') === 'loading')

        <div class="verify__box">
            <x-ui.skeleton shape="avatar" :label="__('verify.certificate.loading')"/>
            <x-ui.skeleton shape="title"/>
            <x-ui.skeleton shape="row" :count="4"/>
        </div>

    {{-- ==========================================================
         State: empty - the code matches nothing
         ========================================================== --}}
    @elseif (($screenState ?? 'normal') === 'empty' || blank($certificate ?? null))

        <x-ui.empty-state
            icon="i-search"
            :title="__('verify.certificate.not_found_title')"
            :description="__('verify.certificate.not_found_body')">
            <x-ui.button variant="secondary" :href="route('home')">
                {{ __('verify.shared.back_home') }}
            </x-ui.button>
        </x-ui.empty-state>

    {{-- ==========================================================
         State: normal - revoked
         ========================================================== --}}
    @elseif (data_get($certificate, 'is_revoked'))

        {{-- Status is never carried by colour alone: icon + word + role="alert" (art. 18) --}}
        <div class="verify__box verify__box--revoked" data-state="revoked" role="alert">
            <span class="verify__seal verify__seal--revoked" aria-hidden="true">
                <svg><use href="#i-warn"/></svg>
            </span>

            <h1 class="verify__title">{{ __('verify.certificate.revoked_title') }}</h1>
            <p class="verify__body">{{ __('verify.certificate.revoked_body') }}</p>

            <dl class="verify__rows">
                <div class="verify__row">
                    <dt>{{ __('verify.shared.name') }}</dt>
                    <dd>{{ data_get($certificate, 'first_name') }} {{ data_get($certificate, 'family_name') }}</dd>
                </div>

                <div class="verify__row">
                    <dt>{{ __('verify.shared.program') }}</dt>
                    <dd>{{ data_get($certificate, 'program') }}</dd>
                </div>

                @if (filled(data_get($certificate, 'revoked_at')))
                    <div class="verify__row">
                        <dt>{{ __('verify.certificate.revoked_at') }}</dt>
                        <dd class="u-num">{{ data_get($certificate, 'revoked_at') }}</dd>
                    </div>
                @endif

                <div class="verify__row">
                    <dt>{{ __('verify.shared.status') }}</dt>
                    <dd class="verify__status verify__status--revoked">
                        <svg aria-hidden="true"><use href="#i-warn"/></svg>
                        <span>{{ __('verify.certificate.status_revoked') }}</span>
                    </dd>
                </div>
            </dl>
        </div>

    {{-- ==========================================================
         State: normal - valid
         ========================================================== --}}
    @else

        <div class="verify__box verify__box--valid" data-state="valid">
            <span class="verify__seal" aria-hidden="true">
                <svg><use href="#i-badge"/></svg>
            </span>

            <h1 class="verify__title">{{ __('verify.certificate.heading') }}</h1>

            <dl class="verify__rows">
                {{-- BR-25: first name and family name only. --}}
                <div class="verify__row">
                    <dt>{{ __('verify.shared.name') }}</dt>
                    <dd>{{ data_get($certificate, 'first_name') }} {{ data_get($certificate, 'family_name') }}</dd>
                </div>

                <div class="verify__row">
                    <dt>{{ __('verify.shared.program') }}</dt>
                    <dd>{{ data_get($certificate, 'program') }}</dd>
                </div>

                <div class="verify__row">
                    <dt>{{ __('verify.shared.cohort') }}</dt>
                    <dd>{{ data_get($certificate, 'cohort') }}</dd>
                </div>

                <div class="verify__row">
                    <dt>{{ __('verify.certificate.issued_at') }}</dt>
                    <dd class="u-num">{{ data_get($certificate, 'issued_at') }}</dd>
                </div>

                <div class="verify__row">
                    <dt>{{ __('verify.shared.status') }}</dt>
                    <dd class="verify__status verify__status--valid">
                        <svg aria-hidden="true"><use href="#i-check"/></svg>
                        <span>{{ __('verify.certificate.status_valid') }}</span>
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
