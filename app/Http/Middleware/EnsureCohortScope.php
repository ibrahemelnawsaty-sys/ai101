<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\LogsDenials;
use App\Models\Cohort;
use App\Services\Audit\AuditLogger;
use App\Services\Permissions\RoleResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registered as the `cohort.scope` alias and applied to every `/trainer/*`
 * route. It decides — on the server, from the enrollments table — which cohort
 * the current request is allowed to read and write, and puts that single id on
 * the request. Controllers never read a cohort id from user input.
 *
 * A trainer with no assigned cohort gets a null scope, not a 403: their screens
 * render their empty state and every scoped query returns nothing. A trainer
 * who *names* a cohort they were not assigned to gets 403 and an audit entry —
 * that is a horizontal access attempt (BR-23).
 *
 * @see BR-22, BR-23, BR-28 · PRD §4.2, §4.3 · CONSTITUTION Art. 22
 */
final class EnsureCohortScope
{
    use LogsDenials;

    /** Request attribute carrying the resolved cohort id (or null). */
    public const ATTRIBUTE = 'athar.cohort_id';

    public function __construct(
        private readonly RoleResolver $roles,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $requested = $request->query('cohort');
        $requested = is_string($requested) && $requested !== '' ? $requested : null;

        $allowed = $this->roles->isAdmin($user)
            ? null                                  // null means "no restriction"
            : $this->roles->trainerCohortIds($user);

        if ($requested !== null && $allowed !== null && ! in_array($requested, $allowed, true)) {
            $this->logDenial($this->audit, $request, 'cohort.scope_denied', 'cohort', $requested);

            abort(Response::HTTP_FORBIDDEN);
        }

        $cohortId = $requested ?? $this->defaultCohortId($allowed);

        if ($requested !== null && ! Cohort::query()->whereKey($requested)->exists()) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $request->attributes->set(self::ATTRIBUTE, $cohortId);

        View::share('scopedCohortId', $cohortId);
        View::share('scopedCohortIds', $allowed);

        return $next($request);
    }

    /**
     * @param  list<string>|null  $allowed
     */
    private function defaultCohortId(?array $allowed): ?string
    {
        if ($allowed !== null) {
            return $allowed[0] ?? null;
        }

        // Admin: fall back to the most recently created cohort so the trainer
        // screens have something to show without inventing a selection rule.
        $id = Cohort::query()->latest('created_at')->value('id');

        return $id === null ? null : (string) $id;
    }
}
