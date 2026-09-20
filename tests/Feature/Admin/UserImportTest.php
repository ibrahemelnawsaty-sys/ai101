<?php

declare(strict_types=1);

/**
 * Bringing a whole cohort in from one sheet.
 *
 * WHY THIS SUITE EXISTS
 * The import is the only place in the platform where one request can create
 * sixty people, and every way it can go wrong is a way that is quiet: a
 * duplicate address inside the file that no database check can see, a phone
 * number Excel turned into a number, a double-clicked confirm that sends every
 * letter twice, a cohort that vanished between the preview and the button.
 *
 * The preview is what makes those visible, so the preview is what is tested:
 * that it writes nothing, that it names the row an administrator can find, and
 * that the confirm afterwards is spent by the first press.
 *
 * @see PRD §4.2, §4.5.1 · CONSTITUTION Art. 5, Art. 10 · D-63
 */

use App\Jobs\InviteImportedParticipant;
use App\Models\User;
use App\Services\Import\ParticipantImportReader;
use App\Services\Import\ParticipantImportSheet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/** A CSV in the sheet's own column order, for reading without an upload. */
function importCsv(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'athar').'.csv';
    $handle = fopen($path, 'w');
    fputcsv($handle, ParticipantImportSheet::headings());

    foreach ($rows as $row) {
        fputcsv($handle, $row);
    }

    fclose($handle);

    return $path;
}

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 09:00:00'));

    $this->cohort = makeCohort();
    $this->admin = makeAdmin();
});

/**
 * A filled sheet, written to a real .xlsx and handed over as an upload.
 *
 * Built rather than fixtured, because the header row comes from the same
 * `ParticipantImportSheet::headings()` the download uses — a fixture would
 * freeze last month's labels and go on passing after they changed.
 *
 * @param  list<list<string>>  $rows
 */
function importSheetFile(array $rows): UploadedFile
{
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();
    $sheet->fromArray(ParticipantImportSheet::headings(), null, 'A1');
    $sheet->fromArray($rows, null, 'A2');

    $path = tempnam(sys_get_temp_dir(), 'athar').'.xlsx';
    IOFactory::createWriter($book, 'Xlsx')->save($path);
    $book->disconnectWorksheets();

    return new UploadedFile($path, 'trainees.xlsx', null, null, true);
}

/**
 * One valid row, in the sheet's own column order: the Arabic name, the
 * address, the mobile number (D-85).
 *
 * @return list<string>
 */
function importRow(string $email, string $phone = '0512345678'): array
{
    return ['محمد', 'عبدالله', 'سعيد', 'القحطاني', $email, $phone];
}

/** The same row with a two-part name — the rest of the name simply unknown. */
function importRowWithShortName(string $email, string $phone = '0512345678'): array
{
    return ['محمد', '', '', 'القحطاني', $email, $phone];
}

it('D-63: القالب يُبنى بورقتين ويحفظ صفر الجوّال', function (): void {
    $response = $this->actingAs($this->admin)->get(route('admin.users.import.template'));

    $response->assertOk()
        ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    // getContent(), not streamedContent(): the endpoint builds the workbook in
    // memory and returns a plain response. streamedContent() does not return
    // null for a non-streamed response — it raises an assertion failure, so the
    // `?:` fallback this line used to carry could never be reached.
    $path = tempnam(sys_get_temp_dir(), 'athar').'.xlsx';
    file_put_contents($path, $response->getContent());

    $book = IOFactory::createReaderForFile($path)->load($path);

    // The data sheet and the instructions sheet. The filled example lives on the
    // second one, where it cannot be imported by accident.
    expect($book->getSheetCount())->toBe(2)
        // Column A renders rightmost, which is where an Arabic reader starts.
        ->and($book->getSheet(0)->getRightToLeft())->toBeTrue();

    $headings = $book->getSheet(0)->toArray(null, true, false, false)[0] ?? [];

    expect(array_filter($headings, static fn ($cell): bool => (string) $cell !== ''))
        ->toHaveCount(count(ParticipantImportSheet::columns()));

    $book->disconnectWorksheets();
});

it('D-85: الملف يطلب الاسم العربي والبريد والجوّال فقط', function (): void {
    expect(array_keys(ParticipantImportSheet::columns()))->toBe([
        'first_name_ar', 'father_name_ar', 'grandfather_name_ar', 'family_name_ar', 'email', 'phone',
    ]);
});

it('D-85: اسم ثنائي يُقبل ويُستورد كما كُتب — والخانات الفارغة تبقى فارغة', function (): void {
    Queue::fake();

    $this->actingAs($this->admin)
        ->post(route('admin.users.import.preview'), [
            'cohort_id' => $this->cohort->id,
            'sheet' => importSheetFile([importRowWithShortName('short@example.com')]),
        ])
        ->assertOk();

    $this->actingAs($this->admin)->post(route('admin.users.import.store'))->assertRedirect();

    Queue::assertPushed(InviteImportedParticipant::class, function (InviteImportedParticipant $job): bool {
        $columns = (fn (): array => $this->profileColumns)->call($job);

        return ! array_key_exists('second_name_ar', $columns)
            && ! array_key_exists('third_name_ar', $columns)
            && ! array_key_exists('gender', $columns)
            && ! array_key_exists('first_name_en', $columns)
            && $columns['last_name_ar'] === 'القحطاني';
    });
});

