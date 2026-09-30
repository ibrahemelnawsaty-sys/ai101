<?php

declare(strict_types=1);

/**
 * Phase 5 — the administrator's forms, read from the page and posted as a browser posts
 * them.
 *
 * Found by running the screens (D-147): the programme editor's «save» and «archive»
 * answered 404 (the form's action carried the programme's uuid, and the route binds
 * by slug); the cohort's «registration closes» field could never be saved (the page
 * fills it as `2026-08-28T23:59`, the request wanted `2026-08-28 23:59`) — and so no
 * cohort that already had a closing date could be edited at all.
 *
 * Every earlier test posted what the request wants. These post what the page sends.
 *
 * @see BR-31 · PRD §9.18 · CONSTITUTION art. 5 · D-147
 */

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-01 09:00:00'));

    $this->admin = makeAdmin();
    $this->program = makeProgram(['name_ar' => 'Programme One', 'slug' => 'programme-one', 'description' => 'A summary.', 'hours' => 12]);
});

it('D-147: نموذج تعديل البرنامج كما ترسله الصفحة يُحفظ (لا 404)', function (): void {
    $html = $this->actingAs($this->admin)
        ->get(route('admin.programs.index', ['edit' => $this->program->id]))
        ->assertOk()
        ->getContent();

    [$action, $fields] = browserForm($html, '//form[.//input[@name="_method"][@value="PATCH"]]', ['name' => 'Programme Renamed']);

    expect($action)->toBe(route('admin.programs.update', $this->program));

    Auth::forgetGuards();

    $this->actingAs($this->admin)->post($action, $fields)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('admin.programs.updated'));

    expect($this->program->fresh()->name_ar)->toBe('Programme Renamed')
        ->and(AuditLog::query()->where('action', 'program.updated')->count())->toBe(1);
});

it('D-147: أرشفة البرنامج من نموذج الصفحة تعمل، ولا تعود لوحة التأكيد بعد الأرشفة', function (): void {
    $page = route('admin.programs.index', ['archive' => $this->program->id]);

    $html = $this->actingAs($this->admin)->get($page)->assertOk()->getContent();

    [$action, $fields] = browserForm($html, '//form[.//input[@name="_method"][@value="PUT"]]');

    expect($action)->toBe(route('admin.programs.archive', $this->program));

    Auth::forgetGuards();

    $this->actingAs($this->admin)->from($page)->post($action, $fields)
        ->assertRedirect($page)
        ->assertSessionHas('status', __('admin.programs.archived'));

    expect($this->program->fresh()->status->value)->toBe('archived');

    // The redirect returns to the same address; the confirmation must not ask again.
    $again = $this->actingAs($this->admin)->get($page)->assertOk()->getContent();

    expect($again)->not->toContain(__('admin.programs.archive_confirm'));
});

it('D-147: موعد إغلاق التسجيل بصيغة الحقل في المتصفح (T) يُقبل عند الإنشاء والتعديل ويُخزَّن بتوقيت الرياض', function (): void {
    $cohort = makeCohort(['program_id' => $this->program->id, 'name' => 'Cohort Close', 'status' => 'upcoming']);

    $html = $this->actingAs($this->admin)
        ->get(route('admin.cohorts.index', ['edit' => $cohort->id]))
        ->assertOk()
        ->getContent();

    [$action, $fields] = browserForm($html, '//form[.//input[@name="_method"][@value="PATCH"]]', [
        'registration_closes_at' => '2026-10-20T23:59',
        'name' => 'Cohort Close Edited',
    ]);

    expect($fields['registration_closes_at'])->toBe('2026-10-20T23:59');

    Auth::forgetGuards();

    $this->actingAs($this->admin)->post($action, $fields)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('admin.cohorts.updated'));

    $fresh = $cohort->fresh();

    expect($fresh->name)->toBe('Cohort Close Edited')
        ->and($fresh->registration_closes_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-20 20:59');
});

it('D-147: كل دفعة لها موعد إغلاق تُعدَّل كما هي بلا تغيير في الحقل — الصفحة تملأ الحقل والخادم يقبل ما ملأته', function (): void {
    $cohort = makeCohort([
        'program_id' => $this->program->id,
        'name' => 'Cohort With Close',
        'status' => 'upcoming',
        'registration_closes_at' => riyadhAt('2026-10-20 23:59:00'),
    ]);

    $html = $this->actingAs($this->admin)
        ->get(route('admin.cohorts.index', ['edit' => $cohort->id]))
        ->getContent();

    [$action, $fields] = browserForm($html, '//form[.//input[@name="_method"][@value="PATCH"]]', ['capacity' => '40']);

    expect($fields['registration_closes_at'])->toBe('2026-10-20T23:59');

    Auth::forgetGuards();

    $this->actingAs($this->admin)->post($action, $fields)->assertSessionHasNoErrors();

    expect($cohort->fresh()->capacity)->toBe(40);
});

