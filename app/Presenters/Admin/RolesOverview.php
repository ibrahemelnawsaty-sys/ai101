<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Support\RoleCapabilities;
use App\Support\ViewModel;

/**
 * The read model of the roles and permissions page (D-133): five role cards, then
 * the capability matrix grouped by area.
 *
 * Every yes/no in it comes from RoleCapabilities, which reads the router; every
 * word comes from lang/roles.php. The presenter only arranges them, so the view
 * prints and decides nothing (CONSTITUTION art. 5, 6).
 *
 * @see D-133, D-117 · PRD §4 · CONSTITUTION Articles 5, 6
 */
final class RolesOverview extends ViewModel
{
    public static function build(): self
    {
        $matrix = collect(RoleCapabilities::matrix());

        $roles = array_map(static fn (string $role): array => [
            'key' => $role,
            'label' => (string) __('enums.user_role.'.$role),
            'summary' => (string) __('roles.roles.'.$role.'.summary'),
            'scope' => (string) __('roles.roles.'.$role.'.scope'),
        ], RoleCapabilities::ROLE_ORDER);

        $areas = [];

        foreach (RoleCapabilities::areas() as $area) {
            $rows = $matrix->where('area', $area)->map(static fn (array $row): array => [
                'text' => (string) __('roles.capabilities.'.$row['key']),
                'cells' => array_map(
                    static fn (string $role): bool => in_array($role, $row['roles'], true),
                    RoleCapabilities::ROLE_ORDER,
                ),
            ])->values()->all();

            if ($rows !== []) {
                $areas[] = ['key' => $area, 'label' => (string) __('roles.areas.'.$area), 'rows' => $rows];
            }
        }

        return new self([
            'roles' => $roles,
            'areas' => $areas,
        ]);
    }
}
