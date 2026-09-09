<?php

declare(strict_types=1);

/**
 * The trainers section names whoever is enrolled to teach the cohort.
 *
 * WHY THIS SUITE EXISTS
 * `landingContent()` returned `'items' => []` written into the method, with a
 * comment explaining that trainers "are not wired to the enrolment table yet".
 * The template hid the whole section while the list was empty — so the section
 * could never appear, and no action any administrator could take would make it.
 * PRD §9.1.1 requires it.
 *
 * Two things are asserted together here because the second is what makes the
 * first safe: the section shows the trainers, and it shows ONLY the public half
 * of a profile. A page that lists staff must not become a way to read their
 * e-mail address or telephone number (BR-25).
 *
 * @see BR-25, BR-31 · PRD §9.1.1 · CONSTITUTION.md Article 17
 */

use App\Models\Profile;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 12:00:00'));

    $this->cohort = makeCohort(['capacity' => 30]);
});

/** A trainer on the cohort, with whatever profile facts the centre has. */
function trainerWith(array $profile): void
{
    $trainer = makeTrainer(test()->cohort);

    Profile::factory()->create(array_merge([
        'user_id' => $trainer->id,
        'first_name_ar' => 'TRAINERFIRST',
        'second_name_ar' => '',
        'third_name_ar' => '',
        'last_name_ar' => 'TRAINERLAST',
    ], $profile));
}

it('PRD §9.1.1: قسم المدرّبين يعرض من يدرّس الدفعة', function (): void {
    trainerWith(['job_title' => 'TRAINER-JOB-TITLE', 'bio' => 'TRAINER-BIOGRAPHY']);

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->toContain('id="trainers"')
        ->and($body)->toContain('TRAINERFIRST TRAINERLAST')
        ->and($body)->toContain('TRAINER-JOB-TITLE')
        ->and($body)->toContain('TRAINER-BIOGRAPHY');
});

it('BR-25: القسم لا يكشف بريد المدرّب ولا هاتفه ولا اسمه الرباعي', function (): void {
    $trainer = makeTrainer($this->cohort);

    Profile::factory()->create([
        'user_id' => $trainer->id,
        'first_name_ar' => 'TRAINERFIRST',
        'second_name_ar' => 'CANARYSECOND',
        'third_name_ar' => 'CANARYTHIRD',
        'last_name_ar' => 'TRAINERLAST',
        'phone' => '0555555555',
    ]);

    $body = $this->get(route('home'))->assertOk()->getContent();

    // The pair the public verification pages already use, and nothing more.
    // The centre's OWN contact address is on this page by design, so the check
    // is against this trainer's address rather than the domain.
    expect($body)->toContain('TRAINERFIRST TRAINERLAST')
        ->and($body)->not->toContain('CANARYSECOND')
        ->and($body)->not->toContain('CANARYTHIRD')
        ->and($body)->not->toContain('0555555555')
        ->and($body)->not->toContain((string) $trainer->email);
});

it('المادة 17: مدرّب بلا مسمّى ولا نبذة يظهر باسمه بلا عنصر فارغ', function (): void {
    // The centre has entered nothing but the name. That is a card with a name,
    // not a card with an empty line under it.
    trainerWith(['job_title' => null, 'bio' => null, 'avatar_url' => null]);

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect(preg_match('/<section[^>]*id="trainers".*?<\/section>/s', $body, $section))->toBe(1);

    expect($section[0])->toContain('TRAINERFIRST TRAINERLAST')
        ->and($section[0])->not->toMatch('/<span>\s*<\/span>/')
        ->and($section[0])->not->toMatch('/<p>\s*<\/p>/')
        // No picture means the monogram, not a broken image.
        ->and($section[0])->not->toContain('<img')
        ->and($section[0])->toContain('trainer__av');
});

it('المادة 17: دفعة بلا مدرّبين تُخفي القسم بدل عرضه فارغًا', function (): void {
    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->not->toContain('id="trainers"');
});

it('BR-31: المتدرّبون لا يظهرون في قسم المدرّبين', function (): void {
    trainerWith(['job_title' => 'TRAINER-JOB-TITLE']);

    $participant = makeParticipant($this->cohort);

    Profile::factory()->create([
        'user_id' => $participant->id,
        'first_name_ar' => 'PARTICIPANTFIRST',
        'last_name_ar' => 'PARTICIPANTLAST',
    ]);

    $body = $this->get(route('home'))->assertOk()->getContent();

    expect($body)->toContain('TRAINERFIRST TRAINERLAST')
        ->and($body)->not->toContain('PARTICIPANTFIRST');
});
