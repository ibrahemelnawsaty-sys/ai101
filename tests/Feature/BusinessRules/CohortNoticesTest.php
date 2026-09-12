<?php

declare(strict_types=1);

/**
 * The notices a cohort's everyday actions send the moment they happen.
 *
 * WHY THIS SUITE EXISTS
 * Six rows of PRD §9.16.1 had copy in two languages, a switch on the
 * preferences screen, and no writer (D-83): a hand-in reached neither the
 * trainee's bell nor the trainer's; a new resource, an announcement and a
 * moved session reached nobody; a message put nothing in the bell and sent a
 * letter to every member for every line, with the thread's empty title in it.
 *
 * @see PRD §9.13, §9.16.1 · FR-NOTIF-12, FR-NOTIF-15, FR-NOTIF-16, FR-NOTIF-20, FR-NOTIF-21, FR-NOTIF-22, FR-MSG-12 · D-83
 */

use App\Mail\AtharLetter;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Profile;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));
    Mail::fake();

    $this->cohort = makeCohort();
    $this->trainer = makeTrainer($this->cohort);
    $this->participant = makeParticipant($this->cohort);
    Profile::factory()->create(['user_id' => $this->participant->id, 'first_name_ar' => 'CANARYTRAINEE']);
    Profile::factory()->create(['user_id' => $this->trainer->id, 'first_name_ar' => 'CANARYCOACH']);
});

function bell(User $user, string $type): int
{
    return Notification::query()->where('user_id', $user->id)->where('type', $type)->count();
}

function letters(string $copyKey, ?User $to = null): int
{
    return Mail::queued(AtharLetter::class, static fn (AtharLetter $l): bool => $l->copyKey === $copyKey
        && ($to === null || $l->hasTo($to->email)))->count();
}

/** The member's most recent request was $secondsAgo before the frozen clock. */
function seenAgo(User $user, int $secondsAgo): void
{
    DB::table((string) config('session.table'))->insert([
        'id' => 'sess-'.$user->id,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'test',
        'payload' => '',
        'last_activity' => App\Services\Time\Clock::now()->getTimestamp() - $secondsAgo,
    ]);
}

/*
|--------------------------------------------------------------------------
| Hand-ins
|--------------------------------------------------------------------------
*/

it('FR-NOTIF-15: التسليم يؤكَّد للمتدرّب على منصته، وFR-NOTIF-16: يُبلَّغ به مدرّب دفعته وحده', function (): void {
    $assignment = makeAssignment($this->cohort, ['title' => 'CANARY-TASK', 'due_at' => riyadhAt('2026-10-20 23:59:00')]);
    $otherTrainer = makeTrainer(makeCohort());

    $this->actingAs($this->participant)
        ->post(route('assignments.submit', $assignment), ['github_url' => 'https://github.com/athar-trainee/ai101-task'])
        ->assertRedirect(route('assignments.show', $assignment));

    $received = Notification::query()->where('user_id', $this->participant->id)->where('type', 'submission_received')->sole();
    $arrived = Notification::query()->where('user_id', $this->trainer->id)->where('type', 'submission_new')->sole();

    expect($received->title)->toContain('CANARY-TASK')
        ->and($arrived->title)->toContain('CANARYTRAINEE')
        ->and($arrived->link)->toContain(route('trainer.submissions'))
        ->and($arrived->link)->toContain((string) $assignment->id)
        ->and(bell($otherTrainer, 'submission_new'))->toBe(0);

    // The platform only, both of them (PRD §9.16.1).
    Mail::assertNothingQueued();

    // The trainer's link opens their list, filtered to this assignment.
    $this->actingAs($this->trainer)->get((string) $arrived->link)->assertOk();
});

it('D-83: إشعار لا يُكتب لا يُسقط التسليم — يُحفظ ويُسجَّل الخلل', function (): void {
    Log::spy();
    $assignment = makeAssignment($this->cohort, ['due_at' => riyadhAt('2026-10-20 23:59:00')]);

    Schema::rename('notifications', 'notifications_away');

    $this->actingAs($this->participant)
        ->post(route('assignments.submit', $assignment), ['github_url' => 'https://github.com/athar-trainee/ai101-task'])
        ->assertRedirect(route('assignments.show', $assignment));

    Schema::rename('notifications_away', 'notifications');

    expect(App\Models\Submission::query()->where('user_id', $this->participant->id)->count())->toBe(1);
    Log::shouldHaveReceived('error')->withArgs(static fn (string $message): bool => $message === 'notices.cohort_failed');
});

