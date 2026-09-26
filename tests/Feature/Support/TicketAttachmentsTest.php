<?php

declare(strict_types=1);

/**
 * The files on a support ticket (D-124): pictures and videos, at most three
 * on one line, each at most ten megabytes — the owner's numbers.
 *
 * What is asked of the server here, not of the form:
 *
 *  · the six formats are admitted by their BYTES — a PDF, an SVG, or a
 *    program named `photo.png` is refused whatever its name says;
 *  · they are stored outside the web root under a random name, and shown
 *    inside the page through a signed link that lasts fifteen minutes;
 *  · a video is served so the browser can ask for ranges — it plays and seeks;
 *  · a file on an internal line reaches the support team alone, and a link
 *    without its signature, or past its fifteen minutes, reaches nobody;
 *  · video is an opt-in type: the rest of the platform refuses it as before.
 *
 * @see D-124 · D-17 · PRD §12.5 · CONSTITUTION art. 22, art. 24
 */

use App\Exceptions\FileException;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Services\Storage\PrivateFileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-05 10:00:00'));
    Storage::fake('private');
    Mail::fake();

    $this->cohort = makeCohort(['status' => 'running']);
    $this->coordinator = makeCoordinator($this->cohort);
    $this->participant = makeParticipant($this->cohort);
});

/** @param list<UploadedFile> $files */
function tkPostWithFiles(object $test, array $files): Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->participant)->post(route('support.store'), [
        'subject' => 'لقطة للمشكلة',
        'category' => 'platform',
        'body' => 'أرفقت ما يوضح الخطأ.',
        'attachments' => $files,
    ]);
}

/** The signed link the ticket page printed for a file, as the page printed it. */
function tkFileUrl(object $test, SupportTicket $ticket, SupportTicketAttachment $attachment, mixed $viewer): string
{
    $page = (string) $test->actingAs($viewer)->get(route('support.show', $ticket))->assertOk()->getContent();
    $prefix = route('files.supportAttachment', $attachment);

    expect(preg_match('/'.preg_quote(e($prefix), '/').'\?[^"]+/', $page, $match))->toBe(1);

    return html_entity_decode($match[0]);
}

it('D-124: الصور والفيديو بصيغها الست تُقبل من محتواها، وتُحفظ خارج جذر الويب باسم عشوائي ويبقى اسمها الأصلي', function (): void {
    tkPostWithFiles($this, [fakeUpload('screen.png', 'png'), fakeUpload('clip.mp4', 'mp4'), fakeUpload('call.webm', 'webm')])
        ->assertSessionHasNoErrors();

    $ticket = SupportTicket::query()->sole();
    $files = SupportTicketAttachment::query()->orderBy('original_name')->get();

    expect($files->pluck('original_name')->all())->toBe(['call.webm', 'clip.mp4', 'screen.png'])
        ->and($files->pluck('kind')->all())->toBe(['video', 'video', 'image'])
        ->and($files->pluck('mime_type')->all())->toBe(['video/webm', 'video/mp4', 'image/png']);

    foreach ($files as $file) {
        expect($file->disk)->toBe('private')
            ->and($file->path)->toMatch('#^support/'.$ticket->id.'/[0-9a-f-]{36}\.(png|mp4|webm)$#')
            ->and($file->path)->not->toContain('screen')
            ->and(Storage::disk('private')->exists($file->path))->toBeTrue();
    }

    // The other three formats, in a reply.
    $this->actingAs($this->participant)
        ->post(route('support.reply', $ticket), [
            'body' => 'وهذه ثلاث أخرى.',
            'attachments' => [fakeUpload('a.jpg', 'jpg'), fakeUpload('b.webp', 'webp'), fakeUpload('c.mov', 'mov')],
        ])
        ->assertSessionHasNoErrors();

    expect(SupportTicketAttachment::query()->pluck('mime_type')->sort()->values()->all())
        ->toBe(['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/quicktime', 'video/webm']);
});

