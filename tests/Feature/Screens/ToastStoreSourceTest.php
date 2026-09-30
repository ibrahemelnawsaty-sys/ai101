<?php

declare(strict_types=1);

/**
 * Phase 5 — a message pushed from script must be a message drawn.
 *
 * `Athar.toast()` pushed onto the plain object the toast store was built from, while the page
 * draws Alpine's reactive proxy of it: everything the landing editor said («published», its
 * refusals, «session expired») went into an array nothing was watching, and the editor
 * looked dead. The pixels were checked in a browser (D-147); this keeps the wiring from
 * drifting back.
 *
 * @see CONSTITUTION art. 17 · D-86, D-147
 */
it('D-147: Athar.toast يدفع عبر مخزن Alpine التفاعلي لا الكائن الخام', function (): void {
    $source = (string) file_get_contents(resource_path('js/app.js'));

    expect($source)->toContain("Alpine.store('toast', toastStore);")
        ->and($source)->toMatch("/toast:\\s*\\(message, tone\\)\\s*=>\\s*\\(Alpine\\.store\\('toast'\\)\\s*\\|\\|\\s*toastStore\\)\\.push\\(message, tone\\)/")
        ->and($source)->not->toMatch('/toast:\s*\(message, tone\)\s*=>\s*toastStore\.push/');
});

it('D-147: محرّر الهبوط لا يكتب في إطار معاينة لم يعد موجودًا', function (): void {
    $source = (string) file_get_contents(resource_path('js/landing-editor.js'));

    expect($source)->toMatch('/const frame = this\.frameNamed\(target\);\s*if \(!frame\) return;/')
        ->and($source)->not->toContain('this.frameNamed(target).srcdoc');
});
