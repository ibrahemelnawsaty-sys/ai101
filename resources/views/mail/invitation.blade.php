{{--
    The invitation, set in the Athar shell.

    This file owns one thing the shell has no place for: the credentials panel.

    EVERY DECISION IN THAT PANEL IS ABOUT SOMEONE RETYPING IT.
      · Monospaced, because proportional type turns `rn` into `m` and gives
        `1`, `l` and `I` the same silhouette. The generator already refuses the
        worst of those glyphs; the typeface removes what is left.
      · `dir="ltr"` on the value cells, and only there. The password is Latin
        and lives inside a right-to-left letter; without an explicit direction
        the bidi algorithm reorders a run that begins or ends with a symbol, and
        the reader types a password that is not the one that was sent.
      · `white-space: nowrap`, because a credential broken across two lines is
        read as two words, and the space between them gets typed.
      · Generous line height and letter spacing on the password alone. This is
        the one string in the message that will be transcribed by hand.
      · A tinted panel rather than a bordered box: Outlook's Word engine drops
        `border-radius` and honours `bgcolor`, so the panel survives as a solid
        block instead of collapsing into an outline with nothing inside it.

    NO IMAGE, NO WEB FONT, NO GRADIENT ON TEXT. Images are blocked by default,
    web fonts are stripped, and a gradient behind text is how the crown tagline
    ended up at 1.84:1. What reads as luxurious here is space, rhythm and one
    hairline — not ornament that half the clients will not render.

    @see PRD §9.2, §9.16 · BR-30 · D-63

    Everything here comes from App\Mail\InvitationLetter.
--}}
@include('mail.layout', [
    'palette' => $palette,
    'subject' => $subject,
    'preheader' => $preheader,
    'eyebrow' => $eyebrow,
    'heading' => $heading,
    'lede' => $greeting,
    'ctaLabel' => $ctaLabel,
    'ctaUrl' => $ctaUrl,
    'footNote' => $footNote,
    'meta' => [
        __('emails.invitation.program_label') => $programName,
        __('emails.common.cohort_label') => $cohortName,
    ],
    'why' => $notYou,
    'platformName' => $platformName,
    'tagline' => $tagline,
    'contactEmail' => $contactEmail,
    'slot' => new Illuminate\Support\HtmlString(
        view('mail.partials.credentials', [
            'c' => $palette,
            'bodyText' => $bodyText,
            'credentialsTitle' => $credentialsTitle,
            'emailLabel' => $emailLabel,
            'passwordLabel' => $passwordLabel,
            'email' => $email,
            'password' => $password,
            'temporaryNote' => $temporaryNote,
        ])->render(),
    ),
])
