<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An account created FOR someone, rather than by them.
 *
 * WHY THESE COLUMNS EXIST
 * Registration for this cohort closed, and the only path that produces a
 * working participant is self-registration: `EmailVerificationController`
 * creates the enrolment when the trainee clicks the link in their own
 * activation letter. An administrator adding a user got an account in NO
 * cohort, with no letter, and — because `store()` stamped `email_verified_at`
 * — no way even to resend one, since `resendVerification()` refuses a verified
 * account. Sixty trainees had no way in.
 *
 * The owner chose an invitation: the platform generates a temporary password,
 * mails it, and forces a change at the first sign-in.
 *
 * `must_change_password` — the middleware gate. TRUE means every request is
 * redirected to the change-password screen until it is FALSE. Defaulting to
 * false leaves every existing account untouched, so nothing has to be
 * backfilled and no one who is already using the platform is interrupted.
 *
 * `temp_password_expires_at` — because a temporary password that never expires
 * is a permanent password sitting in an inbox. Nullable: an ordinary account
 * has no temporary password, and null means "not temporary", never "expired".
 * A trainee who arrives after it lapses is not stranded — the sign-in screen
 * sends them to password recovery, which works.
 *
 * `invited_at` — an audit fact the invitation flow needs and `created_at`
 * cannot carry: an account may be created now and invited later, or re-invited
 * after a bounce, and "when were they last told" is the question the
 * administrator will actually ask.
 *
 * @see PRD §4.5.1, §9.2, §9.3 · BR-30 · CONSTITUTION.md Article 29 · D-63
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('must_change_password')
                ->default(false)
                ->after('locked_until');

            $table->timestamp('temp_password_expires_at')
                ->nullable()
                ->after('must_change_password');

            $table->timestamp('invited_at')
                ->nullable()
                ->after('temp_password_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'must_change_password',
                'temp_password_expires_at',
                'invited_at',
            ]);
        });
    }
};
