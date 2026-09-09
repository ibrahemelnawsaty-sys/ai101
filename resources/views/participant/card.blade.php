{{--
    Digital participant card — 3D tilt (max 8deg, pointer only), server-generated QR.
    The QR carries /verify/{token}; the token is a long signed random value, never a user id.

    @see PRD §9.6 · BR-25
--}}
@extends('layouts.app')

@section('title', __('nav.card'))
@section('subtitle', __('card.subtitle'))

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('card.error_title')"
            :description="__('card.error_body')"
            :action-label="__('app.retry')" :action-href="route('participant.card')" />
    @elseif (is_null($card))
        <div class="idwrap">
            <div>
                <x-ui.skeleton height="var(--d-6)" width="100%" rounded="xl" />
                <div class="idacts">
                    <x-ui.skeleton height="var(--s10)" width="var(--d-1)" />
                    <x-ui.skeleton height="var(--s10)" width="var(--d-1)" />
                    <x-ui.skeleton height="var(--s10)" width="var(--s24)" />
                </div>
            </div>
            <div>
                <x-ui.skeleton height="var(--d-3)" width="100%" rounded="lg" />
                <x-ui.skeleton height="var(--d-2)" width="100%" rounded="lg" class="u-mt-4" />
            </div>
        </div>
    @elseif ($card->isMissing)
        <x-ui.empty-state icon="card"
            :title="__('card.empty_title')"
            :description="__('card.empty_body')"
            :action-label="__('nav.profile')" :action-href="route('profile')" />
    @else
        <div class="idwrap">
            <div>
                {{--
                    Tilt is pointer-only and capped at 8 degrees. It is disabled entirely
                    for coarse pointers and whenever prefers-reduced-motion is set — the
                    guard lives in the Alpine data object, not in CSS alone.
                --}}
                <div class="idscene"
                    x-data="atharCardTilt({ maxDegrees: 8 })"
                    x-on:pointermove.throttle.16ms="track($event)"
                    x-on:pointerleave="reset()">
                    <div class="idcard" x-bind:style="transformStyle">
                        <div class="idcard__grid" aria-hidden="true"></div>
                        <div class="idcard__sheen" aria-hidden="true"></div>
                        <div class="idcard__in">
                            <div class="idcard__top">
                                {{-- x-ui.logo, not x-ui.icon. The icon component
                                     prefixes every name with "i-", so
                                     name="athar-wordmark" resolved to
                                     #i-athar-wordmark — a symbol that does not
                                     exist. The <svg> rendered empty and with no
                                     viewBox, and because .idcard__top svg is
                                     inline-size:auto it took an undefined width
                                     and crushed the programme name beside it
                                     into a one-word-per-line column (D-57). --}}
                                <x-ui.logo variant="wordmark" size="sm" tone="light" />
                                <div class="idcard__prog">
                                    {{ $card->programName }}<br>{{ $card->cohortName }}
                                </div>
                            </div>

                            <div class="idcard__mid">
                                <x-ui.avatar size="lg" variant="on-dark"
                                    :name="$card->fullNameAr" :src="$card->photoUrl" />
                                <div class="idcard__name">
                                    <b>{{ $card->fullNameAr }}</b>
                                    <span dir="ltr">{{ $card->fullNameEn }}</span>
                                </div>
                            </div>

                            <div class="idcard__bot">
                                <div class="idcard__meta">
                                    {{ __('card.role') }} · <b>{{ $card->roleLabel }}</b><br>
                                    {{ __('card.number') }} · <b class="u-num">{{ $card->number }}</b><br>
                                    {{ __('card.issued_on') }} · <b class="u-num">{{ \App\Support\Dates::longDate($card->issuedAt) }}</b>
                                </div>
                                {{-- Rendered on the server as an inline SVG; no client-side QR library. --}}
                                <div class="qr" role="img" aria-label="{{ __('card.qr_alt') }}">
                                    {!! $card->qrSvg !!}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="idacts">
                    {{-- Both download buttons were dead: CardController::download read
                         `file_url`, a column that exists on `certificates` and NOT on
                         `digital_cards`, so it was always null and the route always
                         answered 404. Nothing ever generated a file to download.
                    
                         Replaced with a print sheet, the pattern this project already
                         uses for the timetable: the browser makes the PDF, so there is
                         no PDF library, no second rendering path to keep in step, and
                         no broken Arabic shaping from a server-side renderer. --}}
                    <x-ui.button variant="secondary" size="sm"
                        :href="route('participant.card.print')">{{ __('card.print') }}</x-ui.button>
                    <x-ui.button variant="secondary" size="sm"
                        x-data="atharShare({ url: '{{ $card->verifyUrl }}', title: '{{ __('card.share_title') }}' })"
                        x-on:click="share()">{{ __('app.share') }}</x-ui.button>
                </div>
            </div>

            <div>
                <x-ui.card icon="shield" :title="__('card.status.title')">
                    <div class="row">
                        <div class="row__m">
                            <b>{{ $card->statusLabel }}</b>
                            <span>{{ __('card.status.expires_with_cohort', ['date' => \App\Support\Dates::longDate($card->expiresAt)]) }}</span>
                        </div>
                        <div class="row__e">
                            <x-ui.pill :variant="$card->statusVariant" :icon="$card->statusIcon">{{ $card->statusLabel }}</x-ui.pill>
                        </div>
                    </div>
                    <div class="row">
                        <div class="row__m">
                            <b>{{ __('card.verify_link') }}</b>
                            {{-- Printed in full, and wrapping rather than truncated.
                                 It was masked to six characters of the token, which
                                 protected nothing — the QR on this same screen encodes
                                 the whole URL — while making the copy button the only
                                 way to obtain the link. The copy button was dead
                                 (D-55, D-58), so there was no way at all. --}}
                            <a href="{{ $card->verifyUrl }}" dir="ltr" class="row__ltr row__url">{{ $card->verifyUrl }}</a>
                        </div>
                        <div class="row__e">
                            <x-ui.button variant="secondary" size="sm"
                                x-data="atharCopy({ value: '{{ $card->verifyUrl }}' })"
                                x-on:click="copy()"
                                x-bind:aria-label="copied ? '{{ __('app.copied') }}' : '{{ __('app.copy') }}'">
                                <span x-text="copied ? '{{ __('app.copied') }}' : '{{ __('app.copy') }}'">{{ __('app.copy') }}</span>
                            </x-ui.button>
                        </div>
                    </div>
                    <div class="row">
                        <div class="row__m">
                            <b>{{ __('card.scan_count') }}</b>
                            <span>{{ __('card.scan_count_hint') }}</span>
                        </div>
                        <div class="row__e">
                            <b class="u-num row__score">{{ $card->scanCount }}</b>
                        </div>
                    </div>
                </x-ui.card>

                <x-ui.card icon="eye" :title="__('card.disclosure.title')" class="u-mt-4">
                    <p class="prose">{{ __('card.disclosure.shown') }}</p>
                    <div class="note note--bad">
                        <b>{{ __('card.disclosure.never_shown_title') }}</b>
                        {{ __('card.disclosure.never_shown_body') }}
                    </div>
                </x-ui.card>

                <x-ui.card icon="spark" :title="__('card.tilt.title')" class="u-mt-4">
                    <p class="prose">{{ __('card.tilt.body', ['degrees' => 8]) }}</p>
                </x-ui.card>
            </div>
        </div>
    @endif
@endsection
