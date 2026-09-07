<?php

declare(strict_types=1);

namespace App\Presenters\Participant;

use App\Models\Certificate;
use App\Presenters\Support\Present;
use App\Support\ViewModel;

/**
 * The accredited copy issued by the external training authority (PRD §1.2).
 *
 * It exists only once the centre has attached the file the authority sent, so
 * the honest answer most of the time is awaiting issue - which the template
 * shows rather than an empty download button.
 *
 * @see BR-25 · PRD §1.2, §9.17
 */
final class TvtcPresenter extends ViewModel
{
    public static function from(?Certificate $certificate): self
    {
        $file = $certificate === null
            ? null
            : Present::text($certificate->getAttribute('tvtc_file_url'));

        return new self([
            'isAvailable' => $file !== null,
            'issuedAt' => $certificate === null
                ? null
                : Present::toDateTime($certificate->getAttribute('issued_at')),
        ]);
    }
}
