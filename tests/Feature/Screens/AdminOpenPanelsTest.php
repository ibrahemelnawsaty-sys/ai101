<?php

declare(strict_types=1);

/**
 * Phase 5 — a panel opened by the address, or an error the last post came back with, is
 * where the person is looking.
 *
 * Both QA passes found the same thing on every administrator screen: «edit», «revoke»,
 * «view details» and every refused form reloaded the page at the top, and the panel — or
 * the message — was 1100 to 7600px lower. The button looked dead. The mechanism already
 * existed (the trainer's panels carry `data-open-panel`, and the script scrolls to the
 * first one and focuses it); these screens simply never used it.
 *
 * @see CONSTITUTION art. 17, art. 18 · D-143, D-147
 */
const ADMIN_PANELS = [
    'audit' => ['@if ($opened)'],
    'certificates' => ['@if ($overriding)', '@if ($revoking)'],
    'cohorts' => ['@if ($editing)', '@if ($seating)', '@if ($assigning)'],
    'programs' => ['@if ($editing)', '@if ($archiving)'],
    'registrations' => ['@if ($reviewing)'],
];

it('D-147: كل لوحة تفتحها روابط المشرف تعلن نفسها ليُؤتى بها إلى الشاشة', function (): void {
    foreach (ADMIN_PANELS as $view => $openers) {
        $source = (string) file_get_contents(resource_path("views/admin/{$view}.blade.php"));

        foreach ($openers as $opener) {
            $at = strpos($source, $opener);

            expect($at)->not->toBeFalse("{$view}: {$opener} not found");

            $card = substr($source, (int) $at, 200);

            expect(str_contains($card, 'data-open-panel') && str_contains($card, 'tabindex="-1"'))
                ->toBeTrue("{$view}: the panel after {$opener} does not announce itself");
        }
    }
});

it('D-147: لوحتا الحقل في المشروع الختامي (الحذف والمحرّر) كذلك', function (): void {
    $source = (string) file_get_contents(resource_path('views/admin/final-project.blade.php'));

    expect($source)->toMatch('/<x-ui\.card data-open-panel tabindex="-1"[^>]*id="field-removal"/')
        ->and($source)->toMatch('/<x-ui\.card data-open-panel tabindex="-1"[^>]*id="field-editor"/');
});

it('D-147: ملخّص أخطاء الشهادات يعلن نفسه كذلك', function (): void {
    $source = (string) file_get_contents(resource_path('views/admin/certificates.blade.php'));

    expect(preg_match('/role="alert"[^>]*data-open-panel tabindex="-1"/', $source))->toBe(1);
});

it('D-147: كل رسالة خطأ تحت خانة في أي نموذج تعلن نفسها ليُؤتى بها إلى الشاشة', function (): void {
    foreach (['input', 'select', 'checkbox', 'radio', 'switch', 'file-uploader'] as $component) {
        $source = (string) file_get_contents(resource_path("views/components/ui/{$component}.blade.php"));

        preg_match_all('/<p class="ui-field__hint ui-field__hint--error"[^>]*>/', $source, $hints);

        expect($hints[0])->not->toBeEmpty($component);

        foreach ($hints[0] as $hint) {
            expect(str_contains($hint, 'data-open-panel') && str_contains($hint, 'tabindex="-1"'))
                ->toBeTrue("{$component}: an error line that does not announce itself");
        }
    }
});

it('D-147: رفض نموذج البث يُرسم تحت خانته بعلامة الإعلان، مرة واحدة', function (): void {
    freezeAt(riyadhAt('2026-10-01 09:00:00'));

    $cohort = makeCohort(['status' => 'running']);
    $admin = makeAdmin();
    $back = route('admin.broadcasts.index');

    $this->actingAs($admin)->from($back)->post(route('admin.broadcasts.store'), [
        'cohort_id' => $cohort->id,
        'subject' => '',
        'body' => '',
    ])->assertRedirect($back);

    $page = $this->actingAs($admin)->get($back)->assertOk()->getContent();

    expect($page)->toMatch('/role="alert" data-open-panel tabindex="-1"/')
        ->and($page)->toContain(e((string) __('admin.broadcasts.body_required')));
});
