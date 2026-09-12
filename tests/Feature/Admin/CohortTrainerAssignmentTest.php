<?php

declare(strict_types=1);

/**
 * Giving a trainer a cohort — which, under BR-23, IS giving them permission.
 *
 * WHY THIS SUITE EXISTS
 * The cohorts screen asks for the trainer's e-mail and the request validated a
 * `user_id` the form never sent. Every attempt failed on a field that does not
 * exist, the error was keyed where nothing rendered it, and the page reloaded
 * in silence (D-69). The routes had authorisation tests and both halves looked
 * right on their own — the first case compares them, which nothing did.
 *
 * And removing a trainer withdrew the row while Cohort::trainers() ignored the
 * status, so the removed trainer stayed listed everywhere.
 *
 * @see BR-23 · CONSTITUTION Art. 5 · D-69
 */

use App\Enums\EnrollmentStatus;
use App\Http\Requests\Admin\AssignTrainerRequest;
use App\Models\Enrollment;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    $this->cohort = makeCohort();
    $this->admin = makeAdmin();
    $this->panel = route('admin.cohorts.index', ['trainers' => $this->cohort->id]);
});

it('BR-23: نموذج الإسناد في شاشة الدفعات يرسل كل حقل يطلبه AssignTrainerRequest', function (): void {
    $html = (string) $this->actingAs($this->admin)->get($this->panel)->assertOk()->getContent();
    $action = route('admin.cohorts.trainers.attach', $this->cohort);

    expect(preg_match('#<form[^>]*action="'.preg_quote($action, '#').'"[^>]*>(.*?)</form>#s', $html, $form))->toBe(1);
    preg_match_all('/name="([a-z_]+)"/', $form[1], $names);

    $required = array_keys((new AssignTrainerRequest)->rules());

    expect(array_values(array_diff($required, $names[1])))->toBe([]);
});

it('BR-23: إسناد مدرّب ببريده ينشئ التحاق مدرّب نشطًا', function (): void {
    $trainer = makeTrainer(null, ['email' => 'coach@example.test']);

    $this->actingAs($this->admin)->from($this->panel)
        ->post(route('admin.cohorts.trainers.attach', $this->cohort), ['email' => '  Coach@Example.test '])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('admin.cohorts.trainer_attached'));

    $row = Enrollment::query()->where('cohort_id', $this->cohort->id)->where('user_id', $trainer->id)->sole();

    expect($row->role_in_cohort->value)->toBe('trainer')
        ->and($row->status)->toBe(EnrollmentStatus::Active);
});

it('BR-23: بريد متدرّب يُرفض على حقل البريد، والرفض يظهر على الشاشة', function (): void {
    $participant = makeParticipant();

    $this->actingAs($this->admin)->from($this->panel)
        ->post(route('admin.cohorts.trainers.attach', $this->cohort), ['email' => $participant->email])
        ->assertSessionHasErrors(['email' => __('admin.cohorts.trainer_not_found')]);

    expect(Enrollment::query()->where('cohort_id', $this->cohort->id)->where('user_id', $participant->id)->exists())
        ->toBeFalse();

    $this->actingAs($this->admin)->from($this->panel)->followingRedirects()
        ->post(route('admin.cohorts.trainers.attach', $this->cohort), ['email' => $participant->email])
        ->assertOk()
        ->assertSee(e((string) __('admin.cohorts.trainer_not_found')), false);
});

it('BR-23: بريد مُرسَل مصفوفةً يُرفض بخطأ تحقّق لا بـ500', function (): void {
    $this->actingAs($this->admin)->from($this->panel)
        ->post(route('admin.cohorts.trainers.attach', $this->cohort), ['email' => ['x']])
        ->assertSessionHasErrors('email');
});

it('BR-23: المدرّب المُزال لا يبقى مدرجًا في الدفعة', function (): void {
    $trainer = makeTrainer($this->cohort);

    expect($this->cohort->trainers()->pluck('users.id')->all())->toContain($trainer->id);

    $this->actingAs($this->admin)->from($this->panel)
        ->delete(route('admin.cohorts.trainers.detach', [$this->cohort, $trainer]))
        ->assertSessionHas('status', __('admin.cohorts.trainer_detached'));

    expect($this->cohort->trainers()->pluck('users.id')->all())->not->toContain($trainer->id);

    $this->actingAs($this->admin)->get($this->panel)
        ->assertOk()
        ->assertDontSee(route('admin.cohorts.trainers.detach', [$this->cohort->id, $trainer->id]), false);
});