it('D-147: الصيغة القديمة بمسافة تبقى مقبولة، وقيمة فارغة تمسح الموعد', function (): void {
    $cohort = makeCohort([
        'program_id' => $this->program->id,
        'status' => 'upcoming',
        'registration_closes_at' => riyadhAt('2026-10-20 23:59:00'),
    ]);

    $html = $this->actingAs($this->admin)->get(route('admin.cohorts.index', ['edit' => $cohort->id]))->getContent();

    [$action, $fields] = browserForm($html, '//form[.//input[@name="_method"][@value="PATCH"]]', ['registration_closes_at' => '2026-10-21 08:30']);

    Auth::forgetGuards();
    $this->actingAs($this->admin)->post($action, $fields)->assertSessionHasNoErrors();

    expect($cohort->fresh()->registration_closes_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-21 05:30');

    [$action, $fields] = browserForm($html, '//form[.//input[@name="_method"][@value="PATCH"]]', ['registration_closes_at' => '']);

    Auth::forgetGuards();
    $this->actingAs($this->admin)->post($action, $fields)->assertSessionHasNoErrors();

    expect($cohort->fresh()->registration_closes_at)->toBeNull();
});

it('D-147: نص لا يشبه تاريخًا يبقى مرفوضًا', function (): void {
    $cohort = makeCohort(['program_id' => $this->program->id, 'status' => 'upcoming']);

    $html = $this->actingAs($this->admin)->get(route('admin.cohorts.index', ['edit' => $cohort->id]))->getContent();

    [$action, $fields] = browserForm($html, '//form[.//input[@name="_method"][@value="PATCH"]]', ['registration_closes_at' => 'next week']);

    Auth::forgetGuards();
    $this->actingAs($this->admin)->post($action, $fields)->assertSessionHasErrors('registration_closes_at');
});

it('D-147: مرشّح حالة البرامج اسمه state ولا يشترك في الاسم مع حقل حالة المحرّر', function (): void {
    makeProgram(['name_ar' => 'Archived One', 'slug' => 'archived-one', 'status' => 'archived']);

    $list = $this->actingAs($this->admin)->get(route('admin.programs.index', ['state' => 'archived']))->assertOk()->getContent();

    expect($list)->toContain('Archived One')
        ->and($list)->not->toContain('Programme One');

    // With the editor open there is exactly one control named `status` (the editor's own) and
    // one named `state` (the filter): the failed-save case that rewrote the filter cannot recur.
    $editing = $this->actingAs($this->admin)->get(route('admin.programs.index', ['edit' => 'new']))->getContent();

    expect(substr_count($editing, 'name="state"'))->toBe(1)
        ->and(substr_count($editing, 'name="status"'))->toBe(1);
});

it('D-147: رفض إسناد مدرب لا يتسرّب إلى خانة المنسّق، والعكس', function (): void {
    $cohort = makeCohort(['program_id' => $this->program->id, 'status' => 'upcoming']);
    $back = route('admin.cohorts.index', ['trainers' => $cohort->id]);

    $this->actingAs($this->admin)->from($back)->post(route('admin.cohorts.trainers.attach', $cohort), ['trainer_email' => 'nobody@example.test'])
        ->assertRedirect($back)
        ->assertSessionHasErrors('trainer_email');

    $page = $this->actingAs($this->admin)->get($back)->assertOk()->getContent();

    // The typed address is put back under the trainer field only.
    expect(substr_count($page, 'value="nobody@example.test"'))->toBe(1)
        ->and($page)->toContain('name="trainer_email"')
        ->and($page)->toContain('name="coordinator_email"');

    $this->actingAs($this->admin)->from($back)->post(route('admin.cohorts.coordinators.attach', $cohort), ['coordinator_email' => 'nobody-else@example.test'])
        ->assertSessionHasErrors('coordinator_email');

    $page = $this->actingAs($this->admin)->get($back)->getContent();

    expect(substr_count($page, 'value="nobody-else@example.test"'))->toBe(1)
        ->and(substr_count($page, 'value="nobody@example.test"'))->toBe(0);
});

it('D-147: إزالة إسناد المدرب تسبقها نافذة تؤكّد وتسمّي الشخص والأثر، ونموذج النافذة يزيله فعلًا', function (): void {
    $cohort = makeCohort(['program_id' => $this->program->id, 'status' => 'upcoming']);
    $trainer = makeTrainer($cohort);

    $html = $this->actingAs($this->admin)
        ->get(route('admin.cohorts.index', ['trainers' => $cohort->id]))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('id="detach-trainer-'.$trainer->id.'-form"')
        ->and($html)->toContain(__('admin.cohorts.detach_trainer_body'))
        // The trigger says whom it acts on, so a list of identical buttons is not one name.
        ->and($html)->toContain('aria-label="'.__('admin.cohorts.remove_trainer').' — ');

    [$action, $fields] = browserForm($html, '//form[@id="detach-trainer-'.$trainer->id.'-form"]');

    expect($action)->toBe(route('admin.cohorts.trainers.detach', [$cohort, $trainer]));

    Auth::forgetGuards();

    $this->actingAs($this->admin)->post($action, $fields)->assertSessionHasNoErrors();

    expect(App\Models\Enrollment::query()->where('cohort_id', $cohort->id)->where('user_id', $trainer->id)->where('status', 'active')->count())->toBe(0);
});

it('D-147: الرسالة الجماعية تسبقها نافذة تأكيد تقول من سيتلقاها وأنها لا تُسحب، ونموذجها كما ترسله الصفحة يُرسَل', function (): void {
    $cohort = makeCohort(['program_id' => $this->program->id, 'status' => 'running']);
    makeParticipant($cohort);

    $html = $this->actingAs($this->admin)->get(route('admin.broadcasts.index'))->assertOk()->getContent();

    expect($html)->toContain(__('admin.broadcasts.confirm_body'))
        ->and($html)->toContain('id="broadcast-form"');

    [$action, $fields] = browserForm($html, '//form[@id="broadcast-form"]', [
        'cohort_id' => $cohort->id,
        'subject' => 'Welcome to the cohort',
        'body' => 'The first session starts on Sunday.',
    ]);

    expect($action)->toBe(route('admin.broadcasts.store'));

    Auth::forgetGuards();

    $this->actingAs($this->admin)->post($action, $fields)->assertSessionHasNoErrors();

    expect(App\Models\Broadcast::query()->where('cohort_id', $cohort->id)->count())->toBe(1);
});

it('D-147: تعطيل الحساب وإرسال رابط كلمة المرور وإنهاء الجلسات تسبقها نوافذ تسمّي الشخص، ونماذج النوافذ تعمل', function (): void {
    config(['session.driver' => 'database']);

    $sysadmin = makeUser('system_admin');
    $person = makeParticipant(makeCohort());

    $html = $this->actingAs($sysadmin)->get(route('admin.users.show', $person))->assertOk()->getContent();

    foreach (['suspend-account', 'reset-password', 'logout-everywhere'] as $dialog) {
        expect($html)->toContain('id="'.$dialog.'-form"');
    }

    expect($html)->toContain(__('admin.users.confirm.suspend_body'))
        ->and($html)->toContain(__('admin.users.confirm.logout_body'));

    [$action, $fields] = browserForm($html, '//form[@id="suspend-account-form"]');

    expect($action)->toBe(route('admin.users.status', $person))
        ->and($fields['status'])->toBe('suspended');

    Auth::forgetGuards();

    $this->actingAs($sysadmin)->post($action, $fields)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('admin.users.account_suspended'));

    expect($person->fresh()->status->value)->toBe('suspended');

    // Suspended, the page offers the way back — and activating says so.
    $page = $this->actingAs($sysadmin)->get(route('admin.users.show', $person))->getContent();
    [$action, $fields] = browserForm($page, '//form[.//input[@name="status"][@value="active"]]');

    Auth::forgetGuards();

    $this->actingAs($sysadmin)->post($action, $fields)
        ->assertSessionHas('status', __('admin.users.account_activated'));
});

