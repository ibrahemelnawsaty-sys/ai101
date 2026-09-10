/**
 * منصة أثر — behaviour layer for the Blade UI component library.
 *
 * @see PRD §5.8, §5.9 · CONSTITUTION Articles 5, 11, 16, 17, 18
 *
 * Contract with the server (Article 5):
 *   Nothing in this file decides anything. Every window, permission and score
 *   is decided in PHP; these components only reflect a decision that already
 *   happened. In particular the countdown never enables an action — it counts
 *   down to a moment the server supplied and then asks the page to re-check.
 *
 * Contract with the clock (Article 11):
 *   The browser clock is never trusted. Every countdown is anchored to the
 *   server instant rendered into the page by Clock::now(); the browser clock is
 *   used only to measure elapsed time since that anchor.
 *
 * Contract with accessibility (Article 18):
 *   Dialogs trap focus, close on Escape and on an outside click, and hand focus
 *   back to whatever opened them. Motion respects prefers-reduced-motion.
 *
 * Registers itself on the standard `alpine:init` event, so it works whether the
 * entry bundle imports it before or after Alpine.start().
 */

const REDUCED_MOTION = () =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

function focusableWithin(root) {
    return Array.from(root.querySelectorAll(FOCUSABLE)).filter(
        (el) => el.offsetParent !== null || el === document.activeElement,
    );
}

/** Latin numerals, always, zero-padded to `width` (Article 15). */
function pad(value, width = 2) {
    return String(Math.max(0, Math.floor(value))).padStart(width, '0');
}

function readableSize(bytes) {
    if (!Number.isFinite(bytes) || bytes <= 0) return '0';
    const units = ['B', 'KB', 'MB', 'GB'];
    const index = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
    const value = bytes / 1024 ** index;
    return `${value >= 10 || index === 0 ? Math.round(value) : value.toFixed(1)} ${units[index]}`;
}

/* ==========================================================================
   Dialog — shared engine behind <x-ui.modal> and <x-ui.drawer>
   ========================================================================== */

function uiDialog(options = {}) {
    return {
        open: Boolean(options.open),
        name: options.name || null,
        closeOnBackdrop: options.closeOnBackdrop !== false,
        closeOnEscape: options.closeOnEscape !== false,
        returnFocusTo: null,

        init() {
            if (this.open) this.$nextTick(() => this.afterOpen());
        },

        /** Opened by `$dispatch('ui-dialog-open', 'name')` from anywhere on the page. */
        openFromEvent(event) {
            const target = event && event.detail;
            const wanted = typeof target === 'string' ? target : target && target.name;
            if (this.name && wanted && wanted !== this.name) return;
            this.show(event);
        },

        closeFromEvent(event) {
            const target = event && event.detail;
            const wanted = typeof target === 'string' ? target : target && target.name;
            if (this.name && wanted && wanted !== this.name) return;
            this.hide();
        },

        show(event) {
            if (this.open) return;
            // Remember the trigger so focus can be handed straight back on close.
            this.returnFocusTo =
                (event && event.detail && event.detail.trigger) ||
                (document.activeElement instanceof HTMLElement ? document.activeElement : null);
            this.open = true;
            this.$nextTick(() => this.afterOpen());
        },

        hide() {
            if (!this.open) return;
            this.open = false;
            document.documentElement.style.removeProperty('overflow');
            const back = this.returnFocusTo;
            this.returnFocusTo = null;
            this.$nextTick(() => {
                if (back && document.contains(back)) back.focus();
            });
            this.$dispatch('ui-dialog-closed', { name: this.name });
        },

        afterOpen() {
            document.documentElement.style.setProperty('overflow', 'hidden');
            const panel = this.$refs.panel;
            if (!panel) return;
            const preferred = panel.querySelector('[data-autofocus]');
            const first = preferred || focusableWithin(panel)[0] || panel;
            first.focus({ preventScroll: true });
            this.$dispatch('ui-dialog-opened', { name: this.name });
        },

        onEscape() {
            if (this.closeOnEscape) this.hide();
        },

        onBackdrop() {
            if (this.closeOnBackdrop) this.hide();
        },

        /** Keeps Tab and Shift+Tab inside the panel (Article 18). */
        onKeydown(event) {
            if (event.key !== 'Tab' || !this.open) return;
            const panel = this.$refs.panel;
            if (!panel) return;
            const items = focusableWithin(panel);
            if (items.length === 0) {
                event.preventDefault();
                panel.focus();
                return;
            }
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },

        destroy() {
            document.documentElement.style.removeProperty('overflow');
        },
    };
}

