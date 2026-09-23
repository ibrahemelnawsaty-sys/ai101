<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Session;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * The single recording a participant has just been authorized to watch
 * (LiveController::recording(), policy `viewRecording`). Unlike
 * RecordingPresenter, this one DOES carry the url — building this page is the
 * one guarded moment that url is ever meant to reach a participant's browser
 * (BR-22, D-107).
 *
 * @see BR-22 · PRD §9.10 · D-107 · CONSTITUTION Art. 5
 */
final class RecordingPlayer extends ViewModel
{
    public static function from(Session $session, string $embedUrl): self
    {
        return new self([
            'topic' => Present::text($session->getAttribute('topic'))
                ?? (string) $session->getAttribute('title'),
            'embedUrl' => $embedUrl,
        ]);
    }
}
