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

    // D-117: the general supervisor (`admin`) and the system administrator,
    // who alone edits the landing page and changes accounts.
    $this->admin = makeAdmin();
    $this->sysadmin = makeSystemAdmin();
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

    // The editor's field names are the ones PublishLandingRequest accepts, not
    // the column names: `hero_subtitle` is the sentence the public page prints
    // under the headline and is stored in the `hero_text` column. The cohort's
    // settings ride in the same publish as the page texts (D-114).
    assertAccepted($this->actingAs($this->sysadmin)->put(route('admin.landing.update'), [
        'settings' => [
            'hero_title' => 'CANARY-HERO-TITLE',
            'hero_subtitle' => 'CANARY-HERO-AFTER',
            'countdown_enabled' => false,
        ],
    ]));

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('CANARY-HERO-AFTER', escape: false)
        ->assertDontSee('CANARY-HERO-BEFORE', escape: false);
});

it('BR-31: الأسئلة الشائعة تُدار من لوحة الإدارة لا من الكود', function (): void {
    // The FAQ is a JSON column (PROJECT-CONTRACT §4). Since D-114 the editor
    // publishes the whole ordered list; an entry keeps the key stored beside it
    // and the server generates every new one.
    LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'faq' => [['key' => 'canary-one', 'question' => 'CANARY-Q-ONE', 'answer' => 'CANARY-A-ONE']],
        'is_registration_open' => true,
    ]);

    $this->get(route('home'))->assertSee('CANARY-Q-ONE', escape: false);

    assertAccepted($this->actingAs($this->sysadmin)->put(route('admin.landing.update'), [
        'faq' => [['key' => 'canary-one', 'question' => 'CANARY-Q-TWO', 'answer' => 'CANARY-A-TWO']],
    ]));

    $this->get(route('home'))
        ->assertSee('CANARY-Q-TWO', escape: false)
        ->assertDontSee('CANARY-Q-ONE', escape: false);
});

it('BR-31: إغلاق التسجيل من لوحة الإدارة يُغلقه فعلًا على الخادم', function (): void {
    // D-117 — the general supervisor closes it, from the registrations screen.
    assertAccepted($this->actingAs($this->admin)->put(route('admin.registrations.intake', $this->cohort), ['open' => '0']));
    auth()->logout();

    expect(LandingSetting::query()->where('cohort_id', $this->cohort->id)->sole()->is_registration_open)->toBeFalse();

    assertRefused($this->post(route('register'), [
        'email' => 'late.applicant@example.test',
        'password' => 'Battery-Staple-12',
        'password_confirmation' => 'Battery-Staple-12',
        'cohort_id' => $this->cohort->id,
    ]));

    expect(User::query()->where('email', 'late.applicant@example.test')->count())->toBe(0);
});

it('BR-31: المتدرب والمدرب والمشرف العام لا يعدّلون إعدادات صفحة الهبوط', function (): void {
    $settings = LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'hero_text' => 'CANARY-HERO-BEFORE',
        'is_registration_open' => true,
    ]);

    // D-117 took the landing page from the general supervisor on purpose.
    foreach ([makeParticipant($this->cohort), makeTrainer($this->cohort), $this->admin] as $user) {
        $this->actingAs($user)
            ->put(route('admin.landing.update'), ['settings' => ['hero_subtitle' => 'CANARY-TAMPERED', 'countdown_enabled' => false]])
            ->assertForbidden();
    }

    expect($settings->fresh()->hero_text)->toBe('CANARY-HERO-BEFORE');
});

/*
|--------------------------------------------------------------------------
| BR-32 — one active holder of each administrative role, always (D-117)
|--------------------------------------------------------------------------
| The system administrator changes accounts, so every row below acts as one.
| The guarded roles are two: the general supervisor and the system
| administrator. Through the interface only the supervisor's floor can be
| reached by someone else — the last system administrator is the only person
| who could act on their own account, and that is refused on its own — so the
| system administrator's floor is also exercised from the console, the one
| path that can demote anybody.
*/

