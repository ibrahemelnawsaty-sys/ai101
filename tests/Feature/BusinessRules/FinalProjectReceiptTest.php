<?php

declare(strict_types=1);

/**
 * The receipt of a final-project hand-in (D-122): a unique receipt code, a
 * letter carrying it with a QR and the next step, the notice on the platform,
 * and the receipt page the QR opens — for the owner, the cohort's trainer and
 * the supervisor, and for nobody else.
 *
 * @see BR-22, BR-23 · FR-NOTIF-15, FR-NOTIF-16 · PRD §9.14.2, §9.16.1 · D-122
 */

use App\Mail\HandInReceiptLetter;
use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\ProjectSubmission;
use App\Services\FinalProject\ReceiptCodes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-28 20:00:00'));
    Storage::fake('private');
    Mail::fake();

    $this->cohort = makeCohort();
    $this->trainer = makeTrainer($this->cohort);
    $this->participant = makeParticipant($this->cohort);
    $this->project = makeFinalProject($this->cohort, [
        'title' => 'CANARY-PROJECT',
        'is_unlocked' => true,
        'due_at' => riyadhAt('2026-09-30 23:59:00'),
    ]);
});

function handInForReceipt(object $test): ProjectSubmission
{
    $test->actingAs($test->participant)
        ->post(route('finalProject.submit'), handInPayload($test->project))
        ->assertSessionHasNoErrors();

    return ProjectSubmission::query()->where('user_id', $test->participant->id)->orderByDesc('version')->firstOrFail();
}

it('FR-NOTIF-15: التسليم يعطي رمز تسليم فريدًا ويرسل للمتدرب بريد الإيصال بالرمز وQR والخطوة التالية', function (): void {
    $submission = handInForReceipt($this);
    $code = (string) $submission->receipt_code;

    expect(ReceiptCodes::isWellFormed($code))->toBeTrue();

    Mail::assertQueued(HandInReceiptLetter::class, 1);
    Mail::assertQueued(HandInReceiptLetter::class, fn (HandInReceiptLetter $letter): bool => $letter->hasTo($this->participant->email)
        && $letter->receiptCode === $code
        && $letter->receiptUrl === route('finalProject.receipt', ['code' => $code]));

    /** @var HandInReceiptLetter $letter */
    $letter = Mail::queued(HandInReceiptLetter::class)->first();
    $html = $letter->render();

    expect($letter->envelope()->subject)->toContain($code)
        ->and($html)->toContain($code)
        ->and($html)->toContain('CANARY-PROJECT')
        // The QR is embedded in the message; a render replaces its cid: with
        // the image itself.
        ->and($html)->toContain('data:image/png;base64,')
        ->and($html)->toContain(e(__('emails.final_project_received.next_steps')))
        ->and($html)->toContain(e(__('project.default_fields.presentation_file.label')))
        ->and($html)->not->toContain('emails.final_project_received');
});

it('FR-NOTIF-15: إشعار الاستلام في الحساب يذكر الرمز والخطوة التالية، والمدرب يصله وصول التسليم', function (): void {
    $code = (string) handInForReceipt($this)->receipt_code;

    $notice = Notification::query()->where('user_id', $this->participant->id)->where('type', 'submission_received')->sole();

    expect($notice->title)->toContain($code)
        ->and($notice->body)->toBe(__('project.receipt.notice_body', ['project' => 'CANARY-PROJECT', 'code' => $code]))
        ->and($notice->link)->toBe(route('finalProject.receipt', ['code' => $code]))
        ->and(Notification::query()->where('user_id', $this->trainer->id)->where('type', 'submission_new')->count())->toBe(1);
});

it('D-122: من أطفأ بريد تأكيد الاستلام يصله الإشعار في حسابه وحده', function (): void {
    NotificationPreference::factory()->create([
        'user_id' => $this->participant->id,
        'type' => 'submission_received',
        'email_enabled' => false,
        'in_app_enabled' => true,
    ]);

    handInForReceipt($this);

    Mail::assertNotQueued(HandInReceiptLetter::class);
    expect(Notification::query()->where('user_id', $this->participant->id)->where('type', 'submission_received')->count())->toBe(1);
});

it('BR-19: كل نسخة تسليم برمز إيصال خاص بها', function (): void {
    $first = handInForReceipt($this);
    $second = handInForReceipt($this);

    expect($second->version)->toBe(2)
        ->and($second->receipt_code)->not->toBe($first->receipt_code)
        ->and($first->fresh()?->receipt_code)->toBe($first->receipt_code);
});

it('D-122: صفحة المشروع تعرض إيصال آخر نسخة — الرمز وQR والخطوة التالية', function (): void {
    $code = (string) handInForReceipt($this)->receipt_code;

    $this->actingAs($this->participant)
        ->get(route('finalProject'))
        ->assertOk()
        ->assertSee($code)
        ->assertSee('class="receipt__qr"', false)
        ->assertSee('<svg', false)
        ->assertSee(__('project.receipt.next_pending'))
        ->assertSee(route('finalProject.receipt', ['code' => $code]), false);
});

