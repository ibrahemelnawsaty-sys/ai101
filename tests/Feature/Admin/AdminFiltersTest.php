<?php

declare(strict_types=1);

/**
 * Phase 2 (2-C) — the admin screens' filters, and the name search behind them.
 *
 * Certificates printed a search box the server never read; the reports screen a
 * cohort picker it never read; the users and registrations lists promised a
 * search "by name, e-mail or phone" and matched the e-mail only; and the
 * recipient search on «new conversation» asked the database for two columns
 * that do not exist (`full_name_ar` and `full_name_en` are computed accessors)
 * — SQLite reads an unknown double-quoted name as a string, so the test
 * database answered "no match", while MySQL on the host answers an error.
 *
 * One search now serves all of them: `User::matchingPerson`. Every word typed
 * must match the e-mail or a part of the Arabic or English name (the phone
 * only where the caller says so), in any order.
 *
 * @see BR-22, BR-23, BR-26 · FR-ADMIN-04, FR-ADMIN-09, FR-ADMIN-10, FR-ADMIN-12, FR-CERT-02 · D-118, D-136
 */

use App\Models\Enrollment;
use App\Models\Profile;
use App\Models\User;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    $this->cohort = makeCohort(['name' => 'Cohort A']);
});

/** A person with a known name in all four Arabic and English parts, and a phone. */
function named(User $user, string $en, string $ar, string $phone): User
{
    [$e1, $e2, $e3, $e4] = explode(' ', $en);
    [$a1, $a2, $a3, $a4] = explode(' ', $ar);

    Profile::factory()->create([
        'user_id' => $user->id,
        'first_name_en' => $e1, 'second_name_en' => $e2, 'third_name_en' => $e3, 'last_name_en' => $e4,
        'first_name_ar' => $a1, 'second_name_ar' => $a2, 'third_name_ar' => $a3, 'last_name_ar' => $a4,
        'phone' => $phone,
    ]);

    return $user->refresh();
}

function peopleCase(object $test): void
{
    $test->omar = named(
        makeParticipant($test->cohort, ['email' => 'omar.one@example.test']),
        'Omar Saleh Nasser Beta', 'عمر صالح ناصر بيتا', '0501110001',
    );
    $test->sara = named(
        makeParticipant($test->cohort, ['email' => 'sara.two@example.test']),
        'Sara Khalid Faisal Alpha', 'سارة خالد فيصل ألفا', '0502220002',
    );
}

/*
|--------------------------------------------------------------------------
| The shared person search
|--------------------------------------------------------------------------
*/

it('D-136: البحث بالاسم يطابق أي جزء منه، عربيًا أو إنجليزيًا، والبريد', function (): void {
    peopleCase($this);

    $found = static fn (string $term): array => User::query()->matchingPerson($term)
        ->pluck('email')->sort()->values()->all();

    expect($found('Omar'))->toBe(['omar.one@example.test'])                       // first
        ->and($found('Nasser'))->toBe(['omar.one@example.test'])                  // third — the old search never read it
        ->and($found('Alpha'))->toBe(['sara.two@example.test'])                   // last
        ->and($found('صالح'))->toBe(['omar.one@example.test'])                    // Arabic, second
        ->and($found('two@example'))->toBe(['sara.two@example.test'])             // e-mail
        ->and($found('nobody'))->toBe([]);
});

it('D-136: كل كلمة يكتبها المستخدم يجب أن تطابق، بأي ترتيب', function (): void {
    peopleCase($this);

    $found = static fn (string $term): array => User::query()->matchingPerson($term)
        ->pluck('email')->sort()->values()->all();

    expect($found('Beta Omar'))->toBe(['omar.one@example.test'])
        ->and($found('عمر بيتا'))->toBe(['omar.one@example.test'])
        ->and($found('Omar Alpha'))->toBe([])           // two people, one word each: nobody has both
        ->and($found('  Omar   '))->toBe(['omar.one@example.test']);
});

it('D-136: الجوال لا يُبحث فيه إلا حين يطلب المستدعي ذلك', function (): void {
    peopleCase($this);

    expect(User::query()->matchingPerson('0501110001')->count())->toBe(0)
        ->and(User::query()->matchingPerson('0501110001', withPhone: true)->pluck('email')->all())
        ->toBe(['omar.one@example.test']);
});

/*
|--------------------------------------------------------------------------
| «New conversation» recipient search — D-118
|--------------------------------------------------------------------------
*/

