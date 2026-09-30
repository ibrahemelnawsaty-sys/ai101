<?php

declare(strict_types=1);

/**
 * Phase 5 — who is a «registration request», and what the export says.
 *
 * Found by running the screen (D-147): the list had no role condition, so a detached
 * coordinator appeared among the applicants (filter «withdrawn» or «active»); and the
 * export ignored every filter the screen had — it always sent the pending queue, whatever
 * the person was looking at.
 *
 * @see BR-27 · PRD §9.2.3, §9.18 · D-147
 */

use App\Models\Enrollment;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-01 09:00:00'));

    $this->admin = makeAdmin();
    $this->cohort = makeCohort(['status' => 'open', 'requires_approval' => true]);
    $this->other = makeCohort(['status' => 'open', 'requires_approval' => true]);

    $this->applicant = makeParticipant($this->cohort);
    Enrollment::query()->where('user_id', $this->applicant->id)->update(['status' => 'pending']);

    $this->elsewhere = makeParticipant($this->other);
    Enrollment::query()->where('user_id', $this->elsewhere->id)->update(['status' => 'pending']);

    $this->coordinator = makeCoordinator($this->cohort);
    Enrollment::query()->where('user_id', $this->coordinator->id)->update(['status' => 'withdrawn']);
});

it('D-147: قائمة الطلبات للمتدربين وحدهم — المنسّق المنسحب ليس متقدّمًا', function (): void {
    $withdrawn = $this->actingAs($this->admin)->get(route('admin.registrations.index', ['state' => 'withdrawn']))->assertOk()->getContent();

    expect($withdrawn)->not->toContain($this->coordinator->email);

    $pending = $this->actingAs($this->admin)->get(route('admin.registrations.index'))->assertOk()->getContent();

    expect($pending)->toContain($this->applicant->email)
        ->and($pending)->toContain($this->elsewhere->email);
});

it('D-147: التصدير يحمل مرشّحات الشاشة نفسها — الدفعة والحالة والبحث', function (): void {
    $all = $this->actingAs($this->admin)->get(route('admin.registrations.export'))->assertOk()->getContent();

    expect($all)->toContain($this->applicant->email)->toContain($this->elsewhere->email);

    $one = $this->actingAs($this->admin)
        ->get(route('admin.registrations.export', ['cohort' => $this->cohort->id]))
        ->assertOk()->getContent();

    expect($one)->toContain($this->applicant->email)->not->toContain($this->elsewhere->email);

    $withdrawn = $this->actingAs($this->admin)
        ->get(route('admin.registrations.export', ['state' => 'withdrawn']))
        ->assertOk()->getContent();

    expect($withdrawn)->not->toContain($this->applicant->email)->not->toContain($this->coordinator->email);

    $found = $this->actingAs($this->admin)
        ->get(route('admin.registrations.export', ['q' => $this->elsewhere->email]))
        ->assertOk()->getContent();

    expect($found)->toContain($this->elsewhere->email)->not->toContain($this->applicant->email);
});

it('D-147: قائمة فارغة تحت مرشّح لا تقول «لا طلبات بانتظار المراجعة»', function (): void {
    $page = $this->actingAs($this->admin)
        ->get(route('admin.registrations.index', ['state' => 'completed']))
        ->assertOk()
        ->getContent();

    expect($page)->not->toContain(__('admin.registrations.empty_title'))
        ->and($page)->toContain(__('app.no_search_results_title'));

    $default = $this->actingAs($this->admin)
        ->get(route('admin.registrations.index', ['cohort' => 'nonexistent']))
        ->getContent();

    expect($default)->toContain(__('app.no_search_results_title'));
});

it('D-147: مرشّحات فارغة في العنوان (?q=&cohort=&state=) لا تُعدّ تصفية — تبقى قائمة الانتظار الافتراضية بحالتها الفارغة', function (): void {
    Enrollment::query()->update(['status' => 'active']);

    $page = $this->actingAs($this->admin)
        ->get(route('admin.registrations.index', ['q' => '', 'cohort' => '', 'state' => '']))
        ->assertOk()
        ->getContent();

    expect($page)->toContain(__('admin.registrations.empty_title'))
        ->and($page)->not->toContain(__('app.no_search_results_title'));
});

it('D-147: لوحة المشرف تعدّ طلبات التسجيل بتعريف شاشتها نفسه — للمتدربين وحدهم', function (): void {
    // A trainer's or coordinator's assignment row can be in any state; it is never an applicant.
    Enrollment::query()->where('user_id', $this->coordinator->id)->update(['status' => 'pending']);

    $dash = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->getContent();

    expect($dash)->not->toContain($this->coordinator->email);
});
