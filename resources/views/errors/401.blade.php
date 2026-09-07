{{--
    401 - Unauthenticated

    Raised when a request reaches a protected route with no valid session: the
    session expired, the cookie was dropped, or every session was revoked after
    a password change (BR-29).

    The copy names the fact and the fix - "your session ended, sign in again" -
    and never says whether the account exists, is suspended, or was signed out
    by an administrator (BR-30, Article 24).

    Like every error page it resolves no model and queries nothing: the session
    store is exactly the thing that just failed.

    @see PRD §8, §11.1 · BR-29, BR-30 · CONSTITUTION Articles 7, 15, 17, 22, 24
--}}

<x-layout.error-page code="401" />
