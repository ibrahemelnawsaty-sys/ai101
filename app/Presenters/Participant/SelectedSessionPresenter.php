<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Session;
use App\Presenters\Support\Present;
use App\Services\Attendance\AttendanceWindow;
use App\Support\ViewModel;
use Illuminate\Support\Collection;

/**
 * The calendar's side panel for one chosen session (PRD §9.8.1).
 *
 * Like every other session view model, it carries no meeting link: the link is
 * revealed by the guarded join endpoint inside its own window, never by a page
 * (BR-24).
 *
 * @see BR-22, BR-24 · PRD §9.8.1
 */
final class SelectedSessionPresenter extends ViewModel
{
    /**
     * @param  Collection<int, ResourcePresenter>  $resources
     */
    public static function from(Session $session, AttendanceWindow $window, Collection $resources): self
    {
        return new self([
            'id' => (string) $session->getKey(),
            'topic' => Present::text($session->getAttribute('topic'))
                ?? (string) $session->getAttribute('title'),
            'description' => Present::text($session->getAttribute('topic')),
            'startsAt' => $window->startsAt($session),
            'endsAt' => $window->endsAt($session),
            'resources' => $resources,
        ]);
    }
}
