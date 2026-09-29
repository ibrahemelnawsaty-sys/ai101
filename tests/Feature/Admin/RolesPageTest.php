<?php

declare(strict_types=1);

/**
 * The «الأدوار والصلاحيات» page and the confirmation before a role changes (D-133).
 *
 * The page EXPLAINS; it grants nothing and changes nothing. What it says about who
 * may do what is read from the router — the `role:` middleware each screen is
 * behind — never typed in a second time, so it cannot drift from what the server
 * actually enforces. The system administrator alone may open it (the owner's
 * answer, 29 September 2026): the role changes are theirs (D-117), so the
 * explanation sits where the decision is taken.
 *
 * The confirmation is a courtesy in front of the same form. The server still
 * requires the written reason, still audits, still refuses to demote the last
 * supervisor (BR-32) — none of that moved.
 *
 * @see D-133, D-117, D-127 · PRD §4 · BR-32, BR-33 · CONSTITUTION Articles 5, 18, 22
 */

use App\Enums\UserRole;
use App\Support\RoleCapabilities;

uses()->group('authz');

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    $this->cohort = makeCohort();
    $this->sysadmin = makeSystemAdmin();
});

it('D-133: صفحة الأدوار تعرض الأدوار الخمسة ووصف كل منها ونطاقه', function (): void {
    $html = html_entity_decode(
        $this->actingAs($this->sysadmin)->get(route('admin.roles.index'))->assertOk()->getContent(),
        ENT_QUOTES,
    );

    expect(UserRole::cases())->toHaveCount(5);

    foreach (UserRole::cases() as $role) {
        expect($html)->toContain(__('enums.user_role.'.$role->value))
            ->and($html)->toContain(__('roles.roles.'.$role->value.'.summary'))
            ->and($html)->toContain(__('roles.roles.'.$role->value.'.scope'));
    }
});

it('D-133: كل قدرة في الصفحة خلف وسيط دور في الراوتر، وأدوارها أدوار حقيقية', function (): void {
    $valid = array_map(static fn (UserRole $role): string => $role->value, UserRole::cases());
    $wrong = [];

    foreach (RoleCapabilities::matrix() as $row) {
        if ($row['roles'] === []) {
            $wrong[] = "{$row['key']}: the route carries no role: middleware, so the page could say nothing true about it";
        }

        if (array_diff($row['roles'], $valid) !== []) {
            $wrong[] = "{$row['key']}: unknown role in [".implode(', ', $row['roles']).']';
        }
    }

    expect($wrong)->toBe([]);
});

it('D-133: ما تقوله الصفحة عن الأدوار يطابق الراوتر في أحكام لا يجوز أن تنحرف', function (): void {
    $rolesOf = static fn (string $key): array => collect(RoleCapabilities::matrix())->firstWhere('key', $key)['roles'];
    $sorted = static function (array $roles): array {
        sort($roles);

        return $roles;
    };

    // The mark is the trainer's professional judgement alone (PRD §4.2).
    expect($sorted($rolesOf('grade_submissions')))->toBe(['trainer'])
        // Only the system administrator changes a role, previews an account, edits the landing page.
        ->and($sorted($rolesOf('change_roles')))->toBe(['system_admin'])
        ->and($sorted($rolesOf('preview_accounts')))->toBe(['system_admin'])
        // D-109 — the schedule is written by the supervisor and the coordinator; the trainer only reads it.
        ->and($sorted($rolesOf('write_schedule')))->toBe(['admin', 'coordinator'])
        ->and($sorted($rolesOf('read_sessions')))->toContain('trainer')
        // D-118 — every role converses.
        ->and($sorted($rolesOf('internal_messaging')))->toBe(['admin', 'coordinator', 'participant', 'system_admin', 'trainer'])
        // D-124 — a trainer takes no part in support tickets.
        ->and($rolesOf('support_area'))->not->toContain('trainer')
        // Trainees alone submit work and check themselves in.
        ->and($sorted($rolesOf('submit_assignments')))->toBe(['participant'])
        ->and($sorted($rolesOf('check_in_self')))->toBe(['participant']);
});

it('D-133: ما تنصّ عليه صفحة الأدوار من تضييق لا يتجاوز ما يسمح به الراوتر', function (): void {
    $beyond = [];

    foreach (RoleCapabilities::narrowed() as $key => $narrowing) {
        $extra = array_diff($narrowing['only'], RoleCapabilities::routerRoles($narrowing['route']));

        if ($extra !== []) {
            $beyond[] = "{$key}: names [".implode(', ', $extra).'] which the route does not admit';
        }
    }

    expect($beyond)->toBe([])->and(RoleCapabilities::narrowed())->not->toBeEmpty();
});