/* ==========================================================================
   Toaster — one region per page, aria-live, 5s auto-dismiss (PRD §5.8)
   ========================================================================== */

function uiToaster(options = {}) {
    return {
        toasts: Array.isArray(options.initial) ? options.initial.slice() : [],
        duration: Number(options.duration) > 0 ? Number(options.duration) : 5000,
        counter: 0,

        push(event) {
            const payload = (event && event.detail) || {};
            const id = `t${++this.counter}`;
            const toast = {
                id,
                variant: payload.variant || 'info',
                title: payload.title || '',
                text: payload.text || '',
                leaving: false,
                sticky: Boolean(payload.sticky),
            };
            this.toasts.push(toast);
            if (!toast.sticky) {
                window.setTimeout(() => this.dismiss(id), this.duration);
            }
        },

        dismiss(id) {
            const toast = this.toasts.find((t) => t.id === id);
            if (!toast || toast.leaving) return;
            toast.leaving = true;
            const wait = REDUCED_MOTION() ? 0 : 200;
            window.setTimeout(() => {
                this.toasts = this.toasts.filter((t) => t.id !== id);
            }, wait);
        },
    };
}

/* ==========================================================================
   Tabs — roving tabindex, arrow keys, Home/End, animated indicator
   ========================================================================== */

function uiTabs(options = {}) {
    return {
        active: options.active || null,
        tabs: [],

        init() {
            this.tabs = Array.from(this.$refs.list ? this.$refs.list.querySelectorAll('[role="tab"]') : []);
            if (!this.active && this.tabs.length) {
                this.active = this.tabs[0].dataset.tab;
            }
            this.$nextTick(() => this.moveIndicator());
            window.addEventListener('resize', () => this.moveIndicator(), { passive: true });
        },

        isActive(name) {
            return this.active === name;
        },

        select(name) {
            if (this.active === name) return;
            this.active = name;
            this.$nextTick(() => this.moveIndicator());
            this.$dispatch('ui-tab-changed', { tab: name });
        },

        moveIndicator() {
            const bar = this.$refs.indicator;
            const list = this.$refs.list;
            if (!bar || !list) return;
            const current = list.querySelector('[role="tab"][aria-selected="true"]');
            if (!current) {
                bar.style.setProperty('inline-size', '0px');
                return;
            }
            const listBox = list.getBoundingClientRect();
            const tabBox = current.getBoundingClientRect();
            // Offset measured from the list's inline-start edge, which is the
            // right edge in RTL — so the same maths serves both directions.
            const rtl = getComputedStyle(list).direction === 'rtl';
            const offset = rtl
                ? listBox.right - tabBox.right + list.scrollLeft * -1
                : tabBox.left - listBox.left + list.scrollLeft;
            bar.style.setProperty('inline-size', `${tabBox.width}px`);
            bar.style.setProperty('transform', `translateX(${rtl ? -offset : offset}px)`);
        },

        onKeydown(event) {
            const keys = ['ArrowRight', 'ArrowLeft', 'Home', 'End'];
            if (!keys.includes(event.key)) return;
            event.preventDefault();
            const rtl = getComputedStyle(this.$refs.list).direction === 'rtl';
            const index = this.tabs.findIndex((t) => t.dataset.tab === this.active);
            let next = index;
            if (event.key === 'Home') next = 0;
            else if (event.key === 'End') next = this.tabs.length - 1;
            else {
                // In RTL the right arrow moves toward the previous tab.
                const forward = rtl ? event.key === 'ArrowLeft' : event.key === 'ArrowRight';
                next = (index + (forward ? 1 : -1) + this.tabs.length) % this.tabs.length;
            }
            const target = this.tabs[next];
            if (!target || target.hasAttribute('disabled')) return;
            this.select(target.dataset.tab);
            target.focus();
        },
    };
}

/* ==========================================================================
   Select — listbox with an internal search past 8 options (PRD §5.8)
   ========================================================================== */

