<?php

declare(strict_types=1);

/**
 * Phase 5 · D-152 — an imported participant is invited by a one-use link, like every other
 * invitation, and no password travels by e-mail any more.
 *
 * The single invitation form sent a link and its screen said «no password here or in the
 * letter». The bulk import called the older path: it made the account with a generated
 * «temporary password» and mailed that password, in plain text, to every imported address. Two
 * ways to make the same account, two securities, and the two screens contradicting each other.
 * The owner chose option A: the import uses the link (`AccountInviter::inviteByLink`), and the
 * code that generated and mailed a temporary password is deleted.
 *
 * What stays: accounts that ALREADY carry `must_change_password` (made before this decision) are
 * still stopped at the first-password screen and are covered by the flow tests; this decision
 * removes how new ones are made, not the gate that old ones meet.
 *
 * @see PRD §4.5.1, §9.2 · BR-29, BR-30 · CONSTITUTION art. 5, art. 12 · D-63, D-85, D-152
 */

use App\Enums\EmailTokenType;
use App\Jobs\InviteImportedParticipant;
use App\Mail\EmailTokenLink;
use App\Models\AuditLog;
use App\Models\EmailToken;
use App\Models\Enrollment;
use App\Models\User;
use App\Services\Credentials\AccountInviter;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 09:00:00'));
    Mail::fake();

    $this->cohort = makeCohort();
});

/** The four Arabic name parts and the mobile number — what an import row carries. */
function importedProfile(): array
{
    return [
        'first_name_ar' => 'CANARY-FIRST',
        'second_name_ar' => 'CANARY-SECOND',
        'third_name_ar' => 'CANARY-THIRD',
        'last_name_ar' => 'CANARY-LAST',
        'phone' => '0512345671',
    ];
}

function runImportJob(object $test, string $email = 'imported@example.test'): User
{
    (new InviteImportedParticipant($email, importedProfile(), (string) $test->cohort->getKey()))
        ->handle(app(AccountInviter::class));

    return User::query()->where('email', $email)->sole();
}

it('D-152: المستورَد يُنشأ بحساب غير مفعّل ولا كلمة مرور مؤقتة ولا إلزام بتغييرها', function (): void {
    $user = runImportJob($this);

    expect($user->getAttribute('email_verified_at'))->toBeNull()
        ->and((bool) $user->getAttribute('must_change_password'))->toBeFalse()
        ->and($user->getAttribute('temp_password_expires_at'))->toBeNull()
        ->and($user->getAttribute('invited_at'))->not->toBeNull();
});

it('D-152: يصل المستورَد رابط دعوة يُفتح مرة واحدة — لا رسالة تحمل كلمة مرور', function (): void {
    $user = runImportJob($this);

    Mail::assertQueued(EmailTokenLink::class, fn (EmailTokenLink $letter): bool => $letter->user->is($user)
        && $letter->type === EmailTokenType::Invite
        && str_contains($letter->url, '/invitation/'));

    Mail::assertQueuedCount(1);

    expect(EmailToken::query()->where('user_id', $user->id)->where('type', EmailTokenType::Invite->value)->count())->toBe(1);
});

it('D-152: المقعد والبطاقة والتدقيق كما كانت — والتدقيق يقول إن الدعوة برابط', function (): void {
    $user = runImportJob($this);

    expect(Enrollment::query()->where('user_id', $user->id)->where('cohort_id', $this->cohort->id)->count())->toBe(1)
        ->and(App\Models\DigitalCard::query()->where('user_id', $user->id)->count())->toBe(1);

    $row = AuditLog::query()->where('action', 'user.invited')->where('entity_id', $user->id)->sole();

    expect(json_encode($row->getAttributes(), JSON_UNESCAPED_UNICODE))->toContain('link');
});

it('D-152: تشغيل الوظيفة مرتين لنفس العنوان ينشئ حسابًا واحدًا ورسالة واحدة', function (): void {
    runImportJob($this);
    (new InviteImportedParticipant('imported@example.test', importedProfile(), (string) $this->cohort->getKey()))
        ->handle(app(AccountInviter::class));

    expect(User::query()->where('email', 'imported@example.test')->count())->toBe(1);

    Mail::assertQueuedCount(1);
});

it('D-152: مسار كلمة المرور المؤقتة حُذف من الشيفرة — لا invite() ولا رسالة InvitationLetter ولا قالب «invitation»', function (): void {
    expect(method_exists(AccountInviter::class, 'invite'))->toBeFalse()
        ->and(file_exists(app_path('Mail/InvitationLetter.php')))->toBeFalse()
        ->and(file_exists(resource_path('views/mail/invitation.blade.php')))->toBeFalse()
        ->and(file_exists(resource_path('views/mail/invitation-text.blade.php')))->toBeFalse();

    foreach (['ar', 'en'] as $locale) {
        $emails = require lang_path("{$locale}/emails.php");

        expect($emails)->not->toHaveKey('invitation')
            ->and($emails)->toHaveKey('invitation_link');
    }

    // And the job reaches the one invitation path.
    expect((string) file_get_contents(app_path('Jobs/InviteImportedParticipant.php')))
        ->toContain('inviteByLink(')
        ->and((string) file_get_contents(app_path('Jobs/InviteImportedParticipant.php')))->not->toContain('->invite(');
});
