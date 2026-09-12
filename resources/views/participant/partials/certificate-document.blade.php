{{--
    The certificate as a document — on the certificate page as a preview, and on
    the print sheet as the thing itself. One markup, so the paper and the screen
    cannot disagree (D-81).

    Variables: $certificate  App\Presenters\Participant\CertificatePresenter

    @see PRD §9.17 · BR-25 · D-81
--}}
<div class="certdoc">
    <div class="certdoc__in">
        <div class="certdoc__logo">
            {{-- x-ui.logo, not x-ui.icon: the icon component prefixes every name
                 with "i-" and the wordmark's symbol has none, so the logo never
                 rendered on the certificate (D-81). --}}
            <x-ui.logo />
        </div>
        <h4>{{ __('certificates.doc_heading') }}</h4>
        <div class="certdoc__name">{{ $certificate->holderNameAr }}</div>
        {{-- Direction from the class (direction: ltr; unicode-bidi: isolate), not a
     dir attribute: the base layer aligns every [dir="ltr"] to the left and
     outranks this layer, so the Latin name sat off-centre (D-81). --}}
<div class="certdoc__en">{{ $certificate->holderNameEn }}</div>
        <div class="certdoc__for">
            {{ __('certificates.doc_completed') }}<br>
            <b>{{ $certificate->programName }}</b><br>
            {{ $certificate->cohortName }} ·
            <span class="u-num">{{ $certificate->trainingHours }}</span>
            {{ trans_choice('certificates.hours', $certificate->trainingHours) }}
        </div>
        <div class="certdoc__rule"></div>
        <div class="certdoc__ft">
            <div><b class="u-num"><bdi>{{ $certificate->serialNumber }}</bdi></b>{{ __('certificates.serial_number') }}</div>
            <div><b class="u-num">{{ \App\Support\Dates::longDate($certificate->issuedAt) }}</b>{{ __('certificates.issued_on') }}</div>
            <div><b class="u-num">{{ $certificate->finalScore }} / {{ $certificate->grandTotal }}</b>{{ __('certificates.final_score') }}</div>
        </div>
    </div>
</div>
