@props(['until' => null])

{{--
    Views ask for `:until`; the timer component names the same thing `target`.
    Bridged here so PROJECT-CONTRACT §12 keeps one component and the call sites
    keep the word that reads better at the point of use.
--}}
<x-ui.countdown-timer :target="$until" {{ $attributes }}>
    {{ $slot }}
</x-ui.countdown-timer>
