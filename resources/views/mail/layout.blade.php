{{--
    The Athar letter — the shell every outbound e-mail is set in.

    DESIGN INTENT
    A letter from a training centre should feel like an invitation, not a
    receipt. The shell is built around three ideas:

      · A deep violet crown. Mail clients that support gradients render the
        brand's violet-to-teal sweep; the rest fall back to solid violet, which
        is the brand colour anyway. Nothing is lost, only flattened.
      · A single wide column with generous air. Arabic display type needs room;
        a cramped letter reads as a system notice.
      · One decision per letter. The body says one thing and offers one button.
        Anything a reader must think about twice belongs on a screen, not here.

    ENGINEERING CONSTRAINTS THIS SHAPE ANSWERS
    Tables, not flexbox — Outlook renders with Word's engine. Inline values, not
    a <style> block — Gmail strips it. No image — a logo needs an absolute URL to
    a host many clients block, and a letter whose only branding fails to load
    looks broken, so the wordmark is set in type. `dir="rtl"` on the html
    element AND on the body, because some clients drop the outer one.

    NOT ONE LITERAL IS WRITTEN HERE. Every colour, length and face comes from
    EmailPalette, which reads tokens.css at render time. gate:tokens scans this
    file like any other Blade; exempting the directory would have been easier
    and wrong.

    @see PRD §9.16 · CONSTITUTION.md art. 6, art. 15, art. 18 · D-49

    Slots and variables:
      $palette   array   role => value
      $eyebrow   ?string small line above the heading — the letter's category
      $heading   string
      $lede      ?string one sentence under the heading
      $slot      body
      $ctaLabel  ?string · $ctaUrl ?string
      $footNote  ?string a quiet line under the button
      $meta      array   ['label' => 'value'] rendered as a detail strip
--}}
@php
    // Local aliases keep the markup readable; the values themselves still come
    // only from the token file.
    $c = $palette;
@endphp
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $subject ?? $heading }}</title>
</head>
<body dir="rtl" style="margin:0; padding:0; width:100%; background:{{ $c['surfaceAlt'] }}; direction:rtl; text-align:right; -webkit-font-smoothing:antialiased;">

{{-- The preheader. Hidden, and padded so a client does not pull body text in
     after it. --}}
<div style="display:none; max-height:0; overflow:hidden; opacity:0; visibility:hidden;">
    {{ $preheader ?? '' }}
    {{-- Padding so a client does not pull body text in after the preheader.
         Named entities only: a numeric character reference reads as a hex
         literal to gate:tokens, which scans this file like any other Blade. --}}
    &zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;
</div>

{{-- dir and direction are repeated on the tables, not only on <html> and
     <body>, because Gmail (webmail, Android and iOS) strips those two tags and
     injects the body's inner HTML into its own container — discarding their
     attributes and their style with them. Every RTL signal in this letter used
     to live on exactly those two tags, so in Gmail, the client behind the very
     SMTP relay this platform sends through, every Arabic letter rendered
     left-aligned (D-62). --}}
