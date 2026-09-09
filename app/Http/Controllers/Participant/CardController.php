<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Http\Controllers\Concerns\ResolvesActiveCohort;
use App\Http\Controllers\Controller;
use App\Models\DigitalCard;
use App\Models\User;
use App\Presenters\Participant\CardPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The digital participant card (PRD §9.6).
 *
 * The QR on the card points at /verify/{token}, and the token is a long random
 * value stored with the card — never the account's identifier, so the public
 * verification page cannot be walked by counting (BR-25).
 *
 * The card belongs to the signed-in account and is looked up by that account,
 * not by an id in the URL, so there is nothing to tamper with (BR-22).
 *
 * @see BR-22, BR-25 · PRD §9.6 · CONSTITUTION Art. 22
 */
final class CardController extends Controller
{
    use ResolvesActiveCohort;

    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $card = $this->ownCard($user);

        if ($card !== null) {
            $this->authorize('view', $card);
        }

        return view('participant.card', [
            'card' => $card === null ? CardPresenter::missing() : CardPresenter::from($card),
            'errorState' => null,
        ]);
    }

    /**
     * A printable copy of the card.
     *
     * This replaces `download()`, which could never succeed: it read
     * `file_url` — a column that exists on `certificates` and NOT on
     * `digital_cards` — so the value was always null and the route always
     * answered 404. Nothing in the platform ever generated a card file.
     *
     * The browser makes the PDF. A server-side renderer would have to shape
     * Arabic itself, and one that shapes it badly prints the holder's own name
     * broken across their card (D-57).
     */
    public function print(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $card = $this->ownCard($user);

        if ($card === null) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $this->authorize('view', $card);

        return view('participant.card-print', [
            'card' => CardPresenter::from($card),
        ]);
    }

    private function ownCard(User $user): ?DigitalCard
    {
        /** @var DigitalCard|null $card */
        $card = DigitalCard::query()
            ->with(['cohort.program', 'user.profile'])
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->orderByDesc('issued_at')
            ->first();

        return $card;
    }
}
