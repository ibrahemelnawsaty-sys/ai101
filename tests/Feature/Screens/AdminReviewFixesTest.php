<?php

declare(strict_types=1);

/**
 * Phase 5 — what the independent interface review of the administrator's screens found,
 * and what was fixed for it.
 *
 * Most of these are markup and style, whose pixels were checked in a browser (D-147); what
 * can be read from the source or the rendered page is held here so it does not drift back:
 * a button that cannot be taken back asks first and names who it reaches, a dialog's warning
 * matches the rule the server actually applies, a preview takes no Tab, the toasts start
 * below the header.
 *
 * @see CONSTITUTION art. 17, art. 18 · D-127, D-147
 */

use App\Services\Cohorts\PrimaryCoordinator;

function screenSource(string $relative): string
{
    return (string) file_get_contents(base_path($relative));
}

it('D-147: زر «إرسال» في البث بلا دفعة يذهب إلى الخادم ليقول ما ينقص، ولا يفتح نافذة تأكيد فارغة', function (): void {
    freezeAt(riyadhAt('2026-10-01 09:00:00'));

    $cohort = makeCohort(['status' => 'running', 'name' => 'Cohort of the review']);
    $html = $this->actingAs(makeAdmin())->get(route('admin.broadcasts.index'))->assertOk()->getContent();

    // No cohort chosen → the form is submitted as it is (the server refuses and says why).
    expect($html)->toContain('if (! cohort) { f.requestSubmit(); return; }')
        // Cohort chosen → the dialog opens and reads back WHO and WHAT before the send.
        ->and($html)->toContain('id="broadcast-summary"')
        ->and($html)->toContain('x-text="target"')
        ->and($html)->toContain('x-text="subject"')
        // The label the dialog reads back is the same one the picker shows (name and reach).
        ->and($html)->toContain('Cohort of the review')
        ->and($html)->toContain($cohort->id);
});

it('D-147: الإصدار الجماعي يسأل بعدد المحدَّدين، ويبقى زرّ إرسال عاديًّا بلا سكربت', function (): void {
    $source = screenSource('resources/views/admin/certificates.blade.php');

    expect($source)->toContain('id="bulk-issue-form"')
        ->and($source)->toMatch('/data-action="issue-selected"[^>]*\s+x-on:click\.prevent="\$dispatch\(\'ui-dialog-open\', \'confirm-issue-selected\'\)"/s')
        ->and($source)->toMatch('/<x-ui\.confirm name="confirm-issue-selected" target-form="bulk-issue-form"/')
        ->and($source)->toContain('x-text="picked"');
});

it('BR-25: إعادة الإصدار تسأل باسم صاحب الشهادة ثم ترسل، والنموذج داخل النافذة لا خارجها', function (): void {
    freezeAt(riyadhAt('2026-11-20 12:00:00'));

    $cohort = makeCohort(['status' => 'completed']);
    $holder = makeParticipant($cohort);
    $certificate = issueCertificateFor($holder, $cohort);
    $certificate->update(['revoked_at' => riyadhAt('2026-11-10 09:00:00')]);

    $html = $this->actingAs(makeAdmin())
        ->get(route('admin.certificates.index', ['cohort' => $cohort->id]))
        ->assertOk()->getContent();

    $action = route('admin.certificates.reissue', $certificate->id);

    // Exactly one form posts to the reissue route, and it is the dialog's own.
    expect(substr_count($html, 'action="'.$action.'"'))->toBe(1)
        ->and($html)->toContain(e(__('certificates.admin.reissue_title', ['name' => $holder->name])))
        ->and($html)->toContain(e(__('certificates.admin.reissue_body')));

    [$posted] = browserForm($html, '//form[@action="'.$action.'"]', []);

    expect($posted)->toBe($action);
});

