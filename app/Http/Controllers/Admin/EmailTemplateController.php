<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PreviewEmailTemplateRequest;
use App\Http\Requests\Admin\ResetEmailTemplateRequest;
use App\Http\Requests\Admin\UpdateEmailTemplateRequest;
use App\Models\EmailTemplateOverride;
use App\Models\User;
use App\Presenters\Admin\EmailTemplateEditor;
use App\Services\Audit\AuditLogger;
use App\Services\Mail\EmailOverrides;
use App\Services\Mail\EmailPreview;
use App\Services\Mail\EmailTemplates;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The e-mail template editor (BR-31, D-136): the SUBJECT and the BODY of a
 * template, over the file's defaults.
 *
 * Which texts may be written, and what each must keep, is EmailTemplates —
 * the same rules whether the browser shows them while typing or the server
 * refuses on save. What a letter then says is the copy the translator loads
 * (EmailContentLoader), so every Mailable prints the edited words without a
 * call site changing.
 *
 * Every change is written to the audit trail BEFORE the rows change, inside the
 * same transaction (art. 8), with what each field held and now holds. Nothing
 * here sends a letter and nothing claims one was delivered (D-02): the preview
 * is a picture of the letter, rendered from a draft that is never stored.
 *
 * The system administrator's alone (D-117), and never from an account preview
 * (BR-33): `console.settings` and `not.impersonating` say so twice.
 *
 * @see BR-31, BR-33, BR-36 · PRD §9.16, §9.18 · CONSTITUTION art. 5, art. 8, art. 22 · D-02, D-114, D-117, D-136
 */
final class EmailTemplateController extends Controller
{
    /** The audit entity: the template's own key stands where an id would. */
    private const ENTITY = 'email_template';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EmailTemplates $templates,
        private readonly EmailOverrides $overrides,
        private readonly EmailPreview $preview,
    ) {}

    public function edit(string $template): View
    {
        $this->authorize('console.settings');

        if (! in_array($template, $this->templates->keys(), true)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return view('admin.email-template', [
            'contextLabel' => null,
            'editor' => EmailTemplateEditor::of($template, $this->templates, $this->preview, $this->overrides),
            'errorState' => null,
        ]);
    }

    public function update(UpdateEmailTemplateRequest $request, string $template): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $before = [];
        $after = [];
        $plan = [];

        foreach ($request->texts() as $field => $text) {
            $key = EmailTemplates::GROUP.'.'.$template.'.'.$field;
            $current = EmailTemplateOverride::query()->find($key)?->getAttribute('ar');
            $next = $this->templates->isOverride($template, $field, $text) ? $text : null;

            // Already what it should be — no row change and, if nothing else
            // changed either, no entry in the trail.
            if ($current === $next) {
                continue;
            }

            $before[$field] = $current;
            $after[$field] = $next;
            $plan[$key] = $next;
        }

        if ($plan !== []) {
            DB::transaction(function () use ($template, $before, $after, $plan, $actor): void {
                $this->audit->record(
                    action: 'email_template.updated',
                    entityType: self::ENTITY,
                    entityId: $template,
                    before: $before,
                    after: $after,
                );

                foreach ($plan as $key => $next) {
                    $this->write($key, $next, $actor);
                }
            });

            $this->overrides->forget();
        }

        // A text identical to the original is not a customisation and is stored as none: saying
        // «the new wording is on its way» about a template that just went back to the default
        // would be false (D-147).
        $customised = false;

        foreach ($request->texts() as $field => $text) {
            $customised = $customised || $this->templates->isOverride($template, $field, $text);
        }

        return redirect()
            ->route('admin.settings.template', $template)
            ->with('status', $customised ? __('admin.email_editor.saved') : __('admin.email_editor.saved_default'));
    }

    public function reset(ResetEmailTemplateRequest $request, string $template): RedirectResponse
    {
        $prefix = EmailTemplates::GROUP.'.'.$template.'.';

        $held = EmailTemplateOverride::query()
            ->where('key', 'like', $prefix.'%')
            ->pluck('ar', 'key')
            ->all();

        if ($held !== []) {
            DB::transaction(function () use ($template, $held, $prefix): void {
                $this->audit->record(
                    action: 'email_template.reset',
                    entityType: self::ENTITY,
                    entityId: $template,
                    before: $held,
                    after: null,
                );

                EmailTemplateOverride::query()->where('key', 'like', $prefix.'%')->delete();
            });

            $this->overrides->forget();
        }

        return redirect()
            ->route('admin.settings.template', $template)
            ->with('status', __('admin.email_editor.reset_done'));
    }

    /**
     * The letter as it will look, from a draft — with the problems a save would
     * refuse it for, told alongside in the same sentences. Reading only.
     */
    public function preview(PreviewEmailTemplateRequest $request, string $template): JsonResponse
    {
        $texts = $request->texts();
        $rendered = $this->preview->render($template, $texts['subject'] ?? null, $texts['body'] ?? null);

        $messages = [];

        foreach ($texts as $field => $text) {
            $problem = $this->templates->isOverride($template, $field, $text)
                ? $this->templates->problemWith($template, $field, $text)
                : null;

            $messages[$field] = $problem === null ? null : UpdateEmailTemplateRequest::message($problem);
        }

        return new JsonResponse([
            'subject' => $rendered['subject'],
            'html' => $rendered['html'],
            'messages' => $messages,
        ]);
    }

    /**
     * Fill or clear the Arabic column of one key. A row whose two columns are
     * both empty carries nothing and is deleted: "original" is the absence of a
     * row, never a frozen copy (D-114).
     */
    private function write(string $key, ?string $ar, User $actor): void
    {
        $row = EmailTemplateOverride::query()->find($key);

        if ($ar === null) {
            if ($row === null) {
                return;
            }

            if ($row->getAttribute('en') === null) {
                $row->delete();

                return;
            }
        }

        EmailTemplateOverride::query()->updateOrCreate(
            ['key' => $key],
            ['ar' => $ar, 'updated_by' => $actor->getKey()],
        );
    }
}
