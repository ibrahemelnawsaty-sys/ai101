<?php

declare(strict_types=1);

/**
 * App\Services\FinalProject\SubmissionFields — the default hand-in, the one
 * stored shape of a hand-in entry, and the upload ceilings the fields share
 * (D-121). Every branch, for G8.
 *
 * @see BR-19, BR-36 · FR-PROJ-10 · PRD §12.5 · D-17, D-121
 */

use App\Enums\SubmissionFieldType;
use App\Enums\SubmissionFileFormat;
use App\Models\FinalProject;
use App\Models\FinalProjectField;
use App\Services\FinalProject\SubmissionFields;
use App\Services\Storage\PrivateFileService;
use Illuminate\Support\Facades\Storage;

it('D-17: كل صيغة يختارها المشرف موجودة أصلًا في قائمة المنصة — امتدادًا ونوعًا مفحوصًا', function (): void {
    $extensions = config('athar.uploads.allowed_extensions');
    $types = app(PrivateFileService::class)->allowedMimeTypes();

    foreach (SubmissionFileFormat::cases() as $format) {
        expect(array_diff($format->extensions(), $extensions))->toBe([], "{$format->value} widens the extension list")
            ->and(array_diff($format->mimeTypes(), $types))->toBe([], "{$format->value} widens the type list")
            ->and($format->label())->not->toBe('enums.submission_file_format.'.$format->value);
    }

    foreach (SubmissionFieldType::cases() as $type) {
        expect($type->label())->not->toBe('enums.submission_field_type.'.$type->value);
    }
});

it('D-121: الصيغ المخزّنة تُقرأ بلا تكرار، وقيمة مجهولة تُسقط لا تُخمَّن', function (): void {
    expect(SubmissionFileFormat::fromValues(['pdf', 'exe', 'pdf', 7, 'png']))->toBe([SubmissionFileFormat::Pdf, SubmissionFileFormat::Png])
        ->and(SubmissionFileFormat::fromValues('pdf'))->toBe([])
        ->and(SubmissionFileFormat::extensionsOf([SubmissionFileFormat::Jpeg, SubmissionFileFormat::Png]))->toBe(['jpg', 'jpeg', 'png'])
        ->and(SubmissionFileFormat::mimeTypesOf([SubmissionFileFormat::Csv, SubmissionFileFormat::Text]))->toBe(['text/csv', 'text/plain']);
});

it('D-121: الحقول الافتراضية خمسة بترتيبها ونصوصها من ملف اللغة وحدودها في سقف المنصة', function (): void {
    $rows = SubmissionFields::defaultRows('project-id', riyadhAt('2026-09-26 12:00:00'));

    expect(array_keys($rows))->toBe(['live_url', 'github_url', 'presentation_file', 'logo_file', 'description'])
        ->and(array_column($rows, 'position'))->toBe([1, 2, 3, 4, 5])
        ->and(array_column($rows, 'type'))->toBe(['url', 'github', 'file', 'file', 'textarea'])
        ->and($rows['live_url']['label'])->toBe(__('project.default_fields.live_url.label'))
        ->and(json_decode((string) $rows['live_url']['tips'], true))->toBe(__('project.default_fields.live_url.tips'))
        ->and($rows['logo_file']['tips'])->toBeNull()
        ->and($rows['live_url']['accepted_formats'])->toBeNull()
        ->and($rows['live_url']['max_kilobytes'])->toBeNull()
        ->and(json_decode((string) $rows['presentation_file']['accepted_formats'], true))->toBe(['pdf', 'powerpoint'])
        ->and($rows['presentation_file']['max_kilobytes'])->toBe(25600)
        ->and($rows['logo_file']['max_kilobytes'])->toBe(4096)
        ->and($rows['logo_file']['is_required'])->toBeFalse()
        ->and(count(array_unique(array_column($rows, 'id'))))->toBe(5);

    // A platform ceiling below a default's own is what the default gets.
    config(['athar.uploads.max_kilobytes' => 2048]);

    $clamped = SubmissionFields::defaultRows('project-id', riyadhAt('2026-09-26 12:00:00'));

    expect($clamped['presentation_file']['max_kilobytes'])->toBe(2048)
        ->and($clamped['logo_file']['max_kilobytes'])->toBe(2048);
});

it('D-121: تثبيت الحقول الافتراضية مرة واحدة — مشروع له حقول لا يُمسّ', function (): void {
    $project = FinalProject::factory()->create(['cohort_id' => makeCohort()->id]);
    $service = app(SubmissionFields::class);

    expect($service->installDefaults($project))->toBe(5)
        ->and($service->installDefaults($project))->toBe(0)
        ->and($project->fields()->count())->toBe(5);
});

it('D-121: شكل عنصر التسليم واحد — النص يُقصّ والفارغ null، والملفات لنوع الملف وحده', function (): void {
    expect(SubmissionFields::answer('f1', SubmissionFieldType::Url, 'Link', '  https://x.test  '))
        ->toBe(['field_id' => 'f1', 'type' => 'url', 'label' => 'Link', 'value' => 'https://x.test', 'files' => []])
        ->and(SubmissionFields::answer(null, SubmissionFieldType::Text, 'T', '   ')['value'])->toBeNull()
        ->and(SubmissionFields::answer('f2', SubmissionFieldType::Text, 'T', 'v', [['path' => 'p']])['files'])->toBe([])
        ->and(SubmissionFields::answer('f3', SubmissionFieldType::File, 'F', 'ignored', [5 => ['path' => 'p']]))
        ->toBe(['field_id' => 'f3', 'type' => 'file', 'label' => 'F', 'value' => null, 'files' => [['path' => 'p']]]);
});

