{{--
    Toast

    ONE toaster region per layout, at the top inline-end corner (the top RIGHT
    in RTL). Each toast disappears after 5 seconds and can be dismissed by hand.
    The region is `aria-live="polite"`, so a screen reader hears every message
    without losing its place (Article 18).

    Two ways in, and both carry text the server wrote:

      * Server flash — pass `:messages` from the session, rendered on first paint.
      * Client event — `$dispatch('ui-toast', { variant, title, text })` after an
        interaction. The payload always holds text a `__()` call produced; this
        component authors no copy of its own (Article 15).

    A toast is never the record of anything. A failure the user must act on is
    an inline error next to the control, not a message that vanishes.

    @see PRD §5.8, §5.9, §11.1 · CONSTITUTION Articles 15, 16, 18

    Props
      variant    default variant applied to a dispatched toast with none
      size       sm | md | lg      reserved; the toast is one size
      state      default           kept for interface symmetry
      messages   array of ['variant','title','text','sticky'] rendered at once
      duration   milliseconds before auto-dismiss (PRD: 5000)
--}}


<div
    {{ $attributes->class(['ui-toaster']) }}
    role="region"
    aria-label="{{ __('ui.toast.region') }}"
    aria-live="polite"
    x-data="uiToaster({ initial: @js($initial), duration: @js((int) $duration) })"
    x-on:ui-toast.window="push($event)"
>
    <template x-for="toast in toasts" x-bind:key="toast.id">
        <div
            class="ui-toast"
            x-bind:class="{
                'ui-toast--success': toast.variant === 'success',
                'ui-toast--warning': toast.variant === 'warning',
                'ui-toast--error': toast.variant === 'error',
                'ui-toast--info': toast.variant === 'info' || ! toast.variant,
                'is-leaving': toast.leaving
            }"
        >
            {{-- Meaning never rides on colour alone: each variant gets its icon. --}}
            <span class="ui-toast__icon" aria-hidden="true">
                <svg class="ui-icon" focusable="false" x-show="toast.variant === 'success'"><use href="#{{ $iconFor['success'] }}"/></svg>
                <svg class="ui-icon" focusable="false" x-show="toast.variant === 'warning' || toast.variant === 'error'"><use href="#{{ $iconFor['warning'] }}"/></svg>
                <svg class="ui-icon" focusable="false" x-show="toast.variant === 'info' || ! toast.variant"><use href="#{{ $iconFor['info'] }}"/></svg>
            </span>

            <div class="ui-toast__body">
                <strong class="ui-toast__title" x-show="toast.title" x-text="toast.title"></strong>
                <span class="ui-toast__text" x-show="toast.text" x-text="toast.text"></span>
            </div>

            <button
                type="button"
                class="ui-iconbtn ui-toast__close"
                aria-label="{{ __('ui.toast.dismiss') }}"
                x-on:click="dismiss(toast.id)"
            >
                <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-x"/></svg>
            </button>
        </div>
    </template>
</div>
