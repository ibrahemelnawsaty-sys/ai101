<?php

declare(strict_types=1);

/**
 * Phase 5 — the certificates screen's three forms, submitted the way a browser
 * submits them.
 *
 * The screen's forms had never worked from the screen, for the reason the roster's
 * had not (D-143): every existing test posted the array the request wants, and not
 * the fields the page sends.
 *
 *   • the bulk form named its boxes `candidates[]` and carried no cohort, while the
 *     request reads `user_id[]` and `cohort_id`;
 *   • the row button «إصدار الشهادة» was a submit button INSIDE that bulk form, so it
 *     posted the whole form, not the person on its row;
 *   • the override form carried a reason and no cohort, and the request refuses a
 *     request with no cohort;
 *   • a refusal (not eligible, already issued) came back as a validation error that
 *     nothing on the page printed.
 *
 * These read each form out of the page, press one button, and post exactly what a
 * browser would. They decide nothing new: CertificateEligibility, the reason length
 * and the serial are untouched and asserted elsewhere (BR-26).
 *
 * @see BR-25, BR-26 · PRD §9.17 · D-144 · CONSTITUTION art. 5
 */

use App\Models\AuditLog;
use App\Models\Certificate;
use Illuminate\Support\Facades\Auth;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-11-20 12:00:00'));

    $this->cohort = makeCohort(['pass_score' => 60, 'min_attendance_rate' => 75, 'status' => 'completed']);
    $this->admin = makeAdmin();

    $this->first = makeParticipant($this->cohort);
    $this->second = makeParticipant($this->cohort);
    $this->short = makeParticipant($this->cohort);

    // ONE set of sessions, one assignment and one project for the whole cohort — the
    // helpers that add a set per person would dilute everyone else's rate and score.
    $assignment = makeAssignment($this->cohort, ['max_score' => 50]);
    $project = makeFinalProject($this->cohort, ['is_unlocked' => true]);

    $sessions = [];

    for ($i = 0; $i < 8; $i++) {
        $start = riyadhAt('2026-10-05 18:00:00')->addDays($i);
        $sessions[] = sessionInCohort($this->cohort, $start, $start->addHours(3), ['type' => 'training']);
    }

    foreach ([[$this->first, 8, 45.0, 45.0], [$this->second, 8, 45.0, 45.0], [$this->short, 2, 20.0, 10.0]] as [$person, $attended, $work, $final]) {
        foreach ($sessions as $i => $session) {
            makeAttendance($session, $person, $i < $attended ? 'present' : 'absent');
        }

        makeEvaluation('assignment', makeSubmission($assignment, $person)->id, $person, $work);
        makeEvaluation('final_project', makeProjectSubmission($project, $person)->id, $person, $final);
    }
});

/** The certificates screen for this cohort, as HTML. */
function certificatesPage(): string
{
    return test()->actingAs(test()->admin)
        ->get(route('admin.certificates.index', ['cohort' => test()->cohort->id]))
        ->assertOk()
        ->getContent();
}

/**
 * What a browser sends when the person ticks `$ticked` and presses the submit
 * control matched by `$submitter` (an XPath on the whole page).
 *
 * The button's owner is the form named by its `form` attribute, else the form it
 * sits in. Only enabled, named controls of that form count; an unticked checkbox
 * sends nothing; a hidden input always sends; the pressed button sends its own
 * name and value.
 *
 * @param  list<string>  $ticked
 * @return array{0: string, 1: string, 2: array<string, mixed>} action, method, fields
 */
