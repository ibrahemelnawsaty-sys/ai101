{{--
    404 - Not found

    PRD §8 asks a 404 to be a signpost rather than a dead end, so it offers the
    handful of destinations a lost user actually wants. Each link is guarded
    with Route::has(), so this page renders correctly at any point in the
    project's life.

    A 404 is also the right answer when a record exists but is out of scope and
    naming it would leak its existence - the controller chooses which of 403 and
    404 to raise, never this view (Article 22).

    @see PRD §8, §11.1 · CONSTITUTION Articles 15, 16, 17, 18, 22
--}}

<x-layout.error-page code="404" />
