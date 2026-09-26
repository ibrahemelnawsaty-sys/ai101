<?php

declare(strict_types=1);

/**
 * D-109, D-110: the final project's settings (brief, deadline, ceiling, late
 * policy, opening the tab) are an administrator affair only now. The hand-in
 * itself is the project's own list of fields since D-121; a new project starts
 * with the default five — the three deliverables of the owner's picture, the
 * optional idea logo and the optional description.
 *
 * @see BR-11, BR-15, BR-16, BR-18, BR-19, BR-22, BR-23 · FR-PROJ-10 · PRD §9.14 · D-109, D-110, D-121
 */

use App\Models\AuditLog;
use App\Models\FinalProject;
use App\Models\ProjectSubmission;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->cohort = makeCohort();
    $this->admin = makeAdmin();
    $this->trainer = makeTrainer($this->cohort);
    $this->participant = makeParticipant($this->cohort);
});

it('D-110: المشرف العام يضبط سقف الدرجة وموعد التسليم لدفعة لم يكن لها مشروع من قبل', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.store'), [
            'cohort_id' => $this->cohort->id,
            'title' => 'CANARY-PROJECT-TITLE',
            'brief' => 'CANARY-BRIEF',
            'requirements' => "بند أول\nبند ثانٍ",
            'due_at' => '2026-12-01T23:59',
            'max_score' => 100,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $project = FinalProject::query()->where('cohort_id', $this->cohort->id)->sole();

    expect($project->title)->toBe('CANARY-PROJECT-TITLE')
        ->and((int) $project->max_score)->toBe(100)
        ->and($project->requirements)->toBe(['بند أول', 'بند ثانٍ'])
        ->and($project->is_unlocked)->toBeFalse();
});

it('403: لا المدرب ولا المتدرب يضبطان إعدادات المشروع الختامي', function (): void {
    foreach (['trainer', 'participant'] as $role) {
        $this->actingAs($this->$role)
            ->post(route('admin.finalProject.store'), [
                'cohort_id' => $this->cohort->id,
                'title' => 'Nope',
                'brief' => 'Nope',
                'due_at' => '2026-12-01T23:59',
                'max_score' => 100,
            ])
            ->assertForbidden();
    }

    expect(FinalProject::query()->where('cohort_id', $this->cohort->id)->exists())->toBeFalse();
});

it('D-121: مشروع جديد يُنشأ من الشاشة يبدأ بالحقول الافتراضية الخمسة بترتيبها', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.store'), [
            'cohort_id' => $this->cohort->id,
            'title' => 'CANARY-PROJECT-TITLE',
            'brief' => 'CANARY-BRIEF',
            'due_at' => '2026-12-01T23:59',
            'max_score' => 100,
        ])
        ->assertSessionHasNoErrors();

    $project = FinalProject::query()->where('cohort_id', $this->cohort->id)->sole();
    $fields = $project->fields()->get();

    expect($fields->pluck('type')->map->value->all())->toBe(['url', 'github', 'file', 'file', 'textarea'])
        ->and($fields->pluck('is_required')->all())->toBe([true, true, true, false, false])
        ->and($fields->pluck('label')->all())->toBe([
            __('project.default_fields.live_url.label'),
            __('project.default_fields.github_url.label'),
            __('project.default_fields.presentation_file.label'),
            __('project.default_fields.logo_file.label'),
            __('project.default_fields.description.label'),
        ]);

    // Saving the settings again never adds a second set.
    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.store'), [
            'cohort_id' => $this->cohort->id,
            'title' => 'CANARY-PROJECT-TITLE-2',
            'brief' => 'CANARY-BRIEF',
            'due_at' => '2026-12-01T23:59',
            'max_score' => 100,
        ])
        ->assertSessionHasNoErrors();

    expect($project->fields()->count())->toBe(5);
});

