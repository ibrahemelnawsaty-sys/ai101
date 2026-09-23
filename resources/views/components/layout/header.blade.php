{{--
    Dashboard app bar.

    @see PRD §9.5.2 · CONSTITUTION Articles 5, 16, 17, 18, 23

    The bar is chrome, not authorisation. The notification count it shows and
    the links it offers are a reflection of what the server already decided;
    every target route re-checks the session, the role and the resource scope
    on its own (Article 5).

    Props
      title     string  the page heading, from @section('title')
      subtitle  string  the line under it, from @section('subtitle')
      badges    array   ['assignments' => int, 'messages' => int]
      unread    int     unread notifications
--}}


<header class="appbar">

    {{-- Mobile: opens the navigation drawer. It is hidden on desktop because
         the rail is already there, never because permission differs. --}}
    <button type="button"
            class="icb appbar__burger"
            x-on:click="$dispatch('athar-drawer')"
            aria-label="{{ __('nav.chrome.open_menu') }}">
        <svg aria-hidden="true"><use href="#i-menu"></use></svg>
    </button>

    <div class="appbar__t">
        @if ($heading !== '')
            <h1>{{ $heading }}</h1>
        @endif
        @if ($sub !== '')
            <span>{{ $sub }}</span>
        @endif
    </div>

    <div class="appbar__r">

        @if ($notificationsUrl !== null)
            {{-- The count is IN the name: an aria-label replaces the link's
                 content, so the hidden «unread» text beside the number was never
                 announced — a screen reader heard «Notifications» either way. --}}
            <a href="{{ $notificationsUrl }}"
               class="icb"
               aria-label="{{ $unreadCount > 0
                   ? trans_choice('app.accessibility.notifications_bell_unread', $unreadCount, ['count' => $unreadLabel])
                   : __('app.accessibility.notifications_bell') }}">
                <svg aria-hidden="true"><use href="#i-bell"></use></svg>
                @if ($unreadCount > 0)
                    <span class="icb__n">
                        <span class="u-num" aria-hidden="true">{{ $unreadLabel }}</span>
                        <span class="sr">{{ __('nav.badges.unread_notifications') }}</span>
                    </span>
                @endif
            </a>
        @endif

        @if ($showMessages)
            <a href="{{ $messagesUrl }}"
               class="icb"
               aria-label="{{ __('app.accessibility.messages_unread') }}">
                <svg aria-hidden="true"><use href="#i-chat"></use></svg>
                <span class="icb__d" aria-hidden="true"></span>
                <span class="sr">{{ __('nav.badges.unread_messages') }}</span>
            </a>
        @endif

        {{-- Account menu: Escape closes it, focus returns to the trigger. --}}
        <div class="appbar__menu"
             x-data="menu()"
             x-on:keydown.escape="close()"
             x-on:click.outside="close(false)">

            <button type="button"
                    class="icb"
                    x-on:click="toggle()"
                    x-bind:aria-expanded="open.toString()"
                    aria-controls="account-menu"
                    aria-label="{{ __('app.accessibility.user_menu') }}">
                <span class="av" aria-hidden="true">{{ $initials }}</span>
            </button>

            {{-- A disclosure of links, not an ARIA menu: role="menu" promises
                 arrow-key navigation that nothing implemented, so screen readers
                 announced a menu that did not behave like one (D-86). --}}
            <div class="menu"
                 id="account-menu"
                 x-ref="panel"
                 x-show="open"
                 x-cloak
                 x-transition.opacity.duration.150ms>

                <div class="menu__head">
                    <b>{{ $displayName }}</b>
                    {{-- D-108 — the same role badge as the sidebar footer;
                         text always rides with the colour (Article 18). --}}
                    <x-ui.badge size="sm" :variant="$roleVariant">{{ $roleLabel }}</x-ui.badge>
                    <span class="menu__email" dir="ltr">{{ $email }}</span>
                </div>

                @if ($profileUrl !== null)
                    <a href="{{ $profileUrl }}" class="menu__i">
                        <svg aria-hidden="true"><use href="#i-user"></use></svg>
                        <span>{{ __('nav.chrome.account') }}</span>
                    </a>
                @endif

                @if ($cardUrl !== null)
                    <a href="{{ $cardUrl }}" class="menu__i">
                        <svg aria-hidden="true"><use href="#i-card"></use></svg>
                        <span>{{ __('nav.participant.card') }}</span>
                    </a>
                @endif
            </div>
        </div>
    </div>
</header>
