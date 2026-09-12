/**
 * منصة أثر — حزمة لوحة التحكم
 *
 * Alpine bootstrap plus the approved interaction layer.
 * @see docs/04-design/ref-js-core.html · ref-js-interactions.html
 * @see CONSTITUTION Articles 5, 11, 16, 16-bis, 18, 19
 *
 * HARD RULES OBSERVED HERE
 * -----------------------
 * 1. Not one Arabic string.  Every label is passed in from Blade through
 *    `__('file.key')` (Constitution Article 15).
 * 2. The browser clock is never trusted.  Countdowns are anchored to a server
 *    instant rendered into the markup and the offset is applied once (BR-07).
 *    Nothing here decides an attendance window; the server alone does that.
 * 3. `prefers-reduced-motion` is honoured by refusing to *bind* the effect at
 *    all, not merely by shortening it.
 * 4. No `console.*` anywhere (Article 13 rule 1).
 * 5. Arabic text is never split per character.  `decodeWords` splits on
 *    whitespace only (Article 16-bis).
 *
 * The landing-page canvas and the two interactive demos live in landing.js so
 * this bundle stays small (Article 19: landing JS ≤ 40 KB gzipped).
 */

import Alpine from 'alpinejs';

/* Side-effect import: ui.js registers the Blade component behaviours on the
   standard `alpine:init` event, which fires inside Alpine.start() below. */
import './ui.js';

/* ==========================================================================
   Environment probes
   ========================================================================== */

const mqReduce = window.matchMedia('(prefers-reduced-motion: reduce)');
const mqFine = window.matchMedia('(hover: hover) and (pointer: fine)');

/** Reduced motion is re-read on every call so an OS-level change takes effect. */
export const reduced = () => mqReduce.matches;
/** True only for a precise pointer — every tilt/magnet effect is gated on it. */
export const finePointer = () => mqFine.matches;

const clamp = (v, a, b) => (v < a ? a : v > b ? b : v);
const pad2 = (n) => (n < 10 ? '0' + n : String(n));
const qsa = (sel, root = document) => Array.prototype.slice.call(root.querySelectorAll(sel));

const csrfToken = () => {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
};

const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    'summary',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

/* ==========================================================================
   Server-anchored clock
   --------------------------------------------------------------------------
   `<html data-server-now="2026-10-12T15:00:00Z">` is rendered by every layout
   from Clock::now().  We measure the offset between that instant and the
   browser clock once, then every countdown ticks on the corrected value.  A
   user who moves their system clock changes nothing.
   ========================================================================== */

let serverOffsetMs = 0;

function initServerClock() {
    const raw = document.documentElement.getAttribute('data-server-now');
    if (!raw) return;
    const serverMs = Date.parse(raw);
    if (Number.isNaN(serverMs)) return;
    serverOffsetMs = serverMs - Date.now();
}

/** Current instant in milliseconds, corrected to server time (BR-07). */
export const serverNow = () => Date.now() + serverOffsetMs;

/* ==========================================================================
   Progress bars — they fill from the RIGHT
   --------------------------------------------------------------------------
   The element lives in an RTL flow, so growing `inline-size` from 0 grows it
   from the inline-start edge, which is the right-hand edge.  No physical
   property is ever touched.  (Constitution Article 16.)
   ========================================================================== */

/**
 * @param {ParentNode} root
 */
export function animateFills(root = document) {
    qsa('[data-fill]', root).forEach((el) => {
        const pct = clamp(parseFloat(el.dataset.fill) || 0, 0, 100) + '%';
        const vertical = el.hasAttribute('data-fill-vertical');
        const apply = () => {
            if (vertical) el.style.blockSize = pct;
            else el.style.inlineSize = pct;
        };
        if (reduced()) {
            apply();
            return;
        }
        if (vertical) el.style.blockSize = '0';
        else el.style.inlineSize = '0';
        requestAnimationFrame(() => requestAnimationFrame(apply));
    });
}

