/**
 * Entry bundle for the public shell (layouts/public.blade.php).
 *
 * It is the shared core plus the landing-only layer: the living neural canvas,
 * the classifier lab, the certificate simulator and the scroll-driven motion.
 * The dashboard entry (app.js) never imports landing.js, so a signed-in user
 * pays nothing for the marketing page.
 *
 * @see CONSTITUTION Article 19 — landing JS <= 40 KB gzipped
 * @see docs/04-design/ref-js-core.html · ref-js-interactions.html
 *
 * Not one Arabic string lives in this bundle.  Every label is rendered by
 * Blade through __() into a data-* attribute or a JSON island and read back at
 * run time (Article 15).
 */

import './app.js';
import './landing.js';
