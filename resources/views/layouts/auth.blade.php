{{--
    Authentication shell: register, login, password recovery, e-mail verification.
    Deliberately quieter than the public shell: no ambient canvas, no marketing chrome.

    @see PRD §9.2, §9.3 · CONSTITUTION Articles 16, 18, 24

    An authentication page is never indexed and never leaks a referrer, because
    a reset URL is itself a credential.

    Expected variables (all optional):
      $pageTitle        string  document title without the platform suffix
      $pageDescription  string  meta description
--}}
<!DOCTYPE html>
{{-- data-server-now anchors every countdown to Clock::now(); the browser
     clock is never trusted (BR-07, Article 11). --}}
<html lang="ar"
      dir="rtl"
      {{-- The auth screens are a light shell, exactly as tokens.css section 16
           already states. Without this attribute every text role resolved from
           the dark root while .authcard painted itself white, and the whole
           screen came out white on white. --}}
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

    <title>@yield('pageTitle', $pageTitle ?? __('auth.login.title')) · {{ config('athar.platform_name') }}</title>
    <meta name="description" content="{{ $pageDescription ?? '' }}">

    <link rel="icon" href="{{ asset('brand/icons/favicon-mark.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('brand/icons/icon-180.png') }}">

    @vite(['resources/css/app.css', 'resources/css/public.css', 'resources/js/auth.js'])

    @stack('head')
</head>
<body class="page page--auth">

<a class="sr sr--focusable" href="#main">{{ __('landing.meta.skip_to_content') }}</a>

@include('partials.icon-sprite')

<div class="authshell">
    <header class="authshell__top">
        <a class="logo" href="{{ route('home') }}" aria-label="{{ __('auth.shared.brand_alt') }}">
            <svg viewBox="0 0 242.186 102.814" fill="currentColor" role="img" aria-label="{{ __('auth.shared.brand_alt') }}"><use href="#athar-wordmark"/></svg>
        </a>
        <a class="authshell__back" href="{{ route('home') }}">
            <svg aria-hidden="true" class="ic ic--flip"><use href="#i-chev"/></svg>
            <span>{{ __('auth.shared.back_home') }}</span>
        </a>
    </header>

    <main id="main" class="authshell__main" tabindex="-1">
        {{ $slot ?? '' }}
        @yield('content')
    </main>

    <footer class="authshell__foot">
        <span class="authshell__tag">{{ __('auth.shared.tagline') }}</span>
        <nav class="authshell__links" aria-label="{{ __('landing.footer.quick_links') }}">
            <a href="{{ route('terms') }}">{{ __('landing.footer.terms') }}</a>
            <a href="{{ route('privacy') }}">{{ __('landing.footer.privacy') }}</a>
            <a href="mailto:{{ config('athar.email') }}" dir="ltr">{{ config('athar.email') }}</a>
        </nav>
    </footer>
</div>

{{-- One aria-live region for every dynamic success or error message (Article 18). --}}
<div class="toasts" x-data x-cloak role="status" aria-live="polite" aria-atomic="false">
    <template x-for="toast in $store.toast.items" :key="toast.id">
        <div class="toast" :class="'toast--' + toast.tone">
            <p class="toast__body" x-text="toast.message"></p>
            <button type="button" class="toast__x"
                    :aria-label="'{{ __('app.actions.close') }}'"
                    @click="$store.toast.dismiss(toast.id)">
                <svg aria-hidden="true"><use href="#i-x"/></svg>
            </button>
        </div>
    </template>
</div>

@stack('body')
</body>
</html>