it('BR-22: صفحة الإيصال لصاحبها، ولمدرب دفعته وللمشرف مع رابط التصحيح — ولغيرهم 403 مسجَّلة', function (): void {
    $submission = handInForReceipt($this);
    $url = route('finalProject.receipt', ['code' => $submission->receipt_code]);

    $this->actingAs($this->participant)->get($url)
        ->assertOk()
        ->assertSee((string) $submission->receipt_code)
        ->assertSee(__('project.receipt.next_pending'))
        ->assertDontSee(__('project.receipt.open_grading'));

    $grading = route('trainer.finalProject', ['cohort' => $this->cohort->id, 'grade' => $submission->id]);

    foreach ([$this->trainer, makeAdmin()] as $staff) {
        $this->actingAs($staff)->get($url)
            ->assertOk()
            ->assertSee(__('project.receipt.open_grading'))
            ->assertSee(e($grading), false);
    }

    foreach ([makeParticipant($this->cohort), makeTrainer(makeCohort()), makeCoordinator($this->cohort)] as $stranger) {
        $this->actingAs($stranger)->get($url)->assertForbidden();
    }

    expect(AuditLog::query()->where('action', 'like', '%denied%')->count())->toBeGreaterThanOrEqual(3);
});

it('D-122: رمز بصيغة خاطئة أو لا تسليم له يُجاب 404، والزائر يُحوَّل لتسجيل الدخول', function (): void {
    handInForReceipt($this);

    $this->actingAs($this->participant)->get('/dashboard/final-project/receipt/FP-0000-0000')->assertNotFound();
    $this->actingAs($this->participant)->get('/dashboard/final-project/receipt/not-a-code')->assertNotFound();
    $this->actingAs($this->participant)->get(route('finalProject.receipt', ['code' => 'FP-2222-2222']))->assertNotFound();

    auth()->logout();
    $this->get(route('finalProject.receipt', ['code' => 'FP-2222-2222']))->assertRedirect(route('login'));
});

it('D-122: بعد رصد الدرجة تقول الخطوة التالية إن المشروع قُيّم وتدلّ على الدرجات', function (): void {
    $submission = handInForReceipt($this);
    makeEvaluation('final_project', $submission->id, $this->participant, 40.0);

    $this->actingAs($this->participant)
        ->get(route('finalProject.receipt', ['code' => $submission->receipt_code]))
        ->assertOk()
        ->assertSee(__('project.receipt.next_graded'))
        ->assertSee(route('grades'), false);
});

it('D-122: رموز الإيصال عشوائية بلا المحارف الملتبسة وصيغتها ثابتة', function (): void {
    $codes = [];

    for ($i = 0; $i < 200; $i++) {
        $code = ReceiptCodes::generate();

        expect(ReceiptCodes::isWellFormed($code))->toBeTrue()
            ->and($code)->not->toMatch('/[01ILO]/');

        $codes[] = $code;
    }

    expect(array_unique($codes))->toHaveCount(200)
        ->and(ReceiptCodes::isWellFormed('FP-ABCD-EFG1'))->toBeFalse()
        ->and(ReceiptCodes::isWellFormed('fp-abcd-efgh'))->toBeFalse()
        ->and(app(ReceiptCodes::class)->unused())->toMatch('/^FP-/');
});

it('D-122: ترحيل الرمز يعطي كل تسليم قائم رمزًا فريدًا، والرجوع عنه يعمل', function (): void {
    $migration = require database_path('migrations/2026_09_26_110000_add_receipt_code_to_project_submissions.php');
    $migration->down();

    $ids = [];

    foreach ([1, 2] as $version) {
        $ids[] = $id = (string) Illuminate\Support\Str::uuid7();
        DB::table('project_submissions')->insert([
            'id' => $id,
            'final_project_id' => $this->project->id,
            'user_id' => $this->participant->id,
            'submitted_at' => riyadhAt('2026-09-27 10:00:00'),
            'is_late' => false,
            'version' => $version,
            'status' => 'submitted',
            'created_at' => riyadhAt('2026-09-27 10:00:00'),
            'updated_at' => riyadhAt('2026-09-27 10:00:00'),
        ]);
    }

    $migration->up();

    $codes = DB::table('project_submissions')->whereIn('id', $ids)->pluck('receipt_code')->all();

    expect($codes)->toHaveCount(2)
        ->and(array_unique($codes))->toHaveCount(2)
        ->and(ReceiptCodes::isWellFormed((string) $codes[0]))->toBeTrue();

    Mail::assertNothingQueued();
});

it('D-122: ترحيل الرمز يُعاد تشغيله بعد توقّف في منتصفه — العمود قائم بلا قيده الفريد — فيكمل ولا يفشل', function (): void {
    $submission = makeProjectSubmission($this->project, $this->participant);
    $migration = require database_path('migrations/2026_09_26_110000_add_receipt_code_to_project_submissions.php');
    $migration->down();

    // The column landed and the unique index did not: a run that stopped
    // between MySQL's two schema statements.
    Schema::table('project_submissions', function (Blueprint $table): void {
        $table->string('receipt_code', 20)->nullable()->after('version');
    });

    $migration->up();
    $migration->up();

    expect(Schema::hasIndex('project_submissions', ['receipt_code'], 'unique'))->toBeTrue()
        ->and(ReceiptCodes::isWellFormed((string) DB::table('project_submissions')->where('id', $submission->id)->value('receipt_code')))->toBeTrue();

    Mail::assertNothingQueued();
});