it('D-147: نافذة إزالة المنسّق الأساسي تحذّر مما سيرفضه الخادم، ولا تحذّر بقية المنسّقين', function (): void {
    freezeAt(riyadhAt('2026-10-01 09:00:00'));

    $cohort = makeCohort(['status' => 'running']);
    $first = makeCoordinator($cohort);
    $second = makeCoordinator($cohort);
    $third = makeCoordinator($cohort);

    // Three coordinators and one chosen as primary: the case the server refuses to remove.
    $cohort->setAttribute('primary_coordinator_id', $second->id);
    $cohort->save();

    $primary = app(PrimaryCoordinator::class)->idOf($cohort->fresh());
    $others = collect([$first, $second, $third])->pluck('id')->reject(fn (string $id): bool => $id === $primary);

    expect($primary)->toBe($second->id);

    $html = $this->actingAs(makeAdmin())
        ->get(route('admin.cohorts.index', ['trainers' => $cohort->id]))
        ->assertOk()->getContent();

    // One chunk per dialog: everything from its own `uiDialog(` up to the next one.
    $dialog = static function (string $id) use ($html): string {
        foreach (explode('x-data="uiDialog(', $html) as $chunk) {
            if (str_contains(substr($chunk, 0, 120), 'detach-coordinator-'.$id)) {
                return $chunk;
            }
        }

        return '';
    };

    expect($dialog($primary))->toContain(e(__('admin.cohorts.detach_primary_body')))
        ->and($dialog($primary))->not->toContain(e(__('admin.cohorts.detach_coordinator_body')));

    foreach ($others as $id) {
        expect($dialog($id))->toContain(e(__('admin.cohorts.detach_coordinator_body')))
            ->and($dialog($id))->not->toContain(e(__('admin.cohorts.detach_primary_body')));
    }
});

it('D-147: رقم الكيان في تفاصيل التدقيق معزول يسارًا داخل جملة عربية، والملاحظة ليست تحذيرًا', function (): void {
    $source = screenSource('resources/views/admin/audit.blade.php');

    expect($source)->toContain('<bdi dir="ltr">{{ $opened->entityId }}</bdi>')
        ->and($source)->not->toContain('<dd dir="ltr">{{ $opened->entityLabel }}')
        ->and($source)->toContain('class="note note--info"')
        ->and($source)->not->toContain('note--warn')
        // The two dates say «from» and «to», not «range» twice.
        ->and($source)->toContain("__('app.time.from')")
        ->and($source)->toContain("__('app.time.to')");
});

it('D-147: معاينة الهبوط لا تُدخل Tab إلى صفحة كاملة من أزرار لا تعمل فيها', function (): void {
    $view = screenSource('resources/views/admin/landing.blade.php');
    $script = screenSource('resources/js/landing-editor.js');

    // Neither frame is a tab stop, whichever is showing.
    expect(substr_count($view, 'tabindex="-1"'))->toBeGreaterThanOrEqual(2)
        ->and($view)->not->toContain("x-bind:tabindex=\"activeFrame === 'a'")
        ->and($view)->not->toContain("x-bind:tabindex=\"activeFrame === 'b'")
        // Everything inside the page is taken out of the Tab order once it has loaded.
        ->and($script)->toContain('calmFrame(win)')
        ->and($script)->toMatch("/querySelectorAll\\('a\\[href\\], button, input, select, textarea, summary, \\[tabindex\\]'\\)\\s*\\.forEach\\(\\(el\\) => el\\.setAttribute\\('tabindex', '-1'\\)\\)/");
});

it('D-147: التمرير إلى اللوحة المفتوحة يحترم ارتفاع الترويسة ولا يُفلت من التركيز', function (): void {
    $script = screenSource('resources/js/app.js');

    expect($script)->toContain('scrollPaddingTop')
        ->and($script)->toContain('panel.focus({ preventScroll: true })')
        // A panel that is still on its way (images, fonts) is looked for again once the page loads.
        ->and($script)->toContain("window.addEventListener('load', settle, { once: true })")
        // An alert (a refusal) is preferred to a panel the address opened.
        ->and($script)->toContain('[data-open-panel][role="alert"]');
});