it('D-118: بحث المستلمين بالاسم يجد المدرّب، ولا يبحث بجواله', function (): void {
    peopleCase($this);
    $trainer = named(makeTrainer($this->cohort, ['email' => 'trainer@example.test']), 'Nadia Hamad Rashed Gamma', 'نادية حمد راشد جاما', '0503330003');

    $me = makeParticipant($this->cohort);

    $labels = static fn ($page): array => collect($page->viewData('options'))->pluck('label')->all();

    $byGiven = $this->actingAs($me)->get(route('messages.create', ['q' => 'Nadia']))->assertOk();
    expect($labels($byGiven))->toContain($trainer->profile->full_name_ar);

    $byThird = $this->actingAs($me)->get(route('messages.create', ['q' => 'Rashed']))->assertOk();
    expect($labels($byThird))->toContain($trainer->profile->full_name_ar);

    // A participant must not be able to look people up by phone number.
    $byPhone = $this->actingAs($me)->get(route('messages.create', ['q' => '0503330003']))->assertOk();
    expect($labels($byPhone))->not->toContain($trainer->profile->full_name_ar);
})->group('authz');

/*
|--------------------------------------------------------------------------
| Admin users and registrations — FR-ADMIN-04, FR-ADMIN-09
|--------------------------------------------------------------------------
*/

it('FR-ADMIN-04: بحث المستخدمين يطابق الاسم والبريد والجوال كما يَعِد مربّعه', function (): void {
    peopleCase($this);
    $sysadmin = makeSystemAdmin();

    $emails = static fn ($page): array => collect($page->viewData('users')->items())->pluck('email')->all();

    foreach (['Nasser', 'صالح', 'omar.one', '0501110001'] as $term) {
        $page = $this->actingAs($sysadmin)->get(route('admin.users.index', ['q' => $term]))->assertOk();

        expect($emails($page))->toBe(['omar.one@example.test']);
    }
});

it('FR-ADMIN-09: بحث طلبات التسجيل يطابق الاسم لا البريد وحده', function (): void {
    peopleCase($this);
    Enrollment::query()->update(['status' => 'pending']);
    $admin = makeAdmin();

    $page = $this->actingAs($admin)->get(route('admin.registrations.index', ['q' => 'Faisal']))->assertOk();

    expect(collect($page->viewData('requests')->items())->pluck('email')->all())->toBe(['sara.two@example.test']);
});

/*
|--------------------------------------------------------------------------
| Admin certificates — FR-CERT-02, FR-ADMIN-10
|--------------------------------------------------------------------------
*/

it('FR-CERT-02: بحث الشهادات يضيّق قوائم المستوفين وغير المستوفين والصادرة بالاسم', function (): void {
    peopleCase($this);
    issueCertificateFor($this->omar, $this->cohort);
    issueCertificateFor($this->sara, $this->cohort);
    $admin = makeAdmin();

    $page = $this->actingAs($admin)
        ->get(route('admin.certificates.index', ['cohort' => $this->cohort->id, 'q' => 'Omar']))->assertOk();

    $holders = collect($page->viewData('issued')->items())->map(fn ($row) => $row['holderName'])->all();
    expect($holders)->toBe([$this->omar->profile->full_name_ar]);

    $unfiltered = $this->actingAs($admin)
        ->get(route('admin.certificates.index', ['cohort' => $this->cohort->id]))->assertOk();
    expect($unfiltered->viewData('issued')->items())->toHaveCount(2);
});

it('FR-CERT-02: بحث الشهادات يحصر «غير المستوفين» بالاسم دون أن يمسّ حساب الأهلية', function (): void {
    peopleCase($this);
    $admin = makeAdmin();

    $all = $this->actingAs($admin)
        ->get(route('admin.certificates.index', ['cohort' => $this->cohort->id]))->assertOk();
    $filtered = $this->actingAs($admin)
        ->get(route('admin.certificates.index', ['cohort' => $this->cohort->id, 'q' => 'Faisal']))->assertOk();

    expect(collect($all->viewData('notEligible'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->omar->id, $this->sara->id])->sort()->values()->all())
        ->and(collect($filtered->viewData('notEligible'))->pluck('id')->all())->toBe([$this->sara->id]);

    // The reasons a person is not eligible are the eligibility service's, in
    // full, whatever the search: the filter chooses WHO is listed (BR-26).
    $saraId = $this->sara->id;
    $reasonsOfSara = static fn ($page): array => collect($page->viewData('notEligible'))
        ->firstWhere('id', $saraId)['reasons'];
    expect($reasonsOfSara($filtered))->toEqual($reasonsOfSara($all));
});

