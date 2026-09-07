@props(['rows' => 4])

{{--
    A textarea is the multi-line variant of the field, not a separate control:
    same label, hint, error and state handling, so the two can never drift apart.
--}}
<x-ui.input :rows="$rows" type="textarea" variant="textarea" {{ $attributes }}>
    {{ $slot }}
</x-ui.input>