it('D-124: ما ليس صورة ولا فيديو يُرفض من محتواه لا من اسمه — PDF وSVG وبرنامج باسم صورة', function (UploadedFile $file): void {
    tkPostWithFiles($this, [$file])
        ->assertSessionHasErrors(['attachments.0' => __('support.errors.file_type')]);

    expect(SupportTicket::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
})->with([
    'PDF' => fn (): UploadedFile => fakeUpload('guide.pdf', 'pdf'),
    'SVG بشيفرة' => fn (): UploadedFile => fakeUpload('logo.svg', 'svg'),
    'برنامج باسم صورة' => fn (): UploadedFile => fakeUpload('photo.png', 'exe'),
    'PDF باسم فيديو' => fn (): UploadedFile => fakeUpload('clip.mp4', 'pdf'),
]);

it('D-124: أكثر من ثلاثة ملفات يُرفض، وملف أكبر من عشرة ميجابايت يُرفض — ولا يُكتب شيء', function (): void {
    tkPostWithFiles($this, [fakeUpload('1.png', 'png'), fakeUpload('2.png', 'png'), fakeUpload('3.png', 'png'), fakeUpload('4.png', 'png')])
        ->assertSessionHasErrors(['attachments' => __('support.errors.files_count', ['count' => trans_choice('support.count.files', 3, ['count' => 3])])]);

    // A real PNG, padded past ten megabytes by one kilobyte.
    $heavy = UploadedFile::fake()->createWithContent('big.png', (string) fakeUpload('x.png', 'png')->getContent().str_repeat("\0", 10241 * 1024));

    tkPostWithFiles($this, [$heavy])
        ->assertSessionHasErrors(['attachments.0' => __('support.errors.file_size', ['size' => 10])]);

    expect(SupportTicket::query()->count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('D-124: الصورة والفيديو يُعرضان داخل الصفحة برابط موقّع، والفيديو يُطلب بأجزاء فيعمل ويُقدَّم', function (): void {
    tkPostWithFiles($this, [fakeUpload('screen.png', 'png'), fakeUpload('clip.mp4', 'mp4')])->assertSessionHasNoErrors();

    $ticket = SupportTicket::query()->sole();
    $video = SupportTicketAttachment::query()->where('kind', 'video')->sole();
    $image = SupportTicketAttachment::query()->where('kind', 'image')->sole();

    $page = (string) $this->actingAs($this->participant)->get(route('support.show', $ticket))->getContent();

    expect($page)->toContain('<video')
        ->and($page)->toContain('<img class="tkt-files__media"')
        ->and($page)->not->toContain($video->path)
        ->and($page)->not->toContain($image->path);

    $url = tkFileUrl($this, $ticket, $video, $this->participant);

    $whole = $this->actingAs($this->participant)->get($url)->assertOk();
    expect($whole->headers->get('Content-Type'))->toBe('video/mp4')
        ->and((string) $whole->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($whole->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($whole->headers->get('Accept-Ranges'))->toBe('bytes');

    $part = $this->actingAs($this->participant)->get($url, ['Range' => 'bytes=0-9'])->assertStatus(206);
    expect($part->headers->get('Content-Range'))->toStartWith('bytes 0-9/');
});

it('D-124: ملف على إجراء داخلي لا يفتحه المتدرب — 403، ورابط بلا توقيع أو بعد خمس عشرة دقيقة لا يفتحه أحد', function (): void {
    tkPostWithFiles($this, [])->assertSessionHasNoErrors();
    $ticket = SupportTicket::query()->sole();

    $this->actingAs($this->coordinator)
        ->post(route('support.note', $ticket), ['body' => 'لقطة داخلية', 'internal' => '1', 'attachments' => [fakeUpload('inside.png', 'png')]])
        ->assertSessionHasNoErrors();

    $internal = SupportTicketAttachment::query()->sole();
    $signed = app(PrivateFileService::class)->temporaryUrl('files.supportAttachment', ['attachment' => $internal->id]);

    $this->actingAs($this->coordinator)->get($signed)->assertOk();
    $this->actingAs($this->participant)->get($signed)->assertForbidden();

    // Another participant, with a link that is valid in itself.
    $this->actingAs(makeParticipant($this->cohort))->get($signed)->assertForbidden();

    $this->actingAs($this->coordinator)->get(route('files.supportAttachment', $internal))->assertForbidden();

    // Past its expiry. The signature middleware reads the framework's clock,
    // which Clock::fake() does not move, so the link is signed with an
    // instant already behind any clock this runs on.
    $stale = URL::temporarySignedRoute('files.supportAttachment', riyadhAt('2020-01-01 00:00:00'), ['attachment' => $internal->id]);
    $this->actingAs($this->coordinator)->get($stale)->assertForbidden();
});

it('D-124: الفيديو صيغة يطلبها حقل التذاكر بالاسم — بقية الرفع في المنصة ترفضه كما كانت', function (): void {
    $files = app(PrivateFileService::class);

    expect(fn () => $files->store(fakeUpload('clip.mp4', 'mp4'), 'submissions/x', $this->participant))
        ->toThrow(FileException::class);

    expect($files->store(fakeUpload('clip.mp4', 'mp4'), 'support/x', $this->participant, ['video/mp4'])['mime_type'])
        ->toBe('video/mp4');

    // Naming video is not enough for a type the platform forbids outright.
    expect(fn () => $files->store(fakeUpload('logo.svg', 'svg'), 'support/x', $this->participant, ['image/svg+xml']))
        ->toThrow(FileException::class);
});