/** Count a number up to `data-count`, or set it instantly under reduced motion. */
export function animateCounts(root = document) {
    qsa('[data-count]', root).forEach((el) => {
        const target = parseInt(el.dataset.count, 10);
        if (Number.isNaN(target)) return;
        if (reduced()) {
            el.textContent = String(target);
            return;
        }
        const duration = 900;
        let start = null;
        el.textContent = '0';
        const step = (ts) => {
            if (start === null) start = ts;
            const p = Math.min((ts - start) / duration, 1);
            el.textContent = String(Math.round(target * (1 - Math.pow(1 - p, 3))));
            if (p < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
    });
}

/* ==========================================================================
   Reveal on scroll · animated marker highlight
   ========================================================================== */

let revealObserver = null;

export function initReveals(root = document) {
    const items = qsa('.rv', root).filter((el) => !el.classList.contains('is-in'));
    const light = (el, i) => {
        window.setTimeout(() => {
            el.classList.add('is-in');
            animateFills(el);
            animateCounts(el);
        }, reduced() ? 0 : Math.min(i, 6) * 65);
    };

    if (reduced() || !('IntersectionObserver' in window)) {
        items.forEach((el) => light(el, 0));
        return;
    }
    if (!revealObserver) {
        revealObserver = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (!entry.isIntersecting) return;
                    const el = entry.target;
                    const sibs = Array.prototype.slice
                        .call(el.parentNode ? el.parentNode.children : [])
                        .filter((c) => c.classList.contains('rv'));
                    light(el, Math.max(0, sibs.indexOf(el)));
                    revealObserver.unobserve(el);
                });
            },
            { rootMargin: '0px 0px -8% 0px', threshold: 0.08 }
        );
    }
    items.forEach((el) => {
        if (el.getBoundingClientRect().top < window.innerHeight * 0.95) light(el, 0);
        else revealObserver.observe(el);
    });
}

/** The marker pen sweep behind an inline phrase, and the drawn box variant. */
export function initHighlights(root = document) {
    const items = qsa('.hl, .hlbox', root);
    items.forEach((el) => {
        const rect = el.querySelector('rect');
        if (!rect) return;
        requestAnimationFrame(() => {
            let len = 600;
            try {
                if (typeof rect.getTotalLength === 'function') len = rect.getTotalLength() || 600;
            } catch (e) {
                len = 600;
            }
            el.style.setProperty('--len', String(Math.round(len)));
        });
    });

    if (reduced() || !('IntersectionObserver' in window)) {
        items.forEach((el) => el.classList.add('is-lit'));
        return;
    }
    const ob = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                window.setTimeout(() => entry.target.classList.add('is-lit'), 180);
                ob.unobserve(entry.target);
            });
        },
        { threshold: 0.6 }
    );
    items.forEach((el) => ob.observe(el));
}

/**
 * Word-level heading entrance.
 * Arabic letters connect, so the split is on whitespace and never on a
 * character.  (Constitution Article 16-bis — non-negotiable.)
 */
export function decodeWords(root = document) {
    if (reduced()) return;
    qsa('[data-decode]', root).forEach((el) => {
        if (el.dataset.decoded === '1') return;
        el.dataset.decoded = '1';
        const parts = el.textContent.split(/(\s+)/);
        el.textContent = '';
        const spans = [];
        parts.forEach((part) => {
            if (!part.trim()) {
                el.appendChild(document.createTextNode(part));
                return;
            }
            const span = document.createElement('span');
            span.className = 'wd';
            span.textContent = part;
            el.appendChild(span);
            spans.push(span);
        });
        const delay = parseInt(el.dataset.delay || '0', 10);
        window.setTimeout(() => {
            spans.forEach((s, i) => window.setTimeout(() => s.classList.add('is-lit'), i * 95));
        }, delay + 140);
    });

    qsa('[data-wipe]', root).forEach((el) => {
        el.classList.add('wipe-t');
        const delay = parseInt(el.dataset.delay || '0', 10);
        window.setTimeout(() => el.classList.add('is-lit'), delay + 140);
    });
}

/* ==========================================================================
   3D tilt — capped at 8°, refused on touch and on reduced motion
   ========================================================================== */

const TILT_MAX_DEG = 8;

export function initTilt(root = document) {
    if (reduced() || !finePointer()) return;
    qsa('.tilt', root).forEach((el) => {
        if (el.dataset.tiltBound === '1') return;
        el.dataset.tiltBound = '1';
        el.addEventListener('pointermove', (e) => {
            if (e.pointerType === 'touch') return;
            const r = el.getBoundingClientRect();
            const px = (e.clientX - r.left) / r.width;
            const py = (e.clientY - r.top) / r.height;
            el.style.setProperty('--cx', (px * 100).toFixed(1) + '%');
            el.style.setProperty('--cy', (py * 100).toFixed(1) + '%');
            const ry = clamp((px - 0.5) * 2 * TILT_MAX_DEG, -TILT_MAX_DEG, TILT_MAX_DEG);
            const rx = clamp((0.5 - py) * 2 * TILT_MAX_DEG, -TILT_MAX_DEG, TILT_MAX_DEG);
            el.style.transform =
                'perspective(900px) rotateY(' + ry.toFixed(2) + 'deg) rotateX(' + rx.toFixed(2) + 'deg) translateY(-4px)';
        });
        el.addEventListener('pointerleave', () => {
            el.style.transform = '';
        });
    });
}

