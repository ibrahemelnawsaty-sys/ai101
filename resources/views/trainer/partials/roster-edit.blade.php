{{--
    One roster row's edit control. A row exists once somebody checks in, or once
    the sweep or a bulk mark writes one; before that there is nothing to correct,
    and the panel refuses to open — so the button said Edit and did nothing
    (D-72). Drawn by the page and by the live refresh alike.
    Variables: $entry  App\Presenters\Trainer\RosterEntry
    @see BR-10 · PRD §9.9.7 · D-72
--}}
@if ($entry->canEdit)
    <x-ui.button variant="secondary" size="sm" :href="$entry->editHref">{{ __('app.edit') }}</x-ui.button>
@else
    <span class="u-muted">{{ __('trainer.attendance.edit_needs_record') }}</span>
@endif
