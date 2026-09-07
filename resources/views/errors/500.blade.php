{{--
    500 - Server error

    The most defensive page in the platform: it may be rendering because the
    database, the session store or the view layer just failed. So it resolves no
    user, opens no session, queries nothing, and links only to routes it has
    confirmed exist.

    It shows no exception message, no file path and no class name, in ANY
    environment - APP_DEBUG is false in production without exception, and a
    participant must never read a stack trace (Article 12, Article 24).

    @see PRD §11.1 · CONSTITUTION Articles 7, 12, 15, 17, 24
--}}

<x-layout.error-page code="500" />
