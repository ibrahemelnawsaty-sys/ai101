<?php

declare(strict_types=1);

/**
 * D-121: the general supervisor defines the final project's hand-in field by
 * field — its title, description, tips, whether it is required, its type,
 * and for an upload its formats, size and file count — on the project's own
 * settings screen. Nobody else changes the form, and a hand-in already made
 * keeps what it was asked.
 *
 * @see BR-19, BR-31, BR-33 · FR-PROJ-10 · PRD §9.14.2 · D-110, D-121 · CONSTITUTION Art. 5, Art. 8, Art. 10
 */

use App\Models\AuditLog;
use App\Models\FinalProject;
use App\Models\FinalProjectField;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-26 12:00:00'));

    $this->cohort = makeCohort();
    $this->admin = makeAdmin();
    $this->trainer = makeTrainer($this->cohort);
    $this->coordinator = makeCoordinator($this->cohort);
    $this->participant = makeParticipant($this->cohort);
    $this->project = makeFinalProject($this->cohort, ['is_unlocked' => true]);
});

/** A valid field form, overridable per test. */
function fieldForm(array $overrides = []): array
{
    return $overrides + [
        'type' => 'url',
        'label' => 'CANARY-FIELD-LABEL',
        'description' => 'CANARY-FIELD-DESCRIPTION',
        'tips' => "CANARY-TIP-ONE\n\n  CANARY-TIP-TWO  ",
        'is_required' => '1',
    ];
}

it('BR-31: المشرف العام يضيف حقل رابط فيظهر للمتدرب في نموذج التسليم بعنوانه ووصفه ونقاطه', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.fields.store', $this->project), fieldForm())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.finalProject.index', ['cohort' => $this->cohort->id]).'#submission-fields');

    $field = FinalProjectField::query()->where('label', 'CANARY-FIELD-LABEL')->sole();

    expect($field->final_project_id)->toBe($this->project->id)
        ->and($field->type->value)->toBe('url')
        ->and($field->position)->toBe(6)
        ->and($field->tips)->toBe(['CANARY-TIP-ONE', 'CANARY-TIP-TWO'])
        ->and($field->accepted_formats)->toBeNull()
        ->and($field->max_kilobytes)->toBeNull();

    $this->actingAs($this->participant)
        ->get(route('finalProject'))
        ->assertOk()
        ->assertSee('CANARY-FIELD-LABEL')
        ->assertSee('CANARY-FIELD-DESCRIPTION')
        ->assertSee('CANARY-TIP-TWO')
        ->assertSee('answers['.$field->id.']', false);
});

it('D-121: حقل رفع ملف يحفظ صيغه بترتيب القائمة وحجمه بالكيلوبايت وعدد ملفاته', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.fields.store', $this->project), fieldForm([
            'type' => 'file',
            'formats' => ['webp', 'pdf'],
            'max_megabytes' => '3',
            'max_files' => '1',
        ]))
        ->assertSessionHasNoErrors();

    $field = FinalProjectField::query()->where('label', 'CANARY-FIELD-LABEL')->sole();

    expect($field->accepted_formats)->toBe(['pdf', 'webp'])
        ->and($field->max_kilobytes)->toBe(3072)
        ->and($field->max_files)->toBe(1)
        ->and($field->acceptedExtensions())->toBe(['pdf', 'webp']);
});

it('D-121: حقل رفع بلا صيغة يُرفض، وحجم فوق حد المنصة يُرفض، ونوع خارج القائمة يُرفض', function (): void {
    $store = route('admin.finalProject.fields.store', $this->project);

    $this->actingAs($this->admin)
        ->post($store, fieldForm(['type' => 'file', 'max_megabytes' => '5', 'max_files' => '1']))
        ->assertSessionHasErrors(['formats' => __('admin.final_project.submission_fields.errors.formats_required')]);

    $this->actingAs($this->admin)
        ->post($store, fieldForm(['type' => 'file', 'formats' => ['pdf'], 'max_megabytes' => '26', 'max_files' => '1']))
        ->assertSessionHasErrors(['max_megabytes']);

    $this->actingAs($this->admin)
        ->post($store, fieldForm(['type' => 'file', 'formats' => ['exe'], 'max_megabytes' => '5', 'max_files' => '1']))
        ->assertSessionHasErrors(['formats.0']);

    $this->actingAs($this->admin)
        ->post($store, fieldForm(['type' => 'select']))
        ->assertSessionHasErrors(['type']);

    expect(FinalProjectField::query()->where('label', 'CANARY-FIELD-LABEL')->exists())->toBeFalse();
});

