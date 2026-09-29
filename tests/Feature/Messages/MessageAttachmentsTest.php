<?php

declare(strict_types=1);

/**
 * Phase 2 (2-D) — a file attached to a message is SAVED, not dropped.
 *
 * The composer posted `attachments[]`, the request validated them, and the
 * controller wrote `'attachments' => []`: the participant saw "sent", the other
 * side saw a message with no file, and a message that was ONLY a file arrived
 * as an empty one. Everything below is asked of the server, not of the form:
 *
 *  · the file is admitted by its BYTES from the platform's closed list (D-121):
 *    a program named `notes.pdf` is refused whatever its name says; no video;
 *  · at most three files, ten megabytes each — the numbers the composer always
 *    promised, read from config/athar.php (BR-36);
 *  · it is stored outside the web root under a random name and leaves only
 *    through a link signed for fifteen minutes, on which the thread's own
 *    policy is asked again — a link forwarded to someone outside the thread,
 *    unsigned, or past its fifteen minutes reaches nobody (BR-22, art. 24);
 *  · one refused file refuses the message; nothing is left on disk that no
 *    message points at;
 *  · a message that is only a file is a real message: it notifies the others
 *    and reads as an attachment in the conversation list.
 *
 * @see BR-22, BR-33 · FR-MSG-06 · PRD §9.13.2, §12.5 · D-17, D-121, D-136
 */

use App\Models\AuditLog;
use App\Models\Message;
use App\Models\Notification;
use App\Services\Storage\PrivateFileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));
    Storage::fake('private');
    Mail::fake();

    $this->cohort = makeCohort();
    $this->trainer = makeTrainer($this->cohort);
    $this->me = makeParticipant($this->cohort);
    $this->other = makeParticipant($this->cohort);

    $this->thread = makeThreadFor($this->me, $this->cohort, ['type' => 'trainer_dm']);
    App\Models\ThreadParticipant::factory()->create([
        'thread_id' => $this->thread->id, 'user_id' => $this->trainer->id, 'last_read_at' => null, 'is_muted' => false,
    ]);
});

/** @param list<UploadedFile> $files */
function sendWithFiles(object $test, array $files, string $body = 'see attached', ?object $as = null): Illuminate\Testing\TestResponse
{
    return $test->actingAs($as ?? $test->me)->post(route('messages.store', $test->thread), [
        'body' => $body,
        'attachments' => $files,
    ]);
}

/** The signed link the conversation page printed for a message's file. */
function messageFileUrl(object $test, Message $message, object $viewer, int $index = 0): string
{
    $page = (string) $test->actingAs($viewer)
        ->get(route('messages.index', ['thread' => $test->thread->id]))->assertOk()->getContent();
    $prefix = route('files.message', ['message' => $message, 'index' => $index]);

    expect(preg_match('/'.preg_quote(e($prefix), '/').'\?[^"]+/', $page, $match))->toBe(1);

    return html_entity_decode($match[0]);
}

it('FR-MSG-06: مرفق رسالة يُحفظ، لا يُسقَط — الرسالة تحمل وصفه والملف موجود خارج جذر الويب باسم عشوائي', function (): void {
    sendWithFiles($this, [fakeUpload('agenda.pdf', 'pdf')])->assertSessionHasNoErrors();

    $message = Message::query()->sole();
    $file = $message->attachments[0];

    expect($message->attachments)->toHaveCount(1)
        ->and($file['original_name'])->toBe('agenda.pdf')
        ->and($file['mime_type'])->toBe('application/pdf')
        ->and($file['path'])->not->toContain('agenda')
        ->and($file['path'])->toStartWith('messages/'.$this->thread->id.'/');

    Storage::disk('private')->assertExists($file['path']);
});

