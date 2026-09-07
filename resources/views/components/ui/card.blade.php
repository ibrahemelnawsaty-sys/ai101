{{--
    Card

    The container every dashboard panel sits in, and the place where the four
    mandatory states of Article 17 are actually enforced: pass `state` and the
    card renders the right one. Each state's copy is passed in by the screen —
    the fallbacks below are generic on purpose, so a screen that forgets its own
    empty text is obvious in review rather than invisible.

    CSS gives every direct child of the card its padding, so the header, body
    and footer are siblings and nothing double-pads.

    @see PRD §5.8, §5.9, §11.1 · CONSTITUTION Articles 15, 16, 17, 18

    Props
      variant   default | flat | interactive | brand | raised
      size      sm | md | lg              padding scale
      state     default | loading | empty | error
      title     header title
      subtitle  header subtitle
      icon      sprite id shown before the title
      href      makes the whole card a link (adds the interactive treatment)
      flush     removes the body padding — for a card whose only child is a
                full-bleed table or list that must reach the card's edges

    Slots
      header    replaces the generated header entirely
      action    controls pinned to the header's inline-end
      footer    footer row
      empty     the screen's own empty copy      (state="empty")
      error     the screen's own error copy      (state="error")
      loading   the screen's own skeleton shape  (state="loading")
--}}


<{{ $tag }}
    @if ($href !== null) href="{{ $href }}" @endif
    @if ($headingId) aria-labelledby="{{ $headingId }}" @endif
    {{ $attributes->class([
        'ui-card',
        'ui-card--' . $size,
        'ui-card--' . $variant => $variant !== 'default',
        'ui-card--interactive' => $href !== null,
        'ui-card--flush' => $isFlush,
    ]) }}
>
    @if ($title !== null || isset($header) || isset($action))
        <div class="ui-card__header">
            @if (isset($header))
                {{ $header }}
            @else
                @if ($icon)
                    <svg class="ui-icon" aria-hidden="true" focusable="false"><use href="#{{ str_starts_with($icon, 'i-') ? $icon : 'i-'.$icon }}"/></svg>
                @endif
                @if ($title !== null)
                    <h2 class="ui-card__title" id="{{ $headingId }}">
                        {{ $title }}
                        @if ($subtitle !== null)
                            <span class="ui-card__subtitle">{{ $subtitle }}</span>
                        @endif
                    </h2>
                @endif
                @isset($action)
                    <div class="ui-card__actions">{{ $action }}</div>
                @endisset
            @endif
        </div>
    @endif

    <div class="ui-card__body">
        @if ($state === 'loading')
            <div role="status" aria-live="polite">
                <span class="ui-sr">{{ __('ui.skeleton.label') }}</span>
                @isset($loading)
                    {{ $loading }}
                @else
                    <x-ui.skeleton shape="stack" :lines="3" />
                @endisset
            </div>

        @elseif ($state === 'empty')
            @isset($empty)
                {{ $empty }}
            @else
                <x-ui.empty-state
                    size="sm"
                    icon="i-folder"
                    :title="__('app.states.empty_title')"
                    :description="__('app.states.empty_body')"
                />
            @endisset

        @elseif ($state === 'error')
            <div role="alert">
                @isset($error)
                    {{ $error }}
                @else
                    <x-ui.empty-state
                        size="sm"
                        variant="error"
                        icon="i-warn"
                        :title="__('app.states.error_title')"
                        :description="__('app.states.error_body')"
                    />
                @endisset
            </div>

        @else
            {{ $slot }}
        @endif
    </div>

    @isset($footer)
        <div class="ui-card__footer">{{ $footer }}</div>
    @endisset
</{{ $tag }}>
