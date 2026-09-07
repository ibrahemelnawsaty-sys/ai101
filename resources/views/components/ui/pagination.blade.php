{{--
    Pagination

    Plain links, no JavaScript: paging is the server's job, and Article 19 makes
    it mandatory for any list past 50 rows. Every page number keeps the current
    query string (filters, sort, search), so paging never silently drops a
    filter the user set.

 The summary line ("showing 1 to 20 of 87") is read from lang/ar/ui.php with
    Latin numerals, and the whole strip is a labelled <nav> so a screen reader
    can jump straight to it (Article 15, Article 18).

    The sprite draws `i-chev` pointing LEFT and `i-chev-end` pointing RIGHT,
    which in an RTL flow already means "next" and "previous" respectively — so
    the mirroring utility is deliberately NOT applied here (Article 16).

    @see PRD §5.8, §5.9 · CONSTITUTION Articles 15, 16, 18, 19

    Props
      variant     default | simple      'simple' shows only previous/next
      size        sm | md | lg          reserved
      state       default | loading
      paginator   an Illuminate LengthAwarePaginator / Paginator
      onEachSide  numbered links either side of the current page
--}}


@if ($state === 'loading')
    <div {{ $attributes->class(['ui-pagination']) }} role="status" aria-live="polite">
        <span class="ui-sr">{{ __('ui.skeleton.label') }}</span>
        <span class="ui-sk ui-sk-chip" aria-hidden="true"></span>
    </div>

@elseif ($p !== null && ($hasPages || $total !== null))
    <nav {{ $attributes->class(['ui-pagination']) }} aria-label="{{ __('ui.pagination.label') }}">
        @if ($total !== null)
            <p class="ui-pagination__summary" aria-live="polite">
                @if ($total === 1)
                    {{ __('ui.pagination.summary_single') }}
                @else
                    {{ __('ui.pagination.summary', [
                        'from' => $firstItem ?? 0,
                        'to' => $lastItem ?? 0,
                        'total' => $total,
                    ]) }}
                @endif
            </p>
        @endif

        @if ($hasPages)
            <ul class="ui-pagination__list">
                <li>
                    @if ($p->onFirstPage())
                        <span class="ui-page" aria-disabled="true" aria-label="{{ __('ui.pagination.previous') }}">
                            <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-chev-end"/></svg>
                        </span>
                    @else
                        <a class="ui-page" href="{{ $p->previousPageUrl() }}" rel="prev" aria-label="{{ __('ui.pagination.previous') }}">
                            <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-chev-end"/></svg>
                        </a>
                    @endif
                </li>

                @foreach ($window as $page)
                    <li>
                        @if ($page === null)
                            <span class="ui-page ui-page--gap" aria-label="{{ __('ui.pagination.more') }}">…</span>
                        @elseif ($page === $current)
                            <a
                                class="ui-page ui-num"
                                href="{{ $p->url($page) }}"
                                aria-current="page"
                                aria-label="{{ __('ui.pagination.current', ['page' => $page]) }}"
                            >{{ $page }}</a>
                        @else
                            <a
                                class="ui-page ui-num"
                                href="{{ $p->url($page) }}"
                                aria-label="{{ __('ui.pagination.goto', ['page' => $page]) }}"
                            >{{ $page }}</a>
                        @endif
                    </li>
                @endforeach

                <li>
                    @if ($p->hasMorePages())
                        <a class="ui-page" href="{{ $p->nextPageUrl() }}" rel="next" aria-label="{{ __('ui.pagination.next') }}">
                            <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-chev"/></svg>
                        </a>
                    @else
                        <span class="ui-page" aria-disabled="true" aria-label="{{ __('ui.pagination.next') }}">
                            <svg class="ui-icon ui-icon--sm" aria-hidden="true" focusable="false"><use href="#i-chev"/></svg>
                        </span>
                    @endif
                </li>
            </ul>
        @endif
    </nav>
@endif