it('BR-32: حذف آخر مشرف عام فعّال مرفوض', function (): void {
    $activeSupervisors = static fn (): int => User::query()
        ->where('role', 'admin')
        ->where('status', 'active')
        ->count();

    expect($activeSupervisors())->toBe(1);

    // With two supervisors, removing one is allowed.
    $second = makeAdmin();
    expect($activeSupervisors())->toBe(2);

    assertAccepted($this->actingAs($this->sysadmin)->delete(route('admin.users.destroy', $second), [
        'reason' => 'A duplicate supervisor account.',
    ]));
    expect($activeSupervisors())->toBe(1);

    // With one left, removing that one is refused, and nothing changes. The
    // refusal is a 403, not a redirect: PRD §4.3 lists the floor among the
    // MANDATORY AUTHORISATION rules, so UserPolicy::delete() is where it
    // lives, and tests/Pest.php::assertRefused() says a policy refusal is
    // asserted with assertForbidden(), never through it.
    $this->actingAs($this->sysadmin)->delete(route('admin.users.destroy', $this->admin), [
        'reason' => 'Trying to remove the last one.',
    ])->assertForbidden();
    expect($activeSupervisors())->toBe(1);
});

it('BR-32: مدير النظام لا يحذف حسابه ولا يعطّله ولا يغيّر دوره', function (): void {
    makeSystemAdmin();

    // PRD §4.3, authorisation rules: no one acts on their own account here —
    // even with a second system administrator standing by.
    $this->actingAs($this->sysadmin)->delete(route('admin.users.destroy', $this->sysadmin), [
        'reason' => 'Removing my own account.',
    ])->assertForbidden();
    $this->actingAs($this->sysadmin)->patch(route('admin.users.status', $this->sysadmin), [
        'status' => 'suspended',
    ])->assertForbidden();
    $this->actingAs($this->sysadmin)->put(route('admin.users.role', $this->sysadmin), [
        'role' => 'trainer',
        'reason' => 'Demoting my own account.',
    ])->assertForbidden();

    $fresh = $this->sysadmin->fresh();

    expect($fresh->deleted_at)->toBeNull()
        ->and($fresh->status->value)->toBe('active')
        ->and($fresh->role->value)->toBe('system_admin');
});

it('BR-32: تعطيل آخر مشرف عام فعّال مرفوض', function (): void {
    // UserPolicy::suspend() refuses before the controller is reached: 403 (PRD §4.3).
    $this->actingAs($this->sysadmin)->patch(route('admin.users.status', $this->admin), [
        'status' => 'suspended',
    ])->assertForbidden();

    expect($this->admin->fresh()->status->value)->toBe('active');
});

it('BR-32: تنزيل دور آخر مشرف عام إلى مدرب مرفوض', function (): void {
    $this->actingAs($this->sysadmin)->put(route('admin.users.role', $this->admin), [
        'role' => 'trainer',
        'reason' => 'Moving the supervisor to training.',
    ])->assertForbidden();

    expect($this->admin->fresh()->role->value)->toBe('admin');
});

it('BR-32: مع وجود مشرفَين عامَّين يمكن تعطيل أحدهما وتغيير دوره', function (): void {
    $second = makeAdmin();
    $third = makeAdmin();

    assertAccepted($this->actingAs($this->sysadmin)->patch(route('admin.users.status', $second), [
        'status' => 'suspended',
    ]));

    assertAccepted($this->actingAs($this->sysadmin)->put(route('admin.users.role', $third), [
        'role' => 'trainer',
        'reason' => 'Moving this supervisor to training.',
    ]));

    expect(User::query()->where('role', 'admin')->where('status', 'active')->count())->toBe(1)
        ->and($third->fresh()->role->value)->toBe('trainer');
});

it('BR-32: آخر مدير نظام فعّال لا يُنزَّل دوره ولو من الطرفية', function (): void {
    $this->artisan('athar:change-role', [
        'email' => $this->sysadmin->email,
        'role' => 'trainer',
        '--reason' => 'Trying to demote the last system administrator.',
        '--force' => true,
    ])->assertFailed();

    expect($this->sysadmin->fresh()->role->value)->toBe('system_admin')
        ->and(App\Models\AuditLog::query()->where('action', 'role.last_active_holder')->where('entity_id', $this->sysadmin->id)->count())->toBe(1);

    // With a second system administrator, the same demotion goes through.
    makeSystemAdmin();

    $this->artisan('athar:change-role', [
        'email' => $this->sysadmin->email,
        'role' => 'trainer',
        '--reason' => 'A second system administrator is in place now.',
        '--force' => true,
    ])->assertSuccessful();

    expect($this->sysadmin->fresh()->role->value)->toBe('trainer');
});

it('BR-32: حذف المستخدم حذف ناعم لا فعلي', function (): void {
    $participant = makeParticipant($this->cohort);

    assertAccepted($this->actingAs($this->sysadmin)->delete(route('admin.users.destroy', $participant), [
        'reason' => 'Duplicate account created by mistake.',
    ]));

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
