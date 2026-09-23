<?php

declare(strict_types=1);

namespace App\Http\Requests\Trainer;

use App\Models\Session;
use App\Rules\ValidZoomRecordingUrl;
use App\Support\ZoomRecordingUrl;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Posting a recording link for a finished session — the one field a
 * coordinator may touch on a session they do not otherwise manage
 * (SessionPolicy::updateRecording(), D-105). A trainer or admin may use the
 * same narrow endpoint too; nothing here grants more than the policy already
 * allows for the acting account, which stays scoped to their own cohort like
 * every other attendance ability (BR-22, BR-23).
 *
 * @see D-105, D-107 · CONSTITUTION Art. 22, Art. 24
 */
final class UpdateSessionRecordingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $session = $this->route('session');

        return $session instanceof Session
            && $this->user() !== null
            && $this->user()->can('updateRecording', $session);
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('recording_url');

        if (is_string($raw) && trim($raw) !== '') {
            $extracted = ZoomRecordingUrl::extract($raw);

            if ($extracted !== null) {
                $this->merge(['recording_url' => $extracted]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'recording_url' => ['nullable', 'string', 'max:3000', new ValidZoomRecordingUrl],
        ];
    }

    public function recordingUrl(): ?string
    {
        $url = $this->validated('recording_url');

        return is_string($url) && $url !== '' ? $url : null;
    }

    public function trainingSession(): Session
    {
        /** @var Session $session */
        $session = $this->route('session');

        return $session;
    }
}
