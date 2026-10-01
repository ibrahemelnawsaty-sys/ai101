<?php

declare(strict_types=1);

/**
 * Phase 5 · D-151 — the limit on «send a recovery link / send the activation again» is the limit
 * of one person being written to, not of the whole office.
 *
 * `throttle:password` allowed three a hour, keyed by the `email` field of the request — which the
 * administrator's forms do not send — and so by the IP address. The third action of the hour from
 * ANYONE in the office, on ANY account, answered «429, wait a while» for the next hour: an
 * administrator re-inviting a class of twenty was blocked after three, and the key could be
 * dodged by adding a hidden `email` field. The owner chose option A: on the administrator's two
 * routes the limit is three an hour per (administrator, target account). The public routes —
 * forgot-password, reset, invitation, verification — keep their limiter, keyed by e-mail.
 *
 * @see BR-30, BR-33 · PRD §4.5.2 · CONSTITUTION art. 5, art. 7 · D-117, D-151
 */

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));
    Mail::fake();

    $this->sys = makeSystemAdmin();
});

function sendResetLink(object $test, object $target, ?object $as = null, array $extra = []): Illuminate\Testing\TestResponse
{
    Auth::forgetGuards();

    return test()->actingAs($as ?? $test->sys)->post(route('admin.users.resetPassword', $target), $extra);
}

it('D-151: مدير النظام يرسل رابطًا لعشرة أشخاص مختلفين في الساعة بلا حجب', function (): void {
    foreach (range(1, 10) as $i) {
        sendResetLink($this, makeParticipant())->assertRedirect()->assertSessionHas('status');
    }
});

it('D-151: ثلاث محاولات في الساعة للشخص الواحد، والرابعة تُحجب بـ429', function (): void {
    $target = makeParticipant();

    foreach (range(1, 3) as $i) {
        sendResetLink($this, $target)->assertRedirect();
    }

    sendResetLink($this, $target)->assertStatus(429);
});

it('D-151: الحجب عن شخص لا يمنع مدير النظام عن غيره', function (): void {
    $blocked = makeParticipant();

    foreach (range(1, 4) as $i) {
        sendResetLink($this, $blocked);
    }

    sendResetLink($this, makeParticipant())->assertRedirect()->assertSessionHas('status');
});

it('D-151: «إعادة إرسال رابط كلمة المرور» و«إعادة إرسال التفعيل» لشخص واحد يتقاسمان الحدّ — لا يُغرَق بهما معًا', function (): void {
    $target = makeParticipant(attributes: ['email_verified_at' => null]);

    sendResetLink($this, $target)->assertRedirect();
    sendResetLink($this, $target)->assertRedirect();

    Auth::forgetGuards();
    $this->actingAs($this->sys)->post(route('admin.users.resendVerification', $target))->assertRedirect();

    Auth::forgetGuards();
    $this->actingAs($this->sys)->post(route('admin.users.resendVerification', $target))->assertStatus(429);
});

it('D-151: إضافة حقل email مخفي لا تغيّر المفتاح فلا تتجاوز الحدّ ولا تضيف منه', function (): void {
    $target = makeParticipant();

    foreach (range(1, 3) as $i) {
        sendResetLink($this, $target, extra: ['email' => "dodge{$i}@example.test"])->assertRedirect();
    }

    // A different address each time used to be a different bucket; it is the same person now.
    sendResetLink($this, $target, extra: ['email' => 'dodge4@example.test'])->assertStatus(429);
});

it('D-151: لكل مشرف عدّاده — الثاني لا يحجبه الأول عن الشخص نفسه', function (): void {
    $target = makeParticipant();
    $second = makeSystemAdmin();

    foreach (range(1, 4) as $i) {
        sendResetLink($this, $target);
    }

    sendResetLink($this, $target, $second)->assertRedirect()->assertSessionHas('status');
});

it('BR-30: المسار العام لاستعادة كلمة المرور يبقى محدودًا بالبريد: ثلاث للبريد الواحد، وبريد آخر لا يتأثر', function (): void {
    foreach (range(1, 3) as $i) {
        $this->post(route('password.email'), ['email' => 'same@example.test'])->assertStatus(302);
    }

    $this->post(route('password.email'), ['email' => 'same@example.test'])->assertStatus(429);
    $this->post(route('password.email'), ['email' => 'other@example.test'])->assertStatus(302);
});

it('D-151: المحدِّدان المسمّيان — العام بالبريد والإداري بالمشرف والهدف — لا مفتاح IP لأي منهما في مسار الإدارة', function (): void {
    $source = (string) file_get_contents(base_path('routes/web.php'));

    foreach (['users.resetPassword', 'users.resendVerification'] as $name) {
        $at = strpos($source, "->name('{$name}')");

        expect($at)->not->toBeFalse();

        $route = substr($source, max(0, (int) $at - 220), 260);

        expect($route)->toContain("'throttle:password-admin'")
            ->and($route)->not->toContain("'throttle:password'");
    }
});
