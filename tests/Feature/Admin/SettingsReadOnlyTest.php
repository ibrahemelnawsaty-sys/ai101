<?php

declare(strict_types=1);

/**
 * Phase 5 · D-148 — the general settings screen shows what is deployed and promises nothing else.
 *
 * It offered six fields and a «save changes» button, and saving did nothing: the form posted
 * six fields the request did not know and left out the one it required, so the refusal was never
 * printed — and had it passed, the controller wrote a line in the audit trail and said «saved»
 * without writing a value anywhere (the values live in config/athar.php, which reads the
 * environment: BR-36). The notification-defaults card did the same.
 *
 * The owner chose option A: the values are shown read-only, with a line saying where they are
 * set, and the two write endpoints are gone — an endpoint that records a change that never
 * happened is worse than no endpoint. The e-mail templates stay editable: they really save.
 *
 * @see BR-31, BR-36 · PRD §9.18 · CONSTITUTION art. 4, art. 5 · D-117, D-148
 */

use App\Models\AuditLog;
use App\Presenters\Participant\PreferencePresenter;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    config([
        'athar.platform_name' => 'Centre Under Test',
        'athar.program_name' => 'Programme Under Test',
        'athar.email' => 'contact@example.test',
        'athar.whatsapp' => '+966500000000',
    ]);

    $this->sys = makeSystemAdmin();
});

function settingsPage(object $test): string
{
    return $test->actingAs($test->sys)->get(route('admin.settings.edit'))->assertOk()->getContent();
}

it('D-148: القيم المنشورة تُعرض نصًّا للقراءة لا حقولًا قابلة للكتابة', function (): void {
    $html = settingsPage($this);

    expect($html)->toContain('Centre Under Test')
        ->and($html)->toContain('Programme Under Test')
        ->and($html)->toContain('contact@example.test')
        ->and($html)->toContain('+966500000000')
        // No input of any of them, and no field of the limits either.
        ->and($html)->not->toContain('name="centre_name"')
        ->and($html)->not->toContain('name="program_name"')
        ->and($html)->not->toContain('name="contact_email"')
        ->and($html)->not->toContain('name="whatsapp"')
        ->and($html)->not->toContain('name="max_file_mb"')
        ->and($html)->not->toContain('name="max_files"')
        ->and($html)->not->toContain('name="defaults[');
});

it('D-148: الشاشة تقول أين تُضبط هذه القيم، ولا تحمل زرّ حفظ يعد بما لا يحدث', function (): void {
    $html = settingsPage($this);

    expect($html)->toContain(e(__('admin.settings.readonly_note')))
        ->and($html)->not->toContain(e(__('app.save_changes')));
});

it('D-148: نقطتا الكتابة اللتان تسجّلان تغييرًا لم يحدث حُذفتا، ولا سطر تدقيق يُكتب', function (): void {
    expect(Route::has('admin.settings.update'))->toBeFalse()
        ->and(Route::has('admin.settings.notifications'))->toBeFalse();

    $this->actingAs($this->sys)
        ->put(route('admin.settings.edit'), ['centre_name' => 'Hijacked'])
        ->assertStatus(405);

    expect(AuditLog::query()->whereIn('action', ['settings.updated', 'settings.notifications_updated'])->count())->toBe(0)
        ->and(config('athar.platform_name'))->toBe('Centre Under Test');
});

it('D-148: افتراضيات الإشعارات تُعرض كما هي فعلًا — مفعّلة افتراضيًا، وما لا يُوقَف يُقال إنه يصل دائمًا', function (): void {
    $html = settingsPage($this);

    // Every locked type is named as such; the open ones say they are on by default.
    $label = e(__('admin.settings.always_on'));
    $inNote = substr_count(e(__('admin.settings.defaults_note')), $label);

    expect(substr_count($html, $label) - $inNote)->toBe(count(PreferencePresenter::ALWAYS_ON))
        ->and($html)->toContain(e(__('admin.settings.default_on')))
        ->and($html)->toContain(e(__('admin.settings.defaults_note')));
});

it('D-148: قوالب البريد تبقى قابلة للتعديل — هي التي تحفظ فعلًا', function (): void {
    $html = settingsPage($this);

    expect($html)->toContain(route('admin.settings.template', 'invitation_link'));
    expect(Route::has('admin.settings.template.update'))->toBeTrue();
});

it('D-117: غير مدير النظام لا يقرأ الشاشة', function (): void {
    $this->actingAs(makeAdmin())->get(route('admin.settings.edit'))->assertForbidden();
    $this->actingAs(makeParticipant())->get(route('admin.settings.edit'))->assertForbidden();
});
