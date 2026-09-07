{{--
    Table

    A data table that reflows into cards under 720px: every cell carries a
    `data-label`, so the column heading survives the reflow instead of leaving
    a wall of unlabelled values.

    Sorting is a link, not a script: each sortable heading is an <a> to the same
    route with `sort` and `dir` in the query string, so the SERVER sorts and
    scopes the query (Article 5, Article 22). Nothing is sorted in the browser.

    All four states are handled here (Article 17). The screen supplies its own
    empty and error copy through the slots; the fallbacks are deliberately
    generic so a screen that forgot its copy is visible in review.

    Row markup is the caller's, because only the screen knows what a row means:

        <x-ui.table :columns="$columns" :sort="$sort" :dir="$dir">
            @foreach ($rows as $row)
                <tr>
                    <td data-label="{{ $columns[0]['label'] }}">…</td>
                </tr>
            @endforeach
        </x-ui.table>

    @see PRD §5.8, §5.9 · CONSTITUTION Articles 5, 15, 16, 17, 18, 19, 22

    Props
      variant   default | cards          'cards' enables the mobile reflow
      size      sm | md | lg             reserved
      state     default | loading | empty | error
      columns   array of ['key','label','sortable','numeric','align']
      sort      the column key the SERVER sorted by
      dir       'asc' | 'desc' — the direction the SERVER applied
      caption   a caption read before the table
      rowCount  how many skeleton rows to draw while loading

    Slots
      toolbar   search / filters above the table
      footer    pagination under the table
      empty     the screen's own empty copy
      error     the screen's own error copy
--}}
@props([
    'variant' => 'cards',
    'size' => 'md',
    'state' => 'default',
    'columns' => [],
    'sort' => null,
    'dir' => 'asc',
    'caption' => null,
    'rowCount' => 5,
])

@php
    $cols = array_values(is_array($columns) ? $columns : []);
    $dir = $dir === 'desc' ? 'desc' : 'asc';
    $rowCount = max(1, min(12, (int) $rowCount));
@endphp

<div {{ $attributes->class(['ui-table-wrap']) }}>
    @isset($toolbar)
        <div class="ui-table__toolbar">{{ $toolbar }}</div>
    @endisset

    @if ($state === 'loading')
        <div class="ui-sk-table" role="status" aria-live="polite">
            <span class="ui-sr">{{ __('ui.skeleton.label') }}</span>
            @for ($r = 0; $r < $rowCount; $r++)
                <div class="ui-sk-table__row" aria-hidden="true">
                    @foreach ($cols as $column)
                        <span class="ui-sk ui-sk-table__cell"></span>
                    @endforeach
                    @if (count($cols) === 0)
                        <span class="ui-sk ui-sk-table__cell"></span>
                        <span class="ui-sk ui-sk-table__cell"></span>
                        <span class="ui-sk ui-sk-table__cell"></span>
                    @endif
                </div>
            @endfor
        </div>

    @elseif ($state === 'empty')
        @isset($empty)
            {{ $empty }}
        @else
            <x-ui.empty-state
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
                    variant="error"
                    icon="i-warn"
                    :title="__('app.states.error_title')"
                    :description="__('app.states.error_body')"
                />
            @endisset
        </div>

    @else
        <div class="ui-table-scroll">
            <table class="ui-table @if ($variant === 'cards') ui-table--cards @endif">
                @if ($caption !== null)
                    <caption>{{ $caption }}</caption>
                @endif

                <thead>
                    <tr>
                        @foreach ($cols as $column)
                            @php
                                $key = (string) ($column['key'] ?? '');
                                $isSorted = $sort !== null && $sort === $key;
                                $nextDir = $isSorted && $dir === 'asc' ? 'desc' : 'asc';
                                $numeric = ! empty($column['numeric']);
                            @endphp
                            <th
                                scope="col"
                                @class(['ui-table__num' => $numeric, 'ui-table__actions' => ($column['align'] ?? null) === 'end'])
                                @if ($isSorted) aria-sort="{{ $dir === 'asc' ? 'ascending' : 'descending' }}" @endif
                            >
                                @if (! empty($column['sortable']))
                                    <a
                                        class="ui-table__sort"
                                        data-dir="{{ $isSorted ? $dir : '' }}"
                                        href="{{ request()->fullUrlWithQuery(['sort' => $key, 'dir' => $nextDir, 'page' => 1]) }}"
                                        aria-label="{{ __('ui.table.sort_by', ['column' => $column['label'] ?? $key]) }}"
                                    >
                                        <span>{{ $column['label'] ?? $key }}</span>
                                        <svg class="ui-icon" aria-hidden="true" focusable="false"><use href="#i-chevdown"/></svg>
                                        @if ($isSorted)
                                            <span class="ui-sr">{{ $dir === 'asc' ? __('ui.table.sorted_ascending') : __('ui.table.sorted_descending') }}</span>
                                        @endif
                                    </a>
                                @else
                                    {{ $column['label'] ?? $key }}
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody>
                    {{ $slot }}
                </tbody>
            </table>
        </div>
    @endif

    @isset($footer)
        <div class="ui-table__footer">{{ $footer }}</div>
    @endisset
</div>
