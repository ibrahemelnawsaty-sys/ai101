{{--
    A printable copy of the digital participant card.

    The browser makes the PDF, so there is no PDF library and no second
    rendering path to keep in step — the same reasoning the timetable sheet
    already states, and it matters more here: a server-side PDF renderer would
    have to shape Arabic itself, and one that shapes it badly produces a card
    with the holder's own name broken across it.

    It replaces two download buttons that could never work: CardController read
    `file_url`, a column that exists on `certificates` and not on
    `digital_cards`, so the route answered 404 every time and nothing ever
    generated a file (D-57).

    Print rules handled here:
      - the sheet stays RTL on paper
      - the QR is the point of printing this, so it is sized to scan from paper
        rather than from a screen, and it is never scaled down by a print
        stylesheet
      - the verification URL is printed in full beside the QR, because a printed
        code that will not scan is otherwise a dead end
      - the card keeps its credit-card proportion so it can be cut out

    The bare layout loads no JavaScript, so this does not auto-open the print
    dialog: the reader presses print when they are ready, and can read it on
    screen first.

    @see BR-25 · PRD §7.6, §9.6 · D-57

    Variables from App\Http\Controllers\Participant\CardController@print:
      $card  App\Presenters\Participant\CardPresenter
--}}
@extends('layouts.bare')

@section('title', __('card.title'))
@section('ownSheet', '1')

@section('content')
    <div class="sheet">
        <header class="sheet__hd">
            <x-ui.logo class="sheet__logo" />
            <div>
                <h1>{{ __('card.title') }}</h1>
                <p>{{ $card->programName }} — {{ $card->cohortName }}</p>
            </div>
        </header>

        <div class="pcard">
            <div class="pcard__main">
                <p class="pcard__name">{{ $card->fullNameAr }}</p>
                <p class="pcard__name pcard__name--latin" dir="ltr">{{ $card->fullNameEn }}</p>

                <dl class="pcard__meta">
                    <dt>{{ __('card.role') }}</dt>
                    <dd>{{ $card->roleLabel }}</dd>

                    <dt>{{ __('card.number') }}</dt>
                    <dd class="u-num" dir="ltr">{{ $card->number }}</dd>

                    <dt>{{ __('card.issued_on') }}</dt>
                    <dd class="u-num">{{ \App\Support\Dates::longDate($card->issuedAt) }}</dd>

                    @if ($card->expiresAt !== null)
                        <dt>{{ __('card.expires_on') }}</dt>
                        <dd class="u-num">{{ \App\Support\Dates::longDate($card->expiresAt) }}</dd>
                    @endif
                </dl>
            </div>

            <div class="pcard__side">
                {{-- Server-rendered SVG, so it prints at the printer's own
                     resolution instead of a bitmap's. --}}
                <div class="pcard__qr" role="img" aria-label="{{ __('card.qr_alt') }}">
                    {!! $card->qrSvg !!}
                </div>
                <p class="pcard__url" dir="ltr">{{ $card->verifyUrl }}</p>
            </div>
        </div>

        <p class="sheet__note">{{ __('card.print_note') }}</p>
    </div>
@endsection
