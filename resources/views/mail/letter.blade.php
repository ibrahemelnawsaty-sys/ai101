{{--
    Any letter from the notification matrix, set in the Athar shell.

    The shell owns the look; this file owns only the paragraph between the
    heading and the button. That split is the point: a new letter is a new entry
    in emails.php and a new listener, never a new design.

    @see PRD §9.16 · D-49

    Everything here comes from App\Mail\AtharLetter.
--}}
@include('mail.layout', [
    'palette' => $palette,
    'subject' => $subject,
    'preheader' => $preheader,
    'eyebrow' => $eyebrow,
    'heading' => $heading,
    'lede' => $lede,
    'ctaLabel' => $ctaLabel,
    'ctaUrl' => $ctaUrl,
    'footNote' => $footNote,
    'meta' => $meta,
    'why' => $why,
    'platformName' => $platformName,
    'tagline' => $tagline,
    'contactEmail' => $contactEmail,
    'slot' => new Illuminate\Support\HtmlString(
        $bodyText === ''
            ? ''
            : '<p style="margin:'.$palette['gapLg'].' 0 0 0;">'.e($bodyText).'</p>'
    ),
])
