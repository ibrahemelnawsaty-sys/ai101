{{--
    Tabs

    A WAI-ARIA tablist with a roving tabindex, arrow-key navigation (mirrored in
    RTL: the right arrow moves to the PREVIOUS tab) and an indicator that slides
    under the selected tab. All of that keyboard logic lives in `uiTabs` in
    resources/js/ui.js.

    The component renders only the tab strip. Panels are authored by the screen
    so each can carry its own four states:

        <x-ui.tabs :tabs="$tabs" active="schedule">
            <div class="ui-tabs__panel" role="tabpanel" id="panel-schedule"
                 aria-labelledby="tab-schedule" x-show="isActive('schedule')">
                …
            </div>
        </x-ui.tabs>

    Tabs never gate anything: a tab the user may not open is not rendered at all,
    because hiding a control is not authorisation (Article 5).

    @see PRD §5.8, §5.9 · CONSTITUTION Articles 5, 15, 16, 17, 18

    Props
      variant   line | pills
      size      sm | md | lg               reserved; the strip is one size
      state     default | loading
      tabs      array of ['name','label','badge','disabled']
      active    the name of the tab open on first paint (server-decided)
      label     accessible name for the tablist
--}}
@props([
    'variant' => 'line',
    'size' => 'md',
    'state' => 'default',
    'tabs' => [],
    'active' => null,
    'label' => null,
])

@php
    $items = array_values(is_array($tabs) ? $tabs : []);
    $first = $items[0]['name'] ?? null;
    $activeName = $active ?? $first;
    $listLabel = $label ?? __('ui.tabs.label');
@endphp

@if ($state === 'loading')
    <div {{ $attributes->class(['ui-tabs']) }} role="status" aria-live="polite">
        <span class="ui-sr">{{ __('ui.skeleton.label') }}</span>
        <div class="ui-tabs__list" aria-hidden="true">
            @for ($i = 0; $i < 3; $i++)
                <span class="ui-sk ui-sk-chip" style="margin-inline-end: var(--s3);"></span>
            @endfor
        </div>
    </div>
@else
    <div
        {{ $attributes->class(['ui-tabs', 'ui-tabs--pills' => $variant === 'pills']) }}
        x-data="uiTabs({ active: @js($activeName) })"
    >
        <div
            class="ui-tabs__list"
            role="tablist"
            aria-label="{{ $listLabel }}"
            x-ref="list"
            x-on:keydown="onKeydown($event)"
        >
            @foreach ($items as $tab)
                @php
                    $name = (string) ($tab['name'] ?? '');
                    $isActive = $name === $activeName;
                    $disabled = ! empty($tab['disabled']);
                @endphp
                <button
                    type="button"
                    class="ui-tab"
                    role="tab"
                    id="tab-{{ $name }}"
                    data-tab="{{ $name }}"
                    aria-controls="panel-{{ $name }}"
                    aria-selected="{{ $isActive ? 'true' : 'false' }}"
                    tabindex="{{ $isActive ? '0' : '-1' }}"
                    @disabled($disabled)
                    x-bind:aria-selected="isActive(@js($name)) ? 'true' : 'false'"
                    x-bind:tabindex="isActive(@js($name)) ? 0 : -1"
                    x-on:click="select(@js($name))"
                >
                    @if (! empty($tab['icon']))
                        <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#{{ str_starts_with($tab['icon'], 'i-') ? $tab['icon'] : 'i-'.$tab['icon'] }}"/></svg>
                    @endif
                    <span>{{ $tab['label'] ?? '' }}</span>
                    @if (isset($tab['badge']) && $tab['badge'] !== null && $tab['badge'] !== '')
                        <span class="ui-badge ui-badge--count ui-num">{{ $tab['badge'] }}</span>
                    @endif
                </button>
            @endforeach

            <span class="ui-tabs__indicator" x-ref="indicator" aria-hidden="true"></span>
        </div>

        {{ $slot }}
    </div>
@endif
