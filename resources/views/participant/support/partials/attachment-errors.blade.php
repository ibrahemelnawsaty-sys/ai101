{{--
    The refusals of one form's files (D-124): the count is keyed on
    `attachments`, a refused file on `attachments.N` — which no field would
    print on its own. One line, the first reason, said where the files are.
--}}
@if ($errors->has('attachments') || $errors->has('attachments.*'))
    <p class="note note--bad" role="alert">
        <x-ui.icon name="warn" />
        <span>{{ $errors->first('attachments') ?: $errors->first('attachments.*') }}</span>
    </p>
@endif