function uiSelect(options = {}) {
    return {
        open: false,
        query: '',
        activeIndex: -1,
        value: options.value ?? '',
        items: Array.isArray(options.items) ? options.items : [],
        searchable: options.searchable ?? (Array.isArray(options.items) && options.items.length > 8),
        placeholder: options.placeholder || '',

        // Viewport coordinates for the open panel. See `place()`.
        panelTop: 0,
        panelLeft: 0,
        panelWidth: 0,
        dropUp: false,

        get filtered() {
            const q = this.query.trim();
            if (!q) return this.items;
            return this.items.filter((item) => String(item.label).includes(q));
        },

        get selectedLabel() {
            const found = this.items.find((item) => String(item.value) === String(this.value));
            return found ? found.label : '';
        },

        toggle() {
            this.open ? this.close() : this.show();
        },

        /**
         * The panel's position, in viewport coordinates.
         *
         * WHY IT IS NOT SIMPLY ABSOLUTE ANY MORE
         * `.ui-card` carries `overflow: hidden` — it has to, so a flush card's
         * table cannot poke through its rounded corner — and an absolutely
         * positioned panel is CLIPPED by that, whatever its z-index. Twenty-two
         * screens put a select inside a card, and on every one of them a list
         * opening near the card's lower edge was cut off mid-option. The owner
         * hit it on the first screen they used.
         *
         * Fixed coordinates take the panel out of every ancestor's overflow.
         * `left` and `width` are physical on purpose: they come from
         * `getBoundingClientRect()`, which is physical in both directions, so
         * the panel sits exactly over its button in RTL and LTR alike.
         */
        get panelStyle() {
            if (!this.open || !this.panelWidth) return '';

            return 'top:' + this.panelTop + 'px;'
                + 'left:' + this.panelLeft + 'px;'
                + 'width:' + this.panelWidth + 'px;';
        },

        /**
         * Measure the button and decide which way the list opens.
         *
         * Called after the panel is visible, because a hidden element has no
         * height and the flip decision needs one.
         */
        place() {
            const button = this.$refs.button;
            if (!button) return;

            const box = button.getBoundingClientRect();
            const panel = this.$refs.panel;
            const height = panel ? panel.offsetHeight : 0;
            const room = window.innerHeight - box.bottom;

            // Open upwards only when there is genuinely no room below AND there
            // is room above; otherwise a short viewport would flip a list into
            // the same problem it was flipped out of.
            this.dropUp = height > 0 && room < height && box.top > height;
            this.panelTop = this.dropUp ? box.top - height : box.bottom;
            this.panelLeft = box.left;
            this.panelWidth = box.width;
        },

        show() {
            this.open = true;
            this.query = '';
            this.activeIndex = this.filtered.findIndex((i) => String(i.value) === String(this.value));
            this.$nextTick(() => {
                this.place();
                const search = this.$refs.search;
                if (search) search.focus();
            });
        },

        close(refocus = false) {
            if (!this.open) return;
            this.open = false;
            this.activeIndex = -1;
            if (refocus && this.$refs.button) this.$refs.button.focus();
        },

        pick(item) {
            if (!item || item.disabled) return;
            this.value = item.value;
            this.close(true);
            this.$dispatch('ui-select-changed', { value: item.value });
        },

        onButtonKeydown(event) {
            if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
                event.preventDefault();
                this.show();
            }
        },

        onListKeydown(event) {
            const list = this.filtered;
            if (event.key === 'Escape') {
                event.preventDefault();
                this.close(true);
                return;
            }
            if (event.key === 'Tab') {
                this.close();
                return;
            }
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (list.length === 0) return;
                const step = event.key === 'ArrowDown' ? 1 : -1;
                this.activeIndex = (this.activeIndex + step + list.length) % list.length;
                this.scrollActiveIntoView();
                return;
            }
            if (event.key === 'Home' || event.key === 'End') {
                event.preventDefault();
                this.activeIndex = event.key === 'Home' ? 0 : list.length - 1;
                this.scrollActiveIntoView();
                return;
            }
            if (event.key === 'Enter') {
                event.preventDefault();
                this.pick(list[this.activeIndex]);
            }
        },

        scrollActiveIntoView() {
            this.$nextTick(() => {
                const node = this.$refs.list && this.$refs.list.querySelector('.is-active');
                if (node) node.scrollIntoView({ block: 'nearest' });
            });
        },
    };
}

