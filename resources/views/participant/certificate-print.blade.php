{{--
    The certificate, on paper. The browser makes the PDF — "Print, then save as
    PDF" — exactly as the digital card does (D-57): nothing on the platform
    generated a certificate file, so the download route answered 404 for every
    holder, and the letter that announced the certificate pointed at it (D-81).

    A4 landscape through a named page (.certsheet { page: certificate }), so the
    card and the timetable keep their own page size.

    Variables from App\Http\Controllers\Participant\CertificateController@print:
      $certificate  App\Presenters\Participant\CertificatePresenter

    @see PRD §9.17 · BR-25 · D-57, D-81
--}}
@extends('layouts.bare')

@section('title', __('certificates.doc_heading'))
@section('ownSheet', '1')

@section('content')
    <div class="certsheet">
        <p class="certsheet__note">{{ __('certificates.print_note') }}</p>

        @include('participant.partials.certificate-document', ['certificate' => $certificate])

        @if ($certificate->qrSvg !== '')
            <div class="certsheet__verify">
                {{-- Server-rendered SVG, so it prints at the printer's own resolution. --}}
                <div class="certsheet__qr" role="img" aria-label="{{ __('certificates.qr_alt') }}">
                    {!! $certificate->qrSvg !!}
                </div>
                <p class="certsheet__url" dir="ltr">{{ $certificate->verifyUrl }}</p>
            </div>
        @endif
    </div>
@endsection
