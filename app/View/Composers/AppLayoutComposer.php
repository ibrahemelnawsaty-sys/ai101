<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Http\Controllers\Concerns\ResolvesActiveCohort;
use App\Http\Middleware\EnsureCohortScope;
use App\Models\Assignment;
use App\Models\Cohort;
use App\Models\Message;
use App\Models\Notification;
use App\Models\User;
use App\Services\Permissions\RoleResolver;
use App\Services\Time\Clock;
use App\Support\ImpersonationContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * The four values the application shell shows on every screen: the cohort in
 * the rail's footer, the cohort switcher, the rail's two badges, and the bell's
 * unread count (PRD §9.5.1, §9.5.2).
 *
 * WHY THIS EXISTS
 * The layout documented these as "optional variables" and no controller, no
 * composer and no middleware ever set one. So on every screen, for every role,
 * the footer never named a cohort, the switcher never appeared — a trainee in
 * two cohorts was stuck on whichever came first — the badges were zero and the
 * bell never counted (D-75).
 *
 * EVERY VALUE IS GUARDED. The shell wraps all thirty-odd screens; one throwing
 * count would take every one of them to a 500. Each value is built behind the
 * same guard as the dashboard's cards (D-66): a failure is logged without its
 * message and the value falls back to its empty form. Fail safe, never loud, in
 * the chrome (art. 7).
 *
 * It does NOT set `sidebarGroups`: the rail is chosen by role in the component
 * (D-30), and a controller that passes its own must keep winning.
 *
 * @see PRD §9.5.1, §9.5.2 · BR-22, BR-33, BR-34 · D-30, D-66, D-75
 */
final class AppLayoutComposer
{
    use ResolvesActiveCohort;

    public function __construct(private readonly RoleResolver $roles) {}

    public function compose(View $view): void
    {
        $user = Auth::user();

        $empty = [
            'cohortName' => null,
            'sidebarCohorts' => [],
            'navBadges' => ['assignments' => 0, 'messages' => 0],
            'unreadNotifications' => 0,
        ];

        if (! $user instanceof User) {
            $view->with($empty);

            return;
        }

        $role = $this->guard('role', $user, fn (): string => $this->roles->shellRole($user), 'participant');

        $values = $empty;
        $values['unreadNotifications'] = $this->guard('unread', $user,
            static fn (): int => Notification::query()->forUser($user)->unread()->count(), 0);

        if ($role === 'trainer') {
            // A trainer has conversations too (D-82): the badge counts theirs.
            $values['navBadges']['messages'] = $this->guard('badge.messages', $user,
                static fn (): int => Message::query()->unreadBy($user)->count(), 0);

            // Trainer screens run behind cohort.scope, which names the cohort
            // they act on. No switcher here: the trainer area does not read the
            // key the switcher writes (D-74).
            $values['cohortName'] = $this->guard('cohort', $user, static function (): ?string {
                $id = request()->attributes->get(EnsureCohortScope::ATTRIBUTE);

                return is_string($id) && $id !== ''
                    ? (string) Cohort::query()->whereKey($id)->value('name')
                    : null;
            }, null);
        }

        if ($role === 'participant') {
            $activeId = $this->guard('active', $user, fn (): ?string => $this->activeCohortId($user), null);

            /** @var list<array{id: string, name: string, is_current: bool}> $options */
            $options = $this->guard('cohorts', $user, static fn (): array => Cohort::query()
                ->whereIn('id', $user->accessibleCohortIds())
                ->orderBy('start_date')
                ->get(['id', 'name'])
                ->map(static fn (Cohort $cohort): array => [
                    'id' => (string) $cohort->getKey(),
                    'name' => (string) $cohort->getAttribute('name'),
                    'is_current' => (string) $cohort->getKey() === $activeId,
                ])
                ->values()
                ->all(), []);

            $current = array_values(array_filter($options, static fn (array $option): bool => $option['is_current']));

            $values['cohortName'] = $current[0]['name'] ?? null;
            // Never during a preview: the switcher posts, and a preview may
            // not write — every change would be a refusal (BR-33).
            $values['sidebarCohorts'] = ImpersonationContext::isActive() ? [] : $options;
            $values['navBadges'] = [
                'assignments' => $activeId === null ? 0 : $this->guard('badge.assignments', $user,
                    static fn (): int => Assignment::query()
                        ->where('cohort_id', $activeId)
                        ->openFor($user, Clock::now())
                        ->count(), 0),
                'messages' => $this->guard('badge.messages', $user,
                    static fn (): int => Message::query()->unreadBy($user)->count(), 0),
            ];
        }

        // A controller that passed its own value keeps it.
        $data = $view->getData();

        $view->with(array_filter(
            $values,
            static fn (string $key): bool => ! array_key_exists($key, $data),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $build
     * @param  T  $fallback
     * @return T
     */
    private function guard(string $key, User $user, callable $build, mixed $fallback): mixed
    {
        try {
            return $build();
        } catch (\Throwable $failure) {
            Log::error('shell.block_failed', [
                'block' => $key,
                'user_id' => $user->getKey(),
                'exception' => $failure::class,
                'at' => $failure->getFile().':'.$failure->getLine(),
            ]);

            return $fallback;
        }
    }
}