it('D-121: مجموع عدد الملفات في كل حقول الرفع لا يتجاوز ما يستقبله الخادم في الطلب الواحد', function (): void {
    // The defaults already ask for two files (the deck and the logo); the
    // platform's ceiling is five, so a third field of four files is refused.
    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.fields.store', $this->project), fieldForm([
            'type' => 'file', 'formats' => ['zip'], 'max_megabytes' => '5', 'max_files' => '4',
        ]))
        ->assertSessionHasErrors(['max_files' => __('admin.final_project.submission_fields.errors.too_many_files', ['asked' => 6, 'max' => 5])]);

    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.fields.store', $this->project), fieldForm([
            'type' => 'file', 'formats' => ['zip'], 'max_megabytes' => '5', 'max_files' => '3',
        ]))
        ->assertSessionHasNoErrors();

    // Editing a field counts its NEW number in place of its old one.
    $deck = defaultField($this->project, 'presentation_file');

    $this->actingAs($this->admin)
        ->patch(route('admin.finalProject.fields.update', [$this->project, $deck]), fieldForm([
            'label' => $deck->label, 'type' => 'file', 'formats' => ['pdf'], 'max_megabytes' => '25', 'max_files' => '2',
        ]))
        ->assertSessionHasErrors(['max_files']);
});

it('D-121: لا يتجاوز المشروع عشرين حقلًا', function (): void {
    for ($i = 6; $i <= FinalProjectField::MAX_PER_PROJECT; $i++) {
        FinalProjectField::factory()->create(['final_project_id' => $this->project->id, 'position' => $i]);
    }

    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.fields.store', $this->project), fieldForm())
        ->assertSessionHasErrors(['label' => __('admin.final_project.submission_fields.errors.too_many_fields', ['max' => 20])]);
});

it('D-121: تعديل حقل يغيّر نوعه ويمسح ضوابط الرفع عن غير الملف، ويُسجَّل قبله وبعده في سجل التدقيق', function (): void {
    $deck = defaultField($this->project, 'presentation_file');

    $this->actingAs($this->admin)
        ->patch(route('admin.finalProject.fields.update', [$this->project, $deck]), fieldForm([
            'type' => 'url',
            'label' => 'CANARY-RENAMED',
            'is_required' => '0',
            // Upload settings posted with a non-upload type are ignored.
            'formats' => ['pdf'],
            'max_megabytes' => '5',
            'max_files' => '1',
        ]))
        ->assertSessionHasNoErrors();

    $deck->refresh();

    expect($deck->label)->toBe('CANARY-RENAMED')
        ->and($deck->type->value)->toBe('url')
        ->and($deck->is_required)->toBeFalse()
        ->and($deck->accepted_formats)->toBeNull()
        ->and($deck->max_kilobytes)->toBeNull()
        ->and($deck->max_files)->toBeNull();

    $log = AuditLog::query()->where('action', 'final_project_field.updated')->sole();

    expect($log->entity_type)->toBe('final_project_field')
        ->and($log->entity_id)->toBe($deck->id)
        ->and($log->actor_id)->toBe($this->admin->id)
        ->and($log->before['label'])->toBe(__('project.default_fields.presentation_file.label'))
        ->and($log->after['label'])->toBe('CANARY-RENAMED');
});

