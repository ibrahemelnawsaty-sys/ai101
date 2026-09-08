<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Base class for every business-rule failure raised by the service layer.
 *
 * The exception carries a translation key — never a literal Arabic string —
 * so that Arabic text lives exclusively in lang/ar/*.php (gate G5).
 *
 * @see CONSTITUTION art. 7 (fail safe) · art. 15 (all text in lang files)
 */
abstract class DomainException extends \RuntimeException
{
    /**
     * @param  array<string, string|int|float>  $replacements
     */
    public function __construct(
        private readonly string $langKey,
        private readonly array $replacements = [],
        private readonly int $status = 422,
        ?\Throwable $previous = null,
    ) {
        // The technical message is the key itself: logs stay language neutral.
        parent::__construct($langKey, 0, $previous);
    }

    public function langKey(): string
    {
        return $this->langKey;
    }

    /**
     * @return array<string, string|int|float>
     */
    public function replacements(): array
    {
        return $this->replacements;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * The user facing message, resolved from the active locale.
     */
    public function localizedMessage(): string
    {
        return (string) __($this->langKey, $this->replacements);
    }

    /**
     * Rendered by Laravel's exception handler.
     */
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return new JsonResponse([
                'message' => $this->localizedMessage(),
                'key' => $this->langKey,
            ], $this->status);
        }

        return redirect()
            ->back()
            ->withInput($request->except(['password', 'password_confirmation']))
            ->withErrors(['message' => $this->localizedMessage()]);
    }
}
