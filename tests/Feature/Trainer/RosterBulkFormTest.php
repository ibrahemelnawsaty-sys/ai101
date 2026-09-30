<?php

declare(strict_types=1);

/**
 * Phase 4 — the roster's bulk form, submitted the way a browser submits it.
 *
 * Every test of bulk marking so far posted a hand-built array (`user_id => [...]`),
 * which is what the request wants and not what the page sends. The page sent a
 * hidden "0" beside every checkbox (the companion an unchecked single box needs
 * so that "off" reaches the server), so the array carried one literal "0" per row,
 * `user_id.*` refused it as neither a uuid nor an enrolled participant, and the
 * form redirected back to itself with no record written, no audit line and no
 * message. It had never worked from the screen (D-143).
 *
 * These read the form out of the page, tick a box, and post exactly the fields a
 * browser would post. They decide nothing new: the rules in BulkAttendanceRequest,
 * the reason length and the window are untouched and asserted elsewhere.
 *
 * @see BR-10, BR-27 · PRD §9.9.7 · CONSTITUTION art. 5 · D-143
 */

use App\Models\Attendance;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

beforeEach(function (): void {
    $this->cohort = makeCohort(['status' => 'running']);
    $this->trainer = makeTrainer($this->cohort);
    $this->first = makeParticipant($this->cohort);
    $this->second = makeParticipant($this->cohort);
    $this->session = sessionInCohort($this->cohort, riyadhAt('2026-10-05 18:00:00'), riyadhAt('2026-10-05 21:00:00'), ['title' => 'Session A']);
});

/**
 * The fields a browser would send for the bulk form on this page, given the ids
 * of the boxes the person ticked and what they typed. Only named, enabled controls
 * count; an unticked checkbox sends nothing; a hidden input always sends.
 *
 * @param  list<string>  $ticked
 * @return array<string, mixed>
 */
function bulkFormAsABrowserSendsIt(string $html, array $ticked, string $status, string $reason): array
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $xpath = new DOMXPath($dom);

    $form = $xpath->query('//form[.//input[@name="user_id[]"]]')->item(0);
    expect($form)->not->toBeNull();

    $pairs = [];

    foreach ($xpath->query('.//input[@name]', $form) as $input) {
        /** @var DOMElement $input */
        $type = strtolower($input->getAttribute('type') ?: 'text');

        if ($input->hasAttribute('disabled')) {
            continue;
        }

        if ($type === 'checkbox') {
            if (in_array($input->getAttribute('value'), $ticked, true)) {
                $pairs[] = [$input->getAttribute('name'), $input->getAttribute('value')];
            }

            continue;
        }

        $value = match ($input->getAttribute('name')) {
            'attendance_status' => $status,
            'edit_reason' => $reason,
            default => $input->getAttribute('value'),
        };

        $pairs[] = [$input->getAttribute('name'), $value];
    }

    parse_str(implode('&', array_map(
        static fn (array $pair): string => urlencode($pair[0]).'='.urlencode($pair[1]),
        $pairs,
    )), $fields);

    return $fields;
}

it('BR-10: الشاشة لا ترسل «0» مرافقًا مع مربّعات التحديد الجماعي', function (): void {
    freezeAt(riyadhAt('2026-10-05 21:30:00'));

    $html = $this->actingAs($this->trainer)
        ->get(route('trainer.attendance', ['session' => $this->session->id]))
        ->assertOk()
        ->getContent();

    $fields = bulkFormAsABrowserSendsIt($html, [$this->first->id], 'absent', 'Did not attend and sent no notice beforehand.');

    expect($fields['user_id'])->toBe([$this->first->id]);
});

it('BR-10: نموذج التحضير الجماعي كما يرسله المتصفح يكتب الصفّ ويدوّن سطر تدقيق', function (): void {
    freezeAt(riyadhAt('2026-10-05 21:30:00'));

    $html = $this->actingAs($this->trainer)
        ->get(route('trainer.attendance', ['session' => $this->session->id]))
        ->getContent();

    $fields = bulkFormAsABrowserSendsIt($html, [$this->first->id, $this->second->id], 'absent', 'Did not attend and sent no notice beforehand.');

    Auth::forgetGuards();

    $this->actingAs($this->trainer)
        ->post(route('trainer.attendance.bulk', $this->session), $fields)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('attendance.bulk_saved'));

    expect(Attendance::query()->where('session_id', $this->session->id)->count())->toBe(2)
        ->and(AuditLog::query()->whereIn('entity_id', Attendance::query()->pluck('id'))->count())->toBe(2);
});

