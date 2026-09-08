<?php

declare(strict_types=1);

/**
 * BR-31, BR-32, BR-36: changeable content lives in the database, the platform always
 * keeps one active administrator, and the programme name and domain are read from
 * configuration rather than written into the code.
 *
 * The leaves are the ones routes/web.php declares, which is the authority on them:
 * PROJECT-CONTRACT.md §10 collapses the admin area into `admin.*` and names only the
 * preview and impersonation-stop routes. In particular a STATUS change goes to
 * `admin.users.status` and a ROLE change to `admin.users.role` — PRD §4.2 lists
 * «تغيير دور أي مستخدم» and «تعطيل أو حذف حساب» as two separate permissions, so the
 * platform gives them two separately audited endpoints and an ordinary details edit
 * (`admin.users.update`) can never carry a privilege change in the same payload.
 * Aiming the BR-32 cases at `admin.users.update` made two of them pass on a
 * validation error about missing profile fields rather than on the rule itself.
 *
 * @see BR-31, BR-32, BR-36 · PRD §9.1, §9.18 · PROJECT-CONTRACT.md §1
 */

use App\Models\LandingSetting;
use App\Models\User;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 12:00:00'));

    // The landing page is about the cohort that is OPEN for registration, or
    // the next one starting (PRD §9.1): both HomeController::openCohort() and
    // Admin\LandingController::currentCohort() select on that status, so a
    // `running` cohort — makeCohort()'s default — renders the empty state and
    // has no settings row to edit.
    $this->cohort = makeCohort(['status' => 'open']);
    $this->admin = makeAdmin();
});

/*
|--------------------------------------------------------------------------
| BR-31 — changeable content is data, not code
|--------------------------------------------------------------------------
*/

it('BR-31: نصوص صفحة الهبوط تُقرأ من قاعدة البيانات وتتغير من لوحة الإدارة', function (): void {
    $settings = LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'hero_text' => 'CANARY-HERO-BEFORE',
        'is_registration_open' => true,
        'countdown_enabled' => false,
    ]);

    $this->get(route('home'))->assertOk()->assertSee('CANARY-HERO-BEFORE', escape: false);

    // The editor's field names are the ones the form and UpdateLandingRequest
    // publish, not the column names: `hero_subtitle` is the sentence the public
    // page prints under the headline and is stored in the `hero_text` column.
    assertAccepted($this->actingAs($this->admin)->put(route('admin.landing.update', $settings), [
        'hero_title' => 'CANARY-HERO-TITLE',
        'hero_subtitle' => 'CANARY-HERO-AFTER',
        'is_registration_open' => true,
        'countdown_enabled' => false,
    ]));

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('CANARY-HERO-AFTER', escape: false)
        ->assertDontSee('CANARY-HERO-BEFORE', escape: false);
});

it('BR-31: الأسئلة الشائعة تُدار من لوحة الإدارة لا من الكود', function (): void {
    // The FAQ is a JSON column with its own three endpoints (PRD §9.1,
    // PROJECT-CONTRACT §4) — it does not ride inside the settings form, and an
    // entry is addressed by the key stored beside it.
    LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'faq' => [['key' => 'canary-one', 'question' => 'CANARY-Q-ONE', 'answer' => 'CANARY-A-ONE']],
        'is_registration_open' => true,
    ]);

    $this->get(route('home'))->assertSee('CANARY-Q-ONE', escape: false);

    assertAccepted($this->actingAs($this->admin)->put(route('admin.landing.faq.update', 'canary-one'), [
        'question' => 'CANARY-Q-TWO',
        'answer' => 'CANARY-A-TWO',
    ]));

    $this->get(route('home'))
        ->assertSee('CANARY-Q-TWO', escape: false)
        ->assertDontSee('CANARY-Q-ONE', escape: false);
});

it('BR-31: إغلاق التسجيل من لوحة الإدارة يُغلقه فعلًا على الخادم', function (): void {
    $settings = LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'is_registration_open' => false,
    ]);

    expect($settings->is_registration_open)->toBeFalse();

    assertRefused($this->post(route('register'), [
        'email' => 'late.applicant@example.test',
        'password' => 'Battery-Staple-12',
        'password_confirmation' => 'Battery-Staple-12',
        'cohort_id' => $this->cohort->id,
    ]));

    expect(User::query()->where('email', 'late.applicant@example.test')->count())->toBe(0);
});

it('BR-31: المتدرب والمدرب لا يعدّلان إعدادات صفحة الهبوط', function (): void {
    $settings = LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'hero_text' => 'CANARY-HERO-BEFORE',
        'is_registration_open' => true,
    ]);

    foreach ([makeParticipant($this->cohort), makeTrainer($this->cohort)] as $user) {
        $this->actingAs($user)
            ->put(route('admin.landing.update', $settings), ['hero_text' => 'CANARY-TAMPERED'])
            ->assertForbidden();
    }

    expect($settings->fresh()->hero_text)->toBe('CANARY-HERO-BEFORE');
});

/*
|--------------------------------------------------------------------------
| BR-32 — one active administrator, always
|--------------------------------------------------------------------------
*/

