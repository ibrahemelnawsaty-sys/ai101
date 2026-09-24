/**
 * Entry bundle for the landing-page content editor (admin/landing.blade.php).
 *
 * The dashboard shell plus one Alpine component. It is its own entry, named by
 * the page through @section('entry'), so the rest of the dashboard never ships
 * the editor (Constitution Article 19), and so the component is registered in
 * the same module graph as app.js — before Alpine starts on the next microtask
 * (see the note at the end of app.js, D-67).
 *
 * @see D-114 · BR-31
 */

import Alpine from 'alpinejs';
import './dashboard.js';
import landingEditor from './landing-editor.js';

Alpine.data('landingEditor', landingEditor);
