<?php

declare(strict_types=1);

/**
 * Real accounts get the conversations PRD §9.13 promises.
 *
 * WHY THIS SUITE EXISTS
 * Only the demo seeder ever created a thread. Every invited trainee opened an
 * empty messages page, trainers had no messages entry at all, and no one could
 * post an announcement (D-82).
 *
 * @see PRD §9.13 · BR-22, BR-23 · D-82
 */

use App\Enums\ThreadType;
use App\Enums\UserRole;
use App\Models\Message;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use App\Services\Credentials\AccountInviter;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));
    Mail::fake();

    $this->cohort = makeCohort();
    $this->trainer = makeTrainer($this->cohort);
});

function threadsOf(User $user): array
{
    return Thread::query()
        ->whereIn('id', ThreadParticipant::query()->where('user_id', $user->getKey())->select('thread_id'))
        ->pluck('type')
        ->map(static fn (mixed $t): string => $t instanceof ThreadType ? $t->value : (string) $t)
        ->sort()
        ->values()
        ->all();
}

it('D-82: متدرّب مدعوّ يُمنح قناة الإعلانات ومجموعة الدفعة ومحادثة مع مدرّبه', function (): void {
    $user = app(AccountInviter::class)->invite(
        email: 'new@example.com',
        role: UserRole::Participant,
        profileColumns: [
            'first_name_ar' => 'CANARY', 'second_name_ar' => 'A', 'third_name_ar' => 'B', 'last_name_ar' => 'C',
            'first_name_en' => 'Canary', 'second_name_en' => 'A', 'third_name_en' => 'B', 'last_name_en' => 'C',
            'phone' => '0512345670', 'gender' => 'male',
        ],
        cohort: $this->cohort,
    );

    expect(threadsOf($user))->toBe(['announcement', 'group', 'trainer_dm'])
        ->and(threadsOf($this->trainer))->toContain('trainer_dm');
});

it('D-82: إسناد مدرّب يمنحه القناتين ومحادثة مع كل متدرّب — ومرّة ثانية لا تكرّر شيئًا', function (): void {
    $a = makeParticipant($this->cohort);
    $b = makeParticipant($this->cohort);
    $coach = makeTrainer(null, ['email' => 'coach@example.test']);
    $admin = makeAdmin();

    foreach ([1, 2] as $_) {
        $this->actingAs($admin)->post(route('admin.cohorts.trainers.attach', $this->cohort), ['email' => 'coach@example.test']);
    }

    $types = threadsOf($coach);

    expect(array_count_values($types))->toBe(['announcement' => 1, 'group' => 1, 'trainer_dm' => 2])
        ->and(Thread::query()->where('cohort_id', $this->cohort->id)->where('type', 'announcement')->count())->toBe(1);

    foreach ([$a, $b] as $participant) {
        expect(threadsOf($participant))->toContain('trainer_dm');
    }
});

it('D-82: أمر التعبئة يعطي من سبقوا محادثاتهم، ولا يكرّر شيئًا حين يُعاد', function (): void {
    $participants = [makeParticipant($this->cohort), makeParticipant($this->cohort)];

    $this->artisan('athar:provision-messages')->assertSuccessful();
    $first = Thread::query()->count();
    $this->artisan('athar:provision-messages')->assertSuccessful();

    expect(Thread::query()->count())->toBe($first)
        ->and($first)->toBe(2 + count($participants)); // two channels + one DM each

    foreach ($participants as $participant) {
        expect(threadsOf($participant))->toBe(['announcement', 'group', 'trainer_dm']);
    }
});

it('D-82: المدرّب ينشر إعلانًا يقرؤه المتدرّب، ولا ينشر المتدرّب فيه', function (): void {
    $participant = makeParticipant($this->cohort);
    $this->artisan('athar:provision-messages');
    $channel = Thread::query()->where('cohort_id', $this->cohort->id)->where('type', 'announcement')->sole();

    $this->actingAs($this->trainer)
        ->post(route('messages.store', $channel), ['body' => 'CANARY-ANNOUNCEMENT'])
        ->assertRedirect();

    expect(Message::query()->where('thread_id', $channel->id)->where('body', 'CANARY-ANNOUNCEMENT')->exists())->toBeTrue();

    $this->actingAs($participant)
        ->get(route('messages.index', ['thread' => $channel->id]))
        ->assertOk()
        ->assertSee('CANARY-ANNOUNCEMENT', false);

    $this->actingAs($participant)
        ->post(route('messages.store', $channel), ['body' => 'not allowed here'])
        ->assertForbidden();
});

it('D-82: المحادثة المباشرة تُسمّى باسم الطرف الآخر، وللمدرّب مدخل المحادثات', function (): void {
    $participant = makeParticipant($this->cohort);
    App\Models\Profile::factory()->create(['user_id' => $participant->id, 'first_name_ar' => 'CANARYTRAINEE']);
    App\Models\Profile::factory()->create(['user_id' => $this->trainer->id, 'first_name_ar' => 'CANARYCOACH']);
    $this->artisan('athar:provision-messages');

    $this->actingAs($this->trainer)
        ->get(route('messages.index'))
        ->assertOk()
        ->assertSee('CANARYTRAINEE', false);

    $this->actingAs($participant)
        ->get(route('messages.index'))
        ->assertOk()
        ->assertSee('CANARYCOACH', false);

    $this->actingAs($this->trainer)
        ->get(route('trainer.submissions'))
        ->assertSee(route('messages.index'), false);
});
