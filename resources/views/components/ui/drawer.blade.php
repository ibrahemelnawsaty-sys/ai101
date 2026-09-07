{{--
    Drawer

    The same dialog engine as <x-ui.modal>: Escape, outside click, focus trap,
    focus restored to the trigger. The panel is pinned to the inline-END edge,
    which is the RIGHT in RTL — matching the sidebar it stands in for on mobile
    (Article 16).

    Opening from anywhere on the page:

        <button x-on:click="$dispatch('ui-dialog-open', 'mobile-nav')">…</button>
        <x-ui.drawer name="mobile-nav" :title="__('nav.groups.overview')">…</x-ui.drawer>

    @see PRD §5.8, §5.9, §9.5.1 · CONSTITUTION Articles 5, 15, 16, 17, 18

    Props
      variant       end | start          which edge the panel is pinned to
      size          sm | md | lg
      state         default | loading
      name          identifier used by the open/close events
      title         panel title — the accessible name
      open          render already open
      dismissible   allow Escape and outside click (default: true)

    Slots
      $slot     body
      footer    action row
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
        @class([
            'ui-drawer',
            'ui-drawer--' . $size => $size !== 'md',
            'ui-drawer--start' => $variant === 'start',
        ])
        x-show="open"
        x-cloak
        x-on:keydown="onKeydown($event)"
        {{ $attributes->except('class') }}
    >
        <div class="ui-drawer__backdrop" x-on:click="onBackdrop()" aria-hidden="true"></div>

        <div
            class="ui-drawer__panel"
            role="dialog"
            aria-modal="true"
            aria-labelledby="{{ $titleId }}"
            tabindex="-1"
            x-ref="panel"
        >
            <div class="ui-drawer__header">
                <h2 class="ui-drawer__title" id="{{ $titleId }}">{{ $title }}</h2>

                @if ($dismissible)
                    <button
                        type="button"
                        class="ui-iconbtn ui-drawer__close"
                        aria-label="{{ __('ui.dialog.close_drawer') }}"
                        x-on:click="hide()"
                    >
                        <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-x"/></svg>
                    </button>
                @endif
            </div>

            <div class="ui-drawer__body">
                @if ($state === 'loading')
                    <div role="status" aria-live="polite">
                        <span class="ui-sr">{{ __('ui.skeleton.label') }}</span>
                        <x-ui.skeleton shape="stack" :lines="4" />
                    </div>
                @else
                    {{ $slot }}
                @endif
            </div>

            @isset($footer)
                <div class="ui-drawer__footer">{{ $footer }}</div>
            @endisset
        </div>
    </div>
</div>
