{{--
    429 - Too many requests

    Reachable today: seven routes carry throttle middleware (login, register,
    password reset, attendance check-in / check-out, uploads and messages), and
    Laravel's RateLimiter answers with this status.

    The copy frames the limit as protection rather than punishment, and never
    says how many attempts remain or which limiter fired: that detail helps
    someone probing the limiter and helps the participant not at all
    (Article 15, Article 24, PRD §12.4).

    The wait is printed as mm:ss from the Retry-After header the limiter itself
    set, so the figure is the server's, never the browser's (BR-07). It is a
    static figure, not a live counter: this page deliberately loads no
    JavaScript, so it still renders when the app shell is the thing that failed.

    @see PRD §8, §11.1, §12.4 · BR-07 · CONSTITUTION Articles 7, 11, 15, 17, 24
--}}

<x-layout.error-page code="429" :exception="$exception ?? null" />
