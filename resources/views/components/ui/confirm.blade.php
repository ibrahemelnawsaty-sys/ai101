{{--
    Confirm

    The one confirmation step (D-127): a dialog that names the action, says who
    it reaches and what cannot be undone, and offers two buttons named for it.
    Built on <x-ui.modal>, so it closes on Escape, traps Tab, and hands focus
    back to whatever opened it.

    A destructive row action is drawn as a QUIET trigger (danger-ghost, or the
    bare icon) and the SOLID danger button lives only here, in the footer, where
    the person has already been told what it does. The wording of both is a
    natural noun phrase (a verbal noun such as "suspension of the account"), never
    a command ("suspend the account") — the owner's rule, D-128.

        <x-ui.confirm
            name="suspend-{{ $user->id }}"
            :action="route('some.route', $user->id)"
            :title="__('some.title', ['name' => $user->name])"
            :description="__('some.body')"
            :confirm-label="__('some.action')"
            :trigger-label="__('some.action')" trigger-icon="lock"
            reason-name="reason" />

    Not a permission boundary: the form is still validated by its FormRequest and
    authorised by its Policy on the server (Article 5).

    @see D-127 · PRD §5.8 · CONSTITUTION Articles 5, 15, 17, 18

    Props
      name           dialog identifier (unique on the page)
      action         where the form posts
      method         POST | PUT | PATCH | DELETE
      title          says what is about to happen — it is the accessible name (falls back to
                     the confirm label so the dialog is never unnamed)
      description    one line under the title: who is reached, what is irreversible
      confirm-label  the confirming button, named for the action
      cancel-label   default: the shared "go back" string (ui.confirm.cancel)
      variant        danger | primary
      icon           the dialog's badge (default: warn for danger, info otherwise)
      confirm-icon   glyph on the confirming button
      trigger-label  when given, the component draws its own trigger
      trigger-icon · trigger-variant · trigger-size · trigger-icon-only
      reason-name    when given, asks for a reason (a textarea named so)
      reason-label · reason-hint · reason-required
      reason-value   what was typed, when a failed validation re-renders this dialog — the
                     caller passes it with `open` for THAT dialog only; the component
                     cannot know which of several rows the error belongs to
      open           render already open (a server-rendered confirmation)
      close-href     Cancel becomes this link (clears the query string that opened it)

    Slot: extra body — the consequences list, or hidden fields.
--}}

@if ($triggerLabel !== null)
    <x-ui.button
        :variant="$triggerVariant"
        :size="$triggerSize"
        :icon="$triggerIcon"
        :icon-only="$iconOnlyTrigger"
        :label="$triggerLabel"
        type="button"
        x-on:click="$dispatch('ui-dialog-open', '{{ $uid }}')"
    >{{ $triggerLabel }}</x-ui.button>
@endif

<x-ui.modal
    :name="$uid"
    :title="$title"
    :description="$description"
    :icon="$icon"
    :variant="$variant === 'danger' ? 'danger' : 'default'"
    size="sm"
    :open="$open"
    :close-href="$closeHref"
>
    <form method="POST" action="{{ $action }}" id="{{ $formId }}" class="ui-confirm__form">
        @csrf
        @if ($spoof)
            @method($spoof)
        @endif

        {{ $slot }}

        @if ($reasonName !== null)
            <x-ui.textarea
                :name="$reasonName"
                :id="$formId.'-reason'"
                :label="$reasonLabel"
                :hint="$reasonHint"
                :value="$reasonValue ?? ''"
                :required="$reasonRequired"
                :rows="3"
            />
        @endif
    </form>

    <x-slot:footer>
        {{-- Cancel first, the action last: in RTL the action then sits at the
             far end of the row, away from where the thumb rests (modal docs). --}}
        @if ($closeHref !== null)
            <x-ui.button variant="ghost" :href="$closeHref">{{ $cancelLabel }}</x-ui.button>
        @else
            <x-ui.button variant="ghost" type="button" x-on:click="$dispatch('ui-dialog-close', '{{ $uid }}')">{{ $cancelLabel }}</x-ui.button>
        @endif

        <x-ui.button :variant="$variant" type="submit" form="{{ $formId }}" :icon="$confirmIcon">{{ $confirmLabel }}</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