it('D-147: إنشاء دفعة جديدة بموعد إغلاق بصيغة المتصفح (T) يُحفظ كما يُحفظ التعديل', function (): void {
    $html = $this->actingAs($this->admin)->get(route('admin.cohorts.index', ['edit' => 'new']))->assertOk()->getContent();

    [$action, $fields] = browserForm($html, '//form[@method="POST"][@action="'.route('admin.cohorts.store').'"]', [
        'name' => 'Cohort Created With Close',
        'program_id' => $this->program->id,
        'starts_at' => '2026-11-01',
        'ends_at' => '2026-12-01',
        'capacity' => '30',
        'registration_closes_at' => '2026-10-25T23:59',
        'status' => 'upcoming',
    ]);

    expect($fields['registration_closes_at'])->toBe('2026-10-25T23:59');

    Auth::forgetGuards();

    $this->actingAs($this->admin)->post($action, $fields)->assertSessionHasNoErrors();

    $created = App\Models\Cohort::query()->where('name', 'Cohort Created With Close')->sole();

    expect($created->registration_closes_at->utc()->format('Y-m-d H:i'))->toBe('2026-10-25 20:59');
});

it('D-147: رفض السعة الأقل من المقاعد المشغولة يذكر الرقم الحقيقي لا «مقعدًا واحدًا»', function (): void {
    // `seats_taken` is the cohort's own counter, the number the rule reads.
    $cohort = makeCohort(['program_id' => $this->program->id, 'status' => 'upcoming', 'capacity' => 10, 'seats_taken' => 3]);

    $html = $this->actingAs($this->admin)->get(route('admin.cohorts.index', ['edit' => $cohort->id]))->getContent();
    [$action, $fields] = browserForm($html, '//form[.//input[@name="_method"][@value="PATCH"]]', ['capacity' => '2']);

    Auth::forgetGuards();

    $this->actingAs($this->admin)->post($action, $fields)->assertSessionHasErrors('capacity');

    // Three seats are taken, so the floor is THREE — the old text said «at least one seat».
    expect(session('errors')->first('capacity'))->toContain('3')
        ->and(session('errors')->first('capacity'))->not->toContain('سعة الدفعة لا تقل عن مقعد واحد');
});

