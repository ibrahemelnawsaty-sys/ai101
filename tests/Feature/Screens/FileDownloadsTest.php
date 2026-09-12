<?php

declare(strict_types=1);

/**
 * Stored files can be opened — by the people allowed to, through links that
 * expire (PRD §12.5).
 *
 * WHY THIS SUITE EXISTS
 * There was no route for a stored file at all. A trainer could not open a
 * single hand-in to grade it, participants could not open the attachments on
 * their own assignment brief, and "download all" answered "unavailable" to
 * everyone (D-80).
 *
 * @see PRD §12.5 · BR-19, BR-22, BR-23 · D-80
 */

use App\Models\Submission;
use App\Support\SignedFiles;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-19 12:00:00'));
    Storage::fake('private');

    $this->cohort = makeCohort();
    $this->trainer = makeTrainer($this->cohort);
    $this->participant = makeParticipant($this->cohort);
    $this->assignment = makeAssignment($this->cohort);
});

/** A stored file and the descriptor PrivateFileService would have written. */
function storedFile(string $path, string $name, string $contents): array
{
    Storage::disk('private')->put($path, $contents);

    return ['disk' => 'private', 'path' => $path, 'original_name' => $name, 'mime_type' => 'application/pdf', 'size_bytes' => strlen($contents)];
}

function handIn(object $test, array $files, int $version = 1, ?App\Models\User $user = null): Submission
{
    return makeSubmission($test->assignment, $user ?? $test->participant, ['version' => $version, 'files' => $files]);
}

it('D-80: رابط الملف في لوحة التصحيح يفتح الملف للمدرّب', function (): void {
    $submission = handIn($this, [storedFile('submissions/a.pdf', 'report.pdf', 'CANARY-BYTES')]);

    $html = (string) $this->actingAs($this->trainer)
        ->get(route('trainer.submissions', ['submission' => $submission->id]))
        ->assertOk()
        ->getContent();

    expect(preg_match('#href="([^"]*/files/submissions/'.preg_quote((string) $submission->id, '#').'/0[^"]*)"#', $html, $m))->toBe(1);

    $response = $this->actingAs($this->trainer)->get(html_entity_decode($m[1]));

    $response->assertOk();
    expect($response->streamedContent())->toBe('CANARY-BYTES')
        ->and((string) $response->headers->get('content-disposition'))->toContain('report.pdf');
});

it('D-80: بلا توقيع يُرفض، وبعد انقضاء الرابط يُرفض', function (): void {
    $submission = handIn($this, [storedFile('submissions/a.pdf', 'report.pdf', 'x')]);

    $this->actingAs($this->trainer)
        ->get(route('files.submission', ['submission' => $submission->id, 'index' => 0]))
        ->assertForbidden();

    $url = SignedFiles::for('files.submission', 'submission', $submission)(0);

    // Laravel's clock checks the signature; the platform clock minted it. In
    // production they are one clock — here, move Laravel's past the expiry.
    $this->travelTo(riyadhAt('2026-10-19 12:16:00'));
    $this->actingAs($this->trainer)->get($url)->assertForbidden();
});

it('BR-23: رابط موقَّع لا يفتح الملف لمدرّب دفعة أخرى', function (): void {
    $submission = handIn($this, [storedFile('submissions/a.pdf', 'report.pdf', 'x')]);
    $url = SignedFiles::for('files.submission', 'submission', $submission)(0);

    $this->actingAs(makeTrainer(makeCohort()))->get($url)->assertForbidden();
});

it('BR-22: المتدرّب يفتح ملفّه، ولا يفتح ملف زميله ولو حمل رابطًا موقَّعًا', function (): void {
    $own = handIn($this, [storedFile('submissions/own.pdf', 'mine.pdf', 'OWN')]);
    $peer = handIn($this, [storedFile('submissions/peer.pdf', 'theirs.pdf', 'PEER')], 1, makeParticipant($this->cohort));

    $this->actingAs($this->participant)
        ->get(SignedFiles::for('files.submission', 'submission', $own)(0))
        ->assertOk();

    $this->actingAs($this->participant)
        ->get(SignedFiles::for('files.submission', 'submission', $peer)(0))
        ->assertForbidden();
});

it('D-80: مرفق المهمة المنشورة يفتحه متدرّب الدفعة، ومرفق المسودة لا', function (): void {
    $published = makeAssignment($this->cohort, ['attachments' => [storedFile('briefs/b.pdf', 'brief.pdf', 'BRIEF')]]);
    $draft = makeAssignment($this->cohort, ['status' => 'draft', 'attachments' => [storedFile('briefs/d.pdf', 'draft.pdf', 'DRAFT')]]);

    $this->actingAs($this->participant)
        ->get(route('assignments.show', $published))
        ->assertOk()
        ->assertSee('/files/assignments/'.$published->id.'/0', false);

    $this->actingAs($this->participant)
        ->get(SignedFiles::for('files.assignment', 'assignment', $published)(0))
        ->assertOk();

    $this->actingAs($this->participant)
        ->get(SignedFiles::for('files.assignment', 'assignment', $draft)(0))
        ->assertForbidden();
});

it('D-80: ملف غير موجود على القرص يُجاب 404 لا 500', function (): void {
    $submission = handIn($this, [['disk' => 'private', 'path' => 'submissions/gone.pdf', 'original_name' => 'gone.pdf']]);

    $this->actingAs($this->trainer)
        ->get(SignedFiles::for('files.submission', 'submission', $submission)(0))
        ->assertNotFound();
});

it('BR-19: التحميل المجمّع يضمّ أحدث نسخة لكل متدرّب وحدها', function (): void {
    $other = makeParticipant($this->cohort);
    handIn($this, [storedFile('submissions/v1.pdf', 'v1.pdf', 'OLD')], 1);
    handIn($this, [storedFile('submissions/v2.pdf', 'v2.pdf', 'NEW')], 2);
    handIn($this, [storedFile('submissions/o.pdf', 'other.pdf', 'OTHER')], 1, $other);

    $response = $this->actingAs($this->trainer)->get(route('trainer.submissions.bulkDownload', $this->assignment));
    $response->assertOk();

    $path = $response->baseResponse->getFile()->getPathname();
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = basename((string) $zip->getNameIndex($i));
    }
    sort($names);
    $zip->close();

    expect($names)->toBe(['other.pdf', 'v2.pdf']);
});

it('D-80: التحميل المجمّع لمهمة بلا ملفات يُنبِّه ولا يُرسل أرشيفًا فارغًا', function (): void {
    $this->actingAs($this->trainer)
        ->get(route('trainer.submissions.bulkDownload', $this->assignment))
        ->assertSessionHas('warning', __('trainer.submissions.bulk_empty'));
});
