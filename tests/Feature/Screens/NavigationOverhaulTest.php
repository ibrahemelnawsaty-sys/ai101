<?php

declare(strict_types=1);

/**
 * The rails and the names, phase 1 of the UI overhaul (D-127, D-133).
 *
 * The owner decided, in direct questions on 28 and 29 September 2026: one name
 * per screen in every role; the participant rail grouped under headings, ordered
 * by importance, with «الشهادة» added and the public home page last; the
 * supervisor rail regrouped under «إدارة الدفعة»; and the rail item lit for every
 * page that lives under it, not only for its own.
 *
 * None of this moves a permission. Every destination a role had it still has —
 * pinned below as a set, so a regrouping can never quietly lose a road — and the
 * only addition is the participant's certificate. Whatever a rail shows, the
 * server still decides who may open it (art. 5).
 *
 * @see D-127, D-133 · PRD §9.5.1 · CONSTITUTION Articles 5, 16, 18
 */

use App\Models\SupportTicket;
use App\View\Components\Layout\Sidebar;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

/** The five rails as [method => role label], for the structural checks. */
const RAIL_METHODS = [
    'participantGroups' => 'participant',
    'trainerGroups' => 'trainer',
    'coordinatorGroups' => 'coordinator',
    'adminGroups' => 'admin',
    'systemAdminGroups' => 'system_admin',
];

/** The raw (unresolved) groups of one rail — what the class declares, before routes are checked. */
function declaredGroups(string $method): array
{
    $reflection = new ReflectionMethod(Sidebar::class, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke(new Sidebar);
}

/**
 * The rail a signed-in user receives, as [label-or-null, [route names]] per group.
 *
 * @return list<array{0: ?string, 1: list<string>}>
 */
function railShapeFor(App\Models\User $user): array
{
    test()->actingAs($user);

    $shape = [];

    foreach ((new Sidebar)->resolvedGroups as $group) {
        $shape[] = [$group['label'] ?? null, array_map(static fn (array $item): string => (string) $item['route'], $group['items'])];
    }

    return $shape;
}

/** Every route name in a rail, flat. */
function railRoutesFor(App\Models\User $user): array
{
    return array_merge(...array_map(static fn (array $group): array => $group[1], railShapeFor($user)));
}

/** The hrefs the rail lights as the current page, from the rendered HTML (rail and drawer both draw it). */
function litRailHrefs(string $html): array
{
    preg_match_all('/<a\s+href="([^"]+)"\s+class="side__b"[^>]*aria-current="(?:page|true)"/s', $html, $matches);

    return array_values(array_unique($matches[1]));
}

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));
});

it('D-127: الاسم الواحد للمفهوم الواحد في كل الأدوار', function (): void {
    // «التواصل الداخلي» — للجميع.
    expect([__('nav.messages'), __('nav.participant.messages'), __('nav.admin.messages'), __('nav.admin.contact')])
        ->each->toBe('التواصل الداخلي');

    // «رصد الحضور» — للمدرب والمنسّق والمشرف، في القائمة وفي عنوان الشاشة.
    expect([__('nav.trainer.attendance'), __('nav.coordinator.attendance'), __('trainer.attendance.title')])
        ->each->toBe('رصد الحضور');

    // «الدعم الفني» — في كل مكان.
    expect([__('nav.support'), __('nav.support_tickets'), __('support.title')])->each->toBe('الدعم الفني');

    // «التسليمات والتصحيح» — في القائمة وفي عنوان الشاشة.
    expect([__('nav.trainer.submissions'), __('trainer.submissions.title')])->each->toBe('التسليمات والتصحيح');

    // «متدرب» بدل «مشارك».
    expect([__('enums.user_role.participant'), __('enums.enrollment_role.participant')])->each->toBe('متدرب');
});

it('D-127: لا تبقى كلمة «مشارك» في أي نصّ عربي، مسبوقةً بحرف أو لا (وتبقى «مشاركة» بمعنى Share)', function (): void {
    $found = [];

    // The Arabic the platform shows: its lang files AND the content it seeds (landing page, schedule).
    $files = [...File::allFiles(lang_path('ar')), ...File::allFiles(database_path('seeders/data'))];

    foreach ($files as $file) {
        foreach (explode("\n", (string) File::get($file->getPathname())) as $index => $line) {
            // «المشارك» «للمشارك» «والمشاركين» «مشاركًا» — but not «مشاركة» (a letter follows).
            if (preg_match('/مشارك(?:ين|ون)?(?![\p{L}])/u', $line) === 1) {
                $found[] = $file->getFilename().':'.($index + 1);
            }
        }
    }

    expect($found)->toBe([]);
});

it('D-127: قائمة المتدرب — عناوين المجموعات والترتيب و«الشهادة» ثم «الصفحة الرئيسية» أخيرًا', function (): void {
    $shape = railShapeFor(makeParticipant(makeCohort()));

    expect($shape)->toBe([
        [__('nav.groups.overview'), ['dashboard', 'participant.journey', 'participant.card']],
        [__('nav.groups.program'), ['live', 'schedule', 'attendance.index']],
        [__('nav.groups.work'), ['assignments.index', 'finalProject', 'grades', 'certificate']],
        [__('nav.groups.contact_materials'), ['messages.index', 'resources.index']],
        [null, ['home']],
    ]);
});