it('D-147: أيقونة البحث في منتصف الحقل، والإشعارات تبدأ تحت الترويسة، وتلميح الأدوار لا يظهر على الحاسوب', function (): void {
    $components = screenSource('resources/css/components.css');
    $app = screenSource('resources/css/app.css');
    $screens = screenSource('resources/css/screens.css');

    preg_match('/\.ui-search__icon \{(.*?)\n\}/s', $components, $icon);

    expect($icon[1] ?? '')->toContain('inset-block-start: 0;')
        ->and($icon[1] ?? '')->toContain('block-size: var(--touch-min);')
        ->and($icon[1] ?? '')->not->toContain('inset-block: 0;')
        ->and($app)->toContain('body.page--app .toasts { inset-block-start: calc(var(--header-h) + var(--impbar-h) + var(--s2)); }')
        ->and($app)->toMatch('/@media \(min-width: 1024px\) \{\s*body\.page--app \.toasts \{ inset-inline-start: calc\(var\(--side-w\) \+ var\(--s4\)\); \}\s*\}/')
        // `.hint` sets its own display later in the cascade, so the hidden state has to out-rank it.
        ->and($screens)->toContain('.hint.roles__scroll-hint { display: none; }')
        ->and($screens)->toContain('@media (max-width: 699px) { .hint.roles__scroll-hint { display: flex; } }');
});

it('D-147: عنوان البطاقة على الجوال بحجم المتن لا أصغر منه، ويلتفّ العنوان الطويل', function (): void {
    $screens = screenSource('resources/css/screens.css');

    preg_match('/\.atable--stack th\[scope="row"\],\s*\.atable--stack \.atable__lead \{(.*?)\}/s', $screens, $lead);

    expect($lead[1] ?? '')->toContain('font-size: var(--fs-body);')
        ->and($lead[1] ?? '')->toContain('overflow-wrap: anywhere;');
});

it('D-147: مسح الجلسات وإرسال الرابط يحملان اسمًا لما يفعله الزرّ لا اسم الزرّ الذي فتح النافذة', function (): void {
    $source = screenSource('resources/views/admin/users/show.blade.php');

    expect($source)->toContain("__('admin.users.confirm.reset_action')")
        ->and($source)->toContain("__('admin.users.confirm.logout_action')")
        // The rules list draws its lock icons inline with the text, not as bullets beside them.
        ->and($source)->toContain('class="note__list note__list--icons"');
    // The trigger of a destructive dialog is the component's quiet danger button.

    preg_match('/<x-ui\.confirm name="suspend-account"[^<]*/s', $source, $suspend);

    expect($suspend[0] ?? '')->not->toBeEmpty()
        ->and($suspend[0] ?? '')->not->toContain('trigger-variant=');

    $cohorts = screenSource('resources/views/admin/cohorts.blade.php');

    expect($cohorts)->not->toContain('trigger-variant="secondary"');
});

it('D-147: أزرار الصفوف التي كانت بلا سياق تحمل الآن اسم صاحب الصف لقارئ الشاشة', function (): void {
    foreach ([
        'programs' => ['admin.cohorts.title', '$program->name'],
        'cohorts' => ['admin.cohorts.assign_trainer', '$cohort->name'],
        'registrations' => ['app.view_details', '$request->name'],
    ] as $view => [$label, $context]) {
        $source = screenSource("resources/views/admin/{$view}.blade.php");

        expect(preg_match('/<x-ui\.button[^<]*?:context="'.preg_quote($context, '/').'"[^<]*?\{\{ __\(\''.preg_quote($label, '/').'\'\) \}\}/s', $source))
            ->toBe(1, "{$view}: the «{$label}» row button has no context");
    }
});
