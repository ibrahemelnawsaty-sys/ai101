{{--
    Modal

    Closes on Escape, closes on a click outside the panel, traps Tab and
    Shift+Tab inside the panel, and hands focus back to whatever opened it.
    All of that lives in `uiDialog` in resources/js/ui.js.

    Opening from anywhere on the page:

        <x-ui.button x-on:click="$dispatch('ui-dialog-open', 'confirm-withdraw')">…</x-ui.button>
        <x-ui.modal name="confirm-withdraw" :title="__('…')">…</x-ui.modal>

    A modal is never a permission boundary. A destructive action confirmed here
    is still checked by a Policy and a FormRequest on the server (Article 5).

    @see PRD §5.8, §5.9 · CONSTITUTION Articles 5, 15, 16, 17, 18

    Props
      variant       default | danger | warning | success
      size          sm | md | lg
      state         default | loading
      name          the identifier used by the open/close events
      title         dialog title — required, it is the accessible name
      description   one line under the title
      icon          sprite id shown in the leading badge
      open          render already open (a server-rendered confirmation step)
      dismissible   allow Escape and outside click (default: true)

    Slots
      $slot     body
      footer    action row — the primary action comes LAST in RTL reading order
--}}


<div
    x-data="uiDialog({
        name: @js($name),
        open: @js((bool) $open),
        closeOnBackdrop: @js((bool) $dismissible),
        closeOnEscape: @js((bool) $dismissible)
    })"
    x-on:ui-dialog-open.window="openFromEvent($event)"
    x-on:ui-dialog-close.window="closeFromEvent($event)"
    x-on:keydown.escape.window="onEscape()"
>
    <div
        class="ui-modal ui-modal--{{ $size }} @if ($variant !== 'default') ui-modal--{{ $variant }} @endif"
        x-show="open"
        x-cloak
        x-on:keydown="onKeydown($event)"
        {{ $attributes->except('class') }}
    >
        <div class="ui-modal__backdrop" x-on:click="onBackdrop()" aria-hidden="true"></div>

        <div
            class="ui-modal__panel"
            role="dialog"
            aria-modal="true"
            aria-labelledby="{{ $titleId }}"
            @if ($descId) aria-describedby="{{ $descId }}" @endif
            tabindex="-1"
            x-ref="panel"
        >
            <div class="ui-modal__header">
                @if ($icon)
                    <span class="ui-modal__icon" aria-hidden="true">
                        <svg class="ui-icon" focusable="false"><use href="#{{ str_starts_with($icon, 'i-') ? $icon : 'i-'.$icon }}"/></svg>
                    </span>
                @endif

                <div class="ui-modal__heading">
                    <h2 class="ui-modal__title" id="{{ $titleId }}">{{ $title }}</h2>
                    @if ($description !== null)
                        <p class="ui-modal__desc" id="{{ $descId }}">{{ $description }}</p>
                    @endif
                </div>

                @if ($dismissible)
                    <button
                        type="button"
                        class="ui-iconbtn ui-modal__close"
                        aria-label="{{ __('ui.dialog.close') }}"
                        x-on:click="hide()"
                    >
                        <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-x"/></svg>
                    </button>
                @endif
            </div>

            <div class="ui-modal__body">
                @if ($state === 'loading')
                    <div role="status" aria-live="polite">
                        <span class="ui-sr">{{ __('ui.skeleton.label') }}</span>
                        <x-ui.skeleton shape="stack" :lines="3" />
                    </div>
                @else
                    {{ $slot }}
                @endif
            </div>

            @isset($footer)
                <div class="ui-modal__footer">{{ $footer }}</div>
            @endisset
        </div>
    </div>
</div>
