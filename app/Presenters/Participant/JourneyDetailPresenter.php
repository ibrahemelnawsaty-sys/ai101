<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Enums\AttendanceStatus;
use App\Presenters\Support\Present;
use App\Support\ViewModel;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One line inside an expanded journey step: a training day with its attendance,
 * or a weekly assignment with its hand-in state (PRD §9.7.2).
 *
 * Nothing here decides completion. The line reports the row that exists — an
 * attendance record or a submission — and JourneyEvaluator alone decides
 * whether the step around it is complete (BR-21).
 *
 * @see BR-21 · PRD §9.7.2
 */
final class JourneyDetailPresenter extends ViewModel
{
    public static function forSession(string $title, ?DateTimeInterface $occursAt, ?AttendanceStatus $status): self
    {
        return new self([
            'title' => $title,
            'occursAt' => $occursAt,
            'statusLabel' => $status?->label() ?? (string) __('enums.attendance_status.absent'),
            'variant' => match ($status) {
                AttendanceStatus::Present, AttendanceStatus::Excused => 'success',
                AttendanceStatus::Late, AttendanceStatus::Incomplete => 'warning',
                default => 'error',
            },
            'icon' => match ($status) {
                AttendanceStatus::Present, AttendanceStatus::Excused => 'check',
                AttendanceStatus::Late, AttendanceStatus::Incomplete => 'clock',
                default => 'warn',
            },
        ]);
    }

    public static function forAssignment(
        string $title,
        ?DateTimeInterface $dueAt,
        bool $isSubmitted,
        CarbonImmutable $now,
    ): self {
        $overdue = ! $isSubmitted && Present::hasPassed($dueAt, $now);

        return new self([
            'title' => $title,
            'occursAt' => $dueAt,
            'statusLabel' => $isSubmitted
                ? (string) __('assignments.state.submitted')
                : (string) __('assignments.state.not_submitted'),
            'variant' => $isSubmitted ? 'success' : ($overdue ? 'error' : 'warning'),
            'icon' => $isSubmitted ? 'check' : ($overdue ? 'warn' : 'clock'),
        ]);
    }
}