/* ==========================================================================
   Progress bar — animates to the server-supplied value, 800ms ease-out
   ========================================================================== */

function uiProgress(options = {}) {
    return {
        target: Math.min(100, Math.max(0, Number(options.value) || 0)),
        shown: 0,

        init() {
            if (REDUCED_MOTION()) {
                this.shown = this.target;
                return;
            }
            // Animate only once the bar is actually on screen, so the motion is
            // seen rather than wasted above the fold.
            const start = () => {
                window.requestAnimationFrame(() => {
                    this.shown = this.target;
                });
            };
            if (!('IntersectionObserver' in window)) {
                start();
                return;
            }
            const observer = new IntersectionObserver(
                (entries) => {
                    entries.forEach((entry) => {
                        if (!entry.isIntersecting) return;
                        start();
                        observer.disconnect();
                    });
                },
                { threshold: 0.2 },
            );
            observer.observe(this.$el);
        },

        /** Re-point the bar without a page reload (used after a live update). */
        setValue(next) {
            this.target = Math.min(100, Math.max(0, Number(next) || 0));
            this.shown = REDUCED_MOTION() ? this.target : this.target;
        },
    };
}

/* ==========================================================================
   Countdown — anchored to server time (BR-07 · Article 11)
   ========================================================================== */

function uiCountdown(options = {}) {
    return {
        // Both instants are rendered by the server from Clock::now().
        targetMs: Date.parse(options.target),
        serverNowMs: Date.parse(options.serverNow),
        skewMs: 0,
        timer: null,
        finished: false,
        urgentBelowMs: Number(options.urgentBelow) > 0 ? Number(options.urgentBelow) : 0,
        days: '00',
        hours: '00',
        minutes: '00',
        seconds: '00',

        init() {
            if (!Number.isFinite(this.targetMs)) {
                this.finished = true;
                return;
            }
            // Measure how far this browser's clock sits from the server's, then
            // never consult the browser clock for anything but elapsed time.
            this.skewMs = Number.isFinite(this.serverNowMs) ? this.serverNowMs - Date.now() : 0;
            this.tick();
            this.timer = window.setInterval(() => this.tick(), 1000);
        },

        destroy() {
            if (this.timer) window.clearInterval(this.timer);
        },

        get remainingMs() {
            return this.targetMs - (Date.now() + this.skewMs);
        },

        get isUrgent() {
            return this.urgentBelowMs > 0 && this.remainingMs > 0 && this.remainingMs <= this.urgentBelowMs;
        },

        tick() {
            const left = this.remainingMs;
            if (left <= 0) {
                this.days = this.hours = this.minutes = this.seconds = '00';
                if (!this.finished) {
                    this.finished = true;
                    if (this.timer) window.clearInterval(this.timer);
                    // The server decides what happens next; the page only asks.
                    this.$dispatch('ui-countdown-finished', { target: options.target });
                }
                return;
            }
            const totalSeconds = Math.floor(left / 1000);
            this.days = pad(totalSeconds / 86400);
            this.hours = pad((totalSeconds % 86400) / 3600);
            this.minutes = pad((totalSeconds % 3600) / 60);
            this.seconds = pad(totalSeconds % 60);
        },
    };
}

/* ==========================================================================
   Search input — 300ms debounce plus a clear button (PRD §5.8)
   ========================================================================== */

function uiSearch(options = {}) {
    return {
        value: options.value || '',
        delay: Number(options.delay) > 0 ? Number(options.delay) : 300,
        timer: null,
        autoSubmit: Boolean(options.autoSubmit),

        onInput() {
            if (this.timer) window.clearTimeout(this.timer);
            this.timer = window.setTimeout(() => this.commit(), this.delay);
        },

        onEnter(event) {
            if (this.timer) window.clearTimeout(this.timer);
            if (!this.autoSubmit) event.preventDefault();
            this.commit();
        },

        clear() {
            if (this.timer) window.clearTimeout(this.timer);
            this.value = '';
            if (this.$refs.input) this.$refs.input.focus();
            this.commit();
        },

        commit() {
            this.$dispatch('ui-search', { value: this.value });
            if (this.autoSubmit && this.$el.form) this.$el.form.requestSubmit();
        },

        destroy() {
            if (this.timer) window.clearTimeout(this.timer);
        },
    };
}