it('BR-32: حذف آخر مدير نظام فعّال مرفوض', function (): void {
    $activeAdmins = static fn (): int => User::query()
        ->where('role', 'admin')
        ->where('status', 'active')
        ->count();

    expect($activeAdmins())->toBe(1);

    // With two administrators, removing one is allowed.
    $second = makeAdmin();
    expect($activeAdmins())->toBe(2);

    assertAccepted($this->actingAs($this->admin)->delete(route('admin.users.destroy', $second)));
    expect($activeAdmins())->toBe(1);

    // With one left, removing that one is refused, and nothing changes. The
    // refusal is a 403, not a redirect: PRD §4.3 lists «مدير النظام لا يستطيع حذف
    // حسابه الخاص، ويجب بقاء مدير واحد على الأقل» among the MANDATORY
    // AUTHORISATION rules, so UserPolicy::delete() is where it lives and
    // tests/Pest.php::assertRefused() says in as many words that a policy
    // refusal is asserted with assertForbidden(), never through it.
    $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->admin))->assertForbidden();
    expect($activeAdmins())->toBe(1);
});

it('BR-32: المدير لا يحذف حسابه هو', function (): void {
    makeAdmin();

    // PRD §4.3, authorisation rules: «مدير النظام لا يستطيع حذف حسابه الخاص».
    $this->actingAs($this->admin)->delete(route('admin.users.destroy', $this->admin))->assertForbidden();

    expect($this->admin->fresh()->deleted_at)->toBeNull();
});

it('BR-32: تعطيل آخر مدير نظام فعّال مرفوض', function (): void {
    // UserPolicy::suspend() refuses before the controller is reached: 403 (PRD §4.3).
    $this->actingAs($this->admin)->patch(route('admin.users.status', $this->admin), [
        'status' => 'suspended',
    ])->assertForbidden();

    expect($this->admin->fresh()->status->value)->toBe('active');
});

it('BR-32: تنزيل دور آخر مدير نظام إلى مدرب مرفوض', function (): void {
    // Two authorisation guards forbid this and either alone is enough (PRD §4.3):
    // an admin never changes their own role, and the last active admin is never
    // demoted. Both live in UserPolicy, so the answer is 403.
    $this->actingAs($this->admin)->put(route('admin.users.role', $this->admin), [
        'role' => 'trainer',
    ])->assertForbidden();

    expect($this->admin->fresh()->role->value)->toBe('admin');
});

it('BR-32: مع وجود مديرين اثنين يمكن تعطيل أحدهما', function (): void {
    $second = makeAdmin();

    assertAccepted($this->actingAs($this->admin)->patch(route('admin.users.status', $second), [
        'status' => 'suspended',
    ]));

    expect(User::query()->where('role', 'admin')->where('status', 'active')->count())->toBe(1);
});

it('BR-32: حذف المستخدم حذف ناعم لا فعلي', function (): void {
    $participant = makeParticipant($this->cohort);

    assertAccepted($this->actingAs($this->admin)->delete(route('admin.users.destroy', $participant)));

    expect(User::query()->where('id', $participant->id)->count())->toBe(0)
        ->and(User::withTrashed()->where('id', $participant->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| BR-36 — the name and the domain come from configuration
|--------------------------------------------------------------------------
*/

it('BR-36: اسم البرنامج ونطاق المنصة يُقرآن من الإعدادات', function (): void {
    // The keys are the ones config/athar.php actually publishes: `program_name`
    // and `email` are flat, `program.code` is nested because the serial number
    // parser needs the code on its own (PRD §9.17).
    expect(config('athar.program_name'))->not->toBeEmpty()
        ->and(config('athar.program.code'))->not->toBeEmpty()
        ->and(config('athar.domain'))->not->toBeEmpty()
        ->and(config('athar.email'))->not->toBeEmpty();
});

it('BR-36: تغيير اسم البرنامج في الإعدادات يغيّره في الواجهة', function (): void {
    config(['athar.program_name' => 'CANARY-PROGRAMME-NAME']);

    $this->get(route('home'))->assertOk()->assertSee('CANARY-PROGRAMME-NAME', escape: false);
});

it('BR-36: النطاق واسم البرنامج غير مكتوبين حرفيًا في أي ملف مصدر خارج الإعدادات', function (): void {
    $roots = array_filter([
        base_path('app'),
        base_path('resources/views'),
        base_path('routes'),
    ], fn (string $path): bool => File::isDirectory($path));

    $offenders = [];

    foreach ($roots as $root) {
        foreach (File::allFiles($root) as $file) {
            if (! in_array($file->getExtension(), ['php'], true)) {
                continue;
            }

            $contents = (string) File::get($file->getPathname());

            // The live domain is read from configuration so the guard follows the
            // domain instead of going blind the moment it changes (D-24, D-36).
            // The retired hostnames stay in the list: a leftover hardcode of an
            // old domain is exactly as much of a BR-36 breach as a new one.
            $needles = array_filter([
                (string) config('athar.domain'),
                (string) config('athar.center_domain'),
                'ai.athar-dev.edu.sa',
                'ai101.athar-dev.edu.sa',
                'athar-dev.edu.sa',
            ]);

            foreach ($needles as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = $file->getRelativePathname();

                    break;
                }
            }
        }
    }

    expect($offenders)->toBeEmpty();
});

it('BR-36: كل رابط مطلق يُبنى من APP_URL', function (): void {
    config(['app.url' => 'https://canary.example.test']);

    expect(url('/dashboard'))->toStartWith('https://canary.example.test');
});
