{{--
    The body of a hand-in receipt (D-122): the paragraph, the receipt code in a
    box, the QR, and the items handed in. Every value is escaped by Blade.

    $c          array    the mail palette
    $bodyText   string
    $codeLabel  string · $code string
    $qrSrc      ?string  a cid: reference, or null when the QR could not be drawn
    $qrAlt      string · $qrHint ?string · $qrSize int
    $itemsLabel ?string · $items list<string>
--}}
@if ($bodyText !== '')
    <p style="margin:{{ $c['gapLg'] }} 0 0 0;">{{ $bodyText }}</p>
@endif

<table role="presentation" dir="rtl" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="margin-top:{{ $c['gapLg'] }}; background:{{ $c['brandTint'] }}; border:{{ $c['hairline'] }} solid {{ $c['brandLight'] }}; border-radius:{{ $c['radius'] }}; direction:rtl;">
    <tr>
        <td align="center" style="padding:{{ $c['gapLg'] }}; font-family:{{ $c['font'] }};">
            <div style="font-size:{{ $c['textXs'] }}; color:{{ $c['body'] }};">{{ $codeLabel }}</div>
            <div dir="ltr" style="margin-top:{{ $c['gapSm'] }}; font-size:{{ $c['textBody'] }}; font-weight:700; color:{{ $c['brandDeep'] }};">{{ $code }}</div>
            @if ($qrSrc)
                <img src="{{ $qrSrc }}" alt="{{ $qrAlt }}" width="{{ $qrSize }}" height="{{ $qrSize }}"
                     style="display:block; margin:{{ $c['gapMd'] }} auto 0 auto; border:0;">
                @if ($qrHint)
                    <div style="margin-top:{{ $c['gapSm'] }}; font-size:{{ $c['textXs'] }}; color:{{ $c['muted'] }};">{{ $qrHint }}</div>
                @endif
            @endif
        </td>
    </tr>
</table>

@if ($items !== [] && $itemsLabel)
    <p style="margin:{{ $c['gapLg'] }} 0 0 0; font-weight:700; color:{{ $c['inkText'] }};">{{ $itemsLabel }}</p>
    <ul style="margin:{{ $c['gapSm'] }} 0 0 0;">
        @foreach ($items as $item)
            <li style="margin-top:{{ $c['gapXs'] }};">{{ $item }}</li>
        @endforeach
    </ul>
@endif
