{{--
    Public shell: the landing page and the two legal pages.
    Server-rendered, RTL Arabic, no client-side routing.

    @see PRD §9.1 · CONSTITUTION Articles 16, 18, 19 · BR-36

    NOTHING IN THIS FILE IS A COLOUR.  The browser-chrome tint is read from
    --surface-page at run time by resources/js/app.js, because tokens.css is
    the single source of every colour (Article 6, Article 13 rule 4).

    Expected variables (all optional, every one has a safe default):
      $pageTitle        string  document title without the platform suffix
      $pageDescription  string  meta description
      $canonical        string  absolute canonical URL
      $ogImage          string  absolute URL of the 1200x630 share image
      $jsonLd           array   replaces the default Schema.org graph entirely
      $noIndex          bool    true on a staging deploy
--}}
<!DOCTYPE html>
{{-- data-server-now anchors every countdown to Clock::now(); the browser
     clock is never trusted (BR-07, Article 11). --}}
<html lang="ar" dir="rtl" data-server-now="{{ \App\Services\Time\Clock::now()->toIso8601ZuluString() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    {{-- Filled from --surface-page by app.js; empty until then, never a literal. --}}
    <meta name="theme-color" content="">

    <title>{{ $title }} · {{ config('athar.platform_name') }}</title>
    <meta name="description" content="{{ $description }}">
    <link rel="canonical" href="{{ $url }}">
    @if ($noIndex ?? false)
        <meta name="robots" content="noindex, nofollow">
    @endif

    <meta property="og:type" content="website">
    <meta property="og:locale" content="ar_SA">
    <meta property="og:site_name" content="{{ config('athar.platform_name') }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ $url }}">
    <meta property="og:image" content="{{ $share }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $share }}">

    <link rel="icon" href="{{ asset('brand/icons/favicon-mark.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('brand/icons/favicon-32.png') }}" sizes="32x32">
    <link rel="apple-touch-icon" href="{{ asset('brand/icons/icon-180.png') }}">

    @vite(['resources/css/app.css', 'resources/css/public.css', 'resources/js/public.js'])

    {{-- Structured data is built in PHP and encoded, so no user string is ever
         written into the script element unescaped (Article 24). --}}
    <script type="application/ld+json">{!! json_encode($graph, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>

    @stack('head')
</head>
<body class="page page--public">

<a class="sr sr--focusable" href="#main">{{ __('landing.meta.skip_to_content') }}</a>

@include('partials.icon-sprite')

{{-- Ambient layers. Every one is decorative, aria-hidden, and fully disabled
     under prefers-reduced-motion (Article 18). --}}
<div class="net" id="netLayer" aria-hidden="true"><canvas id="netCanvas"></canvas></div>
<div class="grain" aria-hidden="true"></div>
<canvas class="tracer" id="tracer" aria-hidden="true"></canvas>
<div class="cur" id="cur" aria-hidden="true">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 15l-6-6-6 6"/></svg>
</div>

{{-- The site header is a banner landmark, so it must sit OUTSIDE <main>. It
     used to be included by each of the six public views, from inside their
     @section('content') — which put <header> under <main>, demoted it to a
     generic region, and left the skip link pointing at a #main that still
     contained the whole navigation it exists to skip (WCAG 2.4.1, 1.3.1). --}}
@include('partials.public-header')

{{-- The Article 17 state marker, plus the landing page's own registration
     flag: "closed" is a state of its own and must not read as a generic empty
     screen (PRD §9.1.2). Every value is decided by the controller. --}}
<main id="main" tabindex="-1"
      @isset($screen) data-screen="{{ $screen }}" @endisset
      @isset($screenState) data-state="{{ $screenState }}" @endisset
      @isset($registrationState) data-registration="{{ $registrationState }}" @endisset>
    {{ $slot ?? '' }}
    @yield('content')
</main>

@include('partials.public-footer')

<a class="wa"
   href="https://wa.me/{{ config('athar.whatsapp') }}?text={{ rawurlencode(__('landing.footer.whatsapp_message', ['program' => config('athar.program_name')])) }}"
   target="_blank"
   rel="noopener"
   aria-label="{{ __('landing.footer.whatsapp') }}">
    <svg aria-hidden="true"><use href="#i-wa"/></svg>
</a>

@stack('body')
</body>
</html>