it('D-127: قائمة المشرف العام — مجموعة «إدارة الدفعة» ولا وجهة ضائعة', function (): void {
    $shape = railShapeFor(makeAdmin());

    expect($shape)->toBe([
        [null, ['admin.dashboard']],
        [__('nav.groups.program'), ['admin.programs.index', 'admin.cohorts.index']],
        [__('nav.groups.cohort_admin'), ['admin.registrations.index', 'admin.certificates.index', 'admin.finalProject.index', 'admin.broadcasts.index']],
        [__('nav.groups.monitoring'), ['admin.reports.index', 'admin.audit.index']],
        [__('nav.groups.communication'), ['messages.index', 'support.index']],
    ]);

    expect(__('nav.groups.cohort_admin'))->toBe('إدارة الدفعة');
});

it('D-127: لكل دور مجموعة الوجهات نفسها قبل التعديل وبعده — عدا ما أُضيف عمدًا', function (): void {
    $cohort = makeCohort();

    $expected = [
        // + certificate: the only addition of the whole phase.
        'participant' => [makeParticipant($cohort), ['home', 'dashboard', 'participant.card', 'participant.journey', 'schedule', 'attendance.index', 'live', 'assignments.index', 'resources.index', 'finalProject', 'grades', 'messages.index', 'certificate']],
        'admin' => [makeAdmin(), ['admin.dashboard', 'admin.programs.index', 'admin.cohorts.index', 'admin.registrations.index', 'admin.certificates.index', 'admin.broadcasts.index', 'admin.finalProject.index', 'admin.reports.index', 'admin.audit.index', 'messages.index', 'support.index']],
        'trainer' => [makeTrainer($cohort), ['trainer.dashboard', 'trainer.participants', 'trainer.sessions', 'trainer.attendance', 'trainer.resources', 'trainer.assignments', 'trainer.submissions', 'trainer.finalProject', 'trainer.reports', 'messages.index']],
        'coordinator' => [makeCoordinator($cohort), ['coordinator.dashboard', 'trainer.sessions', 'trainer.attendance', 'messages.index', 'support.index']],
        // + admin.roles.index — D-133, the system administrator alone.
        'system_admin' => [makeSystemAdmin(), ['admin.users.index', 'admin.roles.index', 'admin.landing.edit', 'admin.settings.edit', 'messages.index', 'support.index']],
    ];

    $wrong = [];

    foreach ($expected as $role => [$user, $routes]) {
        $actual = railRoutesFor($user);

        if (array_diff($routes, $actual) !== [] || array_diff($actual, $routes) !== []) {
            $wrong[] = sprintf('%s: lost [%s] gained [%s]', $role, implode(', ', array_diff($routes, $actual)), implode(', ', array_diff($actual, $routes)));
        }
    }

    expect($wrong)->toBe([]);
});

it('D-127: عنوان كل مجموعة مربوط بها برمجيًّا لقارئ الشاشة', function (): void {
    test()->actingAs(makeParticipant(makeCohort()));

    $html = Blade::render('<x-layout.sidebar />');

    // A heading that is only a <p> is not a heading a screen reader can find a
    // group by. Each labelled group is a named group of its own.
    expect($html)->toContain('role="group"')
        ->and($html)->toContain('aria-labelledby=');
});

it('D-127: كل صفحة داخل القشرة تُضيء عنصر قائمتها', function (): void {
    $cohort = makeCohort();
    $participant = makeParticipant($cohort);
    $assignment = makeAssignment($cohort, ['max_score' => 10]);
    $coordinator = makeCoordinator($cohort);
    $ticket = SupportTicket::factory()->create([
        'opener_id' => $participant->id,
        'cohort_id' => $cohort->id,
        'assignee_id' => $coordinator->id,
        'level' => 'coordinator',
        'status' => 'open',
    ]);
    $account = makeParticipant($cohort);

    $cases = [
        // [who, page, the rail item that must be the one lit]
        'assignments.show' => [$participant, route('assignments.show', $assignment), route('assignments.index')],
        'messages.create' => [$participant, route('messages.create'), route('messages.index')],
        // A trainee reaches support from the account menu, so the rail has no item
        // for them to light — and nothing else may be lit in its place.
        'support.create (trainee: no rail item)' => [$participant, route('support.create'), null],
        // The coordinator's rail does list it: the ticket page lights it.
        'support.show (coordinator)' => [$coordinator, route('support.show', $ticket), route('support.index')],
        'admin.users.show' => [makeSystemAdmin(), route('admin.users.show', $account), route('admin.users.index')],
        'admin.users.create' => [makeSystemAdmin(), route('admin.users.create'), route('admin.users.index')],
        'admin.users.import' => [makeSystemAdmin(), route('admin.users.import'), route('admin.users.index')],
        // A page with its own item still lights that one, and only it.
        'admin.cohorts.index' => [makeAdmin(), route('admin.cohorts.index'), route('admin.cohorts.index')],
    ];

    $wrong = [];

    foreach ($cases as $name => [$user, $url, $lit]) {
        $response = $this->actingAs($user)->get($url);

        if ($response->status() !== 200) {
            $wrong[] = "{$name}: answered {$response->status()}";
        } elseif (litRailHrefs($response->getContent()) !== array_filter([$lit])) {
            $wrong[] = sprintf('%s: lit [%s], expected [%s]', $name, implode(', ', litRailHrefs($response->getContent())), (string) $lit);
        }

        $this->flushSession();
    }

    expect($wrong)->toBe([]);
});