it('D-121: سجل تثبيت الحقول الافتراضية يُكتب قبل الحقول نفسها — السجل أولًا ثم الكتابة (المادة 8)', function (): void {
    $writes = [];

    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^insert into ["`]?(audit_logs|final_project_fields)["`]?/i', $query->sql, $table) !== 1) {
            return;
        }

        $writes[] = $table[1] === 'final_project_fields'
            ? 'fields'
            : (in_array('final_project.fields_installed', $query->bindings, true) ? 'trail' : 'other trail');
    });

    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.store'), [
            'cohort_id' => $this->cohort->id,
            'title' => 'CANARY-PROJECT-TITLE',
            'brief' => 'CANARY-BRIEF',
            'due_at' => '2026-12-01T23:59',
            'max_score' => 100,
        ])
        ->assertSessionHasNoErrors();

    $trail = array_search('trail', $writes, true);
    $fields = array_search('fields', $writes, true);

    expect($trail)->toBeInt()
        ->and($fields)->toBeInt()
        ->and($trail)->toBeLessThan($fields)
        ->and(AuditLog::query()->where('action', 'final_project.fields_installed')->sole()->after)->toBe(['fields' => 5]);
});

it('D-121: التسليم يتطلب الحقول الإلزامية الثلاثة معًا — نقص أي منها يرفض التسليم بلا تسجيل ويسمّي الحقل', function (): void {
    Storage::fake('private');
    $project = makeFinalProject($this->cohort, ['is_unlocked' => true, 'max_score' => 100]);

    foreach (['live_url', 'github_url', 'presentation_file'] as $missing) {
        $field = defaultField($project, $missing);

        $this->actingAs($this->participant)
            ->post(route('finalProject.submit'), handInPayload($project, [$missing => null]))
            ->assertSessionHasErrors(['answers.'.$field->id => __('project.errors.field_required', ['field' => $field->label])]);
    }

    expect(ProjectSubmission::query()->where('final_project_id', $project->id)->count())->toBe(0);
});

it('D-121: التسليم الكامل بالعناصر الثلاثة + شعار اختياري يُحفظ بنسخة من كل حقل وبدرجة سقفها من المشروع', function (): void {
    Storage::fake('private');
    $project = makeFinalProject($this->cohort, [
        'is_unlocked' => true,
        'max_score' => 100,
        'due_at' => riyadhAt('2026-12-01 23:59:00'),
    ]);
    freezeAt(riyadhAt('2026-11-20 12:00:00'));

    $this->actingAs($this->participant)
        ->post(route('finalProject.submit'), handInPayload($project, [
            'logo_file' => [fakeUpload('logo.png', 'png')],
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $submission = ProjectSubmission::query()->where('user_id', $this->participant->id)->sole();
    $answers = $submission->answers;

    expect($answers)->toHaveCount(5)
        ->and($answers[0]['field_id'])->toBe(defaultField($project, 'live_url')->id)
        ->and($answers[0]['value'])->toBe('https://example.test/final')
        ->and($answers[1]['value'])->toBe('https://github.com/athar-trainee/ai101-final')
        ->and($answers[2]['files'])->toHaveCount(1)
        ->and($answers[2]['files'][0]['mime_type'])->toBe('application/pdf')
        ->and($answers[3]['files'][0]['mime_type'])->toBe('image/png')
        ->and($answers[4]['value'])->toBeNull()
        ->and($submission->is_late)->toBeFalse();

    Storage::disk('private')->assertExists($answers[2]['files'][0]['path']);
});

it('D-110: عدم السماح بالتسليم المتأخر يرفض التسليم بعد الموعد ولا يُنشئ سجلًا', function (): void {
    Storage::fake('private');
    $project = makeFinalProject($this->cohort, [
        'is_unlocked' => true,
        'allow_late' => false,
        'due_at' => riyadhAt('2026-11-01 23:59:00'),
    ]);
    freezeAt(riyadhAt('2026-11-02 00:00:01'));

    $this->actingAs($this->participant)
        ->post(route('finalProject.submit'), handInPayload($project))
        ->assertSessionHasErrors(['answers' => __('project.closed_deadline')]);

    expect(ProjectSubmission::query()->count())->toBe(0);
});

it('D-110: السماح بالتسليم المتأخر يقبل التسليم بعد الموعد ويعلّمه متأخرًا', function (): void {
    Storage::fake('private');
    $project = makeFinalProject($this->cohort, [
        'is_unlocked' => true,
        'allow_late' => true,
        'due_at' => riyadhAt('2026-11-01 23:59:00'),
    ]);
    freezeAt(riyadhAt('2026-11-02 00:00:01'));

    $this->actingAs($this->participant)
        ->post(route('finalProject.submit'), handInPayload($project))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $submission = ProjectSubmission::query()->where('user_id', $this->participant->id)->sole();

    expect($submission->is_late)->toBeTrue();
});
