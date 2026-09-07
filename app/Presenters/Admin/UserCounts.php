<?php

declare(strict_types=1);

namespace App\Presenters\Admin;

use App\Support\ViewModel;

/**
 * The four counters above the accounts table: one per role, plus the accounts
 * that have not confirmed their address yet.
 *
 * @see PRD §9.18 · BR-27
 */
final class UserCounts extends ViewModel
{
    public static function of(int $participants, int $trainers, int $admins, int $pending): self
    {
        return new self([
            'participants' => $participants,
            'trainers' => $trainers,
            'admins' => $admins,
            'pending' => $pending,
        ]);
    }
}
