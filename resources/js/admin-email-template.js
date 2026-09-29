/**
 * Entry bundle for the e-mail template editor (admin/email-template.blade.php).
 *
 * The dashboard shell plus one Alpine component, named by the page through
 * @section('entry') so the rest of the dashboard never ships the editor
 * (Constitution Article 19), and registered in the same module graph as app.js —
 * before Alpine starts on the next microtask (D-67).
 *
 * @see D-136 · BR-31
 */

import Alpine from 'alpinejs';
import './dashboard.js';
import emailEditor from './email-editor.js';

Alpine.data('emailEditor', emailEditor);
