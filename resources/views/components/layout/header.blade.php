{{--
    Dashboard app bar.

    @see PRD §9.5.2 · D-86, D-108, D-123 · CONSTITUTION Articles 5, 16, 17, 18, 23

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

        {{-- D-123 — the account menu, as the owner drew it: who is signed in on
             the button, then the account's own pages, then the way out.
             Escape closes it and returns focus to the button; a click outside
             or focus leaving it closes it too; the arrow keys walk its entries. --}}
        <div class="appbar__menu"
             x-data="menu()"
             x-on:keydown.escape="close()"
             x-on:keydown="move($event)"
             x-on:focusout="leave($event)"
             x-on:click.outside="close(false)">

            {{-- The visible name IS the button's name (WCAG 2.5.3): the old
                 aria-label replaced it, so the name on screen named nothing.
                 On a phone the name is hidden from the eye, never removed. --}}
            <button type="button"
                    class="acct"
                    x-on:click="toggle()"
                    aria-expanded="false"
                    x-bind:aria-expanded="open.toString()"
                    aria-controls="account-menu">
                <span class="av" aria-hidden="true">{{ $initials }}</span>
                <span class="acct__who">
                    <span class="acct__name">{{ $displayName }}</span>
                    {{-- D-108 — the same role badge as the sidebar footer;
                         text always rides with the colour (Article 18). --}}
                    @if ($roleLabel !== '')
                        <x-ui.badge size="sm" :variant="$roleVariant">{{ $roleLabel }}</x-ui.badge>
                    @endif
                </span>
                <span class="sr">{{ __('app.accessibility.user_menu') }}</span>
                <x-ui.icon name="chevdown" size="sm" class="acct__chev" />
            </button>

            {{-- A disclosure of links, not an ARIA menu: role="menu" promises
                 the full menu keyboard model, and a screen reader announced a
                 menu that did not behave like one (D-86). --}}
            <div class="menu"
                 id="account-menu"
                 x-ref="panel"
                 x-show="open"
                 x-cloak
                 x-transition.opacity.duration.150ms>

                {{-- PRD §9.5.2 heads the menu with the e-mail. The name and the
                     role repeat here only where the button had to hide them. --}}
                <div class="menu__head">
                    <span class="menu__who">
                        <b>{{ $displayName }}</b>
                        @if ($roleLabel !== '')
                            <x-ui.badge size="sm" :variant="$roleVariant">{{ $roleLabel }}</x-ui.badge>
                        @endif
                    </span>
                    @if ($email !== '')
                        <span class="menu__email" dir="ltr">{{ $email }}</span>
                    @endif
                </div>

                @foreach ($menuItems as $item)
                    <a href="{{ $item['url'] }}"
                       class="menu__i"
                       @if ($item['current']) aria-current="page" @endif>
                        <x-ui.icon :name="$item['icon']" size="sm" />
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach

                {{-- D-123 — sign-out is back in this menu, in the danger colour,
                     and the rail keeps its own copy: that one needs no script
                     and stays in the 72px rail (D-108). A POST, never a link:
                     a GET would be triggerable from any other site (Article 24). --}}
                @if ($logoutUrl !== null)
                    <hr class="menu__sep">
                    <form method="POST" action="{{ $logoutUrl }}" class="menu__form">
                        @csrf
                        <button type="submit" class="menu__i menu__i--danger">
                            <x-ui.icon name="logout" size="sm" />
                            <span>{{ __('nav.chrome.logout') }}</span>
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</header>