/* ==========================================================================
   Magnetic buttons + click ripple
   ========================================================================== */

export function initMagnets(root = document) {
    if (reduced() || !finePointer()) return;
    qsa('.mag', root).forEach((btn) => {
        if (btn.dataset.magBound === '1') return;
        btn.dataset.magBound = '1';
        const inner = btn.querySelector('.mag__t');
        btn.addEventListener('pointermove', (e) => {
            const r = btn.getBoundingClientRect();
            const dx = (e.clientX - (r.left + r.width / 2)) / r.width;
            const dy = (e.clientY - (r.top + r.height / 2)) / r.height;
            btn.style.transform = 'translate(' + (dx * 14).toFixed(1) + 'px,' + (dy * 9).toFixed(1) + 'px)';
            if (inner) inner.style.transform = 'translate(' + (dx * 7).toFixed(1) + 'px,' + (dy * 4).toFixed(1) + 'px)';
        });
        btn.addEventListener('pointerleave', () => {
            btn.style.transform = '';
            if (inner) inner.style.transform = '';
        });
    });
}

export function initRipples() {
    document.addEventListener('click', (e) => {
        if (reduced()) return;
        const btn = e.target.closest ? e.target.closest('.btn') : null;
        if (!btn || btn.hasAttribute('disabled')) return;
        const r = btn.getBoundingClientRect();
        const size = Math.max(r.width, r.height);
        const dot = document.createElement('span');
        dot.className = 'rip';
        dot.style.inlineSize = size + 'px';
        dot.style.blockSize = size + 'px';
        dot.style.insetInlineStart = e.clientX - r.left - size / 2 + 'px';
        dot.style.insetBlockStart = e.clientY - r.top - size / 2 + 'px';
        if (window.getComputedStyle(btn).position === 'static') btn.style.position = 'relative';
        btn.style.overflow = 'hidden';
        btn.appendChild(dot);
        window.setTimeout(() => dot.remove(), 640);
    });
}

/* ==========================================================================
   Confetti — 2 seconds maximum, brand colours only
   ========================================================================== */

const CONFETTI_MS = 2000;
const CONFETTI_VARS = ['--confetti-1', '--confetti-2', '--confetti-3', '--confetti-4'];

/**
 * @param {HTMLElement|null} host element carrying `.cf`
 */
export function confetti(host) {
    if (!host || reduced()) return;
    if (host.dataset.busy === '1') return;
    host.dataset.busy = '1';
    const styles = getComputedStyle(document.documentElement);
    const colours = CONFETTI_VARS.map((v) => styles.getPropertyValue(v).trim());
    for (let i = 0; i < 48; i++) {
        const bit = document.createElement('i');
        bit.style.insetInlineStart = (12 + Math.random() * 76).toFixed(1) + '%';
        bit.style.insetBlockStart = (14 + Math.random() * 12).toFixed(1) + '%';
        bit.style.background = colours[i % colours.length];
        bit.style.animationDelay = (Math.random() * 0.35).toFixed(2) + 's';
        host.appendChild(bit);
    }
    window.setTimeout(() => {
        host.innerHTML = '';
        host.dataset.busy = '0';
    }, CONFETTI_MS);
}

/* ==========================================================================
   Logo draw-in
   ========================================================================== */

function initLogo() {
    window.setTimeout(() => {
        qsa('.logo--draw').forEach((l) => l.classList.add('is-drawn'));
    }, 120);
}

/* ==========================================================================
   Alpine components
   ========================================================================== */

/**
 * Countdown to a server instant.
 *
 * Blade renders:
 *   x-data="countdown({ target: '2026-10-12T15:00:00Z', labels: {...} })"
 * where every label already went through __() .
 *
 * The value shown is a courtesy.  Nothing may be unlocked because this hits
 * zero: the server re-checks the window on the request (Article 5, BR-07).
 */
