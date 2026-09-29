<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Presenters\Admin\RolesOverview;
use Illuminate\Contracts\View\View;

/**
 * The roles and permissions page (D-133): what each of the five roles can do.
 *
 * Read-only by construction: it has a GET and nothing else, writes no row and
 * grants nothing. It is the system administrator's alone — the ability
 * `console.roles`, on top of the `role:system_admin` group — because the role
 * changes are theirs (D-117), so the explanation sits where the decision is taken.
 *
 * @see D-133, D-117 · PRD §4 · CONSTITUTION Articles 5, 22
 */
final class RoleController extends Controller
{
    public function index(): View
    {
        $this->authorize('console.roles');

        return view('admin.roles', [
            'contextLabel' => null,
            'overview' => RolesOverview::build(),
            'errorState' => null,
        ]);
    }
}
