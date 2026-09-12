{{--
    Dashboard shell: every authenticated screen for the participant, the
    trainer and the administrator.

    @see PRD §9.5.1, §9.5.2 · CONSTITUTION Articles 5, 11, 16, 17, 18, 23

    THE CHROME IS A REFLECTION, NOT A PERMISSION
    A menu item is hidden because the route is not registered for this person,
    never as protection. Every route it links to enforces its own middleware,
    policy and query scope on the server (Article 5). Hiding a link protects
    nothing and nothing here relies on it.

    LIGHT SURFACE
    data-surface="light" re-points the semantic tokens; not one colour is
    written in this file (Article 6).

    Sections a page fills in:
      @section('title')     required · the appbar heading
      @section('subtitle')  optional · the line under it
      @section('content')   required
    Stacks: @push('head') · @push('body')

    The shell's own values come from App\View\Composers\AppLayoutComposer, on
    every render, each behind a guard so one failing count cannot take the page
    down: $cohortName, $sidebarCohorts, $navBadges, $unreadNotifications. No
    controller set them before D-75, so the footer, the switcher, the badges
    and the bell count had never once appeared.
      $sidebarGroups  array  the one optional override — a controller's own rail
--}}
<!DOCTYPE html>
{{-- data-server-now anchors every countdown to Clock::now(); the browser clock
     is never trusted (BR-07, Article 11). --}}
<html lang="ar"
      dir="rtl"
      data-surface="light"
      data-server-now="{{ \App\Services\Time\Clock::now()->toIso8601ZuluString() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light">
    {{-- Filled from --surface-page by app.js; empty until then, never a literal. --}}
    <meta name="theme-color" content="">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="same-origin">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', __('nav.participant.dashboard')) · {{ config('athar.platform_name') }}</title>

    <link rel="icon" href="{{ asset('brand/icons/favicon-mark.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('brand/icons/icon-180.png') }}">

    @vite(['resources/css/app.css', 'resources/js/dashboard.js'])

    @stack('head')
</head>
<body class="page page--app">

<a class="skip" href="#main">{{ __('app.actions.skip_to_content') }}</a>

<x-layout.icons />

{{-- Preview mode is the strongest permission on the platform, so its banner is
     the first thing in the document and never scrolls away (Article 23). --}}
<x-layout.impersonation-bar />

<div class="shell"
     x-data="sidebar()"
     x-bind:data-collapsed="collapsed.toString()">

    <x-layout.sidebar
        :groups="$sidebarGroups ?? null"
        :badges="$navBadges"
        :cohort="$cohortName"
        :cohorts="$sidebarCohorts" />

    <div class="shell__main">
        <x-layout.header
            :title="\Illuminate\Support\Facades\View::yieldContent('title')"
            :subtitle="\Illuminate\Support\Facades\View::yieldContent('subtitle')"
            :badges="$navBadges"
            :unread="$unreadNotifications" />

        {{-- The Article 17 state marker. Both values are decided by the
             controller (App\Support\ScreenState) and only reflected here; a
             screen that forgets to pass them renders no marker at all, so the
             omission fails its own screen test instead of passing silently. --}}
        <main id="main" class="body" tabindex="-1"
              @isset($screen) data-screen="{{ $screen }}" @endisset
              @isset($screenState) data-state="{{ $screenState }}" @endisset>
            @yield('content')
            {{ $slot ?? '' }}
        </main>

        <x-layout.footer />
    </div>
</div>

{{-- The mobile navigation drawer: the same rail, slid in from the RIGHT.
     Focus is trapped inside it and returns to the burger on close. --}}
<div x-data="drawer()" x-on:keydown.escape.window="hide()" x-on:athar-drawer.window="toggle()">
    <div class="drawer__scrim"
         x-show="open"
         x-cloak
         x-transition.opacity
         x-on:click="hide()"
         aria-hidden="true"></div>

    <div x-show="open" x-cloak x-on:keydown="trap($event)">
        <x-layout.sidebar
            drawer
            :groups="$sidebarGroups ?? null"
            :badges="$navBadges"
            :cohort="$cohortName"
            :cohorts="$sidebarCohorts" />
    </div>
</div>

{{-- One aria-live region for every dynamic success or error message. --}}
<div class="toasts" x-data x-cloak role="status" aria-live="polite" aria-atomic="false">
    <template x-for="toast in $store.toast.items" :key="toast.id">
        <div class="toast" :class="'toast--' + toast.tone">
            <p class="toast__body" x-text="toast.message"></p>
            <button type="button"
                    class="toast__x"
                    :aria-label="'{{ __('app.actions.close') }}'"
                    x-on:click="$store.toast.dismiss(toast.id)">
                <svg aria-hidden="true"><use href="#i-x"></use></svg>
            </button>
        </div>
    </template>
</div>

{{-- Flash messages the controller set are announced through the same region,
     so a success reads once and only once. --}}
@foreach (['ok' => 'status', 'bad' => 'error', 'warn' => 'warning'] as $tone => $key)
    @if (session()->has($key))
        <div x-data x-init="$store.toast.push(@js(session($key)), @js($tone))" hidden></div>
    @endif
@endforeach

@stack('body')
</body>
</html>
