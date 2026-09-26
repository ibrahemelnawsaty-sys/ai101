{{--
    The receipt of a final-project hand-in (D-122), set in the Athar shell.

    The shell owns the look; the body here adds the receipt code set large,
    the QR embedded in the message itself (`$message->embedData()` returns a
    cid: reference — no remote image to block), and the list of what was
    handed in. Everything comes from App\Mail\HandInReceiptLetter.

    @see PRD §9.16 · D-49, D-122
--}}
@include('mail.layout', [
    'palette' => $palette,
    'subject' => $subject,
    'preheader' => $preheader,
    'eyebrow' => null,
    'heading' => $heading,
    'lede' => null,
    'ctaLabel' => $ctaLabel,
    'ctaUrl' => $ctaUrl,
    'footNote' => $footNote,
    'meta' => $meta,
    'why' => $why,
    'platformName' => $platformName,
    'tagline' => $tagline,
    'contactEmail' => $contactEmail,
    'slot' => new Illuminate\Support\HtmlString(view('mail.partials.hand-in-receipt-body', [
        'c' => $palette,
        'bodyText' => $bodyText,
        'codeLabel' => $codeLabel,
        'code' => $code,
        'qrSrc' => $qrPng === '' ? null : $message->embedData($qrPng, 'receipt-qr.png', 'image/png'),
        'qrAlt' => $qrAlt,
        'qrHint' => $qrHint,
        'qrSize' => $qrSize,
        'itemsLabel' => $itemsLabel,
        'items' => $items,
    ])->render()),
])
