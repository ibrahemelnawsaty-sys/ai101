<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Staff posted in a cohort's announcement channel — the PRD §9.16.1 row "new
 * announcement: the whole cohort, on publication, platform and e-mail".
 *
 * An excerpt, not the message: the announcement lives in the platform, where
 * it is scoped and audited. Scalars only, addressed to a cohort (D-51).
 *
 * @see PRD §9.13.1, §9.16.1 · FR-NOTIF-21 · D-83
 */
final class AnnouncementPublished
{
    use Dispatchable;

    public function __construct(
        public readonly string $cohortId,
        public readonly string $threadId,
        public readonly string $excerpt,
    ) {}
}