function countdown() {
    return (config = {}) => ({
        targetMs: Date.parse(config.target || ''),
        days: '00',
        hours: '00',
        minutes: '00',
        seconds: '00',
        finished: false,
        timer: null,

        init() {
            if (Number.isNaN(this.targetMs)) {
                this.finished = true;
                return;
            }
            this.tick();
            this.timer = window.setInterval(() => this.tick(), 1000);
            this.$watch('finished', (value) => {
                if (!value) return;
                window.clearInterval(this.timer);
                this.$dispatch('countdown-finished');
            });
        },

        destroy() {
            window.clearInterval(this.timer);
        },

        tick() {
            const left = Math.max(0, this.targetMs - serverNow());
            this.days = pad2(Math.floor(left / 86400000));
            this.hours = pad2(Math.floor(left / 3600000) % 24);
            this.minutes = pad2(Math.floor(left / 60000) % 60);
            this.seconds = pad2(Math.floor(left / 1000) % 60);
            if (left <= 0) this.finished = true;
        },
    });
}

/** Right-hand drawer for the mobile sidebar (Constitution Article 16). */
function drawer() {
    return () => ({
        open: false,
        previouslyFocused: null,

        show() {
            this.previouslyFocused = document.activeElement;
            this.open = true;
            document.body.style.overflow = 'hidden';
            this.$nextTick(() => {
                const panel = this.$refs.panel;
                if (!panel) return;
                const first = panel.querySelector(FOCUSABLE);
                if (first) first.focus();
            });
        },

        hide() {
            this.open = false;
            document.body.style.overflow = '';
            if (this.previouslyFocused && this.previouslyFocused.focus) this.previouslyFocused.focus();
        },

        toggle() {
            if (this.open) this.hide();
            else this.show();
        },

        trap(event) {
            if (!this.open || event.key !== 'Tab') return;
            trapTab(event, this.$refs.panel);
        },
    });
}

/** Modal dialog: Escape closes, click outside closes, focus is trapped. */
function modal() {
    return (config = {}) => ({
        open: Boolean(config.open),
        previouslyFocused: null,

        show() {
            this.previouslyFocused = document.activeElement;
            this.open = true;
            document.body.style.overflow = 'hidden';
            this.$nextTick(() => {
                const box = this.$refs.box;
                if (!box) return;
                const first = box.querySelector(FOCUSABLE);
                (first || box).focus();
            });
        },

        hide() {
            this.open = false;
            document.body.style.overflow = '';
            if (this.previouslyFocused && this.previouslyFocused.focus) this.previouslyFocused.focus();
            this.$dispatch('modal-closed');
        },

        trap(event) {
            if (!this.open || event.key !== 'Tab') return;
            trapTab(event, this.$refs.box);
        },
    });
}

/** Shared Tab-cycling logic for modal and drawer. */
function trapTab(event, container) {
    if (!container) return;
    const nodes = qsa(FOCUSABLE, container).filter((el) => el.offsetParent !== null || el === document.activeElement);
    if (nodes.length === 0) {
        event.preventDefault();
        return;
    }
    const first = nodes[0];
    const last = nodes[nodes.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

/** Dropdown menu: Escape closes and returns focus, click outside closes. */
function menu() {
    return () => ({
        open: false,
        trigger: null,

        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.trigger = document.activeElement;
                this.$nextTick(() => {
                    const panel = this.$refs.panel;
                    if (!panel) return;
                    const first = panel.querySelector(FOCUSABLE);
                    if (first) first.focus();
                });
            }
        },

        close(returnFocus = true) {
            if (!this.open) return;
            this.open = false;
            if (returnFocus && this.trigger && this.trigger.focus) this.trigger.focus();
        },
    });
}

/** Tooltip: shows on hover AND on keyboard focus (PRD §5.8). */
function tooltip() {
    return () => ({
        open: false,
        show() { this.open = true; },
        hide() { this.open = false; },
    });
}

/** Sidebar collapse state, remembered per browser. */
function sidebar() {
    return (config = {}) => ({
        collapsed: false,
        storageKey: 'athar.sidebar.collapsed',

        init() {
            try {
                const stored = window.localStorage.getItem(this.storageKey);
                this.collapsed = stored === null ? Boolean(config.collapsed) : stored === '1';
            } catch (e) {
                this.collapsed = Boolean(config.collapsed);
            }
        },

        toggle() {
            this.collapsed = !this.collapsed;
            try {
                window.localStorage.setItem(this.storageKey, this.collapsed ? '1' : '0');
            } catch (e) {
                // Private mode: the preference simply does not persist.
            }
        },
    });
}

/**
 * File uploader with real transfer progress.
 *
 * Client-side checks here are a courtesy for the user.  The server re-validates
 * size, count and MIME from file CONTENT before accepting anything
 * (Constitution Article 5, Article 24).
 */
