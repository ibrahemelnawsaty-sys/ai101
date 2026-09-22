<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\ZoomRecordingUrl;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A recorded-session link: a plain `https://…zoom.us/…` url, or Zoom's own
 * `<iframe>` embed snippet — either is accepted, but what is ultimately
 * stored (via the FormRequest's `prepareForValidation()`) is always the
 * bare url ZoomRecordingUrl::extract() pulled out of it, never markup.
 *
 * @see App\Support\ZoomRecordingUrl · CONSTITUTION Article 24 · D-107
 */
final class ValidZoomRecordingUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        if (ZoomRecordingUrl::extract($value) === null) {
            $fail(__('trainer.sessions.errors.recording_url_invalid'));
        }
    }
}
