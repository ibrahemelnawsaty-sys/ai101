<?php

declare(strict_types=1);

/**
 * The participant dashboard keeps standing when one of its cards cannot be built.
 *
 * WHY THIS SUITE EXISTS
 * The template has an error branch for each of its nine cards, keyed by
 * `$failedBlocks`, and the controller passed `'failedBlocks' => []` as a literal.
 * Every branch was dead code: one presenter throwing took the first screen of
 * every trainee to a 500 (D-66).
 *
 * Two halves of one contract live in two files, and nothing compared them —
 * the recurring failure class of this project. The first case compares them.
 *
 * @see PRD §9.4 · D-66
 */

use App\Models\Resource;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 09:00:00'));

    $this->cohort = makeCohort();
    $this->participant = makeParticipant($this->cohort);
});

it('D-66: كل مفتاح خطأ يقرؤه القالب يبنيه المتحكّم، والعكس', function (): void {
    $template = (string) file_get_contents(resource_path('views/participant/dashboard.blade.php'));
    $controller = (string) file_get_contents(app_path('Http/Controllers/Participant/DashboardController.php'));

    preg_match_all('/in_array\(\'([A-Za-z]+)\',\s*\$failedBlocks/', $template, $read);
    preg_match_all('/\$this->block\(\'([A-Za-z]+)\'/', $controller, $built);

    $read = array_values(array_unique($read[1]));
    $built = array_values(array_unique($built[1]));
    sort($read);
    sort($built);

    expect($read)->toHaveCount(9)
        ->and($built)->toBe($read);
});

it('D-66: اللوحة العادية لا تعرض أي حالة خطأ', function (): void {
    $response = $this->actingAs($this->participant)->get(route('dashboard'));

    $response->assertOk();
    expect(substr_count((string) $response->getContent(), e((string) __('errors.block_unavailable'))))->toBe(0);
});

it('D-66: بطاقة ترمي تعرض خطأها وحدها وتبقى الصفحة، ولا تُسجَّل رسالة الاستثناء', function (): void {
    Resource::query()->create([
        'cohort_id' => $this->cohort->getKey(),
        'title' => 'Week 1 slides',
        'type' => 'link',
        'external_url' => 'https://example.com/slides',
    ]);

    // The message stands in for what a failed query carries: bound values.
    Resource::retrieved(static function (): never {
        throw new RuntimeException('CANARY-bound-value@example.com');
    });

    Log::spy();

    $response = $this->actingAs($this->participant)->get(route('dashboard'));

    $response->assertOk();
    expect(substr_count((string) $response->getContent(), e((string) __('errors.block_unavailable'))))->toBe(1);

    Log::shouldHaveReceived('error')
        ->withArgs(static fn (string $message, array $context): bool => $message === 'dashboard.block_failed'
            && $context['block'] === 'resources'
            && $context['exception'] === RuntimeException::class
            && ! str_contains((string) json_encode($context), 'CANARY'))
        ->once();
});