it('D-121: قراءة العناصر المخزّنة تتخطّى التالف وتحفظ مواضع البقية لأن رابط التنزيل يسمّيها', function (): void {
    $read = SubmissionFields::read([
        ['field_id' => 'a', 'type' => 'url', 'label' => 'A', 'value' => ' https://a.test ', 'files' => []],
        'not an entry',
        ['type' => 'hologram', 'label' => 'B'],
        ['field_id' => 7, 'type' => 'file', 'label' => null, 'files' => [['path' => 'x'], 'junk', ['path' => 'y']]],
        ['type' => 'file', 'files' => 'not a list'],
    ]);

    expect(array_keys($read))->toBe([0, 3, 4])
        ->and($read[0]['type'])->toBe(SubmissionFieldType::Url)
        ->and($read[0]['value'])->toBe('https://a.test')
        ->and($read[3]['field_id'])->toBeNull()
        ->and($read[3]['label'])->toBe('')
        ->and($read[3]['value'])->toBeNull()
        ->and($read[3]['files'])->toBe([['path' => 'x'], ['path' => 'y']])
        ->and($read[4]['files'])->toBe([])
        ->and(SubmissionFields::read(null))->toBe([])
        ->and(SubmissionFields::read('[]'))->toBe([]);
});

it('D-121: تحويل التسليم القديم يقرأ النص والمصفوفة والواصف المفرد والقائمة، ويتجاهل الحقل الغائب', function (): void {
    $rows = SubmissionFields::defaultRows('p', riyadhAt('2026-09-26 12:00:00'));
    unset($rows['description']);

    $answers = SubmissionFields::answersFromLegacy((object) [
        'live_url' => 'https://live.test',
        'github_url' => '',
        'presentation_file' => ['path' => 'deck.pdf'],
        'logo_file' => '[{"path":"one.png"},"junk",[]]',
        'files' => '{"not":"a list"}',
    ], $rows);

    expect($answers)->toHaveCount(5)
        ->and($answers[0]['value'])->toBe('https://live.test')
        ->and($answers[1]['value'])->toBeNull()
        ->and($answers[2]['files'])->toBe([['path' => 'deck.pdf']])
        ->and($answers[3]['files'])->toBe([['path' => 'one.png']])
        // `files` held a single descriptor, not a list — still carried across.
        ->and($answers[4]['field_id'])->toBeNull()
        ->and($answers[4]['files'])->toBe([['not' => 'a list']]);

    $bare = SubmissionFields::answersFromLegacy((object) ['files' => 'not json'], $rows);

    expect($bare)->toHaveCount(4)
        ->and(array_column($bare, 'value'))->toBe([null, null, null, null]);
});

it('D-121: سقوف الطلب الواحد من الإعداد، ومجموع الملفات يستثني الحقل قيد التعديل، وأثقل تسليم محسوب', function (): void {
    $project = FinalProject::factory()->create(['cohort_id' => makeCohort()->id]);
    app(SubmissionFields::class)->installDefaults($project);
    $fields = $project->fields()->get();
    $deck = $fields[2];

    expect(SubmissionFields::maxFilesPerHandIn())->toBe(5)
        ->and(SubmissionFields::maxRequestKilobytes())->toBe(30720)
        ->and(SubmissionFields::filesAskedFor($fields))->toBe(2)
        ->and(SubmissionFields::filesAskedFor($fields, (string) $deck->id))->toBe(1)
        ->and(SubmissionFields::largestHandInKilobytes($fields))->toBe(25600 + 4096);

    config(['athar.uploads.max_request_kilobytes' => 'thirty', 'athar.uploads.max_files' => 0, 'athar.uploads.max_kilobytes' => null]);

    expect(SubmissionFields::maxRequestKilobytes())->toBe(SubmissionFields::DEFAULT_MAX_REQUEST_KILOBYTES)
        ->and(SubmissionFields::maxFilesPerHandIn())->toBe(5)
        ->and(FinalProjectField::platformMaxKilobytes())->toBe(25600);
});

it('D-121: حدود الحقل المخزّنة تُقرأ في سقف المنصة دائمًا، والنقاط الفارغة تُسقط', function (): void {
    $field = FinalProjectField::factory()->make([
        'type' => SubmissionFieldType::File,
        'max_kilobytes' => 999999,
        'max_files' => 9,
        'tips' => ['  one ', '', 3, 'two'],
    ]);

    expect($field->maxKilobytes())->toBe(25600)
        ->and($field->maxFiles())->toBe(5)
        ->and($field->tipLines())->toBe(['one', 'two']);

    $unset = FinalProjectField::factory()->make(['max_kilobytes' => null, 'max_files' => null, 'tips' => null]);

    expect($unset->maxKilobytes())->toBe(25600)
        ->and($unset->maxFiles())->toBe(1)
        ->and($unset->tipLines())->toBe([]);
});

it('D-121: الخدمة تفحص البايتات مقابل صيغ الحقل — صورة باسم pdf تُرفض، وPDF يُقبل، وبلا صيغ يبقى سلوك المنصة', function (): void {
    Storage::fake('private');
    $service = app(PrivateFileService::class);
    $pdfOnly = SubmissionFileFormat::Pdf->mimeTypes();

    expect(fn () => $service->store(fakeUpload('slides.pdf', 'png'), 'final-projects/t', null, $pdfOnly))
        ->toThrow(App\Exceptions\FileException::class);

    expect($service->store(fakeUpload('slides.pdf'), 'final-projects/t', null, $pdfOnly)['mime_type'])->toBe('application/pdf')
        ->and($service->store(fakeUpload('logo.pdf', 'png'), 'final-projects/t')['mime_type'])->toBe('image/png');
});
