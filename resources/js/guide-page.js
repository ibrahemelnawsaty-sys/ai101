/**
 * The final project's guide page — its one script (D-127).
 *
 * The page is a document the general supervisor uploads; none of ITS scripts
 * ever run (GuideDocument serves it under a nonce-only script-src). This file
 * is inlined under that nonce and gives the page back the one behaviour it
 * had: the "Copy" button on every code block.
 *
 * Its words come from the platform's language files, through the data-*
 * attributes GuideDocument writes on this very <script> element.
 *
 * @see D-127 · CONSTITUTION Art. 15, Art. 18, Art. 24
 */
(function () {
    'use strict';

    var script = document.currentScript;
    var copiedLabel = script ? script.getAttribute('data-copied') || '' : '';
    var pressLabel = script ? script.getAttribute('data-press') || '' : '';

    document.addEventListener('click', function (event) {
        var target = event.target;
        var button = target && target.closest ? target.closest('.copy-btn') : null;

        if (!button) {
            return;
        }

        var block = button.closest('.code-block');
        var code = block ? block.querySelector('code') : null;

        if (!code) {
            return;
        }

        if (!button.hasAttribute('data-label')) {
            button.setAttribute('data-label', button.textContent);
        }

        button.setAttribute('aria-live', 'polite');

        var label = button.getAttribute('data-label');

        function restore() {
            button.textContent = label;
            button.classList.remove('copied');
        }

        function done() {
            button.textContent = copiedLabel;
            button.classList.add('copied');
            window.setTimeout(restore, 1600);
        }

        function selectOnly() {
            var range = document.createRange();
            range.selectNodeContents(code);

            var selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);

            button.textContent = pressLabel;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(code.textContent).then(done, selectOnly);
        } else {
            selectOnly();
        }
    });
})();
