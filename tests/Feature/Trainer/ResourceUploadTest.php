<?php

declare(strict_types=1);

/**
 * The trainer's training-kit upload form had drifted from the server it posts
 * to: the view sent `type`/`external_url`/`files[]`, the FormRequest read
 * `resource_type`/`url`/`file`, and no HTTP test ever posted the form to
 * notice — the one existing resource test in CohortNoticesTest builds its own
 * correct payload by hand, bypassing the view entirely.
 *
 * @see BR-23 · PRD §9.12, §12.5
 */

use App\Models\Resource;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->cohort = makeCohort();
    $this->trainer = makeTrainer($this->cohort);
});

it('رفع مورد من نوع ملف بحقول صحيحة ينشئ السجل ويظهر في القائمة فورًا', function (): void {
    Storage::fake('private');

    $this->actingAs($this->trainer)
        ->post(route('trainer.resources.store', ['cohort' => $this->cohort->id]), [
            'title' => 'CANARY-FILE-RESOURCE',
            'resource_type' => 'file',
            'file' => fakeUpload('guide.pdf'),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $resource = Resource::query()->where('title', 'CANARY-FILE-RESOURCE')->sole();

    expect($resource->type->value)->toBe('file')
        ->and($resource->file_url)->not->toBeNull();

    $this->actingAs($this->trainer)
        ->get(route('trainer.resources'))
        ->assertOk()
        ->assertSee('CANARY-FILE-RESOURCE');
});

it('فشل رفع المورد يعرض خطأ فعليًا على الحقل الناقص لا صفحة صامتة', function (): void {
    $this->actingAs($this->trainer)
        ->post(route('trainer.resources.store', ['cohort' => $this->cohort->id]), [
            'title' => 'CANARY-INVALID',
            'resource_type' => 'file',
            // No file and no url: the type is "file", so `file` must fail.
        ])
        ->assertSessionHasErrors(['file']);

    expect(Resource::query()->where('title', 'CANARY-INVALID')->exists())->toBeFalse();
});

it('رفع مورد من نوع رابط يتحقق من صحة الرابط لا من وجود ملف', function (): void {
    $this->actingAs($this->trainer)
        ->post(route('trainer.resources.store', ['cohort' => $this->cohort->id]), [
            'title' => 'CANARY-LINK-RESOURCE',
            'resource_type' => 'link',
            'url' => 'not-a-valid-url',
        ])
        ->assertSessionHasErrors(['url']);

    expect(Resource::query()->where('title', 'CANARY-LINK-RESOURCE')->exists())->toBeFalse();

    $this->actingAs($this->trainer)
        ->post(route('trainer.resources.store', ['cohort' => $this->cohort->id]), [
            'title' => 'CANARY-LINK-RESOURCE',
            'resource_type' => 'link',
            'url' => 'https://example.com/prompting-guide',
        ])
        ->assertSessionHasNoErrors();

    expect(Resource::query()->where('title', 'CANARY-LINK-RESOURCE')->sole()->file_url)->toBeNull();
});

it('أرشفة مورد تُبقيه محذوفًا ناعمًا ويمكن استعادته من نفس المسار', function (): void {
    $resource = Resource::factory()->for($this->cohort)->create(['title' => 'CANARY-ARCHIVE']);

    $this->actingAs($this->trainer)
        ->delete(route('trainer.resources.archive', $resource))
        ->assertRedirect();

    expect($resource->fresh()->trashed())->toBeTrue();

    $this->actingAs($this->trainer)
        ->get(route('trainer.resources'))
        ->assertOk()
        ->assertDontSee('CANARY-ARCHIVE');

    // The same DELETE route must still find an already-archived resource
    // (Resource::withTrashed() on the route) in order to restore it.
    $this->actingAs($this->trainer)
        ->delete(route('trainer.resources.archive', $resource))
        ->assertRedirect();

    expect($resource->fresh()->trashed())->toBeFalse();
});