/*
|--------------------------------------------------------------------------
| Admin reports — FR-ADMIN-12
|--------------------------------------------------------------------------
*/

it('FR-ADMIN-12: مرشّح الدفعة في التقارير يحصر الجدول والرسم والملخّص فيها', function (): void {
    $other = makeCohort(['name' => 'Cohort B']);
    makeParticipant($this->cohort);
    makeParticipant($this->cohort);
    makeParticipant($other);
    $admin = makeAdmin();

    $all = $this->actingAs($admin)->get(route('admin.reports.index'))->assertOk();
    expect(collect($all->viewData('cohortRows')->items())->pluck('cohortName')->sort()->values()->all())
        ->toBe(['Cohort A', 'Cohort B']);

    $one = $this->actingAs($admin)->get(route('admin.reports.index', ['cohort' => $this->cohort->id]))->assertOk();

    expect(collect($one->viewData('cohortRows')->items())->pluck('cohortName')->all())->toBe(['Cohort A'])
        ->and(collect($one->viewData('attendanceByCohort'))->count())->toBe(1)
        // The headline «registrations» is this cohort's, not the platform's.
        ->and($one->viewData('summary')['registrations'])->toBe(2)
        ->and($all->viewData('summary')['registrations'])->toBe(3);
});

it('FR-ADMIN-12: دفعة لم تعرضها الشاشة تُتجاهل فتُعرض كل الدفعات', function (): void {
    makeCohort(['name' => 'Cohort B']);
    $admin = makeAdmin();

    $page = $this->actingAs($admin)->get(route('admin.reports.index', ['cohort' => 'not-a-cohort']))->assertOk();

    expect(collect($page->viewData('cohortRows')->items())->pluck('cohortName')->sort()->values()->all())
        ->toBe(['Cohort A', 'Cohort B']);
});

it('FR-ADMIN-12: ملف التصدير بجانب المرشّح يحوي الدفعة المختارة وحدها', function (): void {
    $other = makeCohort(['name' => 'Cohort B']);
    $admin = makeAdmin();

    $all = $this->actingAs($admin)->get(route('admin.reports.export'))->assertOk()->getContent();
    expect($all)->toContain('Cohort A')->toContain('Cohort B');

    $one = $this->actingAs($admin)->get(route('admin.reports.export', ['cohort' => $other->id]))->assertOk()->getContent();
    expect($one)->toContain('Cohort B')->not->toContain('Cohort A');

    // A cohort the picker never offered is ignored, like everywhere else.
    $ignored = $this->actingAs($admin)->get(route('admin.reports.export', ['cohort' => 'nope']))->assertOk()->getContent();
    expect($ignored)->toContain('Cohort A')->toContain('Cohort B');
});

it('FR-CERT-02: بحث لا يطابق أحدًا لا يقول «لا أحد خارج الشروط» — ذلك ادّعاء عن الدفعة كلها', function (): void {
    peopleCase($this);
    $admin = makeAdmin();

    $page = $this->actingAs($admin)
        ->get(route('admin.certificates.index', ['cohort' => $this->cohort->id, 'q' => 'nobody-at-all']))->assertOk();

    $page->assertSee(__('certificates.admin.no_match_title'))
        ->assertDontSee(__('certificates.admin.none_ineligible_title'));

    // No search: the cohort-wide sentence returns when it is true (everyone is eligible).
    $empty = makeCohort(['name' => 'Cohort Empty']);
    $plain = $this->actingAs($admin)->get(route('admin.certificates.index', ['cohort' => $empty->id]))->assertOk();
    $plain->assertSee(__('certificates.admin.none_ineligible_title'))
        ->assertDontSee(__('certificates.admin.no_match_title'));
});

/*
|--------------------------------------------------------------------------
| The independent security review (Art. 27), each finding pinned
|--------------------------------------------------------------------------
*/

it('D-136: عدد كلمات البحث محدود — ما بعد الحدّ يُهمَل بدل أن يبني استعلامًا بآلاف الشروط', function (): void {
    peopleCase($this);

    // Fifty times the same word, then one nobody carries: only the first words
    // are read, so the last never gets the chance to exclude the person.
    $term = str_repeat('Omar ', 50).'nobody';

    expect(User::query()->matchingPerson($term)->pluck('email')->all())->toBe(['omar.one@example.test']);
});

