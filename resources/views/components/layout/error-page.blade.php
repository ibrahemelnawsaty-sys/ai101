{{--
    Error page shell

    A complete, self-contained HTML document. It deliberately extends no layout
    and touches no model: an error page has to render when the thing it is
    reporting is the layout, the database, or the session. Everything it needs
    is a lang file, config/athar.php and one stylesheet.

    Article 15 governs the copy: every message says what happened and what to do
    next, in plain Arabic, with no blame and no technical jargon, and it lives in
    lang/ar/errors.php. Nothing here reveals a stack trace, a file path or a
    class name, in any environment (Article 24).

    Links are guarded with Route::has(), so a page that is reached before a route
    exists still renders instead of throwing a second error inside the first.

    Every value below is decided in App\View\Components\Layout\ErrorPage and in
    App\Support\ErrorNavigation; this template only renders them. Article 13
    item 13 keeps the deciding out of Blade.

    @see PRD §8, §11.1 · CONSTITUTION Articles 7, 13, 15, 16, 17, 18, 24

    Props (see the component class for their defaults)
      code       the HTTP status, printed large
      title      what happened
      body       what to do next
      action     label for the primary action
      href       target for the primary action
      links      array of ['label' => …, 'href' => …] shown under a divider
      ref        a support reference, printed small and monospaced
      exception  the throwable, read only for its Retry-After header
--}}
<!DOCTYPE html>
<html lang="ar" dir="rtl" data-surface="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $title }} · {{ $platform }}</title>

    <link rel="icon" href="{{ asset('brand/icons/favicon-mark.svg') }}" type="image/svg+xml">

    {{-- Guarded: a missing build must not raise a second error inside the first. --}}
    @if (file_exists(public_path('build/manifest.json')))
        @vite(['resources/css/app.css'])
    @endif
</head>
<body>

<div class="ui-errorpage">
    <header class="ui-errorpage__bar">
        <x-ui.logo :href="$homeUrl" size="sm" />
    </header>

    <main class="ui-errorpage__main" id="main" tabindex="-1">
        <div class="ui-errorpage__inner">
            {{-- Latin numerals, isolated LTR so the digits keep their order. --}}
            <p class="ui-errorpage__code" aria-hidden="true">{{ $code }}</p>

            <h1 class="ui-errorpage__title">{{ $title }}</h1>
            <p class="ui-errorpage__text">{{ $body }}</p>

            @if ($action !== null || isset($actions))
                <div class="ui-errorpage__actions">
                    @isset($actions)
                        {{ $actions }}
                    @else
                        <x-ui.button :href="$href ?? $homeUrl" variant="primary">{{ $action }}</x-ui.button>
                    @endisset
                </div>
            @endif

            @if ($retryAfter !== null)
                {{-- "01:30" is a digit run with a common separator, so bidi keeps
                     it intact inside the Arabic sentence without an explicit
                     direction. The figure is the limiter's, never the browser's
                     (BR-07). --}}
                <p class="ui-errorpage__text">
                    {{ __('errors.pages.429.retry_after', ['countdown' => $retryAfter]) }}
                </p>
            @endif

            {{ $slot }}

            @if (count($links) > 0)
                <nav class="ui-errorpage__links" aria-label="{{ __('errors.pages.404.suggestions') }}">
                    <p class="ui-errorpage__links-title">{{ __('errors.pages.404.suggestions') }}</p>
                    <ul>
                        @foreach ($links as $link)
                            <li><a href="{{ $link['href'] }}">{{ $link['label'] }}</a></li>
                        @endforeach
                    </ul>
                </nav>
            @endif

            @if ($ref !== null)
                <p class="ui-errorpage__ref">{{ $ref }}</p>
            @endif
        </div>
    </main>

    <footer class="ui-errorpage__foot">
        <p>{{ $platform }}</p>
        <p><a href="mailto:{{ $email }}" dir="ltr">{{ $email }}</a></p>
    </footer>
</div>

</body>
</html>
