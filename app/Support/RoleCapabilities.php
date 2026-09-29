<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * What each role can reach, for the roles and permissions page (D-133).
 *
 * This list holds WHAT a capability is and which screen or action it is behind.
 * WHO may use it is read from the router — the `role:` middleware the route
 * carries, group and route together — so the page cannot list a role the router
 * refuses. But middleware is only the CEILING: a policy can refuse a role the
 * middleware let through (grading is the trainer's alone though the route admits
 * the supervisor; PRD §4.2). Those few capabilities say so explicitly in `only`,
 * with the reason, and `only` may never name a role the router does not admit.
 * RolesPageTest then compares EVERY row with the authorisation matrix of G9 —
 * the file proven against the server on each run — so the page equals the matrix,
 * and the matrix equals what the server does.
 *
 * It explains, it does not decide. Nothing here grants, withholds or checks a
 * permission: every screen and action still answers to its own middleware,
 * policy and query scope on the server (CONSTITUTION art. 5). What a role sees
 * INSIDE a screen — its own cohorts, its own records — is a matter of scope, and
 * the page says that in words per role (lang/roles.php), because a route cannot.
 *
 * A route that carries no `role:` middleware yields no roles and the test that
 * pins this file fails: the page would have nothing true to say about it.
 *
 * @see D-133, D-117, D-124 · PRD §4 · CONSTITUTION Articles 5, 22
 */
final class RoleCapabilities
{
    /** The five roles, from the narrowest scope to the widest — the page's column order. */
    public const ROLE_ORDER = ['participant', 'trainer', 'coordinator', 'admin', 'system_admin'];

    /**
     * [capability key, area key, route name, optional `only`]. The texts live in
     * lang/roles.php under `capabilities.<key>` and `areas.<area>`.
     *
     * `only` narrows the router's roles to those a POLICY then admits: the four
     * cases below are the ones where the route lets a role reach the request and
     * the policy answers it 403 (D-111, D-124, PRD §4.2).
     *
     * @var list<array{0: string, 1: string, 2: string, 3?: list<string>}>
     */
    private const CAPABILITIES = [
        // ---- following the programme
        ['view_schedule', 'learn', 'schedule'],
        ['live_sessions', 'learn', 'live'],
        ['check_in_self', 'learn', 'attendance.checkIn'],
        ['submit_assignments', 'learn', 'assignments.submit'],
        ['final_project', 'learn', 'finalProject'],
        ['see_grades', 'learn', 'grades'],
        ['own_certificate', 'learn', 'certificate'],
        ['own_card', 'learn', 'participant.card'],
        ['training_kit', 'learn', 'resources.index'],

        // ---- running a cohort
        ['take_attendance', 'run', 'trainer.attendance'],
        ['checkin_code', 'run', 'trainer.attendance.checkinCode'],
        ['decide_excuses', 'run', 'trainer.attendance-exceptions.approve'],
        ['read_sessions', 'run', 'trainer.sessions'],
        ['write_schedule', 'run', 'trainer.sessions.store'],
        ['cohort_trainees', 'run', 'trainer.participants'],
        ['upload_kit', 'run', 'trainer.resources.store'],

        // ---- work and marks
        ['read_assignments', 'grade', 'trainer.assignments'],
        // D-111 — defining a task moved to the supervisor alone; the group still admits the trainer to read.
        ['define_assignments', 'grade', 'trainer.assignments.store', ['admin']],
        ['read_submissions', 'grade', 'trainer.submissions'],
        // PRD §4.2 — the mark is the trainer's professional judgement: the supervisor's column reads "no".
        ['grade_submissions', 'grade', 'trainer.submissions.grade', ['trainer']],
        ['revise_grades', 'grade', 'trainer.submissions.revise', ['trainer']],
        ['cohort_reports', 'grade', 'trainer.reports'],

        // ---- running the programme
        ['manage_programs', 'program', 'admin.programs.index'],
        ['manage_cohorts', 'program', 'admin.cohorts.index'],
        ['registrations', 'program', 'admin.registrations.index'],
        ['certificates_screen', 'program', 'admin.certificates.index'],
        ['final_project_settings', 'program', 'admin.finalProject.index'],
        ['broadcasts', 'program', 'admin.broadcasts.index'],
        ['platform_reports', 'program', 'admin.reports.index'],
        ['audit_log', 'program', 'admin.audit.index'],

        // ---- the platform itself
        ['manage_accounts', 'system', 'admin.users.index'],
        ['change_roles', 'system', 'admin.users.role'],
        ['preview_accounts', 'system', 'admin.users.preview'],
        ['landing_page', 'system', 'admin.landing.edit'],
        ['platform_settings', 'system', 'admin.settings.edit'],
        ['roles_page', 'system', 'admin.roles.index'],

        // ---- contact and support
        ['internal_messaging', 'contact', 'messages.index'],
        ['support_area', 'contact', 'support.index'],
        ['open_ticket', 'contact', 'support.create'],
        // D-124 — a ticket is resolved by whoever holds it, and that is the coordinator's level.
        ['handle_tickets', 'contact', 'support.resolve', ['coordinator']],
    ];

