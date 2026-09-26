<?php

declare(strict_types=1);

/**
 * The final-project hand-in, driven by the fields the general supervisor
 * defined (D-121): each field checked by its own type and limits on the
 * server, every file checked on its bytes against the field's formats, the
 * hand-in stored as a copy of what was asked, and every file reachable only
 * through a signed link its own policy re-checks.
 *
 * @see BR-19, BR-22, BR-23 · FR-PROJ-09, FR-PROJ-10, FR-PROJ-11 · PRD §9.14.2, §12.5 · D-80, D-121
 */

use App\Enums\SubmissionFileFormat;
use App\Models\FinalProjectField;
use App\Models\ProjectSubmission;
use App\Support\SignedFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-20 12:00:00'));
    Storage::fake('private');

    $this->cohort = makeCohort();
    $this->trainer = makeTrainer($this->cohort);
    $this->participant = makeParticipant($this->cohort);
    $this->project = makeFinalProject($this->cohort, [
        'is_unlocked' => true,
        'due_at' => riyadhAt('2026-11-05 23:59:00'),
    ]);
});

function submitHandIn(object $test, array $payload): Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->participant)->post(route('finalProject.submit'), $payload);
}

it('FR-PROJ-10: نموذج التسليم يعرض حقول المشروع بترتيبها ووصفها ونقاطها وصيغها وحدودها', function (): void {
    $html = (string) $this->actingAs($this->participant)->get(route('finalProject'))->assertOk()->getContent();

    $order = array_map(
        static fn (string $key): int|false => mb_strpos($html, (string) __('project.default_fields.'.$key.'.label')),
        ['live_url', 'github_url', 'presentation_file', 'logo_file', 'description'],
    );

    expect($order)->not->toContain(false)
        ->and($order)->toBe(collect($order)->sort()->values()->all())
        ->and($html)->toContain(e(__('project.default_fields.live_url.tips')[0]))
        ->and($html)->toContain(e(__('project.default_fields.presentation_file.description')))
        ->and($html)->toContain('accept=".pdf,.ppt,.pptx"')
        ->and($html)->toContain('name="answers['.defaultField($this->project, 'presentation_file')->id.'][]"')
        ->and($html)->toContain(e(__('project.field_formats', ['formats' => 'PDF'.__('app.list_separator').'PowerPoint'])));
});

it('FR-PROJ-10: رابط غير https أو مستودع خارج GitHub يُرفض برسالة تسمّي الحقل، ولا يُحفظ شيء', function (): void {
    $live = defaultField($this->project, 'live_url');
    $github = defaultField($this->project, 'github_url');

    submitHandIn($this, handInPayload($this->project, ['live_url' => 'http://example.test/final']))
        ->assertSessionHasErrors(['answers.'.$live->id => __('project.errors.field_url', ['field' => $live->label])]);

    submitHandIn($this, handInPayload($this->project, ['live_url' => 'not a link']))
        ->assertSessionHasErrors(['answers.'.$live->id]);

    submitHandIn($this, handInPayload($this->project, ['github_url' => 'https://gitlab.com/trainee/final']))
        ->assertSessionHasErrors(['answers.'.$github->id => __('project.errors.field_github', ['field' => $github->label])]);

    expect(ProjectSubmission::query()->count())->toBe(0);
});