it('D-133: كل سطر في صفحة الأدوار يطابق مصفوفة التفويض G9 — التي يثبت G9 نفسه أنها ما يفعله الخادم', function (): void {
    // The matrix lives in RouteAuthorizationTest, proven against the server on every
    // run. Reading it here (not copying it) means a role that changes in one place
    // and not the other fails a test instead of misleading a reader.
    $source = (string) file_get_contents(base_path('tests/Feature/Authorization/RouteAuthorizationTest.php'));

    preg_match_all("/^\s*'([A-Za-z0-9_.\-]+)' => \['(?:get|post|put|patch|delete)',.*?\[([^\]]*)\]\],?\s*$/m", $source, $matches, PREG_SET_ORDER);

    $matrix = [];

    foreach ($matches as $match) {
        $roles = array_values(array_filter(array_map(static fn (string $role): string => trim($role, " '"), explode(',', $match[2]))));
        sort($roles);
        $matrix[$match[1]] = $roles;
    }

    // If the file's layout ever changes, fail here rather than compare against nothing.
    expect(count($matrix))->toBeGreaterThan(100);

    $wrong = [];

    foreach (RoleCapabilities::matrix() as $row) {
        $expected = $matrix[$row['route']] ?? null;
        $actual = $row['roles'];
        sort($actual);

        if ($expected === null) {
            $wrong[] = "{$row['key']} ({$row['route']}): has no row in the authorisation matrix";
        } elseif ($actual !== $expected) {
            $wrong[] = sprintf('%s (%s): page says [%s], matrix says [%s]', $row['key'], $row['route'], implode(', ', $actual), implode(', ', $expected));
        }
    }

    expect($wrong)->toBe([]);
});

it('D-133: لكل قدرة نص ومنطقة بالعربية والإنجليزية', function (): void {
    $missing = [];

    foreach (['ar', 'en'] as $locale) {
        $lang = (array) require lang_path("{$locale}/roles.php");

        foreach (RoleCapabilities::matrix() as $row) {
            if (! is_string($lang['capabilities'][$row['key']] ?? null) || $lang['capabilities'][$row['key']] === '') {
                $missing[] = "{$locale}: capability {$row['key']}";
            }

            if (! is_string($lang['areas'][$row['area']] ?? null)) {
                $missing[] = "{$locale}: area {$row['area']}";
            }
        }

        foreach (UserRole::cases() as $role) {
            foreach (['summary', 'scope'] as $field) {
                if (! is_string($lang['roles'][$role->value][$field] ?? null)) {
                    $missing[] = "{$locale}: role {$role->value} {$field}";
                }
            }
        }
    }

    expect($missing)->toBe([]);
});

it('D-133: «نعم» و«لا» مكتوبتان لقارئ الشاشة في كل خانة، لا باللون وحده', function (): void {
    $html = $this->actingAs($this->sysadmin)->get(route('admin.roles.index'))->getContent();

    $cells = count(RoleCapabilities::matrix()) * count(UserRole::cases());
    $answered = substr_count($html, '<span class="sr">'.__('roles.matrix.yes').'</span>')
        + substr_count($html, '<span class="sr">'.__('roles.matrix.no').'</span>');

    expect($answered)->toBe($cells);
});

it('D-133: 403 لكل دور غير مدير النظام، والزائر يُحوَّل إلى الدخول', function (): void {
    foreach ([makeAdmin(), makeTrainer($this->cohort), makeCoordinator($this->cohort), makeParticipant($this->cohort)] as $actor) {
        $this->actingAs($actor)->get(route('admin.roles.index'))->assertForbidden();
        $this->flushSession();
    }

    auth()->logout();
    $this->get(route('admin.roles.index'))->assertRedirect();
});

it('D-133: نافذة تأكيد تغيير الدور تسمّي الدورين وتربط النموذج نفسه، وبلا جافاسكربت يبقى زر إرسال', function (): void {
    $account = makeParticipant($this->cohort);

    $html = html_entity_decode(
        $this->actingAs($this->sysadmin)->get(route('admin.users.show', $account))->assertOk()->getContent(),
        ENT_QUOTES,
    );

    // The dialog exists, is opened by name, and confirms the SAME form.
    expect($html)->toContain('id="role-change-form"')
        ->and($html)->toContain("\$dispatch('ui-dialog-open', 'change-role')")
        ->and($html)->toContain('form="role-change-form"')
        ->and($html)->toContain('role="dialog"');

    // It can name the new role's meaning for every role, from the same lang keys as the page.
    foreach (UserRole::cases() as $role) {
        expect($html)->toContain(__('roles.roles.'.$role->value.'.summary'));
    }

    // Without scripting the plain submit is still there.
    expect($html)->toMatch('/<noscript>.*?type="submit".*?<\/noscript>/s');
});

it('D-133: تغيير الدور بعد التأكيد كما كان — السبب إلزامي والخادم هو الحَكَم', function (): void {
    $account = makeTrainer($this->cohort);

    // No reason: refused by the FormRequest, exactly as before the dialog existed.
    $this->actingAs($this->sysadmin)
        ->put(route('admin.users.role', $account), ['role' => 'coordinator'])
        ->assertSessionHasErrors('reason');

    expect($account->refresh()->role)->toBe(UserRole::Trainer);

    $this->actingAs($this->sysadmin)
        ->put(route('admin.users.role', $account), ['role' => 'coordinator', 'reason' => 'The cohort needs a second coordinator.'])
        ->assertSessionHasNoErrors();

    expect($account->refresh()->role)->toBe(UserRole::Coordinator);
});