    /**
     * The narrowings the list declares, keyed by capability — for the test that
     * checks each is a subset of what the router admits.
     *
     * @return array<string, array{route: string, only: list<string>}>
     */
    public static function narrowed(): array
    {
        $out = [];

        foreach (self::CAPABILITIES as $capability) {
            if (isset($capability[3])) {
                $out[$capability[0]] = ['route' => $capability[2], 'only' => $capability[3]];
            }
        }

        return $out;
    }

    /**
     * The roles the ROUTER admits for a capability, before any policy narrowing.
     *
     * @return list<string>
     */
    public static function routerRoles(string $routeName): array
    {
        $route = Route::getRoutes()->getByName($routeName);

        return $route === null ? [] : self::rolesOf($route);
    }

    /**
     * Capabilities that carry a footnote on the page, keyed by capability. A
     * footnote states a case the role columns cannot: BR-23 lets a supervisor
     * account be seated as a cohort's trainer, and in that cohort the supervisor
     * holds the trainer's abilities. Texts: lang/roles.php `notes.<key>`.
     *
     * @var list<string>
     */
    private const NOTED = ['grade_submissions', 'revise_grades'];

    /**
     * The capability keys that carry a footnote.
     *
     * @return list<string>
     */
    public static function noted(): array
    {
        return self::NOTED;
    }

    /**
     * Every capability key the list declares, whether or not its route exists in
     * this build — so a test can tell a row that was dropped from one that was
     * never there (matrix() leaves a missing route out rather than throw).
     *
     * @return list<string>
     */
    public static function declaredKeys(): array
    {
        return array_map(static fn (array $capability): string => $capability[0], self::CAPABILITIES);
    }

    /**
     * The area keys in the order the page prints them.
     *
     * @return list<string>
     */
    public static function areas(): array
    {
        return array_values(array_unique(array_map(static fn (array $row): string => $row[1], self::CAPABILITIES)));
    }

    /**
     * Every capability whose route exists, with the roles that route admits.
     * A route missing from this build is left out rather than thrown: the page is
     * chrome, and fails safe, never loud (art. 7).
     *
     * @return list<array{key: string, area: string, route: string, roles: list<string>}>
     */
    public static function matrix(): array
    {
        $rows = [];

        foreach (self::CAPABILITIES as $capability) {
            [$key, $area, $name] = $capability;
            $route = Route::getRoutes()->getByName($name);

            if ($route === null) {
                continue;
            }

            $roles = self::rolesOf($route);

            // A policy's narrowing, declared and pinned — never a role the router refuses.
            if (isset($capability[3])) {
                $roles = array_values(array_filter($roles, static fn (string $role): bool => in_array($role, $capability[3], true)));
            }

            $rows[] = ['key' => $key, 'area' => $area, 'route' => $name, 'roles' => $roles];
        }

        return $rows;
    }

    /**
     * The roles a route admits: the intersection of every `role:` middleware it
     * carries. A route behind two of them (a group's, then its own) admits only
     * what both admit — which is exactly what the two middleware do at request time.
     *
     * @return list<string>
     */
    private static function rolesOf(LaravelRoute $route): array
    {
        $sets = [];

        foreach ($route->middleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'role:')) {
                $sets[] = array_map('trim', explode(',', substr($middleware, 5)));
            }
        }

        if ($sets === []) {
            return [];
        }

        $allowed = array_intersect(...$sets);

        return array_values(array_filter(self::ROLE_ORDER, static fn (string $role): bool => in_array($role, $allowed, true)));
    }
}
