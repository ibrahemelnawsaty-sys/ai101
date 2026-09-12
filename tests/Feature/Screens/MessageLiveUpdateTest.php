<?php

declare(strict_types=1);

/**
 * An open conversation updates without a reload (PRD §9.13.2).
 *
 * WHY THIS SUITE EXISTS
 * The page called `atharThread(...)` and nothing defined it, and the poll
 * endpoint returned raw rows that no client read (D-67). The endpoint now
 * returns the server's own rendering of the message list, so these cases hold
 * the three things the client relies on: the markup is the page's markup, user
 * content in it is escaped, and `latest` moves only when a message arrives.
 *
 * @see PRD §9.13, §9.13.2 · BR-22 · D-67
 */

use App\Models\Message;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-11 10:00:00'));

    $this->cohort = makeCohort();
    $this->participant = makeParticipant($this->cohort);
    $this->trainer = makeTrainer($this->cohort);
    $this->thread = makeThreadFor($this->participant, $this->cohort);
});

it('D-67: الاستطلاع يعيد قائمة الرسائل مُصيَّرة من الخادم ومُهرَّبة', function (): void {
    $message = Message::factory()->create([
        'thread_id' => $this->thread->id,
        'sender_id' => $this->trainer->id,
        'body' => 'CANARY <script>alert(1)</script>',
        'sent_at' => riyadhAt('2026-10-11 09:00:00'),
    ]);

    $response = $this->actingAs($this->participant)
        ->getJson(route('messages.poll', $this->thread));

    $response->assertOk()->assertJsonPath('latest', (string) $message->id);

    $html = (string) $response->json('html');

    expect($html)->toContain('class="msg msg--in"')
        ->and($html)->toContain('CANARY &lt;script&gt;')
        ->and($html)->not->toContain('<script>alert(1)</script>');
});

it('D-67: قيمة latest تتغيّر حين تصل رسالة، ولا تتغيّر بدونها', function (): void {
    Message::factory()->create([
        'thread_id' => $this->thread->id,
        'sender_id' => $this->trainer->id,
        'body' => 'first',
        'sent_at' => riyadhAt('2026-10-11 09:00:00'),
    ]);

    $first = $this->actingAs($this->participant)->getJson(route('messages.poll', $this->thread))->json('latest');
    $again = $this->actingAs($this->participant)->getJson(route('messages.poll', $this->thread))->json('latest');

    $newer = Message::factory()->create([
        'thread_id' => $this->thread->id,
        'sender_id' => $this->trainer->id,
        'body' => 'second',
        'sent_at' => riyadhAt('2026-10-11 09:30:00'),
    ]);

    $after = $this->actingAs($this->participant)->getJson(route('messages.poll', $this->thread))->json('latest');

    expect($again)->toBe($first)
        ->and($after)->toBe((string) $newer->id)
        ->and($after)->not->toBe($first);
});

it('D-67: الصفحة تحمل آخر معرّف ورابط الاستطلاع وفترته', function (): void {
    $message = Message::factory()->create([
        'thread_id' => $this->thread->id,
        'sender_id' => $this->trainer->id,
        'body' => 'hello',
        'sent_at' => riyadhAt('2026-10-11 09:00:00'),
    ]);

    $this->actingAs($this->participant)
        ->get(route('messages.index', ['thread' => $this->thread->id]))
        ->assertOk()
        ->assertSee('data-latest="'.$message->id.'"', false)
        ->assertSee(route('messages.poll', $this->thread->id), false)
        ->assertSee('pollSeconds: 15', false);
});

it('BR-22: متدرّب لا يستطلع محادثة ليس طرفًا فيها', function (): void {
    $peer = makeParticipant($this->cohort);

    $this->actingAs($peer)
        ->getJson(route('messages.poll', $this->thread))
        ->assertForbidden();
});
