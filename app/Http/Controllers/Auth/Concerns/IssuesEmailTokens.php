<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use App\Enums\EmailTokenType;
use App\Events\EmailTokenIssued;
use App\Models\EmailToken;
use App\Models\User;
use App\Services\Time\Clock;
use Illuminate\Support\Str;

/**
 * Minting and spending the single-use links used for activation and recovery.
 *
 * Only the hash of a token is ever stored, so a leaked database row cannot be
 * replayed as a link. A token is single use (`used_at`) and expires — 24 hours
 * for activation, 30 minutes for recovery (PRD §9.2.3, §9.3.3) — and every
 * instant comes from the server clock (BR-07).
 *
 * Issuing a token invalidates the account's other unused tokens of the same
 * kind, so a second "resend" retires the first link rather than leaving two
 * valid ways in.
 *
 * @see BR-07, BR-29, BR-30 · PRD §9.2.3, §9.3.3 · CONSTITUTION Art. 11, Art. 24
 */
trait IssuesEmailTokens
{
    /** Activation links live for 24 hours (PRD §9.2.3). */
    protected const VERIFY_TOKEN_MINUTES = 1440;

    /** Recovery links live for 30 minutes (PRD §9.3.3). */
    protected const RESET_TOKEN_MINUTES = 30;

    /**
     * Create a token, retire any earlier one of the same kind, and announce it
     * so the mail layer can send it. The raw value is returned to the caller
     * and never stored.
     */
    protected function issueToken(User $user, EmailTokenType $type): string
    {
        $plain = Str::random(64);
        $now = Clock::now();

        EmailToken::query()
            ->where('user_id', $user->getKey())
            ->where('type', $type->value)
            ->whereNull('used_at')
            ->update(['used_at' => $now]);

        /** @var EmailToken $token */
        $token = EmailToken::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => $this->hashToken($plain),
            'type' => $type->value,
            'expires_at' => $now->addMinutes($this->lifetimeMinutes($type)),
            'used_at' => null,
        ]);

        EmailTokenIssued::dispatch($user, $token, $type, $plain, $this->tokenUrl($type, $plain));

        return $plain;
    }

    /**
     * Look up a token that is still usable at the server instant. Anything
     * unusable — spent, expired, unknown — comes back as null, and the caller
     * refuses (CONSTITUTION Art. 7).
     */
    protected function findUsableToken(string $plain, EmailTokenType $type): ?EmailToken
    {
        $plain = trim($plain);

        if ($plain === '' || mb_strlen($plain) > 255) {
            return null;
        }

        /** @var EmailToken|null $token */
        $token = EmailToken::query()
            ->with('user')
            ->where('token_hash', $this->hashToken($plain))
            ->where('type', $type->value)
            ->first();

        if ($token === null || ! $token->isUsableAt(Clock::now())) {
            return null;
        }

        return $token;
    }

    /**
     * SHA-256 of the raw token. A password hash would be wrong here: the value
     * is already 64 random characters, and the lookup must be a single indexed
     * equality rather than a scan of every row.
     */
    protected function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    protected function lifetimeMinutes(EmailTokenType $type): int
    {
        return $type === EmailTokenType::Reset
            ? self::RESET_TOKEN_MINUTES
            : self::VERIFY_TOKEN_MINUTES;
    }

    protected function tokenUrl(EmailTokenType $type, string $plain): string
    {
        return $type === EmailTokenType::Reset
            ? route('password.reset', ['token' => $plain])
            : route('verify-email', ['token' => $plain]);
    }
}
