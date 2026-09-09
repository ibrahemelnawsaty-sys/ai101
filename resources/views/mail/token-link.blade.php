{{--
    The activation / recovery letter.

    RTL Arabic, table-based, every value inline — because e-mail clients strip
    <style>, ignore custom properties and mangle external sheets.

    NOT ONE LITERAL IS WRITTEN HERE. Every colour, length and type value comes
    from App\Services\Mail\EmailPalette, which reads tokens.css at render time.
    Article 6 holds in a letter exactly as it holds in a screen, and gate:tokens
    scans this file like any other Blade — hex and px alike. Exempting the
    directory would have been easier and wrong.

    No image is referenced: a logo needs an absolute URL to a host many clients
    block by default, and a letter whose only branding fails to load looks
    broken. The wordmark is set in text.

    @see BR-29, BR-30, BR-36 · PRD §9.2.3, §9.3.3, §9.16 · D-49

    Variables from App\Mail\EmailTokenLink:
      $palette      array   role => value, read from tokens.css
      $key          string  'emails.verify' | 'emails.password_reset'
      $url          string  the single-use link
      $name         ?string first name, or null for the neutral greeting
      $programName  string  · $platformName · $contactEmail · $homeUrl
--}}
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ __($key.'.subject', ['program' => $programName]) }}</title>
</head>
<body style="margin:0; padding:0; background:{{ $palette['surfaceAlt'] }}; direction:rtl; text-align:right;">

{{-- The preheader: the line a client shows beside the subject. Hidden in the
     body, or it reads as a duplicate of the heading. --}}
<div style="display:none; max-height:0; overflow:hidden; opacity:0;">
    {{ __($key.'.preheader') }}
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background:{{ $palette['surfaceAlt'] }}; padding:{{ $palette['gapXl'] }} {{ $palette['gapMd'] }};">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:{{ $palette['cardWidth'] }}; background:{{ $palette['surface'] }}; border:{{ $palette['hairline'] }} solid {{ $palette['line'] }}; border-radius:{{ $palette['radiusCard'] }}; overflow:hidden;">

                <tr>
                    <td style="background:{{ $palette['brand'] }}; padding:{{ $palette['gapLg'] }} {{ $palette['gapXl'] }};">
                        <div style="font-family:{{ $palette['font'] }}; font-size:{{ $palette['textSm'] }}; font-weight:700; color:{{ $palette['white'] }};">
                            {{ $platformName }}
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding:{{ $palette['padCard'] }} {{ $palette['gapXl'] }} {{ $palette['gapSm'] }} {{ $palette['gapXl'] }}; font-family:{{ $palette['font'] }};">
                        <h1 style="margin:0 0 {{ $palette['gapLg'] }} 0; font-size:{{ $palette['textSm'] }}; line-height:1.6; color:{{ $palette['ink'] }};">
                            {{ __($key.'.heading') }}
                        </h1>

                        <p style="margin:0 0 {{ $palette['gapMd'] }} 0; font-size:{{ $palette['textSm'] }}; line-height:1.9; color:{{ $palette['body'] }};">
                            {{ $name === null ? __('emails.common.greeting_neutral') : __('emails.common.greeting', ['name' => $name]) }}
                        </p>

                        <p style="margin:0 0 {{ $palette['gapXl'] }} 0; font-size:{{ $palette['textSm'] }}; line-height:1.9; color:{{ $palette['body'] }};">
                            {{ __($key.'.body', ['program' => $programName]) }}
                        </p>
                    </td>
                </tr>

                <tr>
                    <td align="center" style="padding:0 {{ $palette['gapXl'] }} {{ $palette['gapXl'] }} {{ $palette['gapXl'] }};">
                        {{-- A real anchor styled as a button: a <button> does
                             nothing in a mail client. --}}
                        <a href="{{ $url }}"
                           style="display:inline-block; padding:{{ $palette['gapMd'] }} {{ $palette['padCard'] }}; border-radius:{{ $palette['radius'] }}; background:{{ $palette['brand'] }}; color:{{ $palette['white'] }}; font-family:{{ $palette['font'] }}; font-size:{{ $palette['textSm'] }}; font-weight:700; text-decoration:none;">
                            {{ __($key.'.cta') }}
                        </a>
                    </td>
                </tr>

                <tr>
                    <td style="padding:0 {{ $palette['gapXl'] }} {{ $palette['gapXl'] }} {{ $palette['gapXl'] }}; font-family:{{ $palette['font'] }};">
                        <p style="margin:0 0 {{ $palette['gapSm'] }} 0; font-size:{{ $palette['textXs'] }}; line-height:1.8; color:{{ $palette['muted'] }};">
                            {{ __($key.'.expiry_note') }}
                        </p>
                        <p style="margin:0 0 {{ $palette['gapLg'] }} 0; font-size:{{ $palette['textXs'] }}; line-height:1.8; color:{{ $palette['muted'] }};">
                            {{ __($key.'.ignore_note') }}
                        </p>

                        <p style="margin:0 0 {{ $palette['gapXs'] }} 0; font-size:{{ $palette['textXs'] }}; line-height:1.8; color:{{ $palette['muted'] }};">
                            {{ __('emails.common.link_fallback') }}
                        </p>
                        {{-- The raw link is Latin: marked ltr and allowed to
                             break, or a 64-character token overflows the card. --}}
                        <p dir="ltr" style="margin:0; font-size:{{ $palette['textXs'] }}; line-height:1.7; color:{{ $palette['brandDark'] }}; word-break:break-all; text-align:left;">
                            {{ $url }}
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:{{ $palette['gapLg'] }} {{ $palette['gapXl'] }}; border-top:{{ $palette['hairline'] }} solid {{ $palette['line'] }}; font-family:{{ $palette['font'] }};">
                        <p style="margin:0 0 {{ $palette['gapXs'] }} 0; font-size:{{ $palette['textXs'] }}; line-height:1.8; color:{{ $palette['muted'] }};">
                            {{ __('emails.common.security_notice') }}
                        </p>
                        <p style="margin:0 0 {{ $palette['gapXs'] }} 0; font-size:{{ $palette['textXs'] }}; line-height:1.8; color:{{ $palette['muted'] }};">
                            {{ __('emails.common.contact_line', ['email' => $contactEmail]) }}
                        </p>
                        <p style="margin:0; font-size:{{ $palette['textXs'] }}; line-height:1.8; color:{{ $palette['muted'] }};">
                            {{ __('emails.common.sign_off') }}
                        </p>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>

</body>
</html>
