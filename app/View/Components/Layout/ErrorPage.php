<?php

declare(strict_types=1);

namespace App\View\Components\Layout;

use App\Support\ErrorNavigation;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * View model for the error page shell.
 *
 * The shell is a complete, self-contained HTML document that extends no layout
 * and touches no model: it has to render when the thing it is reporting is the
 * layout, the database or the session.
 *
 * Everything the eight error views used to work out for themselves - which
 * routes exist, where "back" leads, which suggestions to offer, how long a rate
 * limiter wants the visitor to wait - is decided here and in ErrorNavigation,
 * so the templates render and never decide (Article 13 item 13). Given only a
 * status code the component fills in the rest from lang/ar/errors.php.
 *
 * @see PRD §8, §11.1 · BR-07, BR-30 · CONSTITUTION.md Articles 7, 13, 15, 17, 24
 */
final class ErrorPage extends Component
{
    public string $title;

    public string $body;

    public string $action;

    public string $href;

    /** @var list<array{label: string, href: string}> */
    public array $links;

    public string $homeUrl;

    public string $platform;

    public string $email;

    public ?string $retryAfter;

    /**
     * @param  array<int, array{label?: string, href?: string}>|null  $links  explicit links; null asks for the code's own suggestions
     */
    public function __construct(
        public string $code = '',
        ?string $title = null,
        ?string $body = null,
        ?string $action = null,
        ?string $href = null,
        ?array $links = null,
        public ?string $ref = null,
        mixed $exception = null,
    ) {
        $this->title = $title ?? (string) __('errors.pages.'.$code.'.title');
        $this->body = $body ?? (string) __('errors.pages.'.$code.'.body');
        $this->action = $action ?? (string) __(ErrorNavigation::actionKey($code));
        $this->href = $href ?? ErrorNavigation::actionHref($code);
        $this->links = $links === null
            ? ErrorNavigation::suggestions($code)
            : array_values(array_filter(
                $links,
                static fn (mixed $link): bool => is_array($link) && ! empty($link['href']) && ! empty($link['label'])
            ));

        $this->homeUrl = ErrorNavigation::home();
        $this->platform = (string) config('athar.platform_name');
        $this->email = (string) config('athar.email');
        $this->retryAfter = ErrorNavigation::retryAfter($exception);
    }

    public function render(): View
    {
        return view('components.layout.error-page');
    }
}
