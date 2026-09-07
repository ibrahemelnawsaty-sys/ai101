<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\AttendanceStatus;

/**
 * The single place that decides which attendance statuses count as "attended"
 * when an attendance rate is computed.
 *
 * The set is configurable (config/athar.php) so that a product decision changes
 * configuration rather than code — see BR-31.
 *
 * The fallback below is NOT an approved rule. PRD §9.9.5 names `excused` and
 * `incomplete` but never says how they weigh in the rate, and Constitution
 * art. 4 forbids assuming anything in the attendance and certificate domains.
 * The question is escalated as D-26 in docs/03-decisions/DECISIONS.md, and this
 * class is the ONE place the pending reading lives, so that the seeder, the
 * eligibility service and the tests all read the same answer while it is
 * pending. Approving D-26 sets athar.attendance.counted_as_attended and does
 * not touch this file.
 *
 * Pending reading: `excused` counts as attended; `incomplete` does not.
 *
 * @see BR-08, BR-09, BR-26 · D-26 · PRD §9.9.5 · PRD §9.17
 */
final class AttendanceCounting
{
    /**
     * Statuses that count towards the numerator of the attendance rate.
     *
     * @see D-26 — the fallback set is a declared pending reading, not a ruling.
     *
     * @return list<AttendanceStatus>
     */
    public static function countedAsAttended(): array
    {
        $configured = config('athar.attendance.counted_as_attended');

        if (is_array($configured) && $configured !== []) {
            $resolved = [];

            foreach ($configured as $value) {
                $status = self::toStatus($value);

                if ($status instanceof AttendanceStatus) {
                    $resolved[] = $status;
                }
            }

            if ($resolved !== []) {
                return array_values(array_unique($resolved, SORT_REGULAR));
            }
        }

        return [
            AttendanceStatus::Present,
            AttendanceStatus::Late,
            AttendanceStatus::Excused,
        ];
    }

    /**
     * The same set as raw column values, ready for a whereIn() clause.
     *
     * @return list<string>
     */
    public static function countedAsAttendedValues(): array
    {
        return array_map(
            static fn (AttendanceStatus $status): string => $status->value,
            self::countedAsAttended(),
        );
    }

    /**
     * Statuses produced by an actual, physical check-in. Used by the journey
     * steps that the contract words as "present or late" (CONTRACT §9).
     *
     * @return list<AttendanceStatus>
     */
    public static function physicallyPresent(): array
    {
        return [AttendanceStatus::Present, AttendanceStatus::Late];
    }

    /**
     * @return list<string>
     */
    public static function physicallyPresentValues(): array
    {
        return array_map(
            static fn (AttendanceStatus $status): string => $status->value,
            self::physicallyPresent(),
        );
    }

    public static function counts(AttendanceStatus $status): bool
    {
        return in_array($status, self::countedAsAttended(), true);
    }

    private static function toStatus(mixed $value): ?AttendanceStatus
    {
        if ($value instanceof AttendanceStatus) {
            return $value;
        }

        return is_string($value) ? AttendanceStatus::tryFrom($value) : null;
    }
}