function uploader() {
    return (config = {}) => ({
        endpoint: config.endpoint || '',
        fieldName: config.field || 'file',
        maxBytes: Number(config.maxBytes || 0),
        maxFiles: Number(config.maxFiles || 1),
        accept: config.accept || '',
        files: [],
        hot: false,
        state: 'idle', // idle | uploading | done | error
        errorKey: '',

        pick() {
            if (this.$refs.input) this.$refs.input.click();
        },

        onDrop(event) {
            this.hot = false;
            this.add(event.dataTransfer ? event.dataTransfer.files : []);
        },

        onChange(event) {
            this.add(event.target.files);
            event.target.value = '';
        },

        add(fileList) {
            const incoming = Array.prototype.slice.call(fileList || []);
            for (const file of incoming) {
                if (this.files.length >= this.maxFiles) {
                    this.fail('too_many');
                    return;
                }
                if (this.maxBytes > 0 && file.size > this.maxBytes) {
                    this.fail('too_large');
                    return;
                }
                this.files.push({
                    id: 'f' + Date.now() + Math.random().toString(36).slice(2, 8),
                    name: file.name,
                    size: file.size,
                    loaded: 0,
                    percent: 0,
                    status: 'ready',
                    raw: file,
                    xhr: null,
                });
            }
            this.errorKey = '';
            this.state = 'idle';
        },

        remove(id) {
            const idx = this.files.findIndex((f) => f.id === id);
            if (idx === -1) return;
            const entry = this.files[idx];
            if (entry.xhr) entry.xhr.abort();
            this.files.splice(idx, 1);
        },

        fail(key) {
            this.errorKey = key;
            this.state = 'error';
        },

        humanSize(bytes) {
            const mb = bytes / (1024 * 1024);
            return mb >= 1 ? mb.toFixed(1) : (bytes / 1024).toFixed(0);
        },

        /** Uploads every ready file over XHR so real byte progress is available. */
        submit() {
            if (this.state === 'uploading' || this.files.length === 0 || !this.endpoint) return;
            this.state = 'uploading';
            this.errorKey = '';
            let remaining = this.files.length;

            this.files.forEach((entry) => {
                if (entry.status === 'done') {
                    remaining -= 1;
                    return;
                }
                const form = new FormData();
                form.append(this.fieldName, entry.raw, entry.name);
                form.append('_token', csrfToken());

                const xhr = new XMLHttpRequest();
                entry.xhr = xhr;
                entry.status = 'uploading';
                xhr.open('POST', this.endpoint, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken());

                xhr.upload.onprogress = (e) => {
                    if (!e.lengthComputable) return;
                    entry.loaded = e.loaded;
                    entry.percent = Math.round((e.loaded / e.total) * 100);
                };

                xhr.onload = () => {
                    entry.xhr = null;
                    if (xhr.status >= 200 && xhr.status < 300) {
                        entry.percent = 100;
                        entry.status = 'done';
                    } else {
                        entry.status = 'error';
                        this.errorKey = xhr.status === 413 ? 'too_large' : 'server';
                    }
                    remaining -= 1;
                    if (remaining <= 0) this.settle();
                };

                xhr.onerror = () => {
                    entry.xhr = null;
                    entry.status = 'error';
                    this.errorKey = 'network';
                    remaining -= 1;
                    if (remaining <= 0) this.settle();
                };

                xhr.send(form);
            });

            if (remaining <= 0) this.settle();
        },

        settle() {
            const failed = this.files.some((f) => f.status === 'error');
            this.state = failed ? 'error' : 'done';
            if (!failed) {
                this.$dispatch('upload-complete', { count: this.files.length });
                confetti(this.$refs.confetti || null);
            }
        },
    });
}

/**
 * Async panel — the four mandatory states of Article 17 in one place.
 * Fetches a fragment URL and renders loading / empty / error / normal.
 */
function asyncPanel() {
    return (config = {}) => ({
        url: config.url || '',
        state: 'loading', // loading | normal | empty | error
        html: '',

        init() {
            if (!this.url) {
                this.state = 'empty';
                return;
            }
            this.load();
        },

        async load() {
            this.state = 'loading';
            try {
                const response = await fetch(this.url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                    credentials: 'same-origin',
                });
                if (!response.ok) {
                    this.state = 'error';
                    return;
                }
                const body = (await response.text()).trim();
                this.html = body;
                this.state = body.length === 0 ? 'empty' : 'normal';
                this.$nextTick(() => {
                    const host = this.$refs.body;
                    if (!host) return;
                    animateFills(host);
                    animateCounts(host);
                    initTilt(host);
                });
            } catch (e) {
                this.state = 'error';
            }
        },
    });
}