it('D-136: بحث بآلاف الكلمات في الصفحات الثلاث التي تقرؤه يُجاب بصفحة لا بخطأ خادم', function (): void {
    peopleCase($this);
    $long = trim(str_repeat('a ', 3000));

    $this->actingAs($this->omar)->get(route('messages.create', ['q' => $long]))->assertOk();

    Illuminate\Support\Facades\Auth::forgetGuards();
    $this->actingAs(makeSystemAdmin())->get(route('admin.users.index', ['q' => $long]))->assertOk();

    Illuminate\Support\Facades\Auth::forgetGuards();
    $this->actingAs(makeAdmin())->get(route('admin.registrations.index', ['q' => $long]))->assertOk();
});

it('FR-CERT-02: تصدير الشهادات يحمل الدفعة والبحث اللذين في رابطه — الزر يقول «تصدير» والقائمة أمامك مصفّاة', function (): void {
    peopleCase($this);
    $other = makeCohort(['name' => 'Cohort B']);
    $zed = named(makeParticipant($other, ['email' => 'zed@example.test']), 'Zed Khalid Nasser Gamma', 'زيد خالد ناصر جاما', '0503330003');

    $omarCert = issueCertificateFor($this->omar, $this->cohort);
    $saraCert = issueCertificateFor($this->sara, $this->cohort);
    $zedCert = issueCertificateFor($zed, $other);

    $export = static fn (object $test, array $query): string => $test->actingAs(makeAdmin())
        ->get(route('admin.certificates.export', $query))->assertOk()->getContent();

    $all = $export($this, []);
    expect($all)->toContain($omarCert->serial_number)->toContain($saraCert->serial_number)->toContain($zedCert->serial_number);

    $b = $export($this, ['cohort' => $other->id]);
    expect($b)->toContain($zedCert->serial_number)->not->toContain($omarCert->serial_number)->not->toContain($saraCert->serial_number);

    $searched = $export($this, ['cohort' => $this->cohort->id, 'q' => 'Omar']);
    expect($searched)->toContain($omarCert->serial_number)->not->toContain($saraCert->serial_number)->not->toContain($zedCert->serial_number);

    // A cohort the picker never offered is ignored, like everywhere else.
    expect($export($this, ['cohort' => 'nope']))->toContain($zedCert->serial_number)->toContain($omarCert->serial_number);
});

/*
|--------------------------------------------------------------------------
| Gaps the independent review found by mutation (Art. 27)
|--------------------------------------------------------------------------
*/

it('FR-CERT-02: بحث الشهادات يحصر قائمة «المستوفين» أيضًا — لا الصادرة وغير المستوفين وحدهما', function (): void {
    peopleCase($this);
    // A cohort that asks for nothing: everyone enrolled qualifies, so the
    // «eligible» list has two people to narrow.
    $this->cohort->update(['pass_score' => 0, 'min_attendance_rate' => 0]);
    $admin = makeAdmin();

    $all = $this->actingAs($admin)
        ->get(route('admin.certificates.index', ['cohort' => $this->cohort->id]))->assertOk();
    $ids = static fn ($page): array => collect($page->viewData('eligible'))->pluck('id')->sort()->values()->all();

    expect($ids($all))->toBe(collect([$this->omar->id, $this->sara->id])->sort()->values()->all());

    $found = $this->actingAs($admin)
        ->get(route('admin.certificates.index', ['cohort' => $this->cohort->id, 'q' => 'Omar']))->assertOk();

    expect($ids($found))->toBe([$this->omar->id])
        // The counter above the list follows the same search.
        ->and($found->viewData('counts')['eligible'] ?? null)->toBe(1);
});

it('FR-ADMIN-12: منحنى التسجيل يتبع الدفعة المختارة كالجدول والملخّص', function (): void {
    $other = makeCohort(['name' => 'Cohort B']);
    makeParticipant($this->cohort);
    makeParticipant($this->cohort);
    makeParticipant($other);
    $admin = makeAdmin();

    $total = static fn ($page): int => (int) collect($page->viewData('registrationsOverTime'))->sum(static fn ($point) => $point['value']);

    $all = $this->actingAs($admin)->get(route('admin.reports.index'))->assertOk();
    $one = $this->actingAs($admin)->get(route('admin.reports.index', ['cohort' => $this->cohort->id]))->assertOk();

    expect($total($all))->toBe(3)->and($total($one))->toBe(2);
});
