<?php

declare(strict_types=1);

/**
 * A trainee can hand in work, and can do it without JavaScript.
 *
 * WHY THIS SUITE EXISTS
 * They could not. The browser console on the live platform said so:
 *
 *     Alpine Expression Error: atharUploader is not defined
 *     ... then dragging, files, ready, uploading, accept — in turn
 *
 * The form declared `x-data="atharUploader({...})"` while `app.js` registers the
 * component as `uploader`, with a different name for every single thing the
 * template used: `hot` not `dragging`, `add()` not `accept()`, `submit()` not
 * `send()`, `file.id` not `file.key`. Two contracts, written past each other.
 *
 * And `x-on:submit.prevent` calls preventDefault() BEFORE it evaluates the
 * expression — so the native submit was cancelled and the replacement threw.
 * Pressing "hand in" did nothing at all. Not a degraded experience: no
 * submissions, at all, for anyone.
 *
 * The screen is now a plain form. These tests hold it to that, because the
 * moment a directive comes back the whole platform can silently stop accepting
 * work again and no server-side test would notice.
 *
 * @see BR-19 · PRD §9.11 · CONSTITUTION.md Article 5, Article 21 · D-54
 */

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 12:00:00'));

    $this->cohort = makeCohort();
    $this->participant = makeParticipant($this->cohort);
});

it('D-54: نموذج التسليم لا يعتمد على JavaScript إطلاقًا', function (): void {
    // The template source, not a rendered page: the failure was a directive
    // that only misbehaves in a browser, and a rendered assertion would pass
    // while the button stayed dead.
    $source = (string) File::get(resource_path('views/participant/assignments/show.blade.php'));

    // Strip Blade comments — the explanation of this very fix names the broken
    // directives, and a raw search finds the prose instead of the code.
    $markup = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

    expect($markup)->not->toContain('atharUploader')
        // The one that made it fatal rather than merely unenhanced.
        ->and($markup)->not->toContain('submit.prevent')
        ->and($markup)->not->toContain('x-data')
        // A real multipart form with a reachable file input.
        ->and($markup)->toContain('enctype="multipart/form-data"')
        ->and($markup)->toContain('name="files[]"')
        // The input used to be class="sr", opened only by a button that called
        // into the missing component.
        ->and($markup)->not->toMatch('/name="files\[\]"[^>]*class="sr"/');
});

it('D-54: زرّ «حفظ كمسودّة» أُزيل لأنه كان يسلّم نهائيًّا', function (): void {
    $markup = (string) preg_replace(
        '/\{\{--.*?--\}\}/s',
        '',
        (string) File::get(resource_path('views/participant/assignments/show.blade.php')),
    );

    // It posted draft=1, rules() never declared the field, and the controller
    // writes status = 'submitted' unconditionally. The label said one thing and
    // the platform did another.
    expect($markup)->not->toContain('name="draft"');
});

it('BR-19: تسليم ثانٍ يُنشئ نسخة جديدة ولا يمحو الأولى', function (): void {
    Storage::fake('private');

    $assignment = makeAssignment($this->cohort);

    $this->actingAs($this->participant)
        ->post(route('assignments.submit', $assignment), [
            'note' => 'CANARY-FIRST',
            'files' => [fakeUpload('first.pdf')],
        ])
        ->assertRedirect();

    $this->actingAs($this->participant)
        ->post(route('assignments.submit', $assignment), [
            'note' => 'CANARY-SECOND',
            'files' => [fakeUpload('second.pdf')],
        ])
        ->assertRedirect();

    $versions = App\Models\Submission::query()
        ->where('assignment_id', $assignment->getKey())
        ->where('user_id', $this->participant->getKey())
        ->orderBy('version')
        ->get();

    expect($versions)->toHaveCount(2)
        ->and((int) $versions[0]->getAttribute('version'))->toBe(1)
        ->and((int) $versions[1]->getAttribute('version'))->toBe(2)
        // The first must still be readable: BR-19 is about not losing work.
        ->and((string) $versions[0]->getAttribute('note'))->toBe('CANARY-FIRST');
});

it('المادة 15: رفض الملف يصل المتدرّب نصًا عربيًا لا مفتاح ترجمة خامًا', function (): void {
    Storage::fake('private');

    $assignment = makeAssignment($this->cohort);

    // Bytes that sniff as a DOS executable under a .pdf name: the service reads
    // the content, not the extension, and refuses — which is the path that
    // surfaces a FileException to the screen.
    //
    // The bytes are built inside fakeUpload(), not written here: pint's
    // single_quote fixer rewrites a double-quoted hex escape into the raw byte
    // it denotes, which put four NUL bytes into this source file, made git call
    // the test a binary blob, and quietly changed the payload on the way.
    $response = $this->actingAs($this->participant)
        ->post(route('assignments.submit', $assignment), [
            'files' => [fakeUpload('payload.pdf', 'exe')],
        ]);

    $message = (string) ($response->baseResponse->getSession()?->get('errors')?->first('files') ?? '');

    // The controller used to pass getMessage(), which is the translation KEY:
    // the trainee saw `errors.file.mime_not_allowed` in Latin letters and was
    // told nothing about what had happened or what to do about it.
    expect($message)->not->toBe('')
        ->and($message)->not->toStartWith('errors.')
        ->and($message)->toBe(__('errors.file.mime_not_allowed'));

    expect(App\Models\Submission::query()->count())->toBe(0);
});

it('المادة 5: متدرّب لا يسلّم لمهمة ليست في دفعته', function (): void {
    $otherCohort = makeCohort();
    $foreign = makeAssignment($otherCohort);

    $this->actingAs($this->participant)
        ->post(route('assignments.submit', $foreign), ['note' => 'CANARY'])
        ->assertForbidden();

    expect(App\Models\Submission::query()->where('assignment_id', $foreign->getKey())->count())->toBe(0);
});
