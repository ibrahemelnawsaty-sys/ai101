/**
 * Entry bundle for the landing page when it is rendered inside the content
 * editor's preview frame (D-114). Visitors never download it: the public
 * layout asks for it only when the admin preview renders the page.
 *
 * It is the public bundle — the page must behave as a visitor sees it — plus
 * three things a frame needs:
 *
 *   1. The frame stays on the page. A link to anywhere else, and every form,
 *      is swallowed: one stray click would otherwise carry the preview off to
 *      the registration form. A link to a place ON the page scrolls there.
 *   2. The editor can ask for a section: `AtharPreview.focus(anchor, flash)`
 *      scrolls to it and, when asked, outlines it for a moment.
 *   3. Nothing moves on its own. <html data-still> makes every effect take its
 *      reduced-motion path (app.js `reduced()`), so each re-render shows the
 *      page settled instead of replaying its entrance.
 *
 * @see BR-31 · CONSTITUTION Articles 18, 19
 */

import './public.js';

const FLASH_CLASS = 'lp-focus';
const FLASH_MS = 1800;

let flashTimer = 0;

/**
 * Scroll THIS window to an element. scrollIntoView() would also scroll every
 * scrollable ancestor — the editor page around the frame included — so the
 * editor's toolbar jumped out of view whenever a section was opened.
 */
function scrollToElement(el) {
    if (el.id === 'top') {
        window.scrollTo(0, 0);
        return;
    }
    const nav = document.querySelector('.nav');
    const offset = nav ? nav.getBoundingClientRect().height : 0;
    window.scrollTo(0, Math.max(0, el.getBoundingClientRect().top + window.scrollY - offset));
}

/**
 * The element a link points at when it points somewhere on this page. The page
 * is written into the editor's frame through srcdoc, so its own address is
 * `about:srcdoc`: a bare "#about" and a full link to the home page's "#about"
 * both count, and anything that cannot be parsed counts as leaving.
 */
function targetOf(href) {
    if (!href) return null;
    const hash = href.indexOf('#');
    if (hash === -1) return null;
    if (hash > 0) {
        try {
            if (new URL(href, document.baseURI).pathname !== '/') return null;
        } catch (e) {
            return null;
        }
    }
    return document.getElementById(decodeURIComponent(href.slice(hash + 1)));
}

document.addEventListener(
    'click',
    (event) => {
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!link) return;
        event.preventDefault();
        const target = targetOf(link.getAttribute('href'));
        if (target) scrollToElement(target);
    },
    true,
);

document.addEventListener('submit', (event) => event.preventDefault(), true);

window.AtharPreview = {
    focus(anchor, flash) {
        const el = document.getElementById(anchor) || document.getElementById('top');
        if (!el) return;
        scrollToElement(el);
        if (!flash) return;
        window.clearTimeout(flashTimer);
        document.querySelectorAll('.' + FLASH_CLASS).forEach((node) => node.classList.remove(FLASH_CLASS));
        el.classList.add(FLASH_CLASS);
        flashTimer = window.setTimeout(() => el.classList.remove(FLASH_CLASS), FLASH_MS);
    },
};
