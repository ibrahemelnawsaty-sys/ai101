<?php

declare(strict_types=1);

/**
 * Phase 5 — «log this account out everywhere» deletes the account's sessions on the
 * database driver the platform runs on (shared hosting, CONSTITUTION §hosting).
 *
 * A browser QA pass reported the button as inert. It was run against a dev server on the
 * `file` session driver, where ending a session by deleting its row has nothing to delete;
 * this asserts the behaviour on the driver production uses.
 *
 * @see BR-33 · PRD §9.18 · CONSTITUTION art. 5 · D-147
 */

use App\Services\Time\Clock;
use Illuminate\Support\Facades\DB;

it('D-147: مع محرّك الجلسات database يحذف الزر كل جلسات الحساب ولا يمسّ جلسات غيره', function (): void {
    config(['session.driver' => 'database']);

    $sysadmin = makeUser('system_admin');
    $target = makeParticipant(makeCohort());
    $other = makeParticipant(makeCohort());

    $table = (string) config('session.table', 'user_sessions');

    foreach ([[$target->id, 's-target-1'], [$target->id, 's-target-2'], [$other->id, 's-other']] as [$userId, $id]) {
        DB::table($table)->insert(['id' => $id, 'user_id' => $userId, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => Clock::now()->getTimestamp()]);
    }

    $this->actingAs($sysadmin)->post(route('admin.users.logoutEverywhere', $target))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('admin.users.sessions_revoked'));

    expect(DB::table($table)->where('user_id', $target->id)->count())->toBe(0)
        ->and(DB::table($table)->where('user_id', $other->id)->count())->toBe(1);
});