it('FR-PROJ-10: ملف بامتداد خارج صيغ الحقل يُرفض برسالة تذكر الصيغ المقبولة', function (): void {
    $deck = defaultField($this->project, 'presentation_file');

    submitHandIn($this, handInPayload($this->project, ['presentation_file' => [fakeUpload('notes.txt', 'txt')]]))
        ->assertSessionHasErrors(['answers.'.$deck->id => __('project.errors.field_file_type', [
            'field' => $deck->label,
            'formats' => 'PDF'.__('app.list_separator').'PowerPoint',
        ])]);

    expect(ProjectSubmission::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('FR-PROJ-10: صورة سُمّيت pdf تُرفض في حقل PDF من محتواها لا من اسمها، ولا يبقى على القرص ملف يتيم', function (): void {
    $deck = defaultField($this->project, 'presentation_file');

    // The logo field moved ahead of the deck, and a genuine logo sent: a file
    // IS written before the deck is refused, so the clean-up is what is tested.
    defaultField($this->project, 'logo_file')->update(['position' => 0]);

    submitHandIn($this, handInPayload($this->project, [
        'logo_file' => [fakeUpload('logo.png', 'png')],
        'presentation_file' => [fakeUpload('slides.pdf', 'png')],
    ]))->assertSessionHasErrors(['answers.'.$deck->id => __('project.errors.field_file_type', [
        'field' => $deck->label,
        'formats' => 'PDF'.__('app.list_separator').'PowerPoint',
    ])]);

    expect(ProjectSubmission::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('FR-PROJ-10: عرض PowerPoint حقيقي يُقبل في حقل العرض التقديمي', function (): void {
    submitHandIn($this, handInPayload($this->project, ['presentation_file' => [fakeUpload('deck.pptx', 'pptx')]]))
        ->assertSessionHasNoErrors();

    $answers = ProjectSubmission::query()->sole()->answers;

    expect($answers[2]['files'][0]['original_name'])->toBe('deck.pptx')
        ->and($answers[2]['files'][0]['mime_type'])->toBeIn(SubmissionFileFormat::Powerpoint->mimeTypes());
});

it('FR-PROJ-10: ملف أكبر من حد الحقل يُرفض، وعدد ملفات فوق حد الحقل يُرفض', function (): void {
    $logo = defaultField($this->project, 'logo_file');

    submitHandIn($this, handInPayload($this->project, [
        'logo_file' => [UploadedFile::fake()->create('logo.png', 4097, 'image/png')],
    ]))->assertSessionHasErrors(['answers.'.$logo->id => __('project.errors.field_file_size', [
        'field' => $logo->label,
        'size' => App\Presenters\Support\HandInRules::size(4096),
    ])]);

    submitHandIn($this, handInPayload($this->project, [
        'logo_file' => [fakeUpload('a.png', 'png'), fakeUpload('b.png', 'png')],
    ]))->assertSessionHasErrors(['answers.'.$logo->id => trans_choice('project.errors.field_file_count', 1, [
        'field' => $logo->label,
        'count' => 1,
    ])]);

    expect(ProjectSubmission::query()->count())->toBe(0);
});

it('FR-PROJ-10: حقل رفع يقبل عدة ملفات يحفظها كلها بترتيبها، ونص طويل فوق حده يُرفض', function (): void {
    $gallery = FinalProjectField::factory()
        ->file([SubmissionFileFormat::Png, SubmissionFileFormat::Pdf], 2, 1024)
        ->optional()
        ->create(['final_project_id' => $this->project->id, 'position' => 6, 'label' => 'CANARY-GALLERY']);
    $about = defaultField($this->project, 'description');

    submitHandIn($this, handInPayload($this->project, ['description' => str_repeat('x', 5001)]))
        ->assertSessionHasErrors(['answers.'.$about->id => __('project.errors.field_too_long', ['field' => $about->label, 'max' => 5000])]);

    $payload = handInPayload($this->project);
    $payload['answers'][$gallery->id] = [fakeUpload('one.png', 'png'), fakeUpload('two.pdf')];

    submitHandIn($this, $payload)->assertSessionHasNoErrors();

    $answers = ProjectSubmission::query()->sole()->answers;

    expect($answers[5]['label'])->toBe('CANARY-GALLERY')
        ->and(array_column($answers[5]['files'], 'original_name'))->toBe(['one.png', 'two.pdf']);
});

it('FR-PROJ-11: إعادة التسليم نسخة جديدة لا تمس السابقة، والنموذج يعرض روابط النسخة السابقة ليُعدَّل سطر واحد', function (): void {
    submitHandIn($this, handInPayload($this->project))->assertSessionHasNoErrors();

    $this->actingAs($this->participant)
        ->get(route('finalProject'))
        ->assertOk()
        ->assertSee('value="https://github.com/athar-trainee/ai101-final"', false);

    submitHandIn($this, handInPayload($this->project, ['live_url' => 'https://example.test/final-v2']))->assertSessionHasNoErrors();

    $versions = ProjectSubmission::query()->orderBy('version')->get();

    expect($versions->pluck('version')->all())->toBe([1, 2])
        ->and($versions[0]->answers[0]['value'])->toBe('https://example.test/final')
        ->and($versions[1]->answers[0]['value'])->toBe('https://example.test/final-v2');
});

it('FR-PROJ-09: المدرب يرى عناصر التسليم في لوحة التصحيح ويفتح الملف برابط موقّع', function (): void {
    submitHandIn($this, handInPayload($this->project))->assertSessionHasNoErrors();
    $submission = ProjectSubmission::query()->sole();

    $html = (string) $this->actingAs($this->trainer)
        ->get(route('trainer.finalProject', ['cohort' => $this->cohort->id, 'grade' => $submission->id]))
        ->assertOk()
        ->assertSee('https://example.test/final')
        ->assertSee((string) __('project.default_fields.presentation_file.label'))
        ->getContent();

    expect(preg_match('#href="([^"]*/files/project-submissions/'.preg_quote((string) $submission->id, '#').'/answers/2/0[^"]*)"#', $html, $m))->toBe(1);

    $response = $this->actingAs($this->trainer)->get(html_entity_decode($m[1]));

    $response->assertOk();
    expect((string) $response->headers->get('content-disposition'))->toContain('slides.pdf');
});

it('BR-22: المتدرب يفتح ملفّه ولا يفتح ملف زميله ولو حمل رابطًا موقَّعًا، وBR-23: ولا مدرب دفعة أخرى', function (): void {
    submitHandIn($this, handInPayload($this->project))->assertSessionHasNoErrors();
    $own = ProjectSubmission::query()->sole();
    $link = SignedFiles::for('files.projectSubmissionAnswer', 'projectSubmission', $own, ['answer' => 2])(0);

    $this->actingAs($this->participant)->get($link)->assertOk();
    $this->actingAs(makeParticipant($this->cohort))->get($link)->assertForbidden();
    $this->actingAs(makeTrainer(makeCohort()))->get($link)->assertForbidden();

    // Unsigned, the route answers 403 even to the owner.
    $this->actingAs($this->participant)
        ->get(route('files.projectSubmissionAnswer', ['projectSubmission' => $own->id, 'answer' => 2, 'index' => 0]))
        ->assertForbidden();
});

it('FR-PROJ-09: عنصر أو ملف غير موجود في التسليم يُجاب 404 لا 500', function (): void {
    submitHandIn($this, handInPayload($this->project))->assertSessionHasNoErrors();
    $own = ProjectSubmission::query()->sole();

    foreach ([[0, 0], [2, 1], [9, 0]] as [$answer, $index]) {
        $this->actingAs($this->participant)
            ->get(SignedFiles::for('files.projectSubmissionAnswer', 'projectSubmission', $own, ['answer' => $answer])($index))
            ->assertNotFound();
    }
});

it('FR-PROJ-10: المتدرب يرى ما سلّمه بعنوان كل عنصر كما سُئل', function (): void {
    submitHandIn($this, handInPayload($this->project, ['description' => "سطر أول\nسطر ثانٍ"]))->assertSessionHasNoErrors();

    $this->actingAs($this->participant)
        ->get(route('finalProject'))
        ->assertOk()
        ->assertSee('https://github.com/athar-trainee/ai101-final')
        ->assertSee('slides.pdf')
        ->assertSee("سطر أول\nسطر ثانٍ", false);
});
