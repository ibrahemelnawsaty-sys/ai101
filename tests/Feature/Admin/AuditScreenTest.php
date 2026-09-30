<?php

declare(strict_types=1);

/**
 * Phase 5 — the audit screen says what happened, in words, and its file says what its
 * screen says.
 *
 * Found by running it (D-147): every action and every entity was printed as the machine
 * code the trail stores (`cohort.coordinator_detached`, `Broadcast`); the search box was
 * read by nothing; and the export ignored every filter, gave UTC times and raw codes.
 *
 * @see BR-27 · PRD §9.18 · CONSTITUTION art. 4, art. 8 · D-147
 */

use App\Models\AuditLog;
use App\Presenters\Admin\AuditEntry;
use App\Services\Audit\AuditLogger;

/** A whole group of phrases (`actions` / `entities`), keyed by code. */
function auditPhrases(string $group): array
{
    return (array) trans('admin.audit.'.$group);
}

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-01 09:00:00'));

    $this->admin = makeAdmin();
    $this->other = makeAdmin();

    $audit = app(AuditLogger::class);

    $this->revoked = $audit->record(action: 'certificate.revoked', entityType: 'certificate', entityId: 'cert-aaa-111', actorId: $this->admin->id);
    $this->detached = $audit->record(action: 'cohort.trainer_detached', entityType: 'cohort', entityId: 'cohort-bbb-222', actorId: $this->other->id);
    $this->unknown = $audit->record(action: 'some.future_code', entityType: 'thing', entityId: 'thing-ccc-333', actorId: $this->admin->id);
});

it('D-147: كل رمز فعل معروف يُعرض بعبارة عربية، والمرفوض بعبارة «محاولة مرفوضة»، والمجهول كما هو', function (): void {
    expect(AuditEntry::actionLabel('certificate.revoked'))->toBe(auditPhrases('actions')['certificate.revoked'])
        ->and(AuditEntry::actionLabel('certificate.revoked'))->not->toContain('.')
        ->and(AuditEntry::actionLabel('some.future_code'))->toBe('some.future_code')
        ->and(AuditEntry::entityLabel('App\\Models\\Broadcast'))->toBe(auditPhrases('entities')['Broadcast'])
        ->and(AuditEntry::entityLabel('thing'))->toBe('thing')
        ->and(AuditEntry::actionLabel(''))->toBe('—')
        ->and(AuditEntry::actionLabel('attendance.check_in.rejected'))->toBe(__('admin.audit.rejected_of', ['action' => auditPhrases('actions')['attendance.check_in']]))
        // A refusal of a code nobody has a phrase for stays raw: no phrase is invented (art. 4).
        ->and(AuditEntry::actionLabel('some.future_code.rejected'))->toBe('some.future_code.rejected');
});

it('D-147: كل رمز فعل يكتبه التطبيق له عبارة في lang — فلا يعود رمز خام إلى الشاشة', function (): void {
    $labels = array_keys((array) auditPhrases('actions'));
    $missing = [];

    $sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($sources as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $code = (string) file_get_contents($file->getPathname());

        // Named (`action: 'x.y'`) and positional (`->log('x.y'`, `->record('x.y'`, `->reject('x.y'`)
        // calls alike: a code written the second way used to slip past this guard.
        preg_match_all("/(?:action:\s*|->(?:log|record|reject)\(\s*)'([a-z_]+(?:\.[a-z_]+)+)'/", $code, $literals);

        foreach ($literals[1] as $literal) {
            if (! in_array($literal, $labels, true)) {
                $missing[] = $literal.' ('.$file->getFilename().')';
            }
        }
    }

    $constants = (new ReflectionClass(AuditLogger::class))->getConstants();

    foreach ($constants as $name => $value) {
        if (is_string($value) && str_contains($value, '.') && $name !== 'REJECTED_SUFFIX' && ! in_array($value, $labels, true)) {
            $missing[] = $value.' (AuditLogger::'.$name.')';
        }
    }

    expect($missing)->toBe([]);
});

it('D-147: الشاشة تعرض العبارات لا الرموز، ومرشّحا الفعل والكيان بها كذلك', function (): void {
    $html = $this->actingAs($this->admin)->get(route('admin.audit.index'))->assertOk()->getContent();

    expect($html)->toContain(auditPhrases('actions')['certificate.revoked'])
        ->and($html)->toContain(auditPhrases('actions')['cohort.trainer_detached'])
        ->and($html)->toContain(auditPhrases('entities')['certificate'])
        // Values (the machine codes) still travel in the options; only the words changed.
        ->and($html)->toContain('certificate.revoked');
});

it('D-147: مربّع البحث يبحث فعلًا — باسم الفاعل أو معرّف السجل المتأثر', function (): void {
    $byEntity = $this->actingAs($this->admin)->get(route('admin.audit.index', ['q' => 'cohort-bbb-222']))->assertOk()->getContent();

    expect($byEntity)->toContain(auditPhrases('actions')['cohort.trainer_detached'])
        ->and($byEntity)->not->toContain(auditPhrases('actions')['certificate.revoked']);

    $byActor = $this->actingAs($this->admin)->get(route('admin.audit.index', ['q' => $this->other->email]))->getContent();

    expect($byActor)->toContain(auditPhrases('actions')['cohort.trainer_detached'])
        ->and($byActor)->not->toContain(auditPhrases('actions')['certificate.revoked']);

    $none = $this->actingAs($this->admin)->get(route('admin.audit.index', ['q' => 'zzzzzzzz']))->getContent();

    expect($none)->toContain(__('admin.audit.empty_title'));
});

it('D-147: التصدير يحمل مرشّحات الشاشة نفسها ويكتب العبارات وتوقيت الرياض', function (): void {
    $all = $this->actingAs($this->admin)->get(route('admin.audit.export'))->assertOk()->getContent();

    expect($all)->toContain(auditPhrases('actions')['certificate.revoked'])
        ->toContain(auditPhrases('actions')['cohort.trainer_detached']);

    $filtered = $this->actingAs($this->admin)
        ->get(route('admin.audit.export', ['action' => 'cohort.trainer_detached']))
        ->assertOk()->getContent();

    expect($filtered)->toContain(auditPhrases('actions')['cohort.trainer_detached'])
        ->and($filtered)->not->toContain(auditPhrases('actions')['certificate.revoked'])
        // Riyadh wall time, not the ISO-8601 UTC the row stores.
        ->and($filtered)->toContain('2026-10-01 09:00:00')
        // The code and the record id stay in the file for offline filtering.
        ->and($filtered)->toContain('cohort.trainer_detached')
        ->and($filtered)->toContain('cohort-bbb-222')
        ->and($filtered)->not->toContain('T06:00:00');

    $byActor = $this->actingAs($this->admin)
        ->get(route('admin.audit.export', ['actor' => $this->other->id]))
        ->getContent();

    expect($byActor)->toContain($this->other->email)->and($byActor)->not->toContain($this->admin->email);
});

it('D-147: سجل التدقيق يبقى للقراءة فقط — لا مسار يعدّله ولا يحذفه', function (): void {
    expect(AuditLog::query()->count())->toBe(3);

    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn ($route): bool => str_contains((string) $route->getName(), 'admin.audit'))
        ->flatMap(static fn ($route): array => $route->methods())
        ->reject(static fn (string $method): bool => in_array($method, ['GET', 'HEAD'], true));

    expect($routes)->toBeEmpty();
});
