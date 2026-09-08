/**
 * Entry bundle for the authentication shell (layouts/auth.blade.php).
 *
 * Registration wizard, password-strength readout and the show/hide toggle.
 * None of it is a rule: the wizard cannot let a step pass that the server
 * would reject, the meter cannot make a weak password acceptable, and the
 * consent checkbox is re-checked in RegisterRequest.  Everything here is a
 * courtesy that saves a round trip (Constitution Article 5).
 *
 * @see PRD §9.2, §9.3 · CONSTITUTION Articles 5, 15, 18
 *
 * NOT ONE ARABIC STRING lives in this file.  Every message is rendered by
 * Blade into the `#registerCopy` JSON island and read back here (Article 15).
 * There is no `console.*` anywhere (Article 13 rule 1).
 */

import './app.js';

/* ==========================================================================
   Copy island — Arabic comes from PHP, never from JavaScript
   ========================================================================== */

/**
 * Reads the JSON island Blade rendered.  A missing or malformed island must
 * never break the form, so every consumer falls back to an empty string and
 * the page keeps working with server-side validation alone (Article 7).
 *
 * @param {string} id
 * @returns {Record<string, any>}
 */
function readCopy(id) {
    const node = document.getElementById(id);
    if (!node) return {};
    try {
        return JSON.parse(node.textContent || '{}');
    } catch (error) {
        return {};
    }
}

/* ==========================================================================
   Password scoring
   ========================================================================== */

const RULES = {
    length: (v) => v.length >= 8,
    upper: (v) => /[A-Z]/.test(v),
    lower: (v) => /[a-z]/.test(v),
    digit: (v) => /[0-9]/.test(v),
    symbol: (v) => /[^A-Za-z0-9]/.test(v),
};

/**
 * Mirrors the server rule set. Returns 0-4 so the meter has four segments.
 * The server re-runs the real rule in the FormRequest; this only informs.
 *
 * @param {string} value
 * @returns {{score: number, rules: Record<string, boolean>}}
 */
function scoreOf(value) {
    const rules = {
        length: RULES.length(value),
        upper: RULES.upper(value),
        lower: RULES.lower(value),
        digit: RULES.digit(value),
        symbol: RULES.symbol(value),
    };
    const met = Object.keys(rules).filter((k) => rules[k]).length;
    // Length is the gate: without it nothing above «weak» is shown.
    const score = !rules.length ? Math.min(met, 1) : Math.max(1, met - 1);
    return { score: Math.min(score, 4), rules };
}

/* ==========================================================================
   Alpine component: the three-step registration wizard
   ========================================================================== */

/**
 * @param {{storageKey?: string, totalSteps?: number, startStep?: number}} options
 */
function registerWizard(options = {}) {
    const copy = readCopy('registerCopy');
    const storageKey = options.storageKey || '';
    const totalSteps = Number(options.totalSteps) || 3;

    return {
        step: Math.min(Math.max(Number(options.startStep) || 1, 1), totalSteps),
        totalSteps,
        submitting: false,

        password: '',
        passwordConfirmation: '',
        strength: 0,
        rules: { length: false, upper: false, lower: false, digit: false, symbol: false },

        metLabel: copy.met || '',
        unmetLabel: copy.unmet || '',

        init() {
            this.$watch('step', () => this.persist());
        },

        get stepLabel() {
            const template = copy.step_of || '';
            return template
                .replace('{current}', String(this.step))
                .replace('{total}', String(this.totalSteps));
        },

        get strengthLabel() {
            const labels = Array.isArray(copy.strength) ? copy.strength : [];
            const index = Math.max(0, Math.min(this.strength - 1, labels.length - 1));
            return labels[index] || '';
        },

        /* Pure DOM helpers. They format what the user typed; they do not judge
           it. Validation belongs to the server (art. 5) and the fields already
           carry required / pattern / minlength for the browser's own check. */

        capitalise(event) {
            const el = event.target;
            el.value = el.value.replace(/\S+/g, (w) => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase());
        },

        lowercase(event) {
            event.target.value = event.target.value.toLowerCase().trim();
        },

        digitsOnly(event) {
            event.target.value = event.target.value.replace(/\D+/g, '');
        },

        scorePassword() {
            const result = scoreOf(this.password || '');
            this.strength = result.score;
            this.rules = result.rules;
        },

        next() {
            if (this.step < this.totalSteps) this.step += 1;
            this.focusPane();
        },

        prev() {
            if (this.step > 1) this.step -= 1;
            this.focusPane();
        },

        /** Moves focus to the heading of the pane that just became visible. */
        focusPane() {
            this.$nextTick(() => {
                const heading = this.$el.querySelector('.wiz__pane:not([style*="display: none"]) .wiz__title');
                if (heading) {
                    heading.setAttribute('tabindex', '-1');
                    heading.focus();
                }
            });
        },

        onSubmit() {
            // The password never touches storage, and the draft is dropped the
            // moment the form is handed to the server.
            this.submitting = true;
        },

        /* Draft persistence was removed. It saved { step } and nothing else,
           while the screen told the user "we restored what you entered". The
           comment that used to sit here described names-and-email persistence
           that was never written. Storing a half-filled registration on a
           shared device is also a data-retention question (D-12, open), so the
           honest answer today is to keep nothing and promise nothing. */
    };
}

/* ==========================================================================
   Show / hide password
   ========================================================================== */

/**
 * Binds every `[data-pw-toggle]` button to the field named by its
 * `aria-controls`.  The two labels arrive from Blade as data attributes so no
 * Arabic reaches this file.
 */
function initPasswordToggles() {
    const buttons = Array.prototype.slice.call(
        document.querySelectorAll('[data-pw-toggle]'),
    );
    buttons.forEach((button) => {
        const field = document.getElementById(button.getAttribute('aria-controls') || '');
        if (!field) return;
        button.addEventListener('click', () => {
            const showing = field.getAttribute('type') === 'text';
            field.setAttribute('type', showing ? 'password' : 'text');
            button.setAttribute('aria-pressed', showing ? 'false' : 'true');
            const label = showing
                ? button.getAttribute('data-label-show')
                : button.getAttribute('data-label-hide');
            if (label) button.setAttribute('aria-label', label);
        });
    });
}

/* ==========================================================================
   Boot
   ========================================================================== */

document.addEventListener('alpine:init', () => {
    if (window.Alpine) window.Alpine.data('registerWizard', registerWizard);
});

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initPasswordToggles, { once: true });
} else {
    initPasswordToggles();
}

export { registerWizard, scoreOf };
