{{--
    The plain-text half of the invitation.

    WHY IT EXISTS AT ALL
    Laravel does not synthesise a text part: a Mailable with only `view:` emits a
    single `text/html` body and no `multipart/alternative`. Every common spam
    scorer penalises HTML-only mail — SpamAssassin's MIME_HTML_ONLY among them —
    and this is the letter a trainee cannot proceed without.

    It also matters more here than anywhere else in the platform: this letter
    carries a password, and a reader using a text-only client, a braille
    display, or a screen reader that requests the plain part would otherwise
    receive an empty message.

    Every string is passed in already resolved by App\Mail\InvitationLetter, so
    no Arabic literal lives in this file (Article 15).
--}}
{{ $heading }}

{{ $greeting }}

{{ $bodyText }}

—

{{ $credentialsTitle }}

{{ $emailLabel }}: {{ $email }}
{{ $passwordLabel }}: {{ $password }}

{{ $temporaryNote }}

—

{{ $ctaLabel }}:
{{ $ctaUrl }}

{{ $footNote }}

—

{{ $programName }} — {{ $cohortName }}

{{ $notYou }}
{{ __('emails.common.contact_line', ['email' => $contactEmail]) }}
{{ __('emails.common.sign_off') }}
{{ $platformName }} — {{ $tagline }}