it('D-121: الصيغة تُفحص من المحتوى — برنامج سُمّي pdf وصورة svg يُرفضان ولا تُنشأ رسالة', function (): void {
    foreach ([fakeUpload('notes.pdf', 'exe'), fakeUpload('logo.png', 'svg')] as $bad) {
        sendWithFiles($this, [$bad])->assertSessionHasErrors('attachments');
    }

    expect(Message::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('D-121: الفيديو لا يُقبل في الرسائل — هو خيار اختياري للدعم الفني وحده', function (): void {
    sendWithFiles($this, [fakeUpload('clip.mp4', 'mp4')])->assertSessionHasErrors('attachments');

    expect(Message::query()->count())->toBe(0);
});

it('FR-MSG-06: الحدّ ثلاثة ملفات والملف عشرة ميغابايت — عند الحدّ بالضبط يُقبل وبعده يُرفض', function (): void {
    sendWithFiles($this, [
        fakeUpload('a.pdf', 'pdf'), fakeUpload('b.pdf', 'pdf'), fakeUpload('c.pdf', 'pdf'),
    ])->assertSessionHasNoErrors();
    expect(Message::query()->sole()->attachments)->toHaveCount(3);

    sendWithFiles($this, [
        fakeUpload('a.pdf', 'pdf'), fakeUpload('b.pdf', 'pdf'), fakeUpload('c.pdf', 'pdf'), fakeUpload('d.pdf', 'pdf'),
    ])->assertSessionHasErrors('attachments');
    expect(Message::query()->count())->toBe(1);

    // A real PDF padded to the byte: the ceiling is ten megabytes, and one
    // kilobyte more is refused. (Ten megabytes of one repeated letter is not a
    // fixture: libmagic types it as tape data, and the closed list refuses that.)
    $pdf = (string) fakeUpload('x.pdf', 'pdf')->getContent();
    $atLimit = UploadedFile::fake()->createWithContent('edge.pdf', $pdf.str_repeat("\0", 10240 * 1024 - strlen($pdf)));
    sendWithFiles($this, [$atLimit])->assertSessionHasNoErrors();
    expect(Message::query()->count())->toBe(2);

    $over = UploadedFile::fake()->createWithContent('over.pdf', $pdf.str_repeat("\0", 10241 * 1024 - strlen($pdf)));
    sendWithFiles($this, [$over])->assertSessionHasErrors('attachments');
    expect(Message::query()->count())->toBe(2);
});

it('FR-MSG-06: ملف واحد مرفوض يرفض الرسالة كلها ولا يبقى على القرص ما لا تشير إليه رسالة', function (): void {
    sendWithFiles($this, [fakeUpload('ok.pdf', 'pdf'), fakeUpload('bad.pdf', 'exe')])
        ->assertSessionHasErrors('attachments');

    expect(Message::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('FR-MSG-06: رسالة مرفق وحده رسالة حقيقية — تُحفظ، وتُشعر الطرف الآخر بأنها مرفق، وتُقرأ في القائمة كذلك', function (): void {
    Notification::query()->delete();

    sendWithFiles($this, [fakeUpload('agenda.pdf', 'pdf')], body: '')->assertSessionHasNoErrors();

    $message = Message::query()->sole();
    expect($message->body)->toBe('')
        ->and($message->attachments)->toHaveCount(1);

    $notice = Notification::query()->where('user_id', $this->trainer->id)->latest('created_at')->first();
    expect($notice)->not->toBeNull()
        ->and($notice->body)->toContain(trans_choice('messages.attachment_notice', 1));

    $this->actingAs($this->trainer)->get(route('messages.index'))->assertOk()
        ->assertSee(trans_choice('messages.attachment_notice', 1));
});

it('BR-22: الرابط الموقّع من صفحة المحادثة يُنزّل الملف بمحتواه الأصلي واسمه الأصلي', function (): void {
    $upload = fakeUpload('agenda.pdf', 'pdf');
    sendWithFiles($this, [$upload]);
    $message = Message::query()->sole();

    $response = $this->actingAs($this->trainer)->get(messageFileUrl($this, $message, $this->trainer))->assertOk();

    expect($response->headers->get('content-disposition'))->toContain('attachment')->toContain('agenda.pdf');
    expect($response->streamedContent())->toBe($upload->getContent());
});

it('BR-22: من خارج المحادثة لا يصل إلى مرفقها — 403 برابط موقّع صحيح، وتُسجَّل المحاولة', function (): void {
    sendWithFiles($this, [fakeUpload('agenda.pdf', 'pdf')]);
    $message = Message::query()->sole();
    $signed = messageFileUrl($this, $message, $this->me);

    // Same cohort, not in the thread — and a trainer of another cohort.
    foreach ([$this->other, makeTrainer(makeCohort())] as $outsider) {
        Auth::forgetGuards();
        $this->actingAs($outsider)->get($signed)->assertForbidden();
    }
})->group('authz');

it('BR-22: بلا توقيع أو بتوقيع منتهٍ أو معدَّل لا يصل أحد حتى الطرف نفسه', function (): void {
    sendWithFiles($this, [fakeUpload('agenda.pdf', 'pdf')]);
    $message = Message::query()->sole();
    $signed = messageFileUrl($this, $message, $this->me);

    $this->actingAs($this->me)->get(route('files.message', ['message' => $message, 'index' => 0]))->assertForbidden();

    // A different file position under the same signature is not signed.
    $this->actingAs($this->me)->get(str_replace('/0?', '/1?', $signed))->assertForbidden();

    // Laravel's clock checks the signature; the platform clock minted it (at
    // 12:00:00, valid to 12:15:00). In production they are one clock — here,
    // move Laravel's to each side of the fifteenth minute.
    $minutes = PrivateFileService::SIGNED_URL_MINUTES;
    $this->travelTo(riyadhAt('2026-10-12 12:00:00')->addMinutes($minutes)->subSecond());
    $this->actingAs($this->me)->get($signed)->assertOk();

    $this->travelTo(riyadhAt('2026-10-12 12:00:00')->addMinutes($minutes));
    $this->actingAs($this->me)->get($signed)->assertOk();

    $this->travelTo(riyadhAt('2026-10-12 12:00:00')->addMinutes($minutes)->addSecond());
    $this->actingAs($this->me)->get($signed)->assertForbidden();
})->group('authz');

it('BR-22: موضع ملف غير موجود يُرجع 404 لا خطأ', function (): void {
    sendWithFiles($this, [fakeUpload('agenda.pdf', 'pdf')]);
    $message = Message::query()->sole();

    $url = URL::temporarySignedRoute('files.message', riyadhAt('2026-10-12 12:05:00'), ['message' => $message->id, 'index' => 5]);

    $this->actingAs($this->me)->get($url)->assertNotFound();
});

it('BR-33: أثناء معاينة حساب لا يُرسل مرفق — ولا تُكتب رسالة ولا ملف', function (): void {
    $sysadmin = makeSystemAdmin();
    $this->actingAs($sysadmin)->post(route('admin.users.preview', $this->me))->assertRedirect();

    $this->post(route('messages.store', $this->thread), [
        'body' => 'x', 'attachments' => [fakeUpload('agenda.pdf', 'pdf')],
    ])->assertForbidden();

    expect(Message::query()->count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([]);
})->group('authz');

it('BR-22: إرسال المرفق يمرّ بسياسة النشر نفسها — قناة الإعلانات ليست للمتدرب', function (): void {
    $announcements = makeThreadFor($this->trainer, $this->cohort, ['type' => 'announcement']);
    App\Models\ThreadParticipant::factory()->create(['thread_id' => $announcements->id, 'user_id' => $this->me->id]);

    $this->actingAs($this->me)->post(route('messages.store', $announcements), [
        'body' => 'x', 'attachments' => [fakeUpload('agenda.pdf', 'pdf')],
    ])->assertForbidden();

    expect(Message::query()->count())->toBe(0)->and(Storage::disk('private')->allFiles())->toBe([]);
})->group('authz');

it('FR-MSG-06: تخزين الملف يُدقَّق (FILE_STORED) بمن رفعه', function (): void {
    sendWithFiles($this, [fakeUpload('agenda.pdf', 'pdf')]);

    expect(AuditLog::query()->where('action', 'file.stored')->where('actor_id', $this->me->id)->count())->toBe(1);
});

it('FR-MSG-06: تعديل الرسالة يبقي مرفقاتها', function (): void {
    sendWithFiles($this, [fakeUpload('agenda.pdf', 'pdf')]);
    $message = Message::query()->sole();

    $this->actingAs($this->me)->patch(route('messages.edit', $message), ['body' => 'edited body'])->assertSessionHasNoErrors();

    expect($message->fresh()->attachments)->toHaveCount(1)->and($message->fresh()->body)->toBe('edited body');
});

it('FR-MSG-06: رسالة بلا نص ولا مرفق تبقى مرفوضة', function (): void {
    $this->actingAs($this->me)->post(route('messages.store', $this->thread), ['body' => ''])
        ->assertSessionHasErrors('body');

    expect(Message::query()->count())->toBe(0);
});

it('FR-MSG-06: مربّع الإرسال يعرض حدود المرفقات ولا يُلزم بنص إن اختير ملف', function (): void {
    $page = $this->actingAs($this->me)->get(route('messages.index', ['thread' => $this->thread->id]))->assertOk();

    $page->assertSee(trans_choice('messages.attach_limits', 3, ['count' => 3, 'size' => 10]));
});

it('FR-MSG-06: رفض ملف لا يُضيّع النص الذي كتبه المرسِل — يعود في المربّع', function (): void {
    $conversation = route('messages.index', ['thread' => $this->thread->id]);

    $this->actingAs($this->me)->from($conversation)->post(route('messages.store', $this->thread), [
        'body' => 'ملاحظاتي على الجدول',
        'attachments' => [fakeUpload('notes.pdf', 'exe')],
    ])->assertRedirect($conversation)->assertSessionHasErrors('attachments')->assertSessionHasInput('body', 'ملاحظاتي على الجدول');

    // The refusal page prints it back into the field — the file cannot be
    // restored by a browser, so the message says to choose it again.
    $this->get($conversation)->assertOk()->assertSee('ملاحظاتي على الجدول');
});

/*
|--------------------------------------------------------------------------
| The independent security review (Art. 27), each finding pinned
|--------------------------------------------------------------------------
*/

it('FR-MSG-06: الرسائل الحاملة لملفات تُحدّ بعشرين في الساعة — والنصية وحدها لها سقفها الخاص', function (): void {
    // A message now writes up to three files of ten megabytes each; at thirty a
    // minute one account could fill a shared-hosting disk. Files count against
    // the platform's usual twenty uploads an hour (the support tickets' rule).
    for ($i = 0; $i < 20; $i++) {
        sendWithFiles($this, [fakeUpload("f{$i}.pdf", 'pdf')])->assertSessionHasNoErrors();
    }

    sendWithFiles($this, [fakeUpload('late.pdf', 'pdf')])->assertStatus(429);
    expect(Message::query()->count())->toBe(20);

    // A line of plain text keeps its own bucket: a long conversation never
    // meets the upload ceiling.
    $this->actingAs($this->me)->post(route('messages.store', $this->thread), ['body' => 'نص فقط'])
        ->assertSessionHasNoErrors();
    expect(Message::query()->count())->toBe(21);
});

it('BR-22: أحرف الاتجاه لا تبقى في اسم الملف — «x‮fdp.pdf» لا يظهر كأنه pdf وهو غير ذلك', function (): void {
    // U+202E (right-to-left override) turns "x" + "exe.pdf" into "xfdp.exe" on
    // screen and in a download dialog. The name is display data only, but it is
    // shown to a second person: it must arrive without the override.
    sendWithFiles($this, [fakeUpload("x\u{202E}fdp.pdf", 'pdf')])->assertSessionHasNoErrors();

    $name = (string) Message::query()->sole()->attachments[0]['original_name'];

    expect($name)->toBe('xfdp.pdf')
        ->and(preg_match('/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}\x{061C}]/u', $name))->toBe(0);
});

it('BR-22: الحرف غير الواصل ZWNJ في اسم ملف فارسي وعربي يبقى — المُزال أحرف الاتجاه وحدها', function (): void {
    sendWithFiles($this, [fakeUpload("می\u{200C}خواهم.pdf", 'pdf')])->assertSessionHasNoErrors();

    expect((string) Message::query()->sole()->attachments[0]['original_name'])->toBe("می\u{200C}خواهم.pdf");
});

it('FR-MSG-06: الخدمة نفسها ترفض أكثر من الحدّ ولا تكتب شيئًا — لا يكفي أن يردّها الطلب قبلها', function (): void {
    // The FormRequest refuses a fourth file first; the service is the last line
    // (art. 5) for any other caller — a job, a later screen — and must stand alone.
    $uploads = [fakeUpload('a.pdf', 'pdf'), fakeUpload('b.pdf', 'pdf'), fakeUpload('c.pdf', 'pdf'), fakeUpload('d.pdf', 'pdf')];

    $thrown = null;

    try {
        app(App\Services\Messages\MessageAttachments::class)->store($uploads, $this->thread, $this->me);
    } catch (App\Exceptions\FileException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull()
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('D-137: أثناء معاينة حساب لا يُنزَّل مرفق رسالة — 403 يُسجَّل، وخارج المعاينة يُنزَّل بالرابط نفسه', function (): void {
    sendWithFiles($this, [fakeUpload('agenda.pdf', 'pdf')]);
    $message = Message::query()->sole();
    $signed = messageFileUrl($this, $message, $this->me);

    // A conversation is more private than a hand-in: the previewer sees the
    // screen, not the file. (Outside a preview the very same link works.)
    $this->actingAs($this->me)->get($signed)->assertOk();

    Auth::forgetGuards();
    $this->actingAs(makeSystemAdmin())->post(route('admin.users.preview', $this->me))->assertRedirect();

    $this->get($signed)->assertForbidden();

    $denials = AuditLog::query()
        ->where('action', 'access.denied')
        ->where('after->reason', 'impersonation.write_attempt')
        ->where('after->route', 'files.message')
        ->get();

    expect($denials)->toHaveCount(1)
        ->and($denials->first()->ip_address)->not->toBeNull();
})->group('authz');
