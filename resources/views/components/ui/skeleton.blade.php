{{--
    Skeleton

    Article 17 is explicit: the loading state is a skeleton SHAPED LIKE THE
    CONTENT that is coming, not a spinner and not a grey bar. So this component
    has no generic shape at all — the caller names the thing being loaded and
    gets its silhouette:

        <x-ui.skeleton shape="card"     :count="3" />   dashboard panels
        <x-ui.skeleton shape="row"      :count="6" />   a list of sessions
        <x-ui.skeleton shape="table"    :count="5" :columns="4" />
        <x-ui.skeleton shape="timeline" :count="4" />   the journey rail
        <x-ui.skeleton shape="stat"     :count="4" />   the KPI strip

    The wrapper announces itself once as a live region; the shapes themselves
    are aria-hidden, so a screen reader hears "loading" rather than a stream of
    empty boxes (Article 18).

    The shimmer is switched off entirely by prefers-reduced-motion in
    components.css — the block simply sits still.

    @see PRD §5.8, §5.9 · CONSTITUTION Articles 15, 17, 18

    Props
      shape     line | text | title | avatar | chip | bar | button | thumb |
                stack | card | row | stat | timeline | table
      variant   kept for interface symmetry with the rest of the library
      size      sm | md | lg      reserved
      state     loading           a skeleton has exactly one state
      count     how many silhouettes to draw
      lines     text lines inside each silhouette
      columns   cells per row for shape="table"
      label     what is loading, announced politely
      height    one silhouette block this tall  — var(--token) only
      width     one silhouette block this wide  — var(--token) or a percentage

    height and width draw a single block instead of a named silhouette, for the
    places a screen already knows the exact shape of the thing it is waiting
    for. Both are token references, never raw lengths (Article 6), and anything
    else is ignored rather than written into the style attribute.
--}}
@props([
    'shape' => 'text',
    'variant' => 'default',
    'size' => 'md',
    'state' => 'loading',
    'count' => 1,
    'lines' => 3,
    'columns' => 4,
    'label' => null,
    'height' => null,
    'width' => null,
])

@php
    $count = max(1, min(24, (int) $count));
    $lines = max(1, min(12, (int) $lines));
    $columns = max(1, min(10, (int) $columns));
    $announce = $label ?? __('ui.skeleton.label');

    $sizePattern = '/^(var\(--[a-z0-9-]+\)|\d{1,3}(\.\d+)?%)$/i';
    $blockH = is_string($height) && preg_match($sizePattern, trim($height)) === 1 ? trim($height) : null;
    $blockW = is_string($width) && preg_match($sizePattern, trim($width)) === 1 ? trim($width) : null;
    $blockStyle = ($blockH === null ? '' : '--sk-h:' . $blockH . ';') . ($blockW === null ? '' : '--sk-w:' . $blockW . ';');
    $shape = $blockStyle === '' ? $shape : 'block';
@endphp

<div {{ $attributes->class(['ui-sk-stack']) }} role="status" aria-live="polite" aria-busy="true">
    <span class="ui-sr">{{ $announce }}</span>

    @for ($i = 0; $i < $count; $i++)
        @switch($shape)

            @case('card')
                <div class="ui-sk-card" aria-hidden="true">
                    <div class="ui-sk-card__head">
                        <span class="ui-sk ui-sk-avatar"></span>
                        <span class="ui-sk ui-sk-title" style="margin-block-end: 0;"></span>
                    </div>
                    @for ($l = 0; $l < $lines; $l++)
                        <span class="ui-sk ui-sk-line"></span>
                    @endfor
                    <div class="ui-sk-card__foot">
                        <span class="ui-sk ui-sk-button"></span>
                        <span class="ui-sk ui-sk-chip"></span>
                    </div>
                </div>
                @break

            @case('row')
                <div class="ui-sk-row" aria-hidden="true">
                    <span class="ui-sk ui-sk-avatar"></span>
                    <div class="ui-sk-row__main">
                        <span class="ui-sk ui-sk-line"></span>
                        <span class="ui-sk ui-sk-line"></span>
                    </div>
                    <span class="ui-sk ui-sk-chip"></span>
                </div>
                @break

            @case('stat')
                <div class="ui-sk-card" aria-hidden="true">
                    <span class="ui-sk ui-sk-stat__value"></span>
                    <span class="ui-sk ui-sk-line"></span>
                </div>
                @break

            @case('timeline')
                <div class="ui-sk-timeline__item" aria-hidden="true">
                    <span class="ui-sk ui-sk-timeline__dot"></span>
                    <div class="ui-sk-timeline__body">
                        <span class="ui-sk ui-sk-title" style="margin-block-end: var(--s2);"></span>
                        <span class="ui-sk ui-sk-line"></span>
                    </div>
                </div>
                @break

            @case('table')
                @if ($i === 0)
                    <div class="ui-sk-table" aria-hidden="true">
                        @for ($r = 0; $r < $count; $r++)
                            <div class="ui-sk-table__row">
                                @for ($c = 0; $c < $columns; $c++)
                                    <span class="ui-sk ui-sk-table__cell"></span>
                                @endfor
                            </div>
                        @endfor
                    </div>
                @endif
                @break

            @case('title')
                <span class="ui-sk ui-sk-title" aria-hidden="true"></span>
                @break

            @case('avatar')
                <span class="ui-sk ui-sk-avatar" aria-hidden="true"></span>
                @break

            @case('chip')
                <span class="ui-sk ui-sk-chip" aria-hidden="true"></span>
                @break

            @case('bar')
                <span class="ui-sk ui-sk-bar" aria-hidden="true"></span>
                @break

            @case('button')
                <span class="ui-sk ui-sk-button" aria-hidden="true"></span>
                @break

            @case('thumb')
                <span class="ui-sk ui-sk-thumb" aria-hidden="true"></span>
                @break

            @case('line')
                <span class="ui-sk ui-sk-line" aria-hidden="true"></span>
                @break

            @case('block')
                <span class="ui-sk ui-sk-block" style="{{ $blockStyle }}" aria-hidden="true"></span>
                @break

            @default
                {{-- text / stack: a heading followed by paragraph lines --}}
                <div aria-hidden="true">
                    <span class="ui-sk ui-sk-title"></span>
                    @for ($l = 0; $l < $lines; $l++)
                        <span class="ui-sk ui-sk-line"></span>
                    @endfor
                </div>
        @endswitch
    @endfor
</div>
