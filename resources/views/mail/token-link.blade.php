{{--
    The activation / recovery letter.

    Set in the shared Athar shell like every other letter, with two things the
    shell does not give a normal message and that this one must have:

      · the raw link under the button — a security link that exists only inside
        an anchor is unusable in a client that strips anchors, and a reader who
        cannot see where a link goes should not be asked to trust it
      · the "ignore this if it was not you" line, which is the whole difference
        between a recovery letter and an invitation

    The shell prints the raw link itself whenever a CTA is present, so this file
    adds only the body and the ignore note.

    @see BR-29, BR-30 · PRD §9.2.3, §9.3.3 · D-49

    Variables from App\Mail\EmailTokenLink.
--}}
@include('mail.layout', [
    'palette' => $palette,
    'subject' => __($key.'.subject', ['program' => $programName]),
    'preheader' => __($key.'.preheader'),
    'eyebrow' => null,
    'heading' => __($key.'.heading'),
    'lede' => $name === null
        ? __('emails.common.greeting_neutral')
        : __('emails.common.greeting', ['name' => $name]),
    'ctaLabel' => __($key.'.cta'),
    'ctaUrl' => $url,
    'footNote' => __($key.'.expiry_note'),
    'meta' => [],
    'why' => __('emails.common.security_notice'),
    'platformName' => $platformName,
    'tagline' => $tagline,
    'contactEmail' => $contactEmail,
    'slot' => new Illuminate\Support\HtmlString(
        '<p style="margin:'.$palette['gapLg'].' 0 0 0;">'
        .e(__($key.'.body', ['program' => $programName])).'</p>'
        .'<p style="margin:'.$palette['gapLg'].' 0 0 0; font-size:'.$palette['textXs'].'; color:'.$palette['muted'].';">'
        .e(__($key.'.ignore_note')).'</p>'
    ),
])
