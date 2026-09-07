<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The four mandatory states of a screen, as one closed vocabulary.
 *
 * CONSTITUTION Article 17 requires every screen to have a normal, a loading, an
 * empty and an error state, but a requirement nobody can read off the rendered
 * page cannot be enforced by anything except an eye. So each screen root carries
 * `data-screen="<name>"` and `data-state="<one of these>"`, the loading skeleton
 * in resources/views/partials/skeletons/ carries `data-state="loading"`, and the
 * screen suite asserts the marker instead of eyeballing the page.
 *
 * WHICH STATE A SCREEN IS IN IS DECIDED ON THE SERVER, in the controller, and is
 * handed to the layout as a plain string (PROJECT-CONTRACT §16: the empty and
 * error states are flags the presenter builds, never conditions inside Blade).
 *
 * @see CONSTITUTION.md Article 17 · PROJECT-CONTRACT.md §16
 */
final class ScreenState
{
    /** Content exists and is being shown. */
    public const NORMAL = 'normal';

    /** The screen is waiting for its data; the skeleton is shaped like content. */
    public const LOADING = 'loading';

    /** The query succeeded and returned nothing, which is not a failure. */
    public const EMPTY = 'empty';

    /** The screen could not be built; the page says what happened and what to do. */
    public const ERROR = 'error';

    /**
     * The state of a screen whose data has already been fetched.
     *
     * The error flag is asked first on purpose: a screen that failed is in the
     * error state even when the collection it would have shown is empty, so a
     * failure is never dressed up as "nothing here yet" (Article 7).
     */
    public static function of(bool $isEmpty, bool $hasError = false): string
    {
        if ($hasError) {
            return self::ERROR;
        }

        return $isEmpty ? self::EMPTY : self::NORMAL;
    }
}
