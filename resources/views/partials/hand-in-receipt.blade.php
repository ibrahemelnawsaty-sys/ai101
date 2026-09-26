{{--
    A hand-in receipt (D-122): the code set large, the QR that opens the
    receipt page, and the next step. Shared by the participant's project page
    and the receipt page itself, so the two always show the same receipt.

    The QR is an inline SVG drawn on the server from a URL the server built
    (App\Support\QrSvg) — generated markup, never user input, which is why it
    is the one value here printed unescaped.

    Expects: $receipt — App\Presenters\Shared\HandInReceipt

    @see PRD §9.14.2 · FR-NOTIF-15 · D-122
--}}
<div class="receipt">
    <div class="receipt__qr" role="img" aria-label="{{ $receipt->qrLabel }}">
        {!! $receipt->qrSvg !!}
    </div>
    <div class="receipt__body">
        <span class="receipt__label">{{ __('project.receipt.code_label') }}</span>
        <b class="receipt__code">{{ $receipt->code }}</b>
        <span class="receipt__hint">{{ __('project.receipt.code_hint') }}</span>
    </div>
</div>

<div class="note note--info u-mt-4" role="status">
    <x-ui.icon name="info" />
    <p><b>{{ __('project.receipt.next_title') }}</b>{{ $receipt->nextStep }}</p>
</div>
