<?php

declare(strict_types=1);

namespace App\Services\FinalProject;

use App\Models\FinalProject;
use App\Models\FinalProjectGuide;
use App\Models\FinalProjectGuideVersion;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * The guide's pages and their history (D-127).
 *
 * Every save is a new version; the highest number is what is shown. Nothing is
 * ever overwritten or deleted: a restore copies an older page into a NEW
 * version and says which one it came from, and a copy from another cohort is a
 * new version too. Saving exactly the page already on show writes nothing.
 *
 * What is saved was already judged by the request (size, type, colours); the
 * page is stored as it came, and made safe to show when it is SERVED
 * (GuideDocument) — no script in it ever runs.
 *
 * @see D-127 · BR-31 · CONSTITUTION Art. 8
 */
final class GuideContent
{
    /** The largest page kept, in bytes: a guide is a document, not an archive. */
    public const MAX_BYTES = 2_097_152;

    public function __construct(private readonly AuditLogger $audit) {}

    /** The guide row for one language, created on first need. */
    public function guideFor(FinalProject $project, string $locale): FinalProjectGuide
    {
        /** @var FinalProjectGuide $guide */
        $guide = FinalProjectGuide::query()->firstOrCreate([
            'final_project_id' => $project->getKey(),
            'locale' => $locale,
        ]);

        return $guide;
    }

    /** The page on show, or null when nothing was ever saved. */
    public function current(FinalProjectGuide $guide): ?FinalProjectGuideVersion
    {
        /** @var FinalProjectGuideVersion|null $version */
        $version = FinalProjectGuideVersion::query()
            ->where('final_project_guide_id', $guide->getKey())
            ->orderByDesc('version')
            ->first();

        return $version;
    }

    /**
     * Store a page as the guide's newest version. Returns the version on show
     * afterwards — the same one when the page did not change, which callers
     * tell apart by its number.
     *
     * @param  array<string, string>  $provenance  where a copied page came from, for the trail
     */
    public function save(
        FinalProject $project,
        string $locale,
        string $html,
        string $source,
        ?User $actor,
        ?int $restoredFrom = null,
        array $provenance = [],
    ): FinalProjectGuideVersion {
        $guide = $this->guideFor($project, $locale);

        return DB::transaction(function () use ($guide, $html, $source, $actor, $restoredFrom, $provenance): FinalProjectGuideVersion {
            FinalProjectGuide::query()->whereKey($guide->getKey())->lockForUpdate()->first();

            $previous = $this->current($guide);
            $sha = hash('sha256', $html);

            if ($previous instanceof FinalProjectGuideVersion && $previous->sha256 === $sha) {
                return $previous;
            }

            $number = $previous === null ? 1 : $previous->version + 1;

            $this->audit->log(
                action: 'final_project_guide.saved',
                entity: $guide,
                before: $previous === null ? null : ['version' => $previous->version, 'sha256' => $previous->sha256],
                after: [
                    'locale' => $guide->locale,
                    'version' => $number,
                    'sha256' => $sha,
                    'bytes' => strlen($html),
                    'source' => $source,
                    'restored_from' => $restoredFrom,
                ] + $provenance,
                actor: $actor,
            );

            /** @var FinalProjectGuideVersion $version */
            $version = FinalProjectGuideVersion::query()->create([
                'final_project_guide_id' => $guide->getKey(),
                'version' => $number,
                'html' => $html,
                'sha256' => $sha,
                'bytes' => strlen($html),
                'source' => $source,
                'restored_from' => $restoredFrom,
                'created_by' => $actor?->getKey(),
            ]);

            return $version;
        });
    }

    /** Bring an older page back — as a new version, so the history keeps both. */
    public function restore(FinalProjectGuide $guide, int $number, User $admin): ?FinalProjectGuideVersion
    {
        /** @var FinalProjectGuideVersion|null $old */
        $old = FinalProjectGuideVersion::query()
            ->where('final_project_guide_id', $guide->getKey())
            ->where('version', $number)
            ->first();

        if (! $old instanceof FinalProjectGuideVersion) {
            return null;
        }

        /** @var FinalProject $project */
        $project = FinalProject::query()->findOrFail((string) $guide->final_project_id);

        return $this->save($project, (string) $guide->locale, (string) $old->html, FinalProjectGuideVersion::SOURCE_RESTORE, $admin, $number);
    }

    /**
     * Copy another cohort's pages into this project, language by language, as
     * new versions. Only the pages travel — availability and publication are
     * this cohort's own decisions. Returns how many languages were copied.
     */
    public function copyFrom(FinalProject $source, FinalProject $target, User $admin): int
    {
        $copied = 0;

        $guides = FinalProjectGuide::query()
            ->where('final_project_id', $source->getKey())
            ->whereIn('locale', FinalProjectGuide::LOCALES)
            ->get();

        foreach ($guides as $guide) {
            $page = $this->current($guide);

            if (! $page instanceof FinalProjectGuideVersion) {
                continue;
            }

            $this->save($target, (string) $guide->locale, (string) $page->html, FinalProjectGuideVersion::SOURCE_COPY, $admin, null, [
                'copied_from_project' => (string) $source->getKey(),
                'copied_from_cohort' => (string) $source->cohort_id,
                'copied_from_version' => (string) $page->version,
            ]);
            $copied++;
        }

        return $copied;
    }
}
