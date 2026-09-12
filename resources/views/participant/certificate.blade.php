{{--
    Certificate. Issued only when BOTH conditions are met — attendance rate and final
    score — with no trading one for the other. When it is not issued the page states
    exactly which condition is missing and by how much.

    @see PRD §9.17 · BR-25, BR-26
--}}
@extends('layouts.app')

@section('title', __('nav.certificate'))
@section('subtitle', $certificate ? __('certificates.subtitle_issued') : __('certificates.subtitle_pending'))

@section('content')
    @if ($errorState ?? false)
        <x-ui.empty-state variant="error" icon="warn"
            :title="__('certificates.error_title')"
            :description="__('certificates.error_body')"
            :action-label="__('app.retry')" :action-href="route('certificate')" />

    @elseif (is_null($eligibility))
        <div class="idwrap">
            <div><x-ui.skeleton height="var(--d-6)" width="100%" rounded="lg" /></div>
            <div>
                <x-ui.skeleton height="var(--d-3)" width="100%" rounded="lg" />
                <x-ui.skeleton height="var(--d-1)" width="100%" rounded="lg" class="u-mt-4" />
            </div>
        </div>

    @else
        <div class="idwrap">
            <div>
                @if ($certificate)
                    {{-- Live preview of the issued certificate. --}}
                    @include('participant.partials.certificate-document', ['certificate' => $certificate])

                    <div class="idacts">
                        <x-ui.button variant="primary" size="sm" icon="down"
                            :href="route('certificate.print')">{{ __('certificates.download_pdf') }}</x-ui.button>
                        <x-ui.button variant="secondary" size="sm"
                            x-data="atharCopy({ value: '{{ $certificate->verifyUrl }}' })"
                            x-on:click="copy()">
                            <span x-text="copied ? '{{ __('app.copied') }}' : '{{ __('certificates.copy_verify_link') }}'">{{ __('certificates.copy_verify_link') }}</span>
                        </x-ui.button>
                        <x-ui.button variant="secondary" size="sm"
                            :href="$certificate->linkedInShareUrl" target="_blank" rel="noopener">{{ __('certificates.share_linkedin') }}</x-ui.button>
                    </div>

                    @if ($certificate->isRevoked)
                        <div class="note note--bad u-mt-4">
                            <b>{{ __('certificates.revoked_title') }}</b>
                            {{ __('certificates.revoked_body') }}
                        </div>
                    @endif
                @else
                    <x-ui.empty-state icon="shield"
                        :title="__('certificates.not_issued_title')"
                        :description="__('certificates.not_issued_body')"
                        :action-label="__('nav.grades')" :action-href="route('grades')" />
                @endif
            </div>

            <div>
                {{-- Conditions, always shown, met or not. --}}
                <x-ui.card :icon="$eligibility->isEligible ? 'check' : 'clock'"
                    :title="$eligibility->isEligible ? __('certificates.conditions_met') : __('certificates.conditions_pending')">
                    <div class="row">
                        <x-ui.pill :variant="$eligibility->meetsAttendance ? 'success' : 'danger'"
                            :icon="$eligibility->meetsAttendance ? 'check' : 'warn'">
                            {{ $eligibility->meetsAttendance ? __('certificates.met') : __('certificates.not_met') }}
                        </x-ui.pill>
                        <div class="row__m">
                            <b>{{ __('attendance.rate.title') }}</b>
                            <span>{{ __('certificates.min_attendance', ['rate' => $eligibility->minimumRatePercent]) }}</span>
                        </div>
                        <div class="row__e">
                            <b class="row__score u-num">{{ $eligibility->attendanceRatePercent }}%</b>
                        </div>
                    </div>

                    <div class="row">
                        <x-ui.pill :variant="$eligibility->meetsScore ? 'success' : 'danger'"
                            :icon="$eligibility->meetsScore ? 'check' : 'warn'">
                            {{ $eligibility->meetsScore ? __('certificates.met') : __('certificates.not_met') }}
                        </x-ui.pill>
                        <div class="row__m">
                            <b>{{ __('grades.total.title') }}</b>
                            <span>{{ __('grades.pass_score', ['score' => $eligibility->passScore]) }}</span>
                        </div>
                        <div class="row__e">
                            <b class="row__score u-num">{{ $eligibility->finalScore }}</b>
                        </div>
                    </div>

                    @unless ($eligibility->isEligible)
                        <div class="note note--warn">
                            <b>{{ __('certificates.why_not_title') }}</b>
                            <ul class="note__list">
                                @foreach ($eligibility->reasons as $reason)
                                    <li>{{ $reason }}</li>
                                @endforeach
                            </ul>
                            <span class="note__meta">{{ __('certificates.both_required') }}</span>
                        </div>
                    @endunless
                </x-ui.card>

                {{-- TVTC certificate, issued by the external authority. --}}
                <x-ui.card class="u-mt-4" icon="shield" :title="__('certificates.tvtc_title')">
                    @if ($tvtc && $tvtc->isAvailable)
                        <div class="row">
                            <div class="row__m">
                                <b>{{ __('certificates.tvtc_ready') }}</b>
                                <span class="u-num">{{ \App\Support\Dates::longDate($tvtc->issuedAt) }}</span>
                            </div>
                            <div class="row__e">
                                <x-ui.button variant="primary" size="sm" icon="down"
                                    :href="route('certificate.tvtc')">{{ __('app.download') }}</x-ui.button>
                            </div>
                        </div>
                    @else
                        <div class="row">
                            <div class="row__m">
                                <b>{{ __('certificates.tvtc_pending_title') }}</b>
                                <span>{{ __('certificates.tvtc_pending_body') }}</span>
                            </div>
                            <div class="row__e">
                                <x-ui.pill variant="warning" icon="clock">{{ __('certificates.tvtc_pending_pill') }}</x-ui.pill>
                            </div>
                        </div>
                    @endif
                </x-ui.card>

                @if ($certificate)
                    <x-ui.card class="u-mt-4" icon="globe" :title="__('certificates.public_verify_title')">
                        <p class="prose">{{ __('certificates.public_verify_body') }}</p>
                        <p class="codebox" dir="ltr"><bdi>{{ $certificate->verifyUrl }}</bdi></p>
                    </x-ui.card>
                @endif
            </div>
        </div>
    @endif
@endsection
