{{--
    SearchInput

    A search box that waits 300ms after the last keystroke before it acts, and a
    clear button that empties it and returns focus to the field. Both live in
    `uiSearch` in resources/js/ui.js.

    Searching is the SERVER's job: with `auto-submit` the debounce submits the
    surrounding GET form, so the query is scoped, filtered and paginated in PHP
    and the result is a real URL the user can bookmark and share. Without it the
    component only dispatches `ui-search` and the screen decides what to do.

    With no JavaScript the field is still a plain form control: press Enter and
    the form submits. Nothing here is required for search to work.

    @see PRD §5.8, §5.9 · CONSTITUTION Articles 5, 15, 16, 18, 19, 22

    Props
      variant      default | compact
      size         sm | md | lg     reserved
      state        default | loading | disabled
      name         query parameter name (default: q)
      value        the term the SERVER searched for
      autoSubmit   submit the surrounding form after the debounce
      delay        debounce in milliseconds (PRD: 300)
      status       result summary announced politely under the field
--}}


@if ($state === 'loading')
    <div {{ $attributes->class(['ui-search']) }} role="status" aria-live="polite">
        <span class="ui-sr">{{ __('ui.skeleton.label') }}</span>
        <div class="ui-sk" style="block-size: var(--touch); border-radius: var(--r-md);" aria-hidden="true"></div>
    </div>
@else
    <div
        {{ $attributes->class(['ui-search']) }}
        x-data="uiSearch({
            value: @js((string) $current),
            delay: @js((int) $delay),
            autoSubmit: @js((bool) $autoSubmit)
        })"
    >
        <label class="ui-sr" for="{{ $fieldId }}">{{ $fieldLabel }}</label>

        <span class="ui-search__icon" aria-hidden="true">
            <svg class="ui-icon ui-icon--sm" focusable="false"><use href="#i-search"/></svg>
        </span>

        <input
            type="search"
            class="ui-input"
            id="{{ $fieldId }}"
            name="{{ $name }}"
            value="{{ $current }}"
            placeholder="{{ $placeholder ?? __('app.common.search_placeholder') }}"
            autocomplete="off"
            aria-describedby="{{ $statusId }}"
            @disabled($isDisabled)
            x-ref="input"
            x-model="value"
            x-on:input="onInput()"
            x-on:keydown.enter="onEnter($event)"
            {{ $attributes->except('class') }}
        >

        <button
            type="button"
            class="ui-input-wrap__action"
            aria-label="{{ __('ui.search.clear') }}"
            x-show="value.length > 0"
            x-cloak
            x-on:click="clear()"
        >
            <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-x"/></svg>
        </button>

        {{-- The result count is written by the server after it ran the query. --}}
        <p class="ui-search__status" id="{{ $statusId }}" aria-live="polite">
            {{ $status ?? __('ui.search.typing_hint') }}
        </p>
    </div>
@endif
