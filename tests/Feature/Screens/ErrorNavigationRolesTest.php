<?php

declare(strict_types=1);

/**
 * Where an error page sends each role (D-127, phase 1).
 *
 * The 403, 404 and 405 pages used to suggest the TRAINEE's destinations — the
 * schedule, attendance, assignments — to every signed-in role but the system
 * administrator: a supervisor who mistyped an address was offered "your
 * assignments". Each role is now offered its own — on the pages that know who is
 * asking: a refusal (403) and a missing record behind a real route (404). An address
 * that matches no route is D-134 and is not covered here. Nothing about existence leaks:
 * the list differs by the account's OWN role only, never by the record asked for
 * (BR-22, BR-23), and it asks the database for nothing — an error page may be
 * rendering because the database is the thing that failed (art. 7).
 *
 * @see D-127, D-117 · PRD §8 · BR-22, BR-23 · CONSTITUTION Articles 7, 22
 */
beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    $this->cohort = makeCohort();
});

/**
 * The 404 a signed-in user gets from a REAL route whose record is missing (model
 * binding inside a route that carries the session), through a real session login
 * — not `actingAs`, which pre-loads the user and so also "works" on an address no
 * route matches. That other case is D-134: Laravel starts no session for an
 * address that matches nothing, so the page cannot know who is asking.
 */
function missingPageFor(App\Models\User $user): string
{
    // One test makes several requests in one application; the guard would keep the
    // previous request's user. A real request starts with none.
    Illuminate\Support\Facades\Auth::forgetGuards();

    $absent = '00000000-0000-0000-0000-000000000000';
    $url = $user->role === App\Enums\UserRole::SystemAdmin ? route('admin.users.show', $absent) : route('assignments.show', $absent);

    return test()
        ->withSession(['login_web_'.sha1(Illuminate\Auth\SessionGuard::class) => $user->getKey()])
        ->get($url)
        ->assertNotFound()
        ->getContent();
}

it('D-127: صفحة 404 تقترح على كل دور وجهاته هو', function (): void {
    $cases = [
        // role => [user, links it must offer, links that belong to another role and must not appear]
        'admin' => [makeAdmin(), ['admin.dashboard', 'admin.cohorts.index', 'messages.index', 'profile'], ['assignments.index', 'attendance.index', 'resources.index']],
        'trainer' => [makeTrainer($this->cohort), ['trainer.dashboard', 'trainer.attendance', 'trainer.submissions', 'messages.index', 'profile'], ['assignments.index', 'attendance.index', 'resources.index']],
        'coordinator' => [makeCoordinator($this->cohort), ['coordinator.dashboard', 'trainer.sessions', 'trainer.attendance', 'messages.index', 'profile'], ['assignments.index', 'attendance.index', 'resources.index']],
        'system_admin' => [makeSystemAdmin(), ['admin.users.index', 'admin.landing.edit', 'admin.settings.edit', 'messages.index', 'profile'], ['assignments.index', 'attendance.index', 'schedule']],
        // The trainee's own list is unchanged.
        'participant' => [makeParticipant($this->cohort), ['dashboard', 'schedule', 'attendance.index', 'assignments.index', 'resources.index', 'messages.index'], ['admin.dashboard', 'trainer.dashboard']],
    ];

    $wrong = [];

    foreach ($cases as $role => [$user, $offered, $foreign]) {
        $html = missingPageFor($user);

        // Only the suggestion list is inspected, not the shell around it.
        preg_match('/<nav class="ui-errorpage__links"[^>]*>(.*?)<\/nav>/s', $html, $list);
        $links = $list[1] ?? '';

        if ($links === '') {
            $wrong[] = "{$role}: the page has no suggestion list";

            continue;
        }

        foreach ($offered as $name) {
            if (! str_contains($links, 'href="'.route($name).'"')) {
                $wrong[] = "{$role}: should be offered {$name}";
            }
        }

        foreach ($foreign as $name) {
            if (str_contains($links, 'href="'.route($name).'"')) {
                $wrong[] = "{$role}: must not be offered {$name}";
            }
        }

        $this->flushSession();
    }

    expect($wrong)->toBe([]);
});

it('D-127: صفحة 403 لغير المتدرب تقترح وجهاته لا وجهات المتدرب', function (): void {
    // A trainer asking for a system-administrator page is refused, and offered the trainer's own way back.
    $html = $this->actingAs(makeTrainer($this->cohort))->get(route('admin.roles.index'))->assertForbidden()->getContent();

    preg_match('/<nav class="ui-errorpage__links"[^>]*>(.*?)<\/nav>/s', $html, $list);

    expect($list[1] ?? '')->toContain('href="'.route('trainer.dashboard').'"')
        ->and($list[1] ?? '')->not->toContain('href="'.route('schedule').'"');
});

it('D-127: قائمة الاقتراح لا تسأل قاعدة البيانات — صفحة الخطأ قد تُرسم لأن القاعدة هي التي فشلت', function (): void {
    $user = makeTrainer($this->cohort);
    $this->actingAs($user);

    // The account is already loaded; the suggestions must read its role from it and query nothing.
    DB::enableQueryLog();
    App\Support\ErrorNavigation::suggestions('404');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBe([]);
});