/** Impersonation bar countdown to the hard 30-minute cap (Article 23). */
function impersonation() {
    return (config = {}) => ({
        endsAtMs: Date.parse(config.endsAt || ''),
        label: '00:00',
        expired: false,
        timer: null,

        init() {
            if (Number.isNaN(this.endsAtMs)) return;
            this.tick();
            this.timer = window.setInterval(() => this.tick(), 1000);
        },

        destroy() {
            window.clearInterval(this.timer);
        },

        tick() {
            const left = Math.max(0, this.endsAtMs - serverNow());
            this.label = pad2(Math.floor(left / 60000)) + ':' + pad2(Math.floor(left / 1000) % 60);
            if (left > 0) return;
            this.expired = true;
            window.clearInterval(this.timer);
            // The session is already dead server-side; reload so the server says so.
            if (this.$refs.stop) this.$refs.stop.submit();
        },
    });
}

/* ==========================================================================
   Toast store — one aria-live region for the whole page
   ========================================================================== */

const TOAST_MS = 5000;

const toastStore = {
    items: [],
    seq: 0,

    /**
     * @param {string} message already translated in PHP
     * @param {'info'|'ok'|'warn'|'bad'} tone
     */
    push(message, tone = 'info') {
        const id = ++this.seq;
        this.items.push({ id, message, tone });
        window.setTimeout(() => this.dismiss(id), TOAST_MS);
        return id;
    },

    dismiss(id) {
        const idx = this.items.findIndex((t) => t.id === id);
        if (idx !== -1) this.items.splice(idx, 1);
    },
};

/* ==========================================================================
   Boot
   ========================================================================== */

initServerClock();

/**
 * Copy one value to the clipboard.
 *
 * The card screen called `atharCopy({ value })` and nothing registered it, so
 * the button threw and the label never changed — D-55. Written here rather than
 * removed because a sixty-character verification URL is exactly the thing
 * nobody should have to select by hand on a phone.
 *
 * Falls back to selecting the text when the Clipboard API is unavailable or
 * refused: over plain HTTP, and in some in-app browsers, navigator.clipboard
 * simply does not exist.
 */
function atharCopy() {
    return (config = {}) => ({
        value: config.value || '',
        copied: false,

        async copy() {
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(this.value);
                } else {
                    const helper = document.createElement('textarea');
                    helper.value = this.value;
                    helper.setAttribute('readonly', '');
                    helper.style.position = 'fixed';
                    helper.style.insetInlineStart = '-9999px';
                    document.body.appendChild(helper);
                    helper.select();
                    document.execCommand('copy');
                    document.body.removeChild(helper);
                }

                this.copied = true;
                setTimeout(() => { this.copied = false; }, 2000);
            } catch (error) {
                // A refused clipboard is not worth an error dialogue; the URL is
                // printed beside the button and can still be selected.
                this.copied = false;
            }
        },
    });
}

/**
 * Share the verification link through the operating system's own sheet.
 *
 * `atharShare({ url, title })` was called and never registered — D-55. Where
 * navigator.share does not exist (every desktop browser), the button copies
 * instead, which is the useful thing rather than a dead control.
 */
function atharShare() {
    return (config = {}) => ({
        url: config.url || '',
        title: config.title || '',
        shared: false,

        get supported() {
            return typeof navigator !== 'undefined' && typeof navigator.share === 'function';
        },

        async share() {
            try {
                if (this.supported) {
                    await navigator.share({ title: this.title, url: this.url });
                } else if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(this.url);
                }

                this.shared = true;
                setTimeout(() => { this.shared = false; }, 2000);
            } catch (error) {
                // A cancelled share sheet throws. That is the user declining,
                // not a failure to report.
                this.shared = false;
            }
        },
    });
}

/**
 * The card's pointer tilt.
 *
 * `atharCardTilt({ maxDegrees: 8 })` was called and never registered — D-55.
 * Honours prefers-reduced-motion and does nothing on a coarse pointer: a card
 * that tilts under a finger is a card that fights the scroll.
 */
function atharCardTilt() {
    return (config = {}) => ({
        max: Number(config.maxDegrees || 8),
        rx: 0,
        ry: 0,

        get enabled() {
            return finePointer() && !reduced();
        },

        /*
         * `track` and `transformStyle`, not `move` and `style`: those are the
         * names the card markup has always called, and registering a component
         * under the right name with the wrong members leaves it just as dead
         * — `Alpine Expression Error: transformStyle is not defined` (D-58).
         *
         * `style` would also have been a poor name to bind through
         * `x-bind:style`, where it reads as the DOM property rather than as a
         * value this object computes.
         */
        track(event) {
            if (!this.enabled) return;
            const box = this.$el.getBoundingClientRect();
            if (!box.width || !box.height) return;
            const px = (event.clientX - box.left) / box.width - 0.5;
            const py = (event.clientY - box.top) / box.height - 0.5;
            this.ry = px * this.max * 2;
            this.rx = -py * this.max * 2;
        },

        reset() {
            this.rx = 0;
            this.ry = 0;
        },

        get transformStyle() {
            return this.enabled
                ? `transform: perspective(900px) rotateX(${this.rx}deg) rotateY(${this.ry}deg)`
                : '';
        },
    });
}

