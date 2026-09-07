/**
 * Entry bundle for the dashboard shell (layouts/app.blade.php).
 *
 * The shared core and nothing else: no neural canvas, no classifier lab, no
 * certificate simulator. Those live in landing.js and reach only the public
 * shell (Constitution Article 19).
 *
 * Keeping app.js an imported module rather than a Vite entry means the three
 * shells (dashboard, public, auth) share ONE built chunk and one cache entry,
 * instead of each shipping its own copy of the same code.
 */

import './app.js';
