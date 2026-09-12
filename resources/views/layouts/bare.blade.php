{{--
    Bare shell: the two public verification pages (digital card, certificate)
    and the participant's own two print sheets (card, timetable). An employer
    or a door steward opens the first two, so the page is small, printable,
    carries no navigation and shows nothing beyond what BR-25 permits.

    @see BR-25 · PRD §9.17, §9.6 · CONSTITUTION Articles 16, 18, 24

    No JavaScript is loaded here on purpose: a verification page must render
    identically with scripting off, and it has nothing to animate.

    Expected variables (all optional):
      $pageTitle        string  document title without the platform suffix
      $pageDescription  string  meta description

    Sections (both optional):
      title      the document title without the platform suffix; wins over
                 $pageTitle, which wins over the issuer line. The layout had no
                 yield for it, so the print sheets' own titles never reached the
                 tab or the saved file name (D-78).
      ownSheet   set by a participant's own print sheet. It hides the
                 "verified by" issuer line and the public-page privacy note,
                 which labelled a trainee's preview of their own card as an
                 official verification page (D-78).
--}}
<!DOCTYPE html>
{{-- data-server-now anchors every countdown to Clock::now(); the browser
     clock is never trusted (BR-07, Article 11). --}}
<html lang="ar" dir="rtl" data-server-now="{{ \App\Services\Time\Clock::now()->toIso8601ZuluString() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    {{-- A verification URL is itself the credential: never indexed, never a referrer. --}}
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">

    <title>@yield('title', $pageTitle ?? __('verify.shared.verified_by')) · {{ config('athar.platform_name') }}</title>
    <meta name="description" content="{{ $pageDescription ?? '' }}">

    <link rel="icon" href="{{ asset('brand/icons/favicon-mark.svg') }}" type="image/svg+xml">

    @vite(['resources/css/app.css', 'resources/css/public.css'])

    @stack('head')
</head>
<body class="page page--bare">

@include('partials.icon-sprite')

{{-- The Article 17 state marker; the controller decides it. --}}
<main id="main" class="bare" tabindex="-1"
      @isset($screen) data-screen="{{ $screen }}" @endisset
      @isset($screenState) data-state="{{ $screenState }}" @endisset>
    <header class="bare__top">
        <a class="logo" href="{{ route('home') }}" aria-label="{{ __('verify.shared.issuer') }}">
            <svg viewBox="0 0 242.186 102.814" fill="currentColor" role="img" aria-label="{{ __('verify.shared.issuer') }}"><use href="#athar-wordmark"/></svg>
        </a>
        @sectionMissing('ownSheet')
            <span class="bare__issuer">{{ __('verify.shared.verified_by') }}</span>
        @endif
    </header>

    {{ $slot ?? '' }}
    @yield('content')

    <footer class="bare__foot">
        @sectionMissing('ownSheet')
            <p class="bare__privacy">{{ __('verify.shared.privacy_note') }}</p>
        @endif
        <p class="bare__contact">
            <a href="mailto:{{ config('athar.email') }}" dir="ltr">{{ config('athar.email') }}</a>
        </p>
        <a class="bare__home" href="{{ route('home') }}">{{ __('verify.shared.back_home') }}</a>
    </footer>
</main>

@stack('body')
</body>
</html>
