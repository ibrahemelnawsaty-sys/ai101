<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Enums\EmailTokenType;
use App\Mail\AtharLetter;
use App\Mail\EmailTokenLink;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Mail\Mailable;

/**
 * A template as its letter will look, rendered from a DRAFT — nothing stored,
 * nothing sent.
 *
 * It goes through the same path as a real letter: the draft is laid over the
 * copy the letter reads (EmailOverrides::preview), a real AtharLetter is
 * rendered in the Athar shell, and the draft is taken away again before the
 * method returns. So what the editor shows is what the shell will print, not a
 * second rendering that could drift from it (Constitution, Article 6).
 *
 * The live values are SAMPLES, named as samples in the editor: no real person's
 * name, no real serial. A value nobody supplied a sample for is left as the
 * text typed — the letter shows the gap instead of hiding it.
 *
 * It does not send. Nothing here reports a letter delivered: that needs a real
 * send through the host's mail server (D-02).
 *
 * @see BR-31 · PRD §9.16 · D-02, D-114, D-136
 */
final class EmailPreview
{
    public function __construct(
        private readonly EmailTemplates $templates,
        private readonly EmailOverrides $overrides,
    ) {}

    /**
     * @return array{subject: string, html: string}
     */
    public function render(string $template, ?string $subject, ?string $body): array
    {
        $this->overrides->preview($this->draft($template, $subject, $body));

        try {
            $letter = $this->letter($template);

            return [
                'subject' => (string) $letter->envelope()->subject,
                'html' => $letter->render(),
            ];
        } finally {
            $this->overrides->endPreview();
        }
    }

    /**
     * The letter this template is sent as. The three that carry a link with a
     * token — verification, password reset, invitation link — are a Mailable of
     * their own with a layout of their own (the expiry note, the fallback link),
     * and the editor must show THAT letter, not another shell. Every other
     * template is one AtharLetter.
     *
     * The preview of the rest is the letter shell with sample values: the
     * invitation's credentials block and the receipt's QR are drawn by their own
     * Mailables and are not reproduced here — their subject and body are.
     */
    private function letter(string $template): Mailable
    {
        $type = match ($template) {
            'password_reset' => EmailTokenType::Reset,
            'invitation_link' => EmailTokenType::Invite,
            'verify' => EmailTokenType::Verify,
            default => null,
        };

        if ($type !== null) {
            return new EmailTokenLink($this->sampleUser(), $type, (string) config('app.url'));
        }

        return new AtharLetter(
            copyKey: EmailTemplates::GROUP.'.'.$template,
            values: $this->samples($template),
            ctaUrl: (string) config('app.url'),
        );
    }

    /** A person who exists only for the picture: never saved, never mailed. */
    private function sampleUser(): User
    {
        $user = new User(['email' => $this->sample('email')]);
        $profile = new Profile;
        $profile->setAttribute('first_name_ar', $this->sample('name'));
        $user->setRelation('profile', $profile);

        return $user;
    }

    /**
     * The values a letter of this template is rendered with: a sample for every
     * live value its copy uses.
     *
     * @return array<string, string>
     */
    public function samples(string $template): array
    {
        $samples = [];

        foreach ($this->templates->allowedIn($template) as $name) {
            $samples[$name] = $name === 'program'
                ? (string) config('athar.program_name')
                : $this->sample($name);
        }

        return $samples;
    }

    /** The sample of one live value, or '' when nobody wrote one. */
    public function sample(string $name): string
    {
        $key = 'admin.email_editor.samples.'.$name;
        $text = __($key);

        return is_string($text) && $text !== $key ? $text : '';
    }

    /**
     * The published copy with this draft over the two editable fields. An empty
     * draft is "follow the file", exactly as saving it would be.
     *
     * @return array<string, array{ar: string|null, en: string|null}>
     */
    private function draft(string $template, ?string $subject, ?string $body): array
    {
        $state = app(EmailOverrides::class)->published();

        foreach (['subject' => $subject, 'body' => $body] as $field => $text) {
            if (! in_array($field, $this->templates->fieldsOf($template), true)) {
                continue;
            }

            $key = EmailTemplates::GROUP.'.'.$template.'.'.$field;

            if ($this->templates->isOverride($template, $field, $text)) {
                $state[$key] = ['ar' => trim((string) $text), 'en' => null];
            } else {
                unset($state[$key]);
            }
        }

        return $state;
    }
}
