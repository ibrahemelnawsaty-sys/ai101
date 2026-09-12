{{--
    Sidebar rail, pinned to the RIGHT edge of the screen.

    264px open · 72px collapsed · a right-hand Drawer on mobile.
    Grouping follows PRD §9.5.1 exactly for the participant:
      overview   (dashboard, digital card, my journey)
      programme  (schedule, attendance, live sessions)
      work       (assignments, resources, final project, grades)
      contact    (messages)

    @see PRD §9.5.1 · CONSTITUTION Article 16 · PROJECT-CONTRACT §10

    NOTE ON AUTHORISATION
    The visible menu is a *reflection* of permission, never its source.  Every
    route it links to enforces its own middleware, policy and query scope on the
    server (Constitution Article 5).  Hiding an item here protects nothing and
    is not relied upon.

    NOTE ON TRAINER / ADMIN GROUPING
    PRD §9.5.1 specifies the participant menu only, and PROJECT-CONTRACT §10
    names `/trainer/*` and `/admin/*` as wildcards with no individual route
    names.  Rather than invent a structure (Constitution Article 4), those roles
    render the `:groups` array their controller passes in.  Escalated as D-30 in
    docs/03-decisions/DECISIONS.md.

    Props
      groups   optional explicit structure; overrides the participant default
      badges   ['assignments' => int, 'messages' => int]
      cohort   current cohort name, printed at the foot of the rail
      cohorts  other cohorts the user belongs to, for the switcher
      drawer   render as the mobile drawer panel instead of the fixed rail
--}}


<{{ $tag }}
    {{ $attributes->class([$wrapperClass]) }}
    @if (! $drawer) aria-label="{{ __('nav.chrome.sidebar_label') }}" @endif
    @if ($drawer) x-ref="panel" role="dialog" aria-modal="true" aria-label="{{ __('nav.chrome.sidebar_label') }}" @endif
>
    <div class="side__brand">
        {{-- Two logos, one shown at a time by CSS on `[data-collapsed]`.
             The rail is 72px when collapsed and the wordmark is far wider than
             that, so it used to push the collapse button out of the rail — and
             the button is the only way back. The rule meant to hide it targeted
             `.side__brand .logo` while `x-ui.logo` renders `ui-logo`, so it
             never matched anything (D-65).

             Swapped rather than hidden: a rail with no mark at all reads as a
             column of loose icons belonging to nothing. --}}
        <x-ui.logo variant="wordmark" size="xs" tone="purple" class="side__wordmark" />
        <x-ui.logo variant="mark" size="xs" tone="purple" class="side__mark" />

        @if ($drawer)
            <button type="button" class="side__collapse" x-on:click="hide()">
                <svg aria-hidden="true"><use href="#i-x"></use></svg>
                <span class="sr">{{ __('nav.chrome.close_menu') }}</span>
            </button>
        @else
            <button
                type="button"
                class="side__collapse"
                x-on:click="toggle()"
                x-bind:aria-expanded="(! collapsed).toString()"
                aria-controls="{{ $navId }}"
            >
                <svg aria-hidden="true"><use href="#i-panel"></use></svg>
                <span class="sr">{{ __('nav.chrome.toggle_sidebar') }}</span>
            </button>
        @endif
    </div>

    <nav id="{{ $navId }}" class="side__nav">
        @forelse ($resolvedGroups as $group)
            <div class="side__g">
                @isset($group['label'])
                    <p class="side__t">{{ $group['label'] }}</p>
                @endisset

                <ul>
                    @foreach ($group['items'] as $item)
                        <li>
                            <a
                                href="{{ $item['locked'] ? '#' : $item['href'] }}"
                                class="side__b"
                                @if ($item['active']) aria-current="page" @endif
                                @if ($item['locked']) data-locked="true" aria-disabled="true" tabindex="-1" @endif
                            >
                                <svg aria-hidden="true">
                                    <use href="#{{ $item['locked'] ? 'i-lock' : $item['icon'] }}"></use>
                                </svg>
                                <span class="side__label">{{ $item['label'] }}</span>

                                @if ($item['badgeCount'] > 0)
                                    <span class="side__badge">
                                        <span class="u-num">{{ $item['badgeLabel'] }}</span>
                                        <span class="sr">{{ __('nav.chrome.badge_hint') }}</span>
                                    </span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @empty
            {{-- Empty state: the rail has nothing to show yet (Article 17). --}}
            <div class="empty">
                <span class="empty__ic"><svg aria-hidden="true"><use href="#i-compass"></use></svg></span>
                <b>{{ __('nav.chrome.empty_title') }}</b>
                <p>{{ __('nav.chrome.empty_body') }}</p>
            </div>
        @endforelse
    </nav>

    @if ($cohort)
        <div class="side__foot">
            <b>{{ $cohort }}</b>
            <span>{{ __('nav.chrome.current_cohort') }}</span>

            {{-- `POST /dashboard/cohort` → `cohort.switch`, PROJECT-CONTRACT §10.
                 The route guard lives in the component class: the switcher is
                 pointless with a single cohort, and the check costs nothing.
                 A plain form with a submit button. It auto-submitted from an
                 inline onchange, which the CSP blocks, which fires on a keyboard
                 arrow before the choice is made, and which left the form
                 unsendable without JavaScript (D-75). --}}
            @if ($showSwitcher)
                <form method="POST" action="{{ $switchUrl }}">
                    @csrf
                    <label class="sr" for="{{ $switchId }}">{{ __('nav.chrome.switch_cohort') }}</label>
                    <select id="{{ $switchId }}" name="cohort_id" class="side__switch">
                        @foreach ($cohorts as $option)
                            <option value="{{ $option['id'] }}" @selected($option['is_current'] ?? false)>
                                {{ $option['name'] }}
                            </option>
                        @endforeach
                    </select>
                    <x-ui.button type="submit" variant="secondary" size="sm">{{ __('nav.chrome.switch_cohort') }}</x-ui.button>
                </form>
            @endif
        </div>
    @endif
</{{ $tag }}>