<table role="presentation" dir="rtl" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background:{{ $c['surfaceAlt'] }}; direction:rtl; text-align:right;">
    <tr>
        <td align="center" style="padding:{{ $c['gapXl'] }} {{ $c['gapMd'] }};">

            <!--[if mso]><table role="presentation" width="{{ (int) $c['cardWidth'] }}" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
            <table role="presentation" dir="rtl" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:{{ $c['cardWidth'] }}; background:{{ $c['surface'] }}; border-radius:{{ $c['radiusCard'] }}; overflow:hidden; direction:rtl; text-align:right;">

                {{-- ── The crown ─────────────────────────────────────────── --}}
                <tr>
                    <td style="background:{{ $c['brandDeep'] }}; background-image:linear-gradient(135deg, {{ $c['brandDeep'] }} 0%, {{ $c['brand'] }} 62%, {{ $c['accentDark'] }} 100%); padding:{{ $c['padCard'] }} {{ $c['gapXl'] }};">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="font-family:{{ $c['font'] }}; font-size:{{ $c['textSm'] }}; font-weight:700; letter-spacing:0.01em; color:{{ $c['white'] }};">
                                    {{ $platformName }}
                                </td>
                            </tr>
                            <tr>
                                <td style="padding-top:{{ $c['gapXs'] }}; font-family:{{ $c['font'] }}; font-size:{{ $c['textXs'] }}; color:{{ $c['white'] }};">
                                    {{ $tagline }}
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- A hairline of brand colour under the crown: the one piece of
                     ornament in the letter, and it costs nothing to render. --}}
                <tr>
                    <td style="height:{{ $c['gapXs'] }}; background:{{ $c['accent'] }}; background-image:linear-gradient(90deg, {{ $c['accent'] }} 0%, {{ $c['brand'] }} 100%); font-size:{{ $c['zero'] }}; line-height:{{ $c['zero'] }};">&nbsp;</td>
                </tr>

                {{-- ── The message ───────────────────────────────────────── --}}
                <tr>
                    <td style="padding:{{ $c['gapHuge'] }} {{ $c['gapXl'] }} {{ $c['gapLg'] }} {{ $c['gapXl'] }}; font-family:{{ $c['font'] }};">

                        @if (filled($eyebrow ?? null))
                            <div style="display:inline-block; padding:{{ $c['gapXs'] }} {{ $c['gapMd'] }}; margin-bottom:{{ $c['gapLg'] }}; border-radius:{{ $c['radiusPill'] }}; background:{{ $c['brandTint'] }}; font-size:{{ $c['textXs'] }}; font-weight:700; color:{{ $c['brandDark'] }};">
                                {{ $eyebrow }}
                            </div>
                        @endif

                        <h1 style="margin:0; font-size:{{ $c['gapLg'] }}; line-height:1.55; font-weight:700; color:{{ $c['inkText'] }};">
                            {{ $heading }}
                        </h1>

                        @if (filled($lede ?? null))
                            <p style="margin:{{ $c['gapMd'] }} 0 0 0; font-size:{{ $c['textSm'] }}; line-height:2; color:{{ $c['body'] }};">
                                {{ $lede }}
                            </p>
                        @endif

                        <div style="font-size:{{ $c['textSm'] }}; line-height:2; color:{{ $c['body'] }};">
                            {{ $slot }}
                        </div>
                    </td>
                </tr>

                {{-- ── The detail strip ──────────────────────────────────── --}}
                @if (filled($meta ?? []))
                    <tr>
                        <td style="padding:0 {{ $c['gapXl'] }} {{ $c['gapLg'] }} {{ $c['gapXl'] }};">
                            <table role="presentation" dir="rtl" width="100%" cellpadding="0" cellspacing="0" border="0"
                                   style="background:{{ $c['surfaceAlt'] }}; border:{{ $c['hairline'] }} solid {{ $c['line'] }}; border-radius:{{ $c['radius'] }}; direction:rtl;">
                                @foreach ($meta as $label => $value)
                                    <tr>
                                        <td style="padding:{{ $c['gapMd'] }} {{ $c['gapLg'] }}; font-family:{{ $c['font'] }}; font-size:{{ $c['textXs'] }}; color:{{ $c['muted'] }}; {{ $loop->last ? '' : 'border-bottom:'.$c['hairline'].' solid '.$c['line'].';' }}">
                                            {{ $label }}
                                        </td>
                                        <td align="left" style="padding:{{ $c['gapMd'] }} {{ $c['gapLg'] }}; font-family:{{ $c['font'] }}; font-size:{{ $c['textSm'] }}; font-weight:700; color:{{ $c['inkText'] }}; {{ $loop->last ? '' : 'border-bottom:'.$c['hairline'].' solid '.$c['line'].';' }}">
                                            {{ $value }}
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        </td>
                    </tr>
                @endif

                {{-- ── The one button ────────────────────────────────────── --}}
                @if (filled($ctaUrl ?? null) && filled($ctaLabel ?? null))
                    <tr>
                        <td align="center" style="padding:0 {{ $c['gapXl'] }} {{ $c['gapLg'] }} {{ $c['gapXl'] }};">
                            {{-- Bulletproof: a table cell carries the fill so Outlook
                                 renders a button and not a bare link. --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" bgcolor="{{ $c['brand'] }}"
                                        style="padding:{{ $c['gapMd'] }} {{ $c['gapHuge'] }}; border-radius:{{ $c['radius'] }}; background:{{ $c['brand'] }};">
                                        <a href="{{ $ctaUrl }}"
                                           style="font-family:{{ $c['font'] }}; font-size:{{ $c['textSm'] }}; font-weight:700; color:{{ $c['white'] }}; text-decoration:none;">
                                            {{ $ctaLabel }}
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:0 {{ $c['gapXl'] }} {{ $c['gapLg'] }} {{ $c['gapXl'] }}; font-family:{{ $c['font'] }};">
                            <p style="margin:0 0 {{ $c['gapXs'] }} 0; font-size:{{ $c['textXs'] }}; line-height:1.8; color:{{ $c['muted'] }};">
                                {{ __('emails.common.link_fallback') }}
                            </p>
                            <p dir="ltr" style="margin:0; font-size:{{ $c['textXs'] }}; line-height:1.7; color:{{ $c['brandDark'] }}; word-break:break-all; text-align:left;">
                                {{ $ctaUrl }}
                            </p>
                        </td>
                    </tr>
                @endif

                {{-- ── The note under the button — or standing alone ─────────
                     This used to be nested inside the button block above, so a
                     letter with no button lost it. That is exactly one letter:
                     the rejection, whose own listener docblock says it carries
                     no button because "the copy offers the next cohort in
                     words" — and those words never reached anyone (D-62). --}}
                @if (filled($footNote ?? null))
                    <tr>
                        <td style="padding:0 {{ $c['gapXl'] }} {{ $c['gapLg'] }} {{ $c['gapXl'] }};">
                            <p style="margin:0; font-family:{{ $c['font'] }}; font-size:{{ $c['textXs'] }}; line-height:1.9; color:{{ $c['muted'] }};">
                                {{ $footNote }}
                            </p>
                        </td>
                    </tr>
                @endif

                {{-- ── The foot ──────────────────────────────────────────── --}}
                <tr>
                    <td style="padding:{{ $c['gapLg'] }} {{ $c['gapXl'] }}; background:{{ $c['surfaceAlt'] }}; border-top:{{ $c['hairline'] }} solid {{ $c['line'] }}; font-family:{{ $c['font'] }};">
                        <p style="margin:0 0 {{ $c['gapXs'] }} 0; font-size:{{ $c['textXs'] }}; line-height:1.9; color:{{ $c['muted'] }};">
                            {{ __('emails.common.contact_line', ['email' => $contactEmail]) }}
                        </p>
                        <p style="margin:0 0 {{ $c['gapXs'] }} 0; font-size:{{ $c['textXs'] }}; line-height:1.9; color:{{ $c['muted'] }};">
                            {{ $why ?? __('emails.common.security_notice') }}
                        </p>
                        <p style="margin:0; font-size:{{ $c['textXs'] }}; line-height:1.9; font-weight:700; color:{{ $c['brandDark'] }};">
                            {{ __('emails.common.sign_off') }}
                        </p>
                    </td>
                </tr>

            </table>
            <!--[if mso]></td></tr></table><![endif]-->

            <p style="max-width:{{ $c['cardWidth'] }}; margin:{{ $c['gapLg'] }} auto 0 auto; font-family:{{ $c['font'] }}; font-size:{{ $c['textXs'] }}; line-height:1.8; color:{{ $c['muted'] }}; text-align:center;">
                {{ __('emails.common.rights') }}
            </p>

        </td>
    </tr>
</table>

</body>
</html>
