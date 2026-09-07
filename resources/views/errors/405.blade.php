{{--
    405 - Method not allowed

    Reached when a URL is requested with a verb it does not answer: a bookmarked
    POST endpoint opened in the address bar, a back button replaying a form, a
    stale link. Nothing is wrong with the account and nothing was lost, so the
    copy says what happened and offers the way back rather than an apology
    (Article 15).

    Nothing is replayed automatically: a request the router refused is a request
    the server did not trust (Article 7).

    @see PRD §8, §11.1 · CONSTITUTION Articles 7, 15, 17
--}}

<x-layout.error-page code="405" />