/* ==========================================================================
   File uploader — drag & drop, preview, remove before sending (PRD §5.8)
   ========================================================================== */

function uiUploader(options = {}) {
    return {
        files: [],
        dragging: false,
        multiple: Boolean(options.multiple),
        maxFiles: Number(options.maxFiles) > 0 ? Number(options.maxFiles) : 0,
        maxBytes: Number(options.maxBytes) > 0 ? Number(options.maxBytes) : 0,
        // The server re-validates every one of these. This is convenience only
        // — MIME is checked from file content in PHP (Article 24).
        accept: options.accept || '',
        messages: options.messages || {},
        counter: 0,

        onDrop(event) {
            this.dragging = false;
            this.add(event.dataTransfer ? event.dataTransfer.files : []);
        },

        onPick(event) {
            this.add(event.target.files);
        },

        add(fileList) {
            const incoming = Array.from(fileList || []);
            if (incoming.length === 0) return;
            const accepted = this.multiple ? incoming : incoming.slice(0, 1);
            if (!this.multiple) this.files = [];

            accepted.forEach((file) => {
                if (this.maxFiles && this.files.length >= this.maxFiles) return;
                const entry = {
                    id: `f${++this.counter}`,
                    file,
                    name: file.name,
                    size: readableSize(file.size),
                    preview: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
                    error: this.maxBytes && file.size > this.maxBytes ? this.messages.tooLarge || '' : '',
                    progress: 0,
                };
                this.files.push(entry);
            });

            this.sync();
        },

        remove(id) {
            const entry = this.files.find((f) => f.id === id);
            if (entry && entry.preview) URL.revokeObjectURL(entry.preview);
            this.files = this.files.filter((f) => f.id !== id);
            this.sync();
        },

        /**
         * Rewrite the real <input type="file"> so a plain multipart form submit
         * carries exactly the files still shown — including after a removal.
         */
        sync() {
            const input = this.$refs.input;
            if (!input || typeof DataTransfer === 'undefined') return;
            const bag = new DataTransfer();
            this.files.filter((f) => !f.error).forEach((f) => bag.items.add(f.file));
            input.files = bag.files;
            this.$dispatch('ui-files-changed', { count: bag.files.length });
        },

        get hasFiles() {
            return this.files.length > 0;
        },

        destroy() {
            this.files.forEach((f) => f.preview && URL.revokeObjectURL(f.preview));
        },
    };
}

/* ==========================================================================
   Tooltip — hover AND focus, Escape to dismiss (PRD §5.8)
   ========================================================================== */

function uiTooltip() {
    return {
        open: false,
        show() {
            this.open = true;
        },
        hide() {
            this.open = false;
        },
    };
}

/* ==========================================================================
   Timeline rail — grows to the completed proportion
   ========================================================================== */

function uiTimeline(options = {}) {
    return {
        percent: Math.min(100, Math.max(0, Number(options.percent) || 0)),
        shown: 0,
        init() {
            if (REDUCED_MOTION()) {
                this.shown = this.percent;
                return;
            }
            window.requestAnimationFrame(() => {
                this.shown = this.percent;
            });
        },
    };
}

/* ==========================================================================
   Registration
   ========================================================================== */

export function registerAtharUi(Alpine) {
    Alpine.data('uiDialog', uiDialog);
    Alpine.data('uiToaster', uiToaster);
    Alpine.data('uiTabs', uiTabs);
    Alpine.data('uiSelect', uiSelect);
    Alpine.data('uiProgress', uiProgress);
    Alpine.data('uiCountdown', uiCountdown);
    Alpine.data('uiSearch', uiSearch);
    Alpine.data('uiUploader', uiUploader);
    Alpine.data('uiTooltip', uiTooltip);
    Alpine.data('uiTimeline', uiTimeline);
}

document.addEventListener('alpine:init', () => {
    if (window.Alpine) registerAtharUi(window.Alpine);
});

export default registerAtharUi;
