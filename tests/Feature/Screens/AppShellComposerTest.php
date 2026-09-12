<?php

declare(strict_types=1);

/**
 * The application shell: the cohort in the rail's footer, the switcher, the two
 * badges and the bell.
 *
 * WHY THIS SUITE EXISTS
 * The layout documented five "optional variables" and nothing ever set one.
 * On every screen, for every role, the footer named no cohort, the switcher
 * never appeared, the badges were zero and the bell never counted (D-75). And
 * the unread-messages count it would have shown counted the reader's own
 * messages against a read marker nothing wrote.
 *
 * @see PRD §9.5.1, §9.5.2, §9.13 · SCR-9.5 · BR-22, BR-33 · D-30, D-75
 */

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Message;
use App\Models\Notification;
use App\Models\ThreadParticipant;
use App\Services\Permissions\RoleResolver;
use App\View\Composers\AppLayoutComposer;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-19 12:00:00'));

    $this->cohort = makeCohort(['name' => 'CANARY-COHORT-A']);
    $this->participant = makeParticipant($this->cohort);
});

/** What the composer hands the layout, for whoever is signed in. */
function shellValues(): array
{
    $view = view('layouts.app');
    app(AppLayoutComposer::class)->compose($view);

    return $view->getData();
}

it('SCR-9.5: تذييل الشريط يسمّي الدفعة الفعّالة', function (): void {
    $this->actingAs($this->participant)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('side__foot', false)
        ->assertSee('CANARY-COHORT-A', false);
});

it('BR-22: كل خيار يعرضه المبدّل يقبله cohort.switch ولا رفض مُسجَّل', function (): void {
    $pending = makeCohort(['name' => 'CANARY-COHORT-B']);
    Enrollment::factory()->pending()->create(['cohort_id' => $pending->id, 'user_id' => $this->participant->id]);

    $html = (string) $this->actingAs($this->participant)->get(route('dashboard'))->assertOk()->getContent();

    $action = route('cohort.switch');
    expect(preg_match('#<form[^>]*action="'.preg_quote($action, '#').'"[^>]*>(.*?)</form>#s', $html, $form))->toBe(1);
    preg_match_all('/<option value="([^"]+)"/', $form[1], $options);

    expect($options[1])->toHaveCount(2)
        ->and($form[1])->not->toContain('onchange');

    foreach ($options[1] as $id) {
        $this->actingAs($this->participant)
            ->post($action, ['cohort_id' => $id])
            ->assertRedirect()
            ->assertSessionHas(App\Http\Requests\Participant\SwitchCohortRequest::SESSION_KEY, $id);
    }

    expect(AuditLog::query()->where('action', 'like', '%denied%')->count())->toBe(0);
});

it('SCR-9.5: شارة المهام تعدّ العمل المفتوح غير المسلَّم — عند الموعد يُعدّ، وقبله بثانية لا إلا مع التأخّر', function (): void {
    $now = riyadhAt('2026-10-19 12:00:00');

    makeAssignment($this->cohort, ['due_at' => $now]);                                   // counts: due exactly now
    makeAssignment($this->cohort, ['due_at' => $now->subSecond()]);                      // passed, no late: no
    makeAssignment($this->cohort, ['due_at' => $now->subSecond(), 'allow_late' => true]); // passed, late ok: counts
    makeAssignment($this->cohort, ['due_at' => $now->addDay(), 'status' => 'draft']);     // draft: no
    makeSubmission(makeAssignment($this->cohort, ['due_at' => $now->addDay()]), $this->participant); // handed in: no

    $this->actingAs($this->participant);

    expect(shellValues()['navBadges']['assignments'])->toBe(2);
});

it('SCR-9.5: الجرس يعدّ إشعارات هذا الحساب غير المقروءة وحده', function (): void {
    $other = makeParticipant($this->cohort);
    Notification::factory()->count(2)->create(['user_id' => $this->participant->id, 'is_read' => false]);
    Notification::factory()->create(['user_id' => $this->participant->id, 'is_read' => true]);
    Notification::factory()->count(5)->create(['user_id' => $other->id, 'is_read' => false]);

    $this->actingAs($this->participant);

    expect(shellValues()['unreadNotifications'])->toBe(2);
});

