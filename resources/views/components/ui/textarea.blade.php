@props(['rows' => 4, 'value' => null, 'label' => null, 'hint' => null, 'error' => null, 'placeholder' => null])

{{--
    A textarea is the multi-line variant of the field, not a separate control:
    same label, hint, error and state handling, so the two can never drift apart.

    D-127 — the TEXT props are handed to the field as values, never through
    `{{ $attributes }}`: that echo escapes them once on the way in and the
    field escapes them again on the way out, so "R&D" came back as "R&amp;D"
    and a saved brief with a quotation mark grew an entity on every save. An
    HTML page in the guide editor showed as "&lt;!DOCTYPE …" and would have
    been saved that way.
--}}
<x-ui.input :rows="$rows" :value="$value" :label="$label" :hint="$hint" :error="$error" :placeholder="$placeholder"
    type="textarea" variant="textarea" {{ $attributes }}>
    {{ $slot }}
</x-ui.input>
