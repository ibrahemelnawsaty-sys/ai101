{{--
    419 - Page expired (CSRF token mismatch)

    A page left open long enough for its CSRF token to expire. The copy says
    exactly that in plain language and asks for a reload - never "CSRF token
    mismatch", which tells a participant nothing (Article 15).

    Reloading is the only safe action: the submitted data is deliberately NOT
    replayed, because a request that failed its CSRF check is a request the
    server refused to trust (Article 7, Article 24).

    @see PRD §11.1 · CONSTITUTION Articles 7, 15, 17, 24
--}}

<x-layout.error-page code="419" />
