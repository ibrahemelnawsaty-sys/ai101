/**
 * The e-mail template editor's one Alpine component (BR-31, D-136).
 *
 * It does two things and decides nothing (Constitution, Article 5): it keeps the
 * two drafts the person is typing, and it asks the server — after a quiet moment
 * — for the letter rendered from them and for the sentences a save would refuse
 * them with. Both come back ready to show: the picture is the real letter shell
 * rendered on the server with sample values, and the messages are the server's
 * own wording. Nothing here knows a rule, a value, or a word of the platform's
 * language; the one string it needs (a failed preview) is passed in from Blade.
 *
 * Nothing is stored or sent from here: the draft lives in this object until the
 * form is submitted the ordinary way.
 *
 * @see D-114 · D-136 · BR-31
 */

/** Quiet time after the last keystroke before the picture is re-rendered. */
const QUIET_MS = 400;

/**
 * The widths the letter is laid out at, and the height of the picture. A phone
 * and a desktop mail client are different widths, and the picture must show the
 * DIFFERENCE even in a narrow column: the frame is laid out at its real width
 * and scaled down to fit, never squeezed (which would re-flow it and show a
 * letter that is neither).
 */
const WIDTHS = { mobile: 390, desktop: 680 };
const HEIGHT = 640;

export default function emailEditor(options = {}) {
    return {
        drafts: { ...(options.drafts || {}) },
        frame: 'mobile',
        state: 'idle',
        subject: '',
        html: '',
        messages: {},
        failure: '',
        timer: null,
        ticket: 0,
        scale: 1,

        init() {
            this.render();

            Object.keys(this.drafts).forEach((field) => {
                this.$watch(`drafts.${field}`, () => this.schedule());
            });

            this.$watch('frame', () => this.fit());
            this.$nextTick(() => this.fit());
            window.addEventListener('resize', () => this.fit());
        },

        /** How much the frame is scaled to fit the column it sits in (never above 1). */
        fit() {
            const stage = this.$refs.stage;
            if (!stage) return;

            const room = Math.max(stage.clientWidth - 24, 1);
            this.scale = Math.min(1, room / WIDTHS[this.frame]);
        },

        /** The box that holds the scaled frame: its LAYOUT size is the scaled one. */
        get boxStyle() {
            return {
                inlineSize: `${Math.round(WIDTHS[this.frame] * this.scale)}px`,
                blockSize: `${Math.round(HEIGHT * this.scale)}px`,
            };
        },

        get frameStyle() {
            return {
                inlineSize: `${WIDTHS[this.frame]}px`,
                blockSize: `${HEIGHT}px`,
                transform: `scale(${this.scale})`,
            };
        },

        /** A save is refused while the server says a draft has a problem. */
        get hasProblem() {
            return Object.values(this.messages).some((message) => Boolean(message));
        },

        schedule() {
            window.clearTimeout(this.timer);
            this.timer = window.setTimeout(() => this.render(), QUIET_MS);
        },

        async render() {
            const ticket = ++this.ticket;
            this.state = 'loading';

            try {
                const response = await window.fetch(options.previewUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify(this.drafts),
                });

                // A newer request is already on its way: this answer is stale.
                if (ticket !== this.ticket) return;

                if (response.status === 422) {
                    const body = await response.json();
                    const messages = {};
                    Object.keys(this.drafts).forEach((field) => {
                        messages[field] = (body.errors && body.errors[field] && body.errors[field][0]) || null;
                    });
                    this.messages = messages;
                    this.state = 'ready';
                    return;
                }

                if (!response.ok) throw new Error('preview');

                const body = await response.json();
                this.subject = body.subject || '';
                this.html = body.html || '';
                this.messages = body.messages || {};
                this.failure = '';
                this.state = 'ready';
            } catch (error) {
                if (ticket !== this.ticket) return;
                this.failure = options.failed || '';
                this.state = 'error';
            }
        },
    };
}