it('SCR-9.5: شارة الرسائل لا تعدّ رسائل القارئ نفسه ولا ما قرأه، وفتح المحادثة يعلّمها مقروءة', function (): void {
    $trainer = makeTrainer($this->cohort);
    $thread = makeThreadFor($this->participant, $this->cohort);
    ThreadParticipant::factory()->create(['thread_id' => $thread->id, 'user_id' => $trainer->id, 'last_read_at' => null, 'is_muted' => false]);

    Message::factory()->create(['thread_id' => $thread->id, 'sender_id' => $trainer->id, 'sent_at' => riyadhAt('2026-10-19 09:00:00')]);
    Message::factory()->create(['thread_id' => $thread->id, 'sender_id' => $trainer->id, 'sent_at' => riyadhAt('2026-10-19 10:00:00')]);
    Message::factory()->create(['thread_id' => $thread->id, 'sender_id' => $this->participant->id, 'sent_at' => riyadhAt('2026-10-19 10:30:00')]);

    $this->actingAs($this->participant);
    expect(shellValues()['navBadges']['messages'])->toBe(2);

    $this->actingAs($this->participant)->get(route('messages.index', ['thread' => $thread->id]))->assertOk();

    $this->actingAs($this->participant);
    expect(shellValues()['navBadges']['messages'])->toBe(0)
        ->and(ThreadParticipant::query()->where('thread_id', $thread->id)->where('user_id', $this->participant->id)->sole()->last_read_at)
        ->not->toBeNull();
});

it('BR-33: المعاينة لا تعرض المبدّل ولا تعلّم محادثة مقروءة', function (): void {
    $second = makeCohort(['name' => 'CANARY-COHORT-B']);
    enroll($this->participant, $second);
    $thread = makeThreadFor($this->participant, $this->cohort);

    $admin = makeAdmin();
    $this->actingAs($admin)->post(route('admin.users.preview', $this->participant));

    $this->get(route('dashboard'))->assertOk()->assertDontSee(route('cohort.switch'), false);
    $this->get(route('messages.index', ['thread' => $thread->id]))->assertOk();

    expect(ThreadParticipant::query()->where('thread_id', $thread->id)->where('user_id', $this->participant->id)->sole()->last_read_at)
        ->toBeNull();
});

it('D-30: قاعدة الدور الواحدة تتّفق للمدير والمدرّب والمتدرّب', function (): void {
    $roles = new RoleResolver;

    expect($roles->shellRole(makeAdmin()))->toBe('admin')
        ->and($roles->shellRole(makeTrainer($this->cohort)))->toBe('trainer')
        ->and($roles->shellRole($this->participant))->toBe('participant');
});

it('D-30: تذييل المدرّب يسمّي دفعته في شاشاته بلا مبدّل، والمدير بلا تذييل', function (): void {
    $trainer = makeTrainer($this->cohort);

    $this->actingAs($trainer)
        ->get(route('trainer.submissions'))
        ->assertOk()
        ->assertSee('CANARY-COHORT-A', false)
        ->assertDontSee(route('cohort.switch'), false);

    $this->actingAs(makeAdmin());
    expect(shellValues()['cohortName'])->toBeNull()
        ->and(shellValues()['sidebarCohorts'])->toBe([]);
});

it('SCR-9.5: شاشة دفعات المدير تُصيَّر ومعها ترقيمها الخاص', function (): void {
    $this->actingAs(makeAdmin())->get(route('admin.cohorts.index'))->assertOk();
});

it('SCR-9.5: عدٌّ يرمي في الهيكل لا يُسقط الصفحة', function (): void {
    // The bell's count, and the dashboard card that reads the same table, both
    // meet a missing table. The card shows its error state (D-66) and the
    // shell falls back to zero (D-75): the page still answers.
    Schema::rename('notifications', 'notifications_hidden');

    $this->actingAs($this->participant)->get(route('dashboard'))->assertOk();

    Schema::rename('notifications_hidden', 'notifications');
});
