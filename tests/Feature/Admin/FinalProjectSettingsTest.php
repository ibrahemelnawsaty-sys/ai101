<?php

declare(strict_types=1);

/**
 * D-109, D-110: the final project's settings (brief, deadline, ceiling, late
 * policy, opening the tab) are an administrator affair only now, and a
 * hand-in needs the three named deliverables the picture showed, plus an
 * optional idea logo.
 *
 * @see BR-11, BR-15, BR-16, BR-18, BR-19, BR-22, BR-23 · PRD §9.14 · D-109, D-110
 */

use App\Models\FinalProject;
use App\Models\ProjectSubmission;
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

it('D-110: تسليم المشروع الختامي يتطلب العناصر الثلاثة معًا — نقص أي منها يرفض التسليم بلا تسجيل', function (): void {
    Storage::fake('private');
    $project = makeFinalProject($this->cohort, ['is_unlocked' => true, 'max_score' => 100]);

    $this->actingAs($this->participant)
        ->post(route('finalProject.submit'), [
            'github_url' => 'https://github.com/trainee/final',
            'presentation_file' => fakeUpload('slides.pdf'),
            // live_url missing
        ])
        ->assertSessionHasErrors(['live_url']);

    $this->actingAs($this->participant)
        ->post(route('finalProject.submit'), [
            'live_url' => 'https://example.test/final',
            'presentation_file' => fakeUpload('slides.pdf'),
            // github_url missing
        ])
        ->assertSessionHasErrors(['github_url']);

    $this->actingAs($this->participant)
        ->post(route('finalProject.submit'), [
            'live_url' => 'https://example.test/final',
            'github_url' => 'https://github.com/trainee/final',
            // presentation_file missing
        ])
        ->assertSessionHasErrors(['presentation_file']);

    expect(ProjectSubmission::query()->where('final_project_id', $project->id)->count())->toBe(0);
});

it('D-110: التسليم الكامل بالعناصر الثلاثة + شعار اختياري يُحفظ بدرجة سقفها من المشروع', function (): void {
    Storage::fake('private');
    makeFinalProject($this->cohort, [
        'is_unlocked' => true,
        'max_score' => 100,
        'due_at' => riyadhAt('2026-12-01 23:59:00'),
    ]);
    freezeAt(riyadhAt('2026-11-20 12:00:00'));

    $this->actingAs($this->participant)
        ->post(route('finalProject.submit'), [
            'live_url' => 'https://example.test/final',
            'github_url' => 'https://github.com/trainee/final',
            'presentation_file' => fakeUpload('slides.pdf'),
            'logo_file' => fakeUpload('logo.png', 'png'),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $submission = ProjectSubmission::query()->where('user_id', $this->participant->id)->sole();

    expect($submission->live_url)->toBe('https://example.test/final')
        ->and($submission->presentation_file)->not->toBeNull()
        ->and($submission->logo_file)->not->toBeNull()
        ->and($submission->is_late)->toBeFalse();
});

it('D-110: عدم السماح بالتسليم المتأخر يرفض التسليم بعد الموعد ولا يُنشئ سجلًا', function (): void {
    Storage::fake('private');
    makeFinalProject($this->cohort, [
        'is_unlocked' => true,
        'allow_late' => false,
        'due_at' => riyadhAt('2026-11-01 23:59:00'),
    ]);
    freezeAt(riyadhAt('2026-11-02 00:00:01'));

    $this->actingAs($this->participant)
        ->post(route('finalProject.submit'), [
            'live_url' => 'https://example.test/final',
            'github_url' => 'https://github.com/trainee/final',
            'presentation_file' => fakeUpload('slides.pdf'),
        ])
        ->assertSessionHasErrors(['live_url']);

    expect(ProjectSubmission::query()->count())->toBe(0);
});

it('D-110: السماح بالتسليم المتأخر يقبل التسليم بعد الموعد ويعلّمه متأخرًا', function (): void {
    Storage::fake('private');
    makeFinalProject($this->cohort, [
        'is_unlocked' => true,
        'allow_late' => true,
        'due_at' => riyadhAt('2026-11-01 23:59:00'),
    ]);
    freezeAt(riyadhAt('2026-11-02 00:00:01'));

    $this->actingAs($this->participant)
        ->post(route('finalProject.submit'), [
            'live_url' => 'https://example.test/final',
            'github_url' => 'https://github.com/trainee/final',
            'presentation_file' => fakeUpload('slides.pdf'),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $submission = ProjectSubmission::query()->where('user_id', $this->participant->id)->sole();

    expect($submission->is_late)->toBeTrue();
});