it('D-147: قائمة المستخدمين لا تعرض «محذوف» مرشّحًا، وتُظهر الدعوة غير المقبولة بشارتها وزرّ إعادة إرسالها', function (): void {
    $sysadmin = makeUser('system_admin');
    $invited = makeParticipant(makeCohort(), ['email_verified_at' => null, 'invited_at' => riyadhAt('2026-09-30 09:00:00')]);

    $list = $this->actingAs($sysadmin)->get(route('admin.users.index'))->assertOk()->getContent();

    // The state filter offers the states an account can be LISTED in — not «deleted».
    // (The options travel to the listbox as escaped JSON: `\u0022value\u0022:\u0022suspended\u0022`.)
    expect($list)->not->toContain('\\u0022value\\u0022:\\u0022deleted\\u0022')
        ->and($list)->toContain('\\u0022value\\u0022:\\u0022suspended\\u0022')
        ->and($list)->toContain(__('admin.users.invitation_pending'))
        ->and($list)->toContain(__('admin.users.actions.resend_invitation'))
        ->and($list)->not->toContain(__('admin.users.actions.resend_verification'));

    $show = $this->actingAs($sysadmin)->get(route('admin.users.show', $invited))->assertOk()->getContent();

    expect($show)->toContain(__('admin.users.invitation_pending'));
});

it('D-147: رسالة البريد المكرّر في نموذج الدعوة ليست رسالة المسجِّل', function (): void {
    $sysadmin = makeUser('system_admin');
    $cohort = makeCohort();
    $existing = makeParticipant($cohort);

    $this->actingAs($sysadmin)->post(route('admin.users.store'), [
        'first_name_ar' => 'محمد',
        'email' => $existing->email,
        'role' => 'participant',
        'cohort_id' => $cohort->id,
    ])->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toBe(__('admin.users.email_taken'))
        ->and(session('errors')->first('email'))->not->toContain('تسجيل الدخول');
});

it('D-147: رفض إعدادات محرّر الهبوط يسمّي الحقل بعنوانه في المحرّر لا بمساره الخام', function (): void {
    $sysadmin = makeUser('system_admin');

    $response = $this->actingAs($sysadmin)->putJson(route('admin.landing.update'), ['settings' => ['seats_override' => 20000]])
        ->assertStatus(422);

    $message = (string) collect($response->json('errors'))->flatten()->first();

    expect($message)->toContain(__('admin.landing.seats_override'))
        ->and($message)->not->toContain('settings.seats');
});
