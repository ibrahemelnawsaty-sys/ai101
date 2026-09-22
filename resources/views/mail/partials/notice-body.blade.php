{{--
    The body of a NoticeLetter: paragraphs, then an optional list of rows.
    Every value is escaped by Blade; line breaks inside a paragraph become
    <br> only AFTER escaping, so a message can never carry markup (D-87).

    $c           array   the mail palette
    $paragraphs  list<string>
    $rows        list<array{0: string, 1: string}>
--}}
@foreach ($paragraphs as $paragraph)
    <p style="margin:{{ $c['gapLg'] }} 0 0 0;">{!! nl2br(e($paragraph), false) !!}</p>
@endforeach

@if ($rows !== [])
    <table role="presentation" dir="rtl" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="margin-top:{{ $c['gapLg'] }}; background:{{ $c['surfaceAlt'] }}; border:{{ $c['hairline'] }} solid {{ $c['line'] }}; border-radius:{{ $c['radius'] }}; direction:rtl;">
        @foreach ($rows as [$label, $value])
            <tr>
                <td style="padding:{{ $c['gapMd'] }} {{ $c['gapLg'] }}; font-family:{{ $c['font'] }}; font-size:{{ $c['textSm'] }}; font-weight:700; color:{{ $c['inkText'] }}; {{ $loop->last ? '' : 'border-bottom:'.$c['hairline'].' solid '.$c['line'].';' }}">
                    {{ $label }}
                </td>
                <td align="left" style="padding:{{ $c['gapMd'] }} {{ $c['gapLg'] }}; font-family:{{ $c['font'] }}; font-size:{{ $c['textXs'] }}; color:{{ $c['muted'] }}; {{ $loop->last ? '' : 'border-bottom:'.$c['hairline'].' solid '.$c['line'].';' }}">
                    {{ $value }}
                </td>
            </tr>
        @endforeach
    </table>
@endif