/**
 * The one-time welcome after an invited trainee sets their own password.
 *
 * REDUCED MOTION IS HONOURED BY NOT BINDING THE EFFECT. `confetti()` already
 * returns early when the preference is set, but the guard is repeated here so
 * the intent is readable at the call site: a reader who asked for stillness is
 * not given a shortened animation, they are given none, and the panel around it
 * is the whole welcome on its own.
 *
 * The panel dismisses itself after the pieces have fallen, so nobody has to
 * clear it — and `dismiss()` exists because somebody will want it gone sooner.
 */
function atharWelcome() {
    return (config = {}) => ({
        open: true,
        // Long enough to read the two lines, and it is a status region rather
        // than a dialog: nothing is trapped and nothing waits on it.
        life: Number(config.ms || 9000),
        timer: null,

        start() {
            if (!reduced()) {
                confetti(this.$refs.stage);
            }

            this.timer = window.setTimeout(() => { this.open = false; }, this.life);
        },

        dismiss() {
            if (this.timer) window.clearTimeout(this.timer);
            this.open = false;
        },
    });
}

/* ==========================================================================
   Three components the templates called and nothing defined (D-67)
   ==========================================================================
   When an x-data expression throws, Alpine still gives the element an empty
   scope, every child x-show then evaluates to undefined, and the element is
   HIDDEN. So a missing component is not a missing enhancement: it hides the
   content it wraps. The participant's schedule rendered its toolbar and no
   schedule at all. */

/**
 * The schedule's two views (PRD §9.8) — weekly accordion or calendar — with the
 * choice remembered in this browser.
 *
 * `initial` is the server's answer: the `view` query parameter when the URL
 * names one (the calendar's week arrows do), otherwise what the session
 * remembered. The URL wins over local storage, so a link to the calendar opens
 * the calendar; storage only fills in when the URL is silent.
 */
function atharSchedule() {
    const VIEWS = ['accordion', 'calendar'];

    return (config = {}) => ({
        view: VIEWS.includes(config.initial) ? config.initial : 'accordion',

        init() {
            const fromUrl = new URLSearchParams(window.location.search).get('view');
            if (VIEWS.includes(fromUrl)) return;
            try {
                const stored = window.localStorage.getItem(config.storageKey || '');
                if (VIEWS.includes(stored)) this.view = stored;
            } catch (error) {
                /* Storage can be blocked; the server's default stands. */
            }
        },

        select(view) {
            if (!VIEWS.includes(view)) return;
            this.view = view;
            try {
                window.localStorage.setItem(config.storageKey || '', view);
            } catch (error) {
                /* Remembering is a convenience, never a requirement. */
            }
        },
    });
}

/**
 * A conversation that updates without a reload (PRD §9.13.2).
 *
 * The server renders the message list — the same Blade partial the page was
 * built from — and this only swaps it in when the newest message changed. So
 * there is one source of presentation, and user content is escaped by Blade,
 * never assembled here. A failed poll changes nothing on screen: the next one
 * tries again, and a hidden tab does not poll at all.
 */
