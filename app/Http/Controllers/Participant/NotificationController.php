<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use App\Presenters\Participant\NotificationPresenter;
use App\Presenters\Support\Options;
use App\Services\Time\Clock;
use App\Support\ImpersonationContext;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The notification centre (PRD §9.16).
 *
 * BR-34 applies twice over here: while an account preview is running nothing
 * is marked as read, and the "mark all as read" form is not offered. The guard
 * is on the server — the middleware refuses the POST outright, and the read
 * stamp below refuses a second time (defence in depth, Art. 23).
 *
 * @see BR-22, BR-33, BR-34 · PRD §9.16 · CONSTITUTION Art. 22, Art. 23
 */
final class NotificationController extends Controller
{
    private const PER_PAGE = 30;

    /** The Article 17 screen name, and the name of its loading skeleton. */
    private const SCREEN = 'notifications';

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('viewAny', Notification::class);

        $notifications = Notification::query()
            ->where('user_id', $user->getKey())
            ->orderByDesc('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $unread = Notification::query()
            ->where('user_id', $user->getKey())
            ->where('is_read', false)
            ->count();

        return view('participant.notifications', [
            'notifications' => $notifications->through(
                static fn (Notification $row): NotificationPresenter => NotificationPresenter::from($row)
            ),
            'unreadCount' => $unread,
            'isImpersonating' => ImpersonationContext::isActive(),
            'typeOptions' => $this->typeOptions(),
            'stateOptions' => $this->stateOptions(),
            'errorState' => null,
            'screen' => self::SCREEN,
            'screenState' => ScreenState::of($notifications->getCollection()->isEmpty()),
        ]);
    }

    /**
     * The filter's event list, named from lang/ar/notifications.php so the
     * wording matches the rows it filters (art. 15).
     *
     * @return list<array{value: string, label: string}>
     */
    private function typeOptions(): array
    {
        $types = trans('notifications.types');

        if (! is_array($types)) {
            return [];
        }

        $pairs = [];

        foreach ($types as $key => $entry) {
            if (is_array($entry) && isset($entry['label']) && is_string($entry['label'])) {
                $pairs[(string) $key] = $entry['label'];
            }
        }

        return Options::fromPairs($pairs);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function stateOptions(): array
    {
        return Options::fromPairs([
            'unread' => (string) __('notifications.unread'),
            'read' => (string) __('messages.read'),
        ]);
    }

    /**
     * BR-34 — a preview leaves no trace, so this endpoint refuses to run at all
     * while one is active. The `not.impersonating` middleware has already said
     * the same thing; saying it twice is the point.
     */
    public function readAll(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('markAllRead', Notification::class);

        if (ImpersonationContext::isActive()) {
            return back();
        }

        Notification::query()
            ->where('user_id', $user->getKey())
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => Clock::now(),
            ]);

        return back()->with('status', __('notifications.all_read'));
    }
}
