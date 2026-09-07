<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\EnrollmentRole;
use App\Http\Controllers\Controller;
use App\Models\DigitalCard;
use App\Models\Enrollment;
use App\Services\Time\Clock;
use App\Services\Time\RiyadhFormatter;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;

/**
 * Public verification of a trainee's digital card.
 *
 * Whoever lands here has no session of any kind, so BR-25 is obeyed literally:
 * first name, family name, programme, cohort, role, issue date and status —
 * and nothing else. No address, no telephone number, no grade, no attendance
 * figure, not even the holder's identifier.
 *
 * An unknown token and a revoked card are told apart deliberately: an unknown
 * token yields the empty state, so the page never confirms that a guessed token
 * nearly matched something.
 *
 * @see BR-25 · PRD §9.6, §12.6 · PROJECT-CONTRACT §10 · CONSTITUTION Art. 7
 */
final class CardVerificationController extends Controller
{
    /** The Article 17 screen name, and the name of its loading skeleton. */
    private const SCREEN = 'card';

    public function __construct(private readonly RiyadhFormatter $formatter) {}

    public function show(string $token): View
    {
        $card = $this->find($token);

        if ($card === null) {
            return view('public.card-verify', [
                'screen' => self::SCREEN,
                'screenState' => ScreenState::EMPTY,
                'card' => [],
                'checkedAt' => $this->formatter->dateTime(Clock::now()),
            ]);
        }

        return view('public.card-verify', [
            'screen' => self::SCREEN,
            'screenState' => ScreenState::NORMAL,
            'card' => $this->publicFacts($card),
            'checkedAt' => $this->formatter->dateTime(Clock::now()),
        ]);
    }

    /**
     * Tokens are long random values, so a plain equality lookup is enough; the
     * column is unique and indexed. A token that is not a plausible token at
     * all is refused before the query runs.
     */
    private function find(string $token): ?DigitalCard
    {
        $token = trim($token);

        if ($token === '' || mb_strlen($token) > 255) {
            return null;
        }

        /** @var DigitalCard|null $card */
        $card = DigitalCard::query()
            ->with(['user.profile', 'cohort.program'])
            ->where('qr_token', $token)
            ->first();

        return $card;
    }

    /**
     * The seven facts BR-25 allows, already formatted for display.
     *
     * @return array<string, mixed>
     */
    private function publicFacts(DigitalCard $card): array
    {
        $profile = $card->user?->profile;
        $cohort = $card->cohort;
        $issuedAt = $card->getAttribute('issued_at');

        return [
            'first_name' => $profile?->getAttribute('first_name_ar') ?? '',
            'family_name' => $profile?->getAttribute('last_name_ar') ?? '',
            'program' => $cohort?->program?->getAttribute('name_ar') ?? '',
            'cohort' => $cohort?->getAttribute('name') ?? '',
            'role' => $this->roleLabel($card),
            'issued_at' => $issuedAt === null ? '' : $this->formatter->date($issuedAt),
            'is_revoked' => $card->getAttribute('revoked_at') !== null,
        ];
    }

    /**
     * The role the holder carries inside that cohort, resolved from the
     * enrolment row rather than from the account's global role (PRD §4.4).
     */
    private function roleLabel(DigitalCard $card): string
    {
        $role = Enrollment::query()
            ->where('cohort_id', $card->getAttribute('cohort_id'))
            ->where('user_id', $card->getAttribute('user_id'))
            ->value('role_in_cohort');

        $resolved = is_string($role)
            ? EnrollmentRole::tryFrom($role)
            : null;

        return ($resolved ?? EnrollmentRole::Participant)->label();
    }
}
