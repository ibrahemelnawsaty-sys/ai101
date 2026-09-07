<?php

declare(strict_types=1);

namespace App\Http\Controllers\Participant;

use App\Enums\ResourceType;
use App\Http\Controllers\Concerns\ResolvesActiveCohort;
use App\Http\Controllers\Controller;
use App\Models\Resource;
use App\Models\User;
use App\Presenters\Participant\ResourceGroupPresenter;
use App\Presenters\Participant\ResourcePresenter;
use App\Presenters\Support\Options;
use App\Services\Time\Clock;
use App\Support\ImpersonationContext;
use App\Support\ScreenState;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The training kit (PRD §9.12).
 *
 * The stored path of a file is never rendered. A download goes through this
 * controller, which asks the policy first and only then streams the file off
 * the private disk — so a direct URL to storage does not exist to be guessed
 * (PRD §12.5).
 *
 * The download counter is not touched while an account preview is running: a
 * preview must leave no trace on the previewed account (BR-34).
 *
 * @see BR-22, BR-23, BR-34 · PRD §9.12, §12.5 · CONSTITUTION Art. 22, Art. 23
 */
final class ResourceController extends Controller
{
    use ResolvesActiveCohort;

    /** Lists longer than this are paginated (Art. 19). */
    private const PER_PAGE = 30;

    /** The Article 17 screen name, and the name of its loading skeleton. */
    private const SCREEN = 'resources';

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $this->authorize('viewAny', Resource::class);

        $cohort = $this->activeCohort($user);

        if ($cohort === null) {
            return view('participant.resources', [
                'groups' => new Collection,
                'paginator' => null,
                'weekOptions' => [],
                'typeOptions' => $this->typeOptions(),
                'errorState' => null,
                'screen' => self::SCREEN,
                'screenState' => ScreenState::EMPTY,
            ]);
        }

        $now = Clock::now();

        $paginator = Resource::query()
            ->with(['week', 'session'])
            ->where('cohort_id', $cohort->getKey())
            ->orderBy('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $weeks = $cohort->weeks()->orderBy('index')->get();

        $byWeek = $paginator->getCollection()->groupBy(
            static fn (Resource $item): string => (string) ($item->getAttribute('week_id') ?? '')
        );

        $groups = new Collection;

        foreach ($weeks as $week) {
            $items = $byWeek->get((string) $week->getKey(), new Collection);

            $groups->push(ResourceGroupPresenter::from(
                (string) $week->getAttribute('title'),
                $items->map(
                    static fn (Resource $item): ResourcePresenter => ResourcePresenter::from($item, $now)
                )->values(),
            ));
        }

        // Everything not tied to a week goes on the general shelf (PRD §9.12),
        // and it is only added when it actually holds something.
        $general = $byWeek->get('', new Collection);

        if ($general->isNotEmpty()) {
            $groups->push(ResourceGroupPresenter::from(
                (string) __('resources.general_group'),
                $general->map(
                    static fn (Resource $item): ResourcePresenter => ResourcePresenter::from($item, $now)
                )->values(),
            ));
        }

        return view('participant.resources', [
            'groups' => $groups,
            'paginator' => $paginator,
            'weekOptions' => Options::fromModels(
                $weeks,
                static fn (Model $week): string => (string) $week->getAttribute('title'),
            ),
            'typeOptions' => $this->typeOptions(),
            'errorState' => null,
            'screen' => self::SCREEN,
            'screenState' => ScreenState::of($paginator->getCollection()->isEmpty()),
        ]);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function typeOptions(): array
    {
        return Options::fromEnum(ResourceType::class);
    }

    /**
     * Stream one resource. The policy decides, then the file is read off the
     * private disk; nothing about its location reaches the browser.
     */
    public function download(Resource $resource): StreamedResponse|RedirectResponse
    {
        $this->authorize('download', $resource);

        if ($resource->getAttribute('type') === ResourceType::Link
            || $resource->getAttribute('type') === ResourceType::Video) {
            $url = $resource->getAttribute('external_url');

            if (! is_string($url) || $url === '') {
                abort(HttpResponse::HTTP_NOT_FOUND);
            }

            $this->countDownload($resource);

            return redirect()->away($url);
        }

        $path = $resource->getAttribute('file_url');

        if (! is_string($path) || $path === '' || ! Storage::disk('private')->exists($path)) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        $this->countDownload($resource);

        return Storage::disk('private')->download($path, (string) $resource->getAttribute('title'));
    }

    /** Same guard, inline rather than as an attachment. */
    public function preview(Resource $resource): StreamedResponse
    {
        $this->authorize('download', $resource);

        $path = $resource->getAttribute('file_url');

        if (! is_string($path) || $path === '' || ! Storage::disk('private')->exists($path)) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }

        return Storage::disk('private')->response($path);
    }

    /** BR-34 — a preview never moves a counter on the previewed account. */
    private function countDownload(Resource $resource): void
    {
        if (ImpersonationContext::isActive()) {
            return;
        }

        $resource->increment('download_count');
    }
}
