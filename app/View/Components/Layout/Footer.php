<?php

declare(strict_types=1);

namespace App\View\Components\Layout;

use App\Services\Time\Clock;
use App\View\Components\UiComponent;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;

/**
 * Dashboard footer view model.
 *
 * The year is a plain integer. A thousands separator here renders "2,026",
 * which is the bug this class exists to keep out of the template. It comes from
 * Clock and from nowhere else (CONSTITUTION art. 11).
 *
 * A route the router has not registered yet is dropped rather than thrown: the
 * chrome must never take a whole screen down (art. 7).
 *
 * @see PRD §9.5.2 · BR-36 · CONSTITUTION.md Articles 7, 11, 13, 15, 16
 */
final class Footer extends UiComponent
{
    public int $year;

    public ?string $termsUrl;

    public ?string $privacyUrl;

    public string $email;

    public function __construct()
    {
        $this->year = Clock::riyadh()->year;
        $this->termsUrl = Route::has('terms') ? route('terms') : null;
        $this->privacyUrl = Route::has('privacy') ? route('privacy') : null;
        $this->email = (string) config('athar.email');
    }

    public function render(): View
    {
        return view('components.layout.footer');
    }
}