it('FR-NOTIF-15: تسليم المشروع الختامي يرسل الإشعارين نفسيهما', function (): void {
    makeFinalProject($this->cohort, ['title' => 'CANARY-PROJECT', 'is_unlocked' => true, 'unlocked_at' => riyadhAt('2026-10-10 09:00:00')]);

    $this->actingAs($this->participant)
        ->post(route('finalProject.submit'), [
            'description' => 'A capstone that classifies customer feedback by sentiment.',
            'github_url' => 'https://github.com/athar-trainee/ai101-final',
        ])
        ->assertRedirect(route('finalProject'));

    expect(bell($this->participant, 'submission_received'))->toBe(1)
        ->and(bell($this->trainer, 'submission_new'))->toBe(1);
});

/*
|--------------------------------------------------------------------------
| A new resource
|--------------------------------------------------------------------------
*/

it('FR-NOTIF-20: مورد جديد يصل الدفعة على المنصة، ومن أطفأه لا يصله', function (): void {
    $silenced = makeParticipant($this->cohort);
    NotificationPreference::factory()->create(['user_id' => $silenced->id, 'type' => 'resource_added', 'in_app_enabled' => false]);

    $this->actingAs($this->trainer)
        ->post(route('trainer.resources.store', ['cohort' => $this->cohort->id]), [
            'title' => 'CANARY-RESOURCE',
            'resource_type' => 'link',
            'url' => 'https://example.com/prompting-guide',
        ])
        ->assertSessionHasNoErrors();

    $notice = Notification::query()->where('user_id', $this->participant->id)->where('type', 'resource_added')->sole();

    expect($notice->title)->toContain('CANARY-RESOURCE')
        ->and(bell($silenced, 'resource_added'))->toBe(0)
        ->and(bell($this->trainer, 'resource_added'))->toBe(0);
    Mail::assertNothingQueued();
});

/*
|--------------------------------------------------------------------------
| Announcements and messages
|--------------------------------------------------------------------------
*/

it('FR-NOTIF-21: إعلان في قناة الإعلانات يصل المتدرّبين على المنصة وبالبريد، وليس «رسالة جديدة»', function (): void {
    $this->artisan('athar:provision-messages');
    $channel = Thread::query()->where('cohort_id', $this->cohort->id)->where('type', 'announcement')->sole();

    $this->actingAs($this->trainer)
        ->post(route('messages.store', $channel), ['body' => 'CANARY-ANNOUNCEMENT: the Monday session moves online.'])
        ->assertRedirect();

    $notice = Notification::query()->where('user_id', $this->participant->id)->where('type', 'announcement_published')->sole();

    expect($notice->body)->toContain('CANARY-ANNOUNCEMENT')
        ->and($notice->link)->toContain((string) $channel->id)
        ->and(bell($this->participant, 'message_received'))->toBe(0)
        ->and(letters('emails.announcement_published', $this->participant))->toBe(1)
        ->and(letters('emails.message_received'))->toBe(0)
        ->and(bell($this->trainer, 'announcement_published'))->toBe(0);
});

it('FR-NOTIF-22: رسالة مباشرة تصل الطرف الآخر على المنصة، وبريد واحد للمحادثة كل خمس عشرة دقيقة إن كان غير متصل', function (): void {
    $this->artisan('athar:provision-messages');
    $dm = Thread::query()->where('cohort_id', $this->cohort->id)->where('type', 'trainer_dm')->sole();
    $post = fn (string $body) => $this->actingAs($this->trainer)->post(route('messages.store', $dm), ['body' => $body]);

    $post('First note about your prompt.')->assertRedirect();
    $sentAt = riyadhAt('2026-10-12 12:00:00');

    freezeAt($sentAt->addMinutes(15)->subSecond());
    $post('Second note, inside the window.');

    expect(bell($this->participant, 'message_received'))->toBe(2)
        ->and(letters('emails.message_received', $this->participant))->toBe(1)
        ->and(bell($this->trainer, 'message_received'))->toBe(0);

    freezeAt($sentAt->addMinutes(15));
    $post('Third note, the window has passed.');

    expect(letters('emails.message_received', $this->participant))->toBe(2);

    Mail::assertQueued(AtharLetter::class, fn (AtharLetter $l): bool => $l->copyKey === 'emails.message_received'
        && $l->values['thread'] !== ''
        && str_contains((string) $l->values['name'], 'CANARYCOACH')
        && str_contains((string) $l->ctaUrl, (string) $dm->id));
});