it('D-85: الجوّال والاسم الأول يبقيان مطلوبين، والجوّال المكسور يُرفَض بسطره', function (): void {
    $rows = app(ParticipantImportReader::class)->read(
        importCsv([
            ['', 'عبدالله', 'سعيد', 'القحطاني', 'noname@example.com', '0512345671'],
            ['محمد', 'عبدالله', 'سعيد', 'القحطاني', 'nophone@example.com', ''],
            ['محمد', 'عبدالله', 'سعيد', 'القحطاني', 'bad@example.com', '12345'],
        ]),
        'csv',
    );

    expect($rows)->toHaveCount(3)
        ->and($rows[0]->isValid())->toBeFalse()
        ->and($rows[1]->isValid())->toBeFalse()
        ->and($rows[2]->isValid())->toBeFalse();
});

it('المادة 5: المعاينة لا تُنشئ حسابًا واحدًا', function (): void {
    Queue::fake();

    $before = User::query()->count();

    $this->actingAs($this->admin)
        ->post(route('admin.users.import.preview'), [
            'cohort_id' => $this->cohort->getKey(),
            'sheet' => importSheetFile([importRow('one@example.com'), importRow('two@example.com', '0512345679')]),
        ])
        ->assertOk()
        ->assertViewIs('admin.users.import');

    expect(User::query()->count())->toBe($before);
    Queue::assertNothingPushed();
});

it('D-63: بريد مكرّر داخل الملف يُمسَك ويُسمّي سطره', function (): void {
    // Invisible to any database check: both rows are new, and the second only
    // fails on the unique index — halfway through, after the first half of a
    // cohort has already been created.
    $response = $this->actingAs($this->admin)
        ->post(route('admin.users.import.preview'), [
            'cohort_id' => $this->cohort->getKey(),
            'sheet' => importSheetFile([
                importRow('same@example.com'),
                importRow('same@example.com', '0512345679'),
            ]),
        ]);

    $response->assertOk();

    /** @var list<App\Services\Import\ImportedRow> $rows */
    $rows = $response->viewData('rows');

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->isValid())->toBeTrue()
        ->and($rows[1]->isValid())->toBeFalse()
        // The number the administrator sees in Excel, not an array index.
        ->and(implode(' ', $rows[1]->errors))->toContain('2');

    expect($response->viewData('validCount'))->toBe(1)
        ->and($response->viewData('rejectedCount'))->toBe(1);
});

it('D-63: التأكيد يُطابر دعوة لكل سطر صالح ولا يُنشئ شيئًا في الطلب', function (): void {
    Queue::fake();

    $this->actingAs($this->admin)->post(route('admin.users.import.preview'), [
        'cohort_id' => $this->cohort->getKey(),
        'sheet' => importSheetFile([
            importRow('one@example.com'),
            importRow('two@example.com', '0512345679'),
            // Rejected: the phone is not a Saudi mobile number.
            importRow('three@example.com', '12345'),
        ]),
    ])->assertOk();

    $before = User::query()->count();

    $this->actingAs($this->admin)
        ->post(route('admin.users.import.store'))
        ->assertRedirect(route('admin.users.index'));

    // Two hundred bcrypt hashes at twelve rounds is fifty seconds, and shared
    // hosting cuts a long request off rather than letting it finish. Nothing is
    // created here; the per-minute cron does the work.
    expect(User::query()->count())->toBe($before);

    Queue::assertPushed(InviteImportedParticipant::class, 2);
});

it('D-63: ضغط التأكيد مرّتين لا يُرسل الدعوات مرّتين', function (): void {
    Queue::fake();

    $this->actingAs($this->admin)->post(route('admin.users.import.preview'), [
        'cohort_id' => $this->cohort->getKey(),
        'sheet' => importSheetFile([importRow('one@example.com')]),
    ])->assertOk();

    $this->actingAs($this->admin)->post(route('admin.users.import.store'))->assertRedirect();

    // The session key is pulled, so the second press finds nothing waiting and
    // says so rather than sending every letter again.
    $this->actingAs($this->admin)
        ->post(route('admin.users.import.store'))
        ->assertRedirect(route('admin.users.import'))
        ->assertSessionHasErrors('sheet');

    Queue::assertPushed(InviteImportedParticipant::class, 1);
});

it('المادة 5: غير الإداري لا يصل إلى الاستيراد إطلاقًا', function (): void {
    $participant = makeParticipant($this->cohort);

    $this->actingAs($participant)->get(route('admin.users.import'))->assertForbidden();
    $this->actingAs($participant)->get(route('admin.users.import.template'))->assertForbidden();
    $this->actingAs($participant)
        ->post(route('admin.users.import.preview'), [
            'cohort_id' => $this->cohort->getKey(),
            'sheet' => importSheetFile([importRow('one@example.com')]),
        ])
        ->assertForbidden();
});

it('D-63: الوظيفة ترفض عنوانًا صار له حساب بين المعاينة والتنفيذ', function (): void {
    // The queue is drained by cron and can lag by minutes. In that window the
    // single-account form, or the other half of a double-click, can take the
    // address.
    $existing = makeParticipant($this->cohort);
    $existing->forceFill(['email' => 'taken@example.com'])->save();

    $before = User::query()->count();

    (new InviteImportedParticipant(
        'taken@example.com',
        ['first_name_ar' => 'محمد', 'second_name_ar' => 'عبدالله', 'third_name_ar' => 'سعيد', 'last_name_ar' => 'القحطاني'],
        (string) $this->cohort->getKey(),
    ))->handle(app(App\Services\Credentials\AccountInviter::class));

    expect(User::query()->count())->toBe($before);
});
