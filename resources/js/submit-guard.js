/**
 * منصة أثر — حارس الإرسال المزدوج
 *
 * One guard for every form on every page (D-127). Before it, `state="loading"`
 * on the button component had no caller at all and only three of a hundred and
 * eight forms protected themselves, so a second click during a slow upload sent
 * the request twice: a second version of a hand-in, a second broadcast, or a
 * unique-index collision that surfaced as a 500.
 *
 * WHAT IT DOES
 *   On the first submit of a form that writes (anything but GET) it marks the
 *   form busy, dims the button that was pressed and gives it a spinner. A second
 *   submit of the same form is cancelled until the page has answered.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 *   · It never sets `disabled` on the button. A disabled submitter is left out of
 *     the form data, and several forms tell the server which button was pressed
 *     (`name="next" value="1"`).
 *   · It is not a permission or a validation check — the server refuses what it
 *     refuses (Article 5). This only stops the same click being sent twice.
 *   · It leaves alone a form that opens elsewhere (`target="_blank"`, the live
 *     join): this page stays usable, so there is nothing to protect.
 *   · A form can opt out with `data-submit-guard="off"`.
 *
 * It lets go again when the page comes back from the browser's back/forward
 * cache, and after fifteen seconds in case the answer was a download that never
 * replaced the page.
 *
 * No Arabic string lives here and nothing here writes to the console (Articles
 * 13 and 15): the spinner is the same element the button component draws, and
 * its screen-reader text is the button's own label, which never changes.
 *
 * @see D-127 · CONSTITUTION Articles 5, 13, 18
 */

const RELEASE_AFTER_MS = 15000;

/** Forms that send something to the server and stay on this page's tab. */
const isGuarded = (form) => {
    if (!(form instanceof HTMLFormElement)) return false;
    if (form.dataset.submitGuard === 'off') return false;

    const method = (form.getAttribute('method') || 'get').toLowerCase();
    if (method === 'get' || method === 'dialog') return false;

    const target = form.getAttribute('target');
    return !target || target === '_self';
};

/** The control that was pressed, or the form's first submit control (Enter key). */
const submitterOf = (form, event) => {
    if (event.submitter instanceof HTMLElement) return event.submitter;
    return form.querySelector('button[type="submit"], input[type="submit"], button:not([type])');
};

function lock(form, button) {
    form.dataset.submitting = 'true';
    form.setAttribute('aria-busy', 'true');

    if (button && button.classList.contains('ui-btn')) {
        button.classList.add('is-loading');
        button.setAttribute('aria-busy', 'true');
        button.setAttribute('aria-disabled', 'true');

        if (!button.querySelector('.ui-spinner')) {
            const spinner = document.createElement('span');
            spinner.className = 'ui-spinner';
            spinner.setAttribute('aria-hidden', 'true');
            spinner.dataset.guardSpinner = 'true';
            button.prepend(spinner);
        }
    }

    // An upload can outlast any fixed wait on a slow phone connection; letting go
    // early would allow the very second copy this guard exists to stop. Those
    // forms are freed by the page answering, or by the back/forward cache.
    if (form.enctype !== 'multipart/form-data') {
        form._submitRelease = window.setTimeout(() => release(form), RELEASE_AFTER_MS);
    }
}

function release(form) {
    window.clearTimeout(form._submitRelease);
    delete form.dataset.submitting;
    form.removeAttribute('aria-busy');

    // A submit button may sit outside the <form> and point at it with form="id"
    // (the confirmation dialog's footer does), so ask the document, not the form.
    document.querySelectorAll('.ui-btn.is-loading').forEach((button) => {
        if (button.form !== form) return;
        // Only a spinner this guard added is ours to take away; one the button
        // component drew for `state="loading"` belongs to the server's markup.
        const own = button.querySelector('.ui-spinner[data-guard-spinner]');
        if (!own) return;
        own.remove();
        button.classList.remove('is-loading');
        button.removeAttribute('aria-busy');
        button.removeAttribute('aria-disabled');
    });
}

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!isGuarded(form)) return;

    // A form that is already on its way: this is the same click, sent again.
    if (form.dataset.submitting === 'true') {
        event.preventDefault();
        return;
    }

    // Another handler (a client-side check) has already refused this submit —
    // no request is going out, so there is nothing to lock.
    if (event.defaultPrevented) return;

    lock(form, submitterOf(form, event));
});

window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    document.querySelectorAll('form[data-submitting="true"]').forEach(release);
});
