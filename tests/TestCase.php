<?php

declare(strict_types=1);

namespace Tests;

use App\Services\Time\Clock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Route;

/**
 * Base test case for the whole suite.
 *
 * Enforces three invariants that the Constitution requires of every test:
 *  - the frozen clock never leaks from one test into the next (Article 11);
 *  - no test may hide an N+1 query (Article 19);
 *  - no test may silently discard a model attribute that does not exist,
 *    which is how a renamed column slips past a green suite.
 *
 * @see CONSTITUTION.md Articles 11, 19 · PROJECT-CONTRACT.md §5, §14
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Clock::reset();

        Model::preventLazyLoading();
        Model::preventSilentlyDiscardingAttributes();
    }

    protected function tearDown(): void
    {
        Clock::reset();

        parent::tearDown();
    }

    /**
     * Every named route the contract fixes, so the authorization suite can walk
     * them without hard-coding URLs that a refactor would silently orphan.
     *
     * @return list<string>
     */
    protected function namedRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): ?string => $route->getName())
            ->filter()
            ->values()
            ->all();
    }
}