it('BR-27: بلا سبب كافٍ يبقى الرفض قائمًا ولا يُكتب شيء', function (): void {
    freezeAt(riyadhAt('2026-10-05 21:30:00'));

    $html = $this->actingAs($this->trainer)
        ->get(route('trainer.attendance', ['session' => $this->session->id]))
        ->getContent();

    $fields = bulkFormAsABrowserSendsIt($html, [$this->first->id], 'absent', 'short');

    Auth::forgetGuards();

    $this->actingAs($this->trainer)
        ->from(route('trainer.attendance', ['session' => $this->session->id]))
        ->post(route('trainer.attendance.bulk', $this->session), $fields)
        ->assertSessionHasErrors('edit_reason');

    expect(Attendance::query()->count())->toBe(0);
});

it('BR-10: بلا تحديد يقول الخادم ما العمل بكلمات المدرب، والشاشة تعرض ذلك فوق الجدول', function (): void {
    freezeAt(riyadhAt('2026-10-05 21:30:00'));
    $roster = route('trainer.attendance', ['session' => $this->session->id]);

    $this->actingAs($this->trainer)
        ->from($roster)
        ->followingRedirects()
        ->post(route('trainer.attendance.bulk', $this->session), [
            'attendance_status' => 'absent',
            'edit_reason' => 'Did not attend and sent no notice beforehand.',
        ])
        ->assertOk()
        ->assertSee(e((string) __('trainer.attendance.bulk_pick_required')), false);

    expect(Attendance::query()->count())->toBe(0);
});

it('BR-22: معرّف من خارج الجلسة يُرفض بجملة تشرح الحل لا بـ«المستخدم غير صالح»، ولا يُكتب شيء', function (): void {
    freezeAt(riyadhAt('2026-10-05 21:30:00'));
    $stranger = makeParticipant(makeCohort());

    $this->actingAs($this->trainer)
        ->from(route('trainer.attendance', ['session' => $this->session->id]))
        ->post(route('trainer.attendance.bulk', $this->session), [
            'user_id' => [$stranger->id],
            'attendance_status' => 'absent',
            'edit_reason' => 'Did not attend and sent no notice beforehand.',
        ])
        ->assertSessionHasErrors(['user_id.0' => (string) __('trainer.attendance.bulk_pick_invalid')]);

    expect(Attendance::query()->count())->toBe(0);
});

it('BR-27: بعد الرفض تعود الصفوف المحدَّدة محدَّدة — يرى المدرب ما أرسل والرسالة بجانبه', function (): void {
    freezeAt(riyadhAt('2026-10-05 21:30:00'));
    $roster = route('trainer.attendance', ['session' => $this->session->id]);

    $html = $this->actingAs($this->trainer)
        ->from($roster)
        ->followingRedirects()
        ->post(route('trainer.attendance.bulk', $this->session), [
            'user_id' => [$this->first->id],
            'attendance_status' => 'absent',
            'edit_reason' => 'short',
        ])
        ->assertOk()
        ->getContent();

    preg_match('/<input[^>]*value="'.preg_quote($this->first->id, '/').'"[^>]*>/', (string) $html, $box);
    preg_match('/<input[^>]*value="'.preg_quote($this->second->id, '/').'"[^>]*>/', (string) $html, $other);

    expect($box[0] ?? '')->toContain('checked')
        ->and($other[0] ?? '')->not->toContain('checked');
});

it('BR-22: كل رسالة رفض للتسجيل الجماعي لها نص في اللغتين، ولا يظهر مفتاحها بدل نصها', function (): void {
    $request = new App\Http\Requests\Trainer\BulkAttendanceRequest;

    foreach (['ar', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach ($request->messages() as $rule => $message) {
            expect($message)->not->toBe('')
                ->and($message)->not->toStartWith('trainer.attendance.', "{$locale}: {$rule} shows its key");
        }
    }

    app()->setLocale('ar');
});

it('BR-22: لا معرّفات، أو أكثر من الحدّ، أو معرّف بشكل خاطئ — كلٌّ برسالته التي تشرح الحل', function (): void {
    freezeAt(riyadhAt('2026-10-05 21:30:00'));
    $roster = route('trainer.attendance', ['session' => $this->session->id]);
    $base = ['attendance_status' => 'absent', 'edit_reason' => 'Did not attend and sent no notice beforehand.'];

    $cases = [
        'no ids at all' => [$base + ['user_id' => []], 'user_id', 'bulk_pick_required', []],
        'too many ids' => [$base + ['user_id' => array_map(fn (int $n): string => sprintf('00000000-0000-7000-8000-%012d', $n), range(1, 201))], 'user_id', 'bulk_pick_too_many', ['max' => 200]],
        'not an id' => [$base + ['user_id' => ['not-a-uuid']], 'user_id.0', 'bulk_pick_invalid', []],
    ];

    foreach ($cases as $label => [$payload, $key, $lang, $replace]) {
        Auth::forgetGuards();

        $this->actingAs($this->trainer)
            ->from($roster)
            ->post(route('trainer.attendance.bulk', $this->session), $payload)
            ->assertSessionHasErrors([$key => (string) __('trainer.attendance.'.$lang, $replace)]);
    }

    expect(Attendance::query()->count())->toBe(0);
});
