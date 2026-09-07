<?php

declare(strict_types=1);

/**
 * The training kit, the message threads and the in-app notifications.
 *
 * Three thread types exist in the seeded data because PROJECT-CONTRACT §3 fixes
 * three of them: a locked announcement thread, an open group discussion, and
 * direct threads between a trainer and a single participant. Unread state is
 * left uneven on purpose so the unread badge has something to count.
 *
 * A file resource stores a STORAGE PATH in `file_url`, never a public address, because
 * uploads live outside the web root and are reached through a 15-minute signed
 * link (Constitution art. 22).
 *
 * @see PRD §7.6, §9.12, §9.13 · BR-34 · PROJECT-CONTRACT §4, §11
 */

namespace Database\Seeders;

use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Resource;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use App\Models\Week;
use App\Services\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EngagementSeeder extends Seeder
{
    /** How many direct trainer threads to open. */
    private const DIRECT_THREAD_COUNT = 3;

    /** How many notifications each active participant receives. */
    private const NOTIFICATIONS_PER_PARTICIPANT = 4;

    public function run(): void
    {
        $cohort = Cohort::query()->firstOrFail();

        /** @var Collection<int, User> $trainers */
        $trainers = User::query()
            ->where('role', 'trainer')
            ->orderBy('email')
            ->get()
            ->values();

        $participants = $this->activeParticipants($cohort);

        if ($trainers->isEmpty() || $participants->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($cohort, $trainers, $participants): void {
            $this->seedResources($cohort, $trainers);
            $this->seedAnnouncementThread($cohort, $trainers, $participants);
            $this->seedGroupThread($cohort, $trainers, $participants);
            $this->seedDirectThreads($cohort, $trainers, $participants);
            $this->seedNotifications($participants);
        });
    }

    /**
     * @param  Collection<int, User>  $trainers
     */
    private function seedResources(Cohort $cohort, Collection $trainers): void
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = SeedContent::section('resources');

        /** @var Collection<int, Week> $weeks */
        $weeks = Week::query()
            ->where('cohort_id', $cohort->getKey())
            ->orderBy('index')
            ->get()
            ->keyBy('index');

        foreach ($rows as $ordinal => $row) {
            $weekIndex = $row['week'] === null ? null : (int) $row['week'];
            $isLink = $row['type'] === 'link';

            Resource::factory()->create([
                'cohort_id' => $cohort->getKey(),
                'week_id' => $weekIndex === null ? null : $weeks->get($weekIndex)?->getKey(),
                'session_id' => null,
                'title' => $row['title'],
                'description' => $row['description'],
                'type' => $row['type'],
                'file_url' => $isLink ? null : 'resources/ai101/'.($ordinal + 1).'-material.pdf',
                'external_url' => $isLink ? 'https://athar-dev.edu.sa/ai101/tools' : null,
                'size' => $isLink ? null : 480_000 + ($ordinal * 90_000),
                'uploaded_by' => $trainers->first()?->getKey(),
                'download_count' => $ordinal * 7,
            ]);
        }
    }

    /**
     * @param  Collection<int, User>  $trainers
     * @param  Collection<int, User>  $participants
     */
    private function seedAnnouncementThread(Cohort $cohort, Collection $trainers, Collection $participants): void
    {
        /** @var array<string, mixed> $content */
        $content = SeedContent::section('threads')['announcement'];

        /** @var User $author */
        $author = $trainers->first();

        $thread = Thread::factory()->announcement()->create([
            'cohort_id' => $cohort->getKey(),
            'title' => $content['title'],
            'created_by' => $author->getKey(),
        ]);

        $this->attachParticipants($thread, $trainers, $participants, readEveryNth: 3);

        /** @var list<string> $bodies */
        $bodies = $content['messages'];

        foreach ($bodies as $ordinal => $body) {
            $this->postMessage($thread, $author, $body, SeedContent::instant(
                ($ordinal * 9) - 2,
                '18:30:00',
            ));
        }
    }

    /**
     * @param  Collection<int, User>  $trainers
     * @param  Collection<int, User>  $participants
     */
    private function seedGroupThread(Cohort $cohort, Collection $trainers, Collection $participants): void
    {
        /** @var array<string, mixed> $content */
        $content = SeedContent::section('threads')['group'];

        /** @var User $trainer */
        $trainer = $trainers->first();

        $thread = Thread::factory()->create([
            'cohort_id' => $cohort->getKey(),
            'type' => 'group',
            'title' => $content['title'],
            'created_by' => $trainer->getKey(),
            'is_locked' => false,
        ]);

        $this->attachParticipants($thread, $trainers, $participants, readEveryNth: 4);

        /** @var list<string> $bodies */
        $bodies = $content['messages'];

        // Question from a participant, answer from the trainer, thanks back.
        $speakers = [
            $participants->get(0) ?? $trainer,
            $trainer,
            $participants->get(0) ?? $trainer,
        ];

        foreach ($bodies as $ordinal => $body) {
            $this->postMessage(
                $thread,
                $speakers[$ordinal] ?? $trainer,
                $body,
                SeedContent::instant(8, '21:'.str_pad((string) (10 + ($ordinal * 7)), 2, '0', STR_PAD_LEFT).':00'),
            );
        }
    }

    /**
     * @param  Collection<int, User>  $trainers
     * @param  Collection<int, User>  $participants
     */
    private function seedDirectThreads(Cohort $cohort, Collection $trainers, Collection $participants): void
    {
        /** @var array<string, mixed> $content */
        $content = SeedContent::section('threads')['trainer_dm'];

        /** @var list<string> $bodies */
        $bodies = $content['messages'];

        for ($n = 0; $n < self::DIRECT_THREAD_COUNT; $n++) {
            $participant = $participants->get($n);
            $trainer = $trainers->get($n % max($trainers->count(), 1));

            if (! $participant instanceof User || ! $trainer instanceof User) {
                continue;
            }

            $thread = Thread::factory()->trainerDm()->create([
                'cohort_id' => $cohort->getKey(),
                'title' => null,
                'created_by' => $participant->getKey(),
                'is_locked' => false,
            ]);

            foreach ([$participant, $trainer] as $member) {
                ThreadParticipant::factory()->create([
                    'thread_id' => $thread->getKey(),
                    'user_id' => $member->getKey(),
                    'last_read_at' => $member->is($trainer) ? Clock::now() : null,
                    'is_muted' => false,
                ]);
            }

            $speakers = [$participant, $trainer];

            foreach ($bodies as $ordinal => $body) {
                $this->postMessage(
                    $thread,
                    $speakers[$ordinal] ?? $participant,
                    $body,
                    SeedContent::instant(22 + $n, '20:'.($ordinal === 0 ? '05' : '40').':00'),
                );
            }
        }
    }

    /**
     * @param  Collection<int, User>  $participants
     */
    private function seedNotifications(Collection $participants): void
    {
        /** @var list<array<string, string>> $templates */
        $templates = SeedContent::section('notifications');

        foreach ($participants as $index => $participant) {
            $i = (int) $index;

            for ($n = 0; $n < self::NOTIFICATIONS_PER_PARTICIPANT; $n++) {
                $template = $templates[($i + $n) % count($templates)];
                $isRead = ($i + $n) % 3 === 0;

                Notification::factory()->create([
                    'user_id' => $participant->getKey(),
                    'type' => $template['type'],
                    'title' => $template['title'],
                    'body' => $template['body'],
                    'link' => $template['link'],
                    'is_read' => $isRead,
                    'read_at' => $isRead ? Clock::now()->subHours($n + 1) : null,
                    'channel' => 'in_app',
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, User>  $trainers
     * @param  Collection<int, User>  $participants
     */
    private function attachParticipants(
        Thread $thread,
        Collection $trainers,
        Collection $participants,
        int $readEveryNth,
    ): void {
        foreach ($trainers as $trainer) {
            ThreadParticipant::factory()->create([
                'thread_id' => $thread->getKey(),
                'user_id' => $trainer->getKey(),
                'last_read_at' => Clock::now(),
                'is_muted' => false,
            ]);
        }

        foreach ($participants as $index => $participant) {
            $i = (int) $index;

            ThreadParticipant::factory()->create([
                'thread_id' => $thread->getKey(),
                'user_id' => $participant->getKey(),
                'last_read_at' => $i % $readEveryNth === 0 ? Clock::now()->subDays(1) : null,
                'is_muted' => $i % 17 === 0,
            ]);
        }
    }

    private function postMessage(Thread $thread, User $sender, string $body, CarbonImmutable $sentAt): void
    {
        Message::factory()->create([
            'thread_id' => $thread->getKey(),
            'sender_id' => $sender->getKey(),
            'body' => $body,
            'attachments' => [],
            'sent_at' => $sentAt,
            'edited_at' => null,
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    private function activeParticipants(Cohort $cohort): Collection
    {
        $userIds = Enrollment::query()
            ->where('cohort_id', $cohort->getKey())
            ->where('role_in_cohort', 'participant')
            ->where('status', 'active')
            ->pluck('user_id');

        /** @var Collection<int, User> $users */
        $users = User::query()
            ->whereIn('id', $userIds)
            ->orderBy('email')
            ->get();

        return $users->values();
    }
}