/**
 * GET routes under a rail item's name that are NOT pages inside the shell:
 * exports, downloads, calendars, polls, signed links, the print sheets that use
 * the bare layout, a stream. Every other GET route under an item must be named in
 * that item's `also` list — so a new screen cannot ship with its rail item dark.
 */
const NOT_A_SHELL_PAGE = [
    'schedule.ics', 'schedule.pdf', 'schedule.session.ics',
    'attendance.export', 'attendance.selfCheckIn',
    'grades.export',
    'messages.poll',
    'resources.download', 'resources.preview',
    'participant.card.print',
    'certificate.verify', 'certificate.tvtc', 'certificate.download', 'certificate.print',
    'admin.registrations.export', 'admin.certificates.export', 'admin.reports.export', 'admin.audit.export',
    'admin.users.export', 'admin.users.import.template', 'admin.users.import.templateCsv',
    'trainer.participants.export', 'trainer.attendance.export', 'trainer.attendance.checkinCode', 'trainer.attendance.poll',
    'trainer.submissions.bulkDownload', 'trainer.submissions.export', 'trainer.reports.export',
];

it('D-127: كل مسار GET تحت عنصر قائمة إمّا يُضيئه أو مصنَّف «ليس صفحة»', function (): void {
    $getRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => in_array('GET', $route->methods(), true) && $route->getName() !== null)
        ->map(fn ($route): string => (string) $route->getName())
        ->values();

    $unclassified = [];

    foreach (array_keys(RAIL_METHODS) as $method) {
        foreach (declaredGroups($method) as $group) {
            foreach ($group['items'] as $item) {
                $name = (string) $item['route'];
                $base = str_ends_with($name, '.index') ? substr($name, 0, -6) : $name;
                $also = $item['also'] ?? [];

                foreach ($getRoutes as $candidate) {
                    if ($candidate !== $name && str_starts_with($candidate, $base.'.') && ! in_array($candidate, $also, true) && ! in_array($candidate, NOT_A_SHELL_PAGE, true)) {
                        $unclassified[] = "{$name} → {$candidate}";
                    }
                }
            }
        }
    }

    expect(array_values(array_unique($unclassified)))->toBe([]);
});

it('D-127: كل مسار في قائمة جانبية أو في «also» مسجَّل فعلًا في الراوتر', function (): void {
    $missing = [];

    foreach (array_keys(RAIL_METHODS) as $method) {
        foreach (declaredGroups($method) as $group) {
            foreach ($group['items'] as $item) {
                foreach ([(string) $item['route'], ...($item['also'] ?? [])] as $name) {
                    if (! Route::has($name)) {
                        $missing[] = "{$method}: {$name}";
                    }
                }
            }
        }
    }

    expect($missing)->toBe([]);
});

it('D-127: عنوان مجموعة القائمة بلون مقروء لا بالرمادي الزخرفي (2.78:1)', function (): void {
    preg_match('/\.side__t \{(.*?)\}/s', (string) File::get(resource_path('css/app.css')), $rule);

    expect($rule[1] ?? '')->toContain('color: var(--text-muted)')
        ->and($rule[1] ?? '')->not->toContain('--text-faint');
});

it('D-127: الصفحة نفسها aria-current="page"، والقسم الذي تقع تحته aria-current="true"، ولكليهما مظهر الحالي', function (): void {
    $cohort = makeCohort();
    $participant = makeParticipant($cohort);
    $assignment = makeAssignment($cohort, ['max_score' => 10]);

    $current = static function (string $html): array {
        preg_match_all('/<a\s+href="([^"]+)"\s+class="side__b"[^>]*aria-current="([^"]+)"/s', $html, $m, PREG_SET_ORDER);

        return array_values(array_unique(array_map(static fn (array $row): string => $row[2], $m)));
    };

    expect($current($this->actingAs($participant)->get(route('assignments.index'))->getContent()))->toBe(['page']);
    $this->flushSession();
    expect($current($this->actingAs($participant)->get(route('assignments.show', $assignment))->getContent()))->toBe(['true']);

    // The shell paints both the same way (and keeps the forced-colours reset).
    $css = (string) File::get(resource_path('css/app.css'));

    expect($css)->toContain('.side__b[aria-current="true"]::before,')
        ->and($css)->toContain('.side__b[aria-current="true"],');
});