function pressButton(string $html, string $submitter, array $ticked = []): array
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $xpath = new DOMXPath($dom);

    $button = $xpath->query($submitter)->item(0);
    expect($button)->not->toBeNull();

    /** @var DOMElement $button */
    $formId = $button->getAttribute('form');
    $form = $formId !== ''
        ? $dom->getElementById($formId)
        : $xpath->query('ancestor::form[1]', $button)->item(0);

    expect($form)->not->toBeNull();

    /** @var DOMElement $form */
    $controls = [];

    foreach ($xpath->query('.//input[@name]|.//select[@name]|.//textarea[@name]', $form) as $control) {
        $controls[] = $control;
    }

    if ($form->getAttribute('id') !== '') {
        foreach ($xpath->query('//*[@form="'.$form->getAttribute('id').'"][@name][not(self::button)]') as $control) {
            $controls[] = $control;
        }
    }

    $pairs = [];

    foreach ($controls as $control) {
        /** @var DOMElement $control */
        if ($control->hasAttribute('disabled')) {
            continue;
        }

        $type = strtolower($control->getAttribute('type') ?: 'text');

        if ($type === 'checkbox') {
            if (in_array($control->getAttribute('value'), $ticked, true)) {
                $pairs[] = [$control->getAttribute('name'), $control->getAttribute('value')];
            }

            continue;
        }

        $pairs[] = [$control->getAttribute('name'), $control->getAttribute('value') ?: trim($control->textContent)];
    }

    if ($button->getAttribute('name') !== '') {
        $pairs[] = [$button->getAttribute('name'), $button->getAttribute('value')];
    }

    parse_str(implode('&', array_map(
        static fn (array $pair): string => urlencode($pair[0]).'='.urlencode($pair[1]),
        $pairs,
    )), $fields);

    return [$form->getAttribute('action'), strtoupper($form->getAttribute('method') ?: 'GET'), $fields];
}

/** XPath for the bulk submit button of the eligible list. */
const BULK_BUTTON = '//button[@data-action="issue-selected"]';

/** XPath for the row button of one person. */
function rowButton(string $userId): string
{
    return '//button[@data-issue-one="'.$userId.'"]';
}

it('BR-26: النموذج الجماعي كما يرسله المتصفح يُصدر لمن حُدِّد وحده', function (): void {
    [$action, $method, $fields] = pressButton(certificatesPage(), BULK_BUTTON, [$this->first->id]);

    expect($action)->toBe(route('admin.certificates.issueBulk'))
        ->and($method)->toBe('POST')
        ->and($fields['user_id'])->toBe([$this->first->id])
        ->and($fields['cohort_id'])->toBe($this->cohort->id);

    Auth::forgetGuards();

    $this->actingAs($this->admin)->post($action, $fields)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('certificates.bulk_issued', ['count' => 1]));

    expect(Certificate::query()->where('user_id', $this->first->id)->count())->toBe(1)
        ->and(Certificate::query()->where('user_id', $this->second->id)->count())->toBe(0)
        ->and(AuditLog::query()->where('entity_type', 'certificate')->count())->toBe(1);
});

it('BR-26: تحديد اثنين يُصدر لهما ولا يمسّ الثالث', function (): void {
    [$action, , $fields] = pressButton(certificatesPage(), BULK_BUTTON, [$this->first->id, $this->second->id]);

    Auth::forgetGuards();

    $this->actingAs($this->admin)->post($action, $fields)->assertSessionHasNoErrors();

    expect(Certificate::query()->count())->toBe(2)
        ->and(Certificate::query()->where('user_id', $this->short->id)->count())->toBe(0);
});

it('BR-26: الصفحة لا ترسل «0» مرافقًا ولا اسم الحقل القديم candidates[]', function (): void {
    $html = certificatesPage();

    [, , $fields] = pressButton($html, BULK_BUTTON, [$this->first->id]);

    expect($fields['user_id'])->toBe([$this->first->id])
        ->and($fields)->not->toHaveKey('candidates')
        ->and($fields)->not->toHaveKey('single')
        ->and($html)->not->toContain('name="candidates[]"');
});

it('BR-26: زر «إصدار الشهادة» في الصف يُصدر لصاحب الصف وحده ولو حُدِّد غيره', function (): void {
    // The second person is ticked in the bulk form and the button on the FIRST row is
    // pressed: only the first is issued, and the ticked box is not part of that post.
    [$action, $method, $fields] = pressButton(certificatesPage(), rowButton($this->first->id), [$this->second->id]);

    expect($action)->toBe(route('admin.certificates.issue'))
        ->and($method)->toBe('POST')
        ->and($fields['user_id'])->toBe($this->first->id)
        ->and($fields['cohort_id'])->toBe($this->cohort->id)
        ->and($fields)->not->toHaveKey('override');

    Auth::forgetGuards();

    $this->actingAs($this->admin)->post($action, $fields)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('certificates.issued'));

    expect(Certificate::query()->where('user_id', $this->first->id)->count())->toBe(1)
        ->and(Certificate::query()->where('user_id', $this->second->id)->count())->toBe(0);
});

