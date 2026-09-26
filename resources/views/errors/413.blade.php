{{--
    413 - Request body too large

    Reachable when one request carries more than the host's post_max_size — in
    practice a final-project hand-in whose upload fields were filled close to
    their limits all at once (D-121). PHP discards the whole body before the
    application reads a byte, so nothing was stored and there is nothing to
    restore: the page says what happened and how to fix it, and sends the
    participant back to the form they came from (Article 15).

    @see PRD §9.14.2, §12.5 · D-121 · CONSTITUTION Articles 7, 10, 15, 17
--}}

<x-layout.error-page code="413" :exception="$exception ?? null" />
