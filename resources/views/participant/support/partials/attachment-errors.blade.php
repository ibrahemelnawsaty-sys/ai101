{{--
    The refusal of one file (D-124), keyed on `attachments.N`, which no field
    prints on its own. The count, keyed on `attachments`, the uploader
    prints itself — said once, not twice. One line, the first reason.
--}}
@if (! $errors->has('attachments') && $errors->has('attachments.*'))
    <p class="note note--bad" role="alert">
        <x-ui.icon name="warn" />
        <span>{{ $errors->first('attachments.*') }}</span>
    </p>
@endif