it('D-121: نقل حقل للأعلى وللأسفل يعيد ترقيم القائمة، وطرفها يبقى مكانه', function (): void {
    $github = defaultField($this->project, 'github_url');
    $live = defaultField($this->project, 'live_url');

    $this->actingAs($this->admin)
        ->patch(route('admin.finalProject.fields.move', [$this->project, $github]), ['direction' => 'up'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('admin.final_project.submission_fields.moved'));

    expect($this->project->fields()->pluck('id')->take(2)->all())->toBe([$github->id, $live->id]);

    // The first field cannot go further up; nothing changes, nothing is logged.
    $this->actingAs($this->admin)
        ->patch(route('admin.finalProject.fields.move', [$this->project, $github]), ['direction' => 'up'])
        ->assertSessionHasNoErrors();

    expect($this->project->fields()->pluck('id')->take(2)->all())->toBe([$github->id, $live->id])
        ->and(AuditLog::query()->where('action', 'final_project_field.moved')->count())->toBe(1);

    $this->actingAs($this->admin)
        ->patch(route('admin.finalProject.fields.move', [$this->project, $github]), ['direction' => 'down'])
        ->assertSessionHasNoErrors();

    expect($this->project->fields()->pluck('position')->all())->toBe([1, 2, 3, 4, 5])
        ->and($this->project->fields()->pluck('id')->take(2)->all())->toBe([$live->id, $github->id]);

    $this->actingAs($this->admin)
        ->patch(route('admin.finalProject.fields.move', [$this->project, $github]), ['direction' => 'sideways'])
        ->assertSessionHasErrors(['direction']);
});

it('BR-19: حذف حقل أو إعادة تسميته لا يغيّر تسليمًا سابقًا — يبقى بعنوانه كما سُئل', function (): void {
    $submission = makeProjectSubmission($this->project, $this->participant);
    $before = $submission->answers;
    $live = defaultField($this->project, 'live_url');

    $this->actingAs($this->admin)
        ->patch(route('admin.finalProject.fields.update', [$this->project, $live]), fieldForm(['label' => 'CANARY-NEW-NAME']))
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->delete(route('admin.finalProject.fields.destroy', [$this->project, defaultField($this->project, 'github_url')]))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    expect($submission->fresh()->answers)->toBe($before)
        ->and($this->project->fields()->count())->toBe(4)
        ->and(AuditLog::query()->where('action', 'final_project_field.deleted')->sole()->before['label'])
        ->toBe(__('project.default_fields.github_url.label'));

    $this->actingAs($this->trainer)
        ->get(route('trainer.finalProject', ['cohort' => $this->cohort->id, 'grade' => $submission->id]))
        ->assertOk()
        ->assertSee('Public GitHub repository')
        ->assertDontSee('CANARY-NEW-NAME');
});

it('403: لا المدرب ولا المنسق ولا المتدرب يضيفون حقلًا أو يعدّلونه أو ينقلونه أو يحذفونه', function (): void {
    $field = defaultField($this->project, 'live_url');

    foreach (['trainer', 'coordinator', 'participant'] as $role) {
        $this->actingAs($this->$role)
            ->post(route('admin.finalProject.fields.store', $this->project), fieldForm())
            ->assertForbidden();
        $this->actingAs($this->$role)
            ->patch(route('admin.finalProject.fields.update', [$this->project, $field]), fieldForm())
            ->assertForbidden();
        $this->actingAs($this->$role)
            ->patch(route('admin.finalProject.fields.move', [$this->project, $field]), ['direction' => 'down'])
            ->assertForbidden();
        $this->actingAs($this->$role)
            ->delete(route('admin.finalProject.fields.destroy', [$this->project, $field]))
            ->assertForbidden();
    }

    expect($field->fresh()?->label)->toBe(__('project.default_fields.live_url.label'))
        ->and($this->project->fields()->count())->toBe(5);
});

it('D-121: حقل مشروع آخر تحت رابط هذا المشروع يُجاب 404 قبل أي سياسة', function (): void {
    $other = makeFinalProject(makeCohort());
    $foreign = defaultField($other, 'live_url');

    $this->actingAs($this->admin)
        ->patch(route('admin.finalProject.fields.update', [$this->project, $foreign]), fieldForm())
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->delete(route('admin.finalProject.fields.destroy', [$this->project, $foreign]))
        ->assertNotFound();

    expect($foreign->fresh())->not->toBeNull();
});

it('BR-33: معاينة حساب المشرف العام لا تغيّر حقول التسليم — 403', function (): void {
    $this->actingAs(makeSystemAdmin())->post(route('admin.users.preview', $this->admin))->assertRedirect();

    $field = defaultField($this->project, 'live_url');

    $this->post(route('admin.finalProject.fields.store', $this->project), fieldForm())->assertForbidden();
    $this->patch(route('admin.finalProject.fields.move', [$this->project, $field]), ['direction' => 'down'])->assertForbidden();
    $this->delete(route('admin.finalProject.fields.destroy', [$this->project, $field]))->assertForbidden();

    expect(FinalProjectField::query()->where('label', 'CANARY-FIELD-LABEL')->exists())->toBeFalse()
        ->and($this->project->fields()->count())->toBe(5);
});

it('D-121: شاشة المشرف تعرض الحقول بترتيبها ومحرّرها ونافذة الحذف — ومعرّف من مشروع آخر لا يفتح شيئًا', function (): void {
    $index = route('admin.finalProject.index', ['cohort' => $this->cohort->id]);
    $live = defaultField($this->project, 'live_url');

    $html = (string) $this->actingAs($this->admin)->get($index)->assertOk()->getContent();

    expect($html)->toContain('id="submission-fields"')
        ->and(strpos($html, (string) __('project.default_fields.live_url.label')))
        ->toBeLessThan(strpos($html, (string) __('project.default_fields.presentation_file.label')))
        ->and($html)->not->toContain('id="field-editor"');

    $this->actingAs($this->admin)->get($index.'&field=new')
        ->assertOk()
        ->assertSee('id="field-editor"', false)
        ->assertSee(route('admin.finalProject.fields.store', $this->project), false);

    $this->actingAs($this->admin)->get($index.'&field='.$live->id)
        ->assertOk()
        ->assertSee(route('admin.finalProject.fields.update', [$this->project, $live]), false);

    $this->actingAs($this->admin)->get($index.'&remove='.$live->id)
        ->assertOk()
        ->assertSee('id="field-removal"', false)
        ->assertSee(route('admin.finalProject.fields.destroy', [$this->project, $live]), false);

    $foreign = defaultField(makeFinalProject(makeCohort()), 'live_url');

    $this->actingAs($this->admin)->get($index.'&field='.$foreign->id)
        ->assertOk()
        ->assertDontSee('id="field-editor"', false);
});

it('D-121: مشروع بلا حقول — الشاشة تقول ذلك وتدعو للإضافة، ونموذج المتدرب يعرض حالة فارغة ويرفض التسليم', function (): void {
    $this->project->fields()->delete();

    $this->actingAs($this->admin)
        ->get(route('admin.finalProject.index', ['cohort' => $this->cohort->id]))
        ->assertOk()
        ->assertSee(__('admin.final_project.submission_fields.empty_title'));

    $this->actingAs($this->participant)
        ->get(route('finalProject'))
        ->assertOk()
        ->assertSee(__('project.fields_empty_title'));

    $this->actingAs($this->participant)
        ->post(route('finalProject.submit'), ['answers' => []])
        ->assertSessionHasErrors(['answers' => __('project.errors.no_fields')]);
});

it('D-121: تنبيه المشرف حين تسمح الحقول معًا بتسليم أثقل من حد الطلب الواحد', function (): void {
    $index = route('admin.finalProject.index', ['cohort' => $this->cohort->id]);

    // The defaults: 25 MB + 4 MB = 29 MB under the 30 MB request — no warning.
    $this->actingAs($this->admin)->get($index)
        ->assertOk()
        ->assertDontSee('note--warn', false);

    FinalProjectField::factory()->file([App\Enums\SubmissionFileFormat::Zip], 1, 5120)
        ->create(['final_project_id' => $this->project->id, 'position' => 6]);

    $this->actingAs($this->admin)->get($index)
        ->assertOk()
        ->assertSee('note--warn', false);
});

it('D-121: الحقول جزء من دليل المشروع — لا يصل عنوان حقل ولا وصفه إلى المتدرب قبل الإتاحة (BR-16)', function (): void {
    $locked = makeFinalProject($cohort = makeCohort());
    $trainee = makeParticipant($cohort);

    FinalProjectField::factory()->create([
        'final_project_id' => $locked->id,
        'label' => 'CANARY-LOCKED-FIELD',
        'description' => 'CANARY-LOCKED-DESCRIPTION',
        'position' => 6,
    ]);

    $body = (string) $this->actingAs($trainee)->get(route('finalProject'))->getContent();

    expect($body)->not->toContain('CANARY-LOCKED-FIELD')
        ->and($body)->not->toContain('CANARY-LOCKED-DESCRIPTION')
        ->and($body)->not->toContain(__('project.default_fields.live_url.description'));

    expect(FinalProject::query()->whereKey($locked->id)->value('is_unlocked'))->toBeFalsy();
});