function atharThread() {
    return (config = {}) => ({
        latest: '',
        busy: false,
        timer: null,
        onVisible: null,

        init() {
            const stream = this.$refs.stream;
            this.latest = stream ? stream.getAttribute('data-latest') || '' : '';
            this.$nextTick(() => this.scrollToEnd());

            const seconds = Number(config.pollSeconds) || 0;
            if (!config.pollUrl || seconds <= 0) return;

            this.timer = window.setInterval(() => this.poll(), seconds * 1000);
            this.onVisible = () => {
                if (!document.hidden) this.poll();
            };
            document.addEventListener('visibilitychange', this.onVisible);
        },

        destroy() {
            window.clearInterval(this.timer);
            if (this.onVisible) document.removeEventListener('visibilitychange', this.onVisible);
        },

        nearEnd() {
            const stream = this.$refs.stream;
            if (!stream) return false;
            return stream.scrollHeight - stream.scrollTop - stream.clientHeight < 120;
        },

        scrollToEnd() {
            const stream = this.$refs.stream;
            if (stream) stream.scrollTop = stream.scrollHeight;
        },

        async poll() {
            if (this.busy || document.hidden) return;
            this.busy = true;
            try {
                const response = await window.fetch(config.pollUrl, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) return;
                const data = await response.json();
                if (!data || typeof data.html !== 'string') return;
                const latest = typeof data.latest === 'string' ? data.latest : '';
                if (latest === this.latest) return;

                // Follow the conversation only if the reader was already at its
                // end; someone scrolled up to reread is not yanked away.
                const follow = this.nearEnd();
                this.$refs.stream.innerHTML = data.html;
                this.$refs.stream.setAttribute('data-latest', latest);
                this.latest = latest;
                if (follow) this.$nextTick(() => this.scrollToEnd());
            } catch (error) {
                /* Offline or a server hiccup: keep what is on screen. */
            } finally {
                this.busy = false;
            }
        },
    });
}

/**
 * The wait before "send the link again" is offered (PRD §9.2.3). The instant
 * comes from the server and is counted against server time (BR-07); the server
 * enforces the limit again when the form arrives — this only avoids offering a
 * button that would be refused.
 */
function resendCooldown() {
    return (config = {}) => ({
        availableMs: Date.parse(config.availableAt || ''),
        remaining: 0,
        ready: true,
        timer: null,

        init() {
            if (Number.isNaN(this.availableMs)) return;
            this.tick();
            if (!this.ready) this.timer = window.setInterval(() => this.tick(), 1000);
        },

        destroy() {
            window.clearInterval(this.timer);
        },

        tick() {
            const left = Math.max(0, this.availableMs - serverNow());
            this.remaining = Math.ceil(left / 1000);
            this.ready = left <= 0;
            if (this.ready) window.clearInterval(this.timer);
        },
    });
}

Alpine.store('toast', toastStore);
Alpine.data('countdown', countdown());
Alpine.data('drawer', drawer());
Alpine.data('modal', modal());
Alpine.data('menu', menu());
Alpine.data('tooltip', tooltip());
Alpine.data('sidebar', sidebar());
Alpine.data('uploader', uploader());
Alpine.data('asyncPanel', asyncPanel());
Alpine.data('impersonation', impersonation());
Alpine.data('atharCopy', atharCopy());
Alpine.data('atharShare', atharShare());
Alpine.data('atharCardTilt', atharCardTilt());
Alpine.data('atharWelcome', atharWelcome());
Alpine.data('atharSchedule', atharSchedule());
Alpine.data('atharThread', atharThread());
Alpine.data('resendCooldown', resendCooldown());

window.Alpine = Alpine;

/** Small public surface so page scripts and landing.js can reuse the helpers. */
window.Athar = {
    reduced,
    finePointer,
    serverNow,
    animateFills,
    animateCounts,
    initReveals,
    initHighlights,
    decodeWords,
    initTilt,
    initMagnets,
    confetti,
    toast: (message, tone) => toastStore.push(message, tone),
};

/**
 * Paints the browser chrome with the page background.
 *
 * The value is read from --surface-page at run time rather than written into
 * the Blade file, because a colour literal in a template is forbidden and
 * tokens.css is the single source of every colour (Article 6, Article 13
 * rule 4).  A missing meta tag or an unsupported browser simply means no tint.
 */
function syncThemeColour() {
    const meta = document.querySelector('meta[name="theme-color"]');
    if (!meta) return;
    const value = getComputedStyle(document.documentElement)
        .getPropertyValue('--surface-page')
        .trim();
    if (value) meta.setAttribute('content', value);
}

function boot() {
    syncThemeColour();
    initLogo();
    initReveals();
    initHighlights();
    decodeWords();
    initTilt();
    initMagnets();
    initRipples();
    animateFills();
    animateCounts();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}

/* Started on the next microtask, never synchronously.
   The entry bundles (auth.js, dashboard.js, public.js) import THIS module first,
   and ES modules evaluate their dependencies before themselves. A synchronous
   start therefore fired `alpine:init` while the entry's own listener did not
   exist yet: registerWizard was never registered, and the registration wizard
   rendered with every pane, field and button hidden (D-67). Every module in the
   graph finishes evaluating before the first microtask runs, so each entry's
   listener is in place when Alpine asks.
   Do not "fix" this by registering in a module imported before app.js: the
   bundler inlines that module into the entry chunk and hoists this import
   above it, which restores the original order. */
queueMicrotask(() => Alpine.start());
