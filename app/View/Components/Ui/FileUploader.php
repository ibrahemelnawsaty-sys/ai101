<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;

/**
 * File uploader view model (PRD §5.8).
 *
 * Nothing here is a limit: the size and type ceilings printed as a hint are the
 * server's, restated for the participant. The upload itself is validated again
 * on the server, from the file's own content and never its extension
 * (CONSTITUTION art. 5, art. 24).
 *
 * @see PRD §5.8, §5.9 · CONSTITUTION.md Articles 5, 13, 18, 24
 */
final class FileUploader extends UiComponent
{
    public string $fieldId;

    public ?string $message;

    public bool $isDisabled;

    public int $maxBytes;

    public ?string $hintText;

    public ?string $hintId;

    public ?string $errorId;

    public string $describedBy;

    public string $title;

    public bool $multiple;

    public ?int $maxFiles;

    public function __construct(
        public string $variant = 'default',
        public string $size = 'md',
        public string $state = 'default',
        public string $name = 'file',
        ?string $id = null,
        public ?string $label = null,
        public ?string $accept = null,
        mixed $multiple = false,
        mixed $maxFiles = null,
        public mixed $maxMb = null,
        public ?string $typesLabel = null,
        ?string $error = null,
        public ?string $hint = null,
    ) {
        $this->multiple = (bool) $multiple;
        $this->maxFiles = $maxFiles === null ? null : (int) $maxFiles;
        $this->fieldId = $id ?? 'u-'.Str::slug(str_replace(['[', ']', '.'], '-', $name));
        $this->message = self::errorFor($name, $error);
        $this->isDisabled = $state === 'disabled';

        $this->maxBytes = $maxMb !== null ? (int) round(((float) $maxMb) * 1024 * 1024) : 0;

        $this->hintText = $hint ?? $this->buildHint();

        $this->hintId = $this->hintText !== null ? $this->fieldId.'-hint' : null;
        $this->errorId = $this->message !== null ? $this->fieldId.'-error' : null;
        $this->describedBy = self::describedBy($this->errorId, $this->hintId, $this->fieldId.'-note');

        $this->title = (string) __($this->multiple ? 'ui.uploader.title' : 'ui.uploader.title_single');
    }

    private function buildHint(): ?string
    {
        if ($this->typesLabel !== null && $this->maxMb !== null) {
            return (string) __('ui.uploader.hint', ['types' => $this->typesLabel, 'limit' => $this->maxMb]);
        }

        if ($this->typesLabel !== null) {
            return (string) __('ui.uploader.hint_types', ['types' => $this->typesLabel]);
        }

        if ($this->maxMb !== null) {
            return (string) __('ui.uploader.hint_size', ['limit' => $this->maxMb]);
        }

        return null;
    }

    public function render(): View
    {
        return view('components.ui.file-uploader');
    }
}
