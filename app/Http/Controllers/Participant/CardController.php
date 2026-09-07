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
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function download(Request $request): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();

        $card = $this->ownCard($user);

        if ($card === null) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $this->authorize('download', $card);

        $path = $card->getAttribute('file_url');

        if (! is_string($path) || $path === '' || ! Storage::disk('generated')->exists($path)) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        return Storage::disk('generated')->download($path);
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
