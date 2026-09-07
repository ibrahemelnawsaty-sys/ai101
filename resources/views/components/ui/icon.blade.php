@props([
    'name' => null,
    'size' => 'md',
    'variant' => 'default',
    'label' => null,
])

@php
    /**
     * Single place that turns an icon name into a sprite reference.
     *
     * Call sites are split between bare names ("warn") and prefixed ones
     * ("i-warn"). Normalising here rather than rewriting ~180 call sites means
     * there is one rule to remember and one place it can be wrong.
     *
     * A decorative icon is hidden from assistive tech; one that carries meaning
     * on its own must be given a label, because colour and shape alone never
     * convey meaning (CONSTITUTION art. 18).
     */
    $symbol = $name === null ? null : (str_starts_with($name, 'i-') ? $name : 'i-'.$name);

    $sizes = [
        'xs' => 'ui-icon--xs',
        'sm' => 'ui-icon--sm',
        'md' => 'ui-icon--md',
        'lg' => 'ui-icon--lg',
    ];

    $classes = trim(implode(' ', array_filter([
        'ui-icon',
        $sizes[$size] ?? $sizes['md'],
        $variant !== 'default' ? 'ui-icon--'.$variant : null,
    ])));
@endphp

@if ($symbol)
    <svg {{ $attributes->merge(['class' => $classes]) }}
         @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" focusable="false" @endif>
        <use href="#{{ $symbol }}" />
    </svg>
@endif
