<?php

declare(strict_types=1);

/**
 * The sidebar rail each role actually receives — `D-30`.
 *
 * This suite exists because of a defect found on the live host, not in review:
 * the component fell back to the PARTICIPANT rail for every role while it
 * waited for a controller to hand it `:groups`, and no controller ever did. An
 * administrator therefore signed in and had no link to programmes, cohorts,
 * users, registrations, certificates, the audit log or settings. Every one of
 * those screens existed and worked; only the road to them was missing.
 *
 * The tests assert the SHAPE of the rail, never the styling: that an admin can
 * reach admin destinations, that a trainer gets trainer ones, that a
 * participant is unaffected, and — the part that actually caught the bug — that
 * every route named in a rail is registered, because `resolveGroups()` drops an
 * unknown route silently and a typo would empty the menu again with no error
 * anywhere.
 *
 * @see CONSTITUTION.md Article 16 · PRD §9.5.1, §9.18, §9.21 · D-30
 */

use App\View\Components\Layout\Sidebar;
use Illuminate\Support\Facades\Route as RouteFacade;

/** Every route name the component can put in a rail, for the registration check. */
function railRouteNames(): array
{
    $sidebar = new Sidebar;

    $names = [];

    foreach (['participantGroups', 'trainerGroups', 'adminGroups'] as $method) {
        $reflection = new ReflectionMethod(Sidebar::class, $method);
        $reflection->setAccessible(true);

        foreach ($reflection->invoke($sidebar) as $group) {
            foreach ($group['items'] ?? [] as $item) {
                if (isset($item['route']) && is_string($item['route'])) {
                    $names[] = $item['route'];
                }
            }
        }
    }

    return array_values(array_unique($names));
}

/** The resolved hrefs a signed-in user would see in their rail. */
function railHrefsFor(?App\Models\User $user): array
{
    if ($user !== null) {
        test()->actingAs($user);
    }

    $hrefs = [];

    foreach ((new Sidebar)->resolvedGroups as $group) {
        foreach ($group['items'] as $item) {
            $hrefs[] = $item['href'];
        }
    }

    return $hrefs;
}

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 12:00:00'));
});

it('D-30: كل مسار تذكره القائمة الجانبية مسجَّل فعلًا في الراوتر', function (): void {
    // resolveGroups() drops an unregistered route silently and fails safe. That
    // is right at run time and blinding at review time: a renamed route empties
    // a menu and nothing anywhere reports it. This test is that report.
    $missing = array_values(array_filter(
        railRouteNames(),
        static fn (string $name): bool => ! RouteFacade::has($name),
    ));

    expect($missing)->toBe([]);
});

it('D-30: المدير يرى وجهات الإدارة لا قائمة المتدرّب', function (): void {
    $hrefs = railHrefsFor(makeAdmin());

    expect($hrefs)->toContain(route('admin.programs.index'))
        ->and($hrefs)->toContain(route('admin.cohorts.index'))
        ->and($hrefs)->toContain(route('admin.users.index'))
        ->and($hrefs)->toContain(route('admin.certificates.index'))
        ->and($hrefs)->toContain(route('admin.audit.index'))
        ->and($hrefs)->toContain(route('admin.settings.edit'))
        // The exact symptom that was reported: the participant rail served to
        // an administrator.
        ->and($hrefs)->not->toContain(route('participant.journey'));
});

it('D-30: المدرّب يرى وجهات المدرّب', function (): void {
    $cohort = makeCohort();
    $hrefs = railHrefsFor(makeTrainer($cohort));

    expect($hrefs)->toContain(route('trainer.participants'))
        ->and($hrefs)->toContain(route('trainer.sessions'))
        ->and($hrefs)->toContain(route('trainer.submissions'))
        ->and($hrefs)->toContain(route('trainer.reports'))
        ->and($hrefs)->not->toContain(route('admin.settings.edit'));
});

it('D-30: المتدرّب لم تتغيّر قائمته', function (): void {
    $cohort = makeCohort();
    $hrefs = railHrefsFor(makeParticipant($cohort));

    expect($hrefs)->toContain(route('dashboard'))
        ->and($hrefs)->toContain(route('participant.journey'))
        ->and($hrefs)->toContain(route('schedule'))
        ->and($hrefs)->toContain(route('grades'))
        ->and($hrefs)->not->toContain(route('admin.users.index'));
});

it('D-30: أسبقية الأدوار تطابق ما يفعله DashboardController', function (): void {
    // An account that is both a trainer and a participant is sent to the
    // participant dashboard by DashboardController, so it must get the
    // participant rail. Two different answers to "which role am I" would be
    // worse than either answer alone.
    // `enrollments` is unique on (cohort_id, user_id), so one account cannot hold
    // two roles inside a single cohort — the dual-role case only exists ACROSS
    // cohorts, and that is what RoleResolver::effectiveRoles() reads. Enrolling
    // twice in the same cohort tested a row the schema forbids and died on the
    // constraint before it ever reached the rail.
    $trainerCohort = makeCohort();
    $participantCohort = makeCohort();

    $user = makeTrainer($trainerCohort);
    enroll($user, $participantCohort, 'participant');

    $hrefs = railHrefsFor($user->fresh());

    expect($hrefs)->toContain(route('participant.journey'));
});

it('المادة 16: كل أيقونة تذكرها القائمة موجودة في مجموعة الرموز', function (): void {
    // A missing sprite id renders an empty square and throws nothing. `i-home`
    // was referenced by the participant rail from the first commit and never
    // existed.
    $sprite = (string) file_get_contents(base_path('resources/views/partials/icon-sprite.blade.php'));
    $sidebar = new Sidebar;

    $missing = [];

    foreach (['participantGroups', 'trainerGroups', 'adminGroups'] as $method) {
        $reflection = new ReflectionMethod(Sidebar::class, $method);
        $reflection->setAccessible(true);

        foreach ($reflection->invoke($sidebar) as $group) {
            foreach ($group['items'] ?? [] as $item) {
                $icon = $item['icon'] ?? null;

                if (is_string($icon) && ! str_contains($sprite, 'id="'.$icon.'"')) {
                    $missing[] = $icon;
                }
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});
