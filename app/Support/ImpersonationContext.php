<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Session;

/**
 * Session-level facts about an active account preview (impersonation).
 *
 * This class knows only "who is previewing whom, since when". The read-only
 * guarantee itself is enforced by middleware, by every Policy and by the data
 * layer — never by this class and never by the UI.
 *
 * @see BR-33, BR-34, BR-35 · PRD §4.5 · CONSTITUTION Art. 23
 */
final class ImpersonationContext
{
    /** Session key holding the preview payload. */
    public const SESSION_KEY = 'athar_impersonation';

    /** Hard ceiling for a preview session, in minutes (PRD §4.5.2). */
    public const MAX_MINUTES = 30;

    /**
     * Route names that stay reachable with a non-GET verb while previewing.
     * Ending the preview and logging out must never be blocked.
     *
     * @var list<string>
     */
    public const ESCAPE_ROUTES = ['admin.impersonation.stop', 'logout'];

    private function __construct()
    {
        // Static-only helper.
    }

    /**
     * @return array{admin_id: string, target_id: string, record_id: string, started_at: string}|null
     */
    public static function payload(): ?array
    {
        $data = Session::get(self::SESSION_KEY);

        if (! is_array($data)) {
            return null;
        }

        foreach (['admin_id', 'target_id', 'record_id', 'started_at'] as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key]) || $data[$key] === '') {
                return null;
            }
        }

        /** @var array{admin_id: string, target_id: string, record_id: string, started_at: string} $data */
        return $data;
    }

    public static function isActive(): bool
    {
        return self::payload() !== null;
    }

    public static function adminId(): ?string
    {
        return self::payload()['admin_id'] ?? null;
    }

    public static function targetId(): ?string
    {
        return self::payload()['target_id'] ?? null;
    }

    public static function recordId(): ?string
    {
        return self::payload()['record_id'] ?? null;
    }

    public static function startedAt(): ?CarbonImmutable
    {
        $payload = self::payload();

        if ($payload === null) {
            return null;
        }

        return CarbonImmutable::parse($payload['started_at'], 'UTC');
    }

    public static function expiresAt(): ?CarbonImmutable
    {
        return self::startedAt()?->addMinutes(self::MAX_MINUTES);
    }

    /** Seconds left before the preview ends by itself; 0 when inactive or expired. */
    public static function remainingSeconds(): int
    {
        $expiresAt = self::expiresAt();

        if ($expiresAt === null) {
            return 0;
        }

        $left = (int) Clock::now()->diffInSeconds($expiresAt, false);

        return max($left, 0);
    }

    public static function hasExpired(): bool
    {
        $expiresAt = self::expiresAt();

        return $expiresAt !== null && Clock::now()->greaterThanOrEqualTo($expiresAt);
    }

    public static function begin(string $adminId, string $targetId, string $recordId): void
    {
        Session::put(self::SESSION_KEY, [
            'admin_id' => $adminId,
            'target_id' => $targetId,
            'record_id' => $recordId,
            'started_at' => Clock::now()->toIso8601String(),
        ]);
    }

    /**
     * Remove the preview state and hand back what it held.
     *
     * @return array{admin_id: string, target_id: string, record_id: string, started_at: string}|null
     */
    public static function clear(): ?array
    {
        $payload = self::payload();

        Session::forget(self::SESSION_KEY);

        return $payload;
    }
}