it('BR-26: لكل صف مستوفٍ زر إصدار واحد باسم صاحبه، وزر الدفعة واحد', function (): void {
    $html = certificatesPage();

    expect(substr_count($html, 'data-issue-one="'.$this->first->id.'"'))->toBe(1)
        ->and(substr_count($html, 'data-issue-one="'.$this->second->id.'"'))->toBe(1)
        ->and(substr_count($html, 'data-issue-one="'.$this->short->id.'"'))->toBe(0)
        ->and(substr_count($html, 'data-action="issue-selected"'))->toBe(1);
});

it('BR-26: من لم يستوفِ لا يُصدَر له بالنموذج الجماعي ولو أُرسل معرّفه', function (): void {
    [$action, , $fields] = pressButton(certificatesPage(), BULK_BUTTON, [$this->first->id]);
    $fields['user_id'][] = $this->short->id;

    Auth::forgetGuards();

    $this->actingAs($this->admin)->post($action, $fields)
        ->assertSessionHas('status', __('certificates.bulk_issued', ['count' => 1]));

    expect(Certificate::query()->where('user_id', $this->short->id)->count())->toBe(0);
});

it('BR-26: بلا تحديد يقول الخادم ما العمل، وتعرض الصفحة الرسالة فوق القائمة', function (): void {
    [$action, , $fields] = pressButton(certificatesPage(), BULK_BUTTON, []);

    Auth::forgetGuards();

    $back = route('admin.certificates.index', ['cohort' => $this->cohort->id]);

    $this->actingAs($this->admin)->from($back)->post($action, $fields)
        ->assertRedirect($back)
        ->assertSessionHasErrors('user_id');

    expect(session('errors')->first('user_id'))->toBe(__('certificates.errors.select_first'));

    $page = $this->actingAs($this->admin)->get($back)->assertOk()->getContent();

    expect($page)->toContain(__('certificates.errors.select_first'))
        ->and($page)->toContain('role="alert"');
    expect(Certificate::query()->count())->toBe(0);
});

it('BR-26: رفض الإصدار لمن استوفى ثم أُصدرت له شهادة في تبويب آخر يظهر بنصّه على الصفحة', function (): void {
    $html = certificatesPage();
    [$action, , $fields] = pressButton($html, rowButton($this->first->id));

    issueCertificateFor($this->first, $this->cohort);

    Auth::forgetGuards();

    $back = route('admin.certificates.index', ['cohort' => $this->cohort->id]);

    $this->actingAs($this->admin)->from($back)->post($action, $fields)
        ->assertRedirect($back)
        ->assertSessionHasErrors('user_id');

    $page = $this->actingAs($this->admin)->get($back)->getContent();

    expect($page)->toContain(__('certificates.errors.already_issued'));
    expect(Certificate::query()->where('user_id', $this->first->id)->count())->toBe(1);
});

it('BR-26: نموذج التجاوز اليدوي كما يرسله المتصفح يحمل الدفعة والسبب ويُسجَّل تجاوزًا', function (): void {
    $back = route('admin.certificates.index', ['cohort' => $this->cohort->id, 'override' => $this->short->id]);

    $html = $this->actingAs($this->admin)->get($back)->assertOk()->getContent();

    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $xpath = new DOMXPath($dom);
    $form = $xpath->query('//form[contains(@action, "/override")]')->item(0);

    expect($form)->not->toBeNull();

    /** @var DOMElement $form */
    $names = [];

    foreach ($xpath->query('.//input[@name]|.//textarea[@name]', $form) as $control) {
        /** @var DOMElement $control */
        $names[$control->getAttribute('name')] = $control->getAttribute('value');
    }

    expect($names)->toHaveKey('cohort_id')
        ->and($names['cohort_id'])->toBe($this->cohort->id)
        ->and($names)->toHaveKey('override_reason');

    Auth::forgetGuards();

    $this->actingAs($this->admin)->post($form->getAttribute('action'), [
        'cohort_id' => $names['cohort_id'],
        'override_reason' => 'The trainee finished an approved make-up track offline.',
    ])->assertSessionHasNoErrors()->assertSessionHas('status', __('certificates.issued'));

    expect(Certificate::query()->where('user_id', $this->short->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'certificate.overridden')->count())->toBe(1);
});

