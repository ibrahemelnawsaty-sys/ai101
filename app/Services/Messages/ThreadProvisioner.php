<?php

declare(strict_types=1);

namespace App\Services\Messages;

use App\Enums\EnrollmentRole;
use App\Enums\EnrollmentStatus;
use App\Enums\ThreadType;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The conversations every cohort has, created when people join it.
 *
 * WHY THIS EXISTS
 * PRD §9.13 gives each trainee three conversations — a direct line to their
 * trainer, the cohort group, and the announcements channel. Only the DEMO
 * seeder ever created one. So every real, invited trainee opened an empty
 * messages page, no trainer could post an announcement, and the trainer DM
 * did not exist for anybody (D-82).
 *
 * WHAT IT GUARANTEES, IDEMPOTENTLY
 *   · each cohort has one announcement channel and one group;
 *   · each active participant and trainer of the cohort is a member of both;
 *   · each (trainer, participant) pair has one direct thread.
 * Calling it twice changes nothing, so it is safe from every seating path and
 * from the backfill command alike. Work for one cohort is serialised on the
 * cohort row, so two simultaneous seatings cannot create a second channel.
 *
 * @see PRD §9.13 · BR-22, BR-23 · D-82
 */
final class ThreadProvisioner
{
    /** A participant has just been seated in this cohort. */
    public function seatParticipant(User $participant, Cohort $cohort): void
    {
        $this->withCohortLock($cohort, function () use ($participant, $cohort): void {
            [$announcement, $group] = $this->cohortThreads($cohort);

            $this->join($announcement, $participant);
            $this->join($group, $participant);

            foreach ($this->activeMembers($cohort, EnrollmentRole::Trainer) as $trainerId) {
                $this->ensureDirect($cohort, $trainerId, (string) $participant->getKey());
            }
        });
    }

    /** A trainer has just been given this cohort. */
    public function seatTrainer(User $trainer, Cohort $cohort): void
    {
        $this->withCohortLock($cohort, function () use ($trainer, $cohort): void {
            [$announcement, $group] = $this->cohortThreads($cohort);

            $this->join($announcement, $trainer);
            $this->join($group, $trainer);

            foreach ($this->activeMembers($cohort, EnrollmentRole::Participant) as $participantId) {
                $this->ensureDirect($cohort, (string) $trainer->getKey(), $participantId);
            }
        });
    }

    /**
     * Everything above for everyone already in the cohort — the backfill for
     * people who joined before D-82.
     *
     * @return int how many members were brought in
     */
    public function provisionCohort(Cohort $cohort): int
    {
        $count = 0;

        $this->withCohortLock($cohort, function () use ($cohort, &$count): void {
            [$announcement, $group] = $this->cohortThreads($cohort);

            $trainers = $this->activeMembers($cohort, EnrollmentRole::Trainer);
            $participants = $this->activeMembers($cohort, EnrollmentRole::Participant);

            foreach ([...$trainers, ...$participants] as $userId) {
                $count += $this->joinId($announcement, $userId);
                $this->joinId($group, $userId);
            }

            foreach ($trainers as $trainerId) {
                foreach ($participants as $participantId) {
                    $this->ensureDirect($cohort, $trainerId, $participantId);
                }
            }
        });

        return $count;
    }

    private function withCohortLock(Cohort $cohort, callable $work): void
    {
        DB::transaction(function () use ($cohort, $work): void {
            Cohort::query()->whereKey($cohort->getKey())->lockForUpdate()->first();
            $work();
        });
    }

    /**
     * @return array{0: Thread, 1: Thread} the announcement channel and the group
     */
    private function cohortThreads(Cohort $cohort): array
    {
        return [
            $this->cohortThread($cohort, ThreadType::Announcement),
            $this->cohortThread($cohort, ThreadType::Group),
        ];
    }

    private function cohortThread(Cohort $cohort, ThreadType $type): Thread
    {
        /** @var Thread|null $thread */
        $thread = Thread::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('type', $type->value)
            ->orderBy('created_at')
            ->first();

        if ($thread instanceof Thread) {
            return $thread;
        }

        /** @var Thread $created */
        $created = Thread::query()->create([
            'cohort_id' => $cohort->getKey(),
            'type' => $type->value,
            // No title: the presenter names a cohort thread by its type, in the
            // reader's language, rather than freezing one language in the row.
            'title' => null,
            'created_by' => null,
            'is_locked' => false,
        ]);

        return $created;
    }

    private function ensureDirect(Cohort $cohort, string $trainerId, string $participantId): void
    {
        $exists = Thread::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('type', ThreadType::TrainerDm->value)
            ->whereHas('participants', static fn ($q) => $q->where('user_id', $trainerId))
            ->whereHas('participants', static fn ($q) => $q->where('user_id', $participantId))
            ->exists();

        if ($exists) {
            return;
        }

        /** @var Thread $thread */
        $thread = Thread::query()->create([
            'cohort_id' => $cohort->getKey(),
            'type' => ThreadType::TrainerDm->value,
            'title' => null,
            'created_by' => $trainerId,
            'is_locked' => false,
        ]);

        $this->joinId($thread, $trainerId);
        $this->joinId($thread, $participantId);
    }

    private function join(Thread $thread, User $user): void
    {
        $this->joinId($thread, (string) $user->getKey());
    }

    /**
     * @return int 1 when the member was added, 0 when already there
     */
    private function joinId(Thread $thread, string $userId): int
    {
        $stamp = Clock::now()->format('Y-m-d H:i:s');

        // insertOrIgnore against unique(thread_id, user_id): a member already
        // there is left as they are — their read marker included.
        return ThreadParticipant::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'thread_id' => $thread->getKey(),
            'user_id' => $userId,
            'last_read_at' => null,
            'is_muted' => false,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);
    }

    /**
     * @return list<string>
     */
    private function activeMembers(Cohort $cohort, EnrollmentRole $role): array
    {
        return array_values(Enrollment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('role_in_cohort', $role->value)
            ->where('status', EnrollmentStatus::Active->value)
            ->pluck('user_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all());
    }
}