it('FR-NOTIF-22: من طلب صفحة في آخر خمس دقائق متصل — لا بريد، وقبلها بثانية غير متصل — بريد', function (): void {
    $this->artisan('athar:provision-messages');
    $dm = Thread::query()->where('cohort_id', $this->cohort->id)->where('type', 'trainer_dm')->sole();

    seenAgo($this->participant, 300);
    $this->actingAs($this->trainer)->post(route('messages.store', $dm), ['body' => 'Are you there?']);

    expect(bell($this->participant, 'message_received'))->toBe(1)
        ->and(letters('emails.message_received'))->toBe(0);

    DB::table((string) config('session.table'))->delete();
    seenAgo($this->participant, 301);
    $this->actingAs($this->trainer)->post(route('messages.store', $dm), ['body' => 'Writing by e-mail then.']);

    expect(letters('emails.message_received', $this->participant))->toBe(1);
});

it('FR-MSG-12: المحادثة المكتومة لا تضع شيئًا في الجرس ولا ترسل بريدًا', function (): void {
    $this->artisan('athar:provision-messages');
    $dm = Thread::query()->where('cohort_id', $this->cohort->id)->where('type', 'trainer_dm')->sole();
    ThreadParticipant::query()->where('thread_id', $dm->id)->where('user_id', $this->participant->id)->update(['is_muted' => true]);

    $this->actingAs($this->trainer)->post(route('messages.store', $dm), ['body' => 'Muted on the other side.']);

    expect(bell($this->participant, 'message_received'))->toBe(0)
        ->and(letters('emails.message_received'))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| A moved session
|--------------------------------------------------------------------------
*/

it('FR-NOTIF-12: نقل موعد جلسة يُبلغ الدفعة بموعدها الجديد على القناتين، وتغيير الموضوع وحده لا يُبلغ أحدًا', function (): void {
    $session = sessionInCohort($this->cohort, riyadhAt('2026-10-14 17:00:00'), riyadhAt('2026-10-14 19:00:00'), ['title' => 'CANARY-SESSION']);
    $payload = static fn (array $overrides): array => $overrides + [
        'topic' => 'CANARY-SESSION',
        'type' => 'training',
        'date' => '2026-10-14',
        'start_time' => '17:00',
        'end_time' => '19:00',
    ];

    $this->actingAs($this->trainer)
        ->patch(route('trainer.sessions.update', $session), $payload(['topic' => 'CANARY-SESSION']))
        ->assertSessionHasNoErrors();

    expect(bell($this->participant, 'session_changed'))->toBe(0)
        ->and(letters('emails.session_changed'))->toBe(0);

    $this->actingAs($this->trainer)
        ->patch(route('trainer.sessions.update', $session), $payload(['date' => '2026-10-15']))
        ->assertSessionHasNoErrors();

    $notice = Notification::query()->where('user_id', $this->participant->id)->where('type', 'session_changed')->sole();
    $newTime = App\Support\Dates::dateTime(riyadhAt('2026-10-15 17:00:00'));

    expect($notice->body)->toContain($newTime)
        ->and(letters('emails.session_changed', $this->participant))->toBe(1);
    Mail::assertQueued(AtharLetter::class, fn (AtharLetter $l): bool => $l->copyKey === 'emails.session_changed'
        && $l->values['datetime'] === $newTime);
});

it('FR-NOTIF-12: تصحيح موعد جلسة مضت لا يُبلغ أحدًا بموعد لا يُحضَر', function (): void {
    $past = sessionInCohort($this->cohort, riyadhAt('2026-10-10 17:00:00'), riyadhAt('2026-10-10 19:00:00'), ['title' => 'CANARY-PAST']);

    $this->actingAs($this->trainer)
        ->patch(route('trainer.sessions.update', $past), [
            'topic' => 'CANARY-PAST',
            'type' => 'training',
            'date' => '2026-10-10',
            'start_time' => '17:30',
            'end_time' => '19:00',
        ])
        ->assertSessionHasNoErrors();

    expect(bell($this->participant, 'session_changed'))->toBe(0)
        ->and(letters('emails.session_changed'))->toBe(0);
});

it('FR-NOTIF-22: رسالة وُضعت في الطابور قبل النشر — بلا محادثة — تُرسَل كما كانت', function (): void {
    $event = new App\Events\MessageReceived($this->participant, 'CANARYCOACH', 'Trainer conversation', 'Queued before the deploy.', 'placeholder');
    $legacy = (new ReflectionClass(App\Events\MessageReceived::class))->newInstanceWithoutConstructor();

    foreach (['recipient', 'senderName', 'threadTitle', 'excerpt'] as $property) {
        (new ReflectionProperty(App\Events\MessageReceived::class, $property))->setValue($legacy, $event->{$property});
    }

    app(App\Listeners\SendMessageReceived::class)->handle($legacy);

    Mail::assertQueued(AtharLetter::class, fn (AtharLetter $l): bool => $l->copyKey === 'emails.message_received'
        && $l->hasTo($this->participant->email)
        && $l->ctaUrl === route('messages.index'));
});
