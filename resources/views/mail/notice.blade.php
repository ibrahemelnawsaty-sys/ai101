{{--
    A letter whose words were written before it was queued — an
    administrator's message, or a digest of sessions or unfinished work (D-87).

    The shell is the same as every letter's. Only the body differs: paragraphs,
    each ESCAPED, and an optional list of rows set like the shell's own detail
    strip. Nothing in either is ever printed as HTML.

    @see PRD §9.16 · D-87

    Everything here comes from App\Mail\NoticeLetter.
--}}
@include('mail.layout', [
    'palette' => $palette,
    'subject' => $subject,
    'preheader' => $preheader,
    'eyebrow' => $eyebrow,
    'heading' => $heading,
    'lede' => null,
    'ctaLabel' => $ctaLabel,
    'ctaUrl' => $ctaUrl,
    'footNote' => null,
    'meta' => [],
    'why' => $why,
    'platformName' => $platformName,
    'tagline' => $tagline,
    'contactEmail' => $contactEmail,
    'slot' => new Illuminate\Support\HtmlString(view('mail.partials.notice-body', [
        'c' => $palette,
        'paragraphs' => $paragraphs,
        'rows' => $rows,
    ])->render()),
])