it('BR-26: لوحة السحب لا تعلن أن الشهادة سُحبت قبل أن يؤكّد المشرف، وتحمل زر التأكيد', function (): void {
    $certificate = issueCertificateFor($this->first, $this->cohort);

    $html = $this->actingAs($this->admin)
        ->get(route('admin.certificates.index', ['cohort' => $this->cohort->id, 'revoke' => $certificate->id]))
        ->assertOk()
        ->getContent();

    // «سُحبت الشهادة…» is what the screen says AFTER it has been done; before, it says
    // what WILL happen and that a new one can be issued later.
    $panel = substr($html, (int) strpos($html, 'name="revoke_reason"') - 1500, 3000);

    expect($panel)->not->toContain(__('certificates.revoked'))
        ->and($panel)->toContain(__('certificates.admin.revoke_warning'))
        ->and($panel)->toContain(__('certificates.admin.revoke_reason_hint'));
});

it('BR-26: إن لم يُصدَر شيء لأحد ممن حُدِّدوا تقول الرسالة ذلك بدل «عدد الشهادات الجديدة: 0»', function (): void {
    [$action, , $fields] = pressButton(certificatesPage(), BULK_BUTTON, [$this->first->id]);

    // Between the page and the press, someone else issued this person's certificate.
    issueCertificateFor($this->first, $this->cohort);

    Auth::forgetGuards();

    $this->actingAs($this->admin)->post($action, $fields)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', __('certificates.bulk_none'));

    expect(Certificate::query()->where('user_id', $this->first->id)->count())->toBe(1);
});

it('BR-26: رفض التجاوز اليدوي (شهادة قائمة) يظهر على الصفحة، وسبب التجاوز يبقى تحت خانته لا في الملخّص', function (): void {
    issueCertificateFor($this->short, $this->cohort);

    $back = route('admin.certificates.index', ['cohort' => $this->cohort->id, 'override' => $this->short->id]);

    $this->actingAs($this->admin)->from($back)->post(route('admin.certificates.override', $this->short), [
        'cohort_id' => $this->cohort->id,
        'override_reason' => 'short',
    ])->assertSessionHasErrors('override_reason');

    $reasonMessage = (string) session('errors')->first('override_reason');

    $page = $this->actingAs($this->admin)->get($back)->getContent();

    // The reason's own message is printed once, under its box — not again in the summary.
    expect(substr_count($page, $reasonMessage))->toBe(1);

    $this->actingAs($this->admin)->from($back)->post(route('admin.certificates.override', $this->short), [
        'cohort_id' => $this->cohort->id,
        'override_reason' => 'The trainee finished an approved make-up track offline.',
    ])->assertSessionHasErrors('cohort_id');

    $page = $this->actingAs($this->admin)->get($back)->getContent();

    expect($page)->toContain(__('certificates.errors.already_issued'))
        ->and($page)->toContain(__('certificates.admin.form_error_title'));
});

it('BR-26: القائمة الجاهزة للإصدار تحمل اسم صاحب الصف في زرّه وفي مربّعه لقارئ الشاشة', function (): void {
    $html = certificatesPage();

    [$before, $after] = explode(':name', (string) __('certificates.admin.select_candidate', ['name' => ':name']));

    expect($html)->toContain('aria-label="'.__('certificates.admin.issue_one').' — ')
        ->and(substr_count($html, $before) >= 2)->toBeTrue()
        ->and(substr_count($html, $after) >= 2)->toBeTrue();
});
