<?php

declare(strict_types=1);

namespace App\Presenters\Trainer;

use App\Models\Session;
use App\Support\Dates;
use App\Support\ViewModel;

/**
 * One finished session on the shared attendance screen's recording list.
 * `recordingUrl` here only ever reaches trainer, admin or coordinator staff
 * of the cohort, never a participant page (BR-22).
 *
 * @see D-107 · CONSTITUTION Art. 22, Art. 24
 */
final class RecordingRow extends ViewModel
{
    public static function from(Session $session, string $editHref): self
    {
        $url = $session->getAttribute('recording_url');
        $hasRecording = is_string($url) && $url !== '';

        return new self([
            'id' => (string) $session->getKey(),
            'topic' => (string) ($session->getAttribute('topic') ?? $session->getAttribute('title') ?? '—'),
            'date' => Dates::shortDate($session->getAttribute('date')),
            'hasRecording' => $hasRecording,
            'recordingUrl' => $hasRecording ? $url : null,
            'editHref' => $editHref,
        ]);
    }
}
