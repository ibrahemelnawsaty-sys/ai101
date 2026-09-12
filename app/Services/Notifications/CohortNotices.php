<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Enums\ThreadType;
use App\Events\AnnouncementPublished;
use App\Events\MessageReceived;
use App\Events\SessionRescheduled;
use App\Models\Profile;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use App\Presenters\Participant\ThreadPresenter;
use App\Presenters\Support\Present;
use App\Services\Mail\CohortAudience;
use App\Services\Time\Clock;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The notices a cohort's everyday actions send the moment they happen: a
 * hand-in, a new resource, an announcement, a message, a moved session.
 *
 * WHY THIS EXISTS
 * Each of these is a row of PRD §9.16.1, each had copy in two languages and a
 * switch on the preferences screen, and not one had a writer (D-83). A trainee
 * who handed in was told on screen and nowhere else; a trainer learned of a
 * submission by opening the list; a new resource, an announcement, a message
 * in a DM put nothing in anybody's bell.
 *
 * AFTER THE FACT, NEVER INSTEAD OF IT
 * Every caller has already done its work — the submission is saved, the
 * message posted. A notice that fails to write must not turn that success into
 * an error page, which would invite the trainee to hand in twice. So each
 * method logs and returns; the failure is visible in the log and nowhere else
 * (art. 7).
 *
 * @see PRD §9.13, §9.16.1 · FR-NOTIF-12, FR-NOTIF-15, FR-NOTIF-16, FR-NOTIF-20, FR-NOTIF-21, FR-NOTIF-22 · D-83
 */
final class CohortNotices
{
    /** How much of a message a notice or a letter quotes. */
    private const EXCERPT = 120;

    public function __construct(
        private readonly InAppNotifier $notifier,
        private readonly CohortAudience $audience,
    ) {}

    /**
     * A participant handed something in: they are told it arrived, and the
     * cohort's trainers that it is waiting (FR-NOTIF-15, FR-NOTIF-16). The
     * platform only, both of them.
     */
    public function handedIn(User $participant, string $cohortId, string $itemTitle, string $participantLink, string $trainerLink): void
    {
        $this->guard('handed_in', function () use ($participant, $cohortId, $itemTitle, $participantLink, $trainerLink): void {
            $now = Clock::now();
            $values = ['assignment' => $itemTitle];

            $this->notifier->notify(
                [(string) $participant->getKey()],
                'submission_received',
                (string) __('notifications.types.submission_received.title', $values),
                (string) __('notifications.types.submission_received.body', $values),
                $participantLink,
                $now,
            );

            $this->notifier->notify(
                $this->audience->trainerIds($cohortId),
                'submission_new',
                (string) __('notifications.types.submission_new.title', ['name' => $this->nameOf($participant)]),
                (string) __('notifications.types.submission_new.body', $values),
                $trainerLink,
                $now,
            );
        });
    }

    /** A resource was added to the pack: the cohort, on the platform (FR-NOTIF-20). */
    public function resourceAdded(string $cohortId, string $resourceTitle): void
    {
        $this->guard('resource_added', function () use ($cohortId, $resourceTitle): void {
            $this->notifier->notify(
                $this->participantIds($cohortId),
                'resource_added',
                (string) __('notifications.types.resource_added.title', ['resource' => $resourceTitle]),
                (string) __('notifications.types.resource_added.body'),
                route('resources.index'),
                Clock::now(),
            );
        });
    }

    /**
     * Somebody posted in a thread.
     *
     * In the announcement channel it is an announcement: every participant of
     * the cohort, on the platform and by letter (FR-NOTIF-21). Anywhere else
     * it is a message: each other member who has not muted the thread, on the
     * platform, and by letter only if offline — the listener decides that
     * when the queue runs (FR-NOTIF-22).
     */
    public function posted(Thread $thread, User $author, string $body): void
    {
        $this->guard('posted', function () use ($thread, $author, $body): void {
            $excerpt = Str::limit(trim($body), self::EXCERPT);
            $threadId = (string) $thread->getKey();
            $link = route('messages.index', ['thread' => $threadId]);
            $type = $thread->getAttribute('type');
            $type = $type instanceof ThreadType ? $type : ThreadType::tryFrom((string) $type);

            if ($type === ThreadType::Announcement) {
                $cohortId = (string) $thread->getAttribute('cohort_id');

                $this->notifier->notify(
                    array_diff($this->participantIds($cohortId), [(string) $author->getKey()]),
                    'announcement_published',
                    (string) __('notifications.types.announcement_published.title'),
                    (string) __('notifications.types.announcement_published.body', ['excerpt' => $excerpt]),
                    $link,
                    Clock::now(),
                );

                AnnouncementPublished::dispatch($cohortId, $threadId, $excerpt);

                return;
            }

            $recipients = User::query()
                ->with('profile')
                ->whereIn('id', ThreadParticipant::query()
                    ->where('thread_id', $threadId)
                    ->where('user_id', '!=', $author->getKey())
                    ->where('is_muted', false)
                    ->select('user_id'))
                ->get();

            $name = $this->nameOf($author);

            $this->notifier->notify(
                $recipients->map(static fn (User $user): string => (string) $user->getKey()),
                'message_received',
                (string) __('notifications.types.message_received.title', ['name' => $name]),
                (string) __('notifications.types.message_received.body', ['excerpt' => $excerpt]),
                $link,
                Clock::now(),
            );

            // A DM's row has no title, and the reader's own view of it is the
            // other person's name — the sender's. Their type label reads right
            // for every member, in the words the thread list uses.
            $threadTitle = ThreadPresenter::titleOf($thread, $type);

            foreach ($recipients as $recipient) {
                MessageReceived::dispatch($recipient, $name, $threadTitle, $excerpt, $threadId);
            }
        });
    }

    /**
     * A session moved to another time: the cohort, on the platform and by
     * letter, with the new time (FR-NOTIF-12). Cancelling has its own words.
     */
    public function sessionMoved(string $cohortId, string $sessionTitle, CarbonImmutable $newStart): void
    {
        $this->guard('session_moved', function () use ($cohortId, $sessionTitle, $newStart): void {
            $values = ['session' => $sessionTitle, 'datetime' => Dates::dateTime($newStart)];

            $this->notifier->notify(
                $this->participantIds($cohortId),
                'session_changed',
                (string) __('notifications.types.session_changed.title', $values),
                (string) __('notifications.types.session_changed.body', $values),
                route('schedule'),
                Clock::now(),
            );

            SessionRescheduled::dispatch($cohortId, $sessionTitle, $values['datetime']);
        });
    }

    /** @return list<string> */
    private function participantIds(string $cohortId): array
    {
        return array_values($this->audience->participants($cohortId)
            ->map(static fn (User $user): string => (string) $user->getKey())
            ->all());
    }

    private function nameOf(User $user): string
    {
        $profile = $user->relationLoaded('profile') ? $user->getRelation('profile') : $user->profile()->first();
        $name = $profile instanceof Profile ? Present::text($profile->getAttribute('full_name_ar')) : null;

        return $name ?? (string) $user->getAttribute('email');
    }

    private function guard(string $notice, callable $write): void
    {
        try {
            $write();
        } catch (\Throwable $failure) {
            Log::error('notices.cohort_failed', [
                'notice' => $notice,
                'exception' => $failure::class,
                'at' => $failure->getFile().':'.$failure->getLine(),
            ]);
        }
    }
}
