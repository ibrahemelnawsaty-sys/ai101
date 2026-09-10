{{--
    The credentials panel. Rendered into the shell's slot by mail/invitation.blade.php.

    Tables, not divs: the Word engine behind Outlook 2007–2021 lays out tables
    and very little else, and this is the block that must survive everywhere.

    @see PRD §9.2 · BR-30 · D-63

    Variables: $c (palette) · $bodyText · $credentialsTitle · $emailLabel
               $passwordLabel · $email · $password · $temporaryNote
--}}
<p style="margin:{{ $c['gapLg'] }} 0 0 0;">{{ $bodyText }}</p>

<table role="presentation" dir="rtl" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin:{{ $c['gapXl'] }} 0 0 0; direction:rtl; text-align:right;">
    <tr>
        <td bgcolor="{{ $c['brandTint'] }}"
            style="padding:{{ $c['gapXl'] }}; background:{{ $c['brandTint'] }}; border-radius:{{ $c['radius'] }};">

            <p style="margin:0 0 {{ $c['gapLg'] }} 0; font-family:{{ $c['font'] }}; font-size:{{ $c['textXs'] }}; font-weight:700; letter-spacing:0.04em; color:{{ $c['brandDark'] }};">
                {{ $credentialsTitle }}
            </p>

            {{-- The address. Labelled above rather than beside, so a narrow
                 phone never puts the label and a long address on one line. --}}
            <p style="margin:0 0 {{ $c['gapXs'] }} 0; font-family:{{ $c['font'] }}; font-size:{{ $c['textXs'] }}; color:{{ $c['muted'] }};">
                {{ $emailLabel }}
            </p>
            <p dir="ltr"
               style="margin:0 0 {{ $c['gapLg'] }} 0; font-family:{{ $c['fontMono'] }}; font-size:{{ $c['textSm'] }}; line-height:1.7; color:{{ $c['inkText'] }}; direction:ltr; text-align:left; word-break:break-all;">
                {{ $email }}
            </p>

            <p style="margin:0 0 {{ $c['gapXs'] }} 0; font-family:{{ $c['font'] }}; font-size:{{ $c['textXs'] }}; color:{{ $c['muted'] }};">
                {{ $passwordLabel }}
            </p>

            {{-- The password gets its own surface, a step of size and a little
                 tracking. `nowrap` is not cosmetic: a credential wrapped across
                 two lines is read as two words and the break gets typed. --}}
            <table role="presentation" dir="ltr" cellpadding="0" cellspacing="0" border="0"
                   style="direction:ltr;">
                <tr>
                    <td bgcolor="{{ $c['surface'] }}"
                        style="padding:{{ $c['gapMd'] }} {{ $c['gapLg'] }}; background:{{ $c['surface'] }}; border:{{ $c['hairline'] }} solid {{ $c['brandLight'] }}; border-radius:{{ $c['radius'] }};">
                        <span dir="ltr"
                              style="font-family:{{ $c['fontMono'] }}; font-size:{{ $c['textBody'] }}; font-weight:700; letter-spacing:0.08em; line-height:1.7; color:{{ $c['inkText'] }}; white-space:nowrap; direction:ltr;">{{ $password }}</span>
                    </td>
                </tr>
            </table>

            <p style="margin:{{ $c['gapLg'] }} 0 0 0; font-family:{{ $c['font'] }}; font-size:{{ $c['textXs'] }}; line-height:1.9; color:{{ $c['brandDark'] }};">
                {{ $temporaryNote }}
            </p>

        </td>
    </tr>
</table>
