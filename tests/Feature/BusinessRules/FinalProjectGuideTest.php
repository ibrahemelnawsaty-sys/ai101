<?php

declare(strict_types=1);

/**
 * D-127 — the final project's guide: a whole HTML page per language, edited by
 * the general supervisor, made available by them, published by the cohort's
 * primary coordinator, read by the trainees once both the guide and the
 * project are published — and never able to run a script of its own.
 *
 * @see D-127 · BR-15, BR-16, BR-22, BR-23, BR-31 · CONSTITUTION Art. 5, Art. 8, Art. 14, Art. 24
 */

use App\Events\FinalProjectGuidePublished;
use App\Events\FinalProjectUnlocked;
use App\Mail\AtharLetter;
use App\Models\AuditLog;
use App\Models\FinalProject;
use App\Models\FinalProjectGuide;
use App\Models\FinalProjectGuideVersion;
use App\Models\Notification;
use App\Services\FinalProject\GuideContent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

const GUIDE_CANARY = 'CANARY-GUIDE-7Hk3';

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-28 12:00:00'));

    $this->cohort = makeCohort();
    $this->admin = makeAdmin();
    $this->trainer = makeTrainer($this->cohort);
    $this->coordinator = makeCoordinator($this->cohort);
    $this->participant = makeParticipant($this->cohort);
    $this->project = makeFinalProject($this->cohort, ['is_unlocked' => true]);
});

/** A small, valid guide page carrying a canary and a script that must never run. */
function guidePage(string $marker = GUIDE_CANARY, string $extra = ''): string
{
    return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><title>Guide</title>'
        .'<style>body{color:#1a1523}</style></head><body><h1>'.$marker.'</h1>'
        .'<div class="code-block"><button class="copy-btn" type="button">copy</button><pre><code>print(1)</code></pre></div>'
        .'<script>window.pwned = true;</script>'.$extra.'</body></html>';
}

/** A guide language with a saved page, in the state asked for. */
function makeGuide(FinalProject $project, string $locale = 'ar', array $state = [], string $page = ''): FinalProjectGuide
{
    app(GuideContent::class)->save($project, $locale, $page !== '' ? $page : guidePage(GUIDE_CANARY.'-'.$locale), FinalProjectGuideVersion::SOURCE_EDITOR, null);

    $guide = FinalProjectGuide::query()->where('final_project_id', $project->id)->where('locale', $locale)->sole();
    $guide->update($state);

    return $guide->fresh();
}

/*
|--------------------------------------------------------------------------
| The general supervisor's side
|--------------------------------------------------------------------------
*/

it('D-127: المشرف العام يحفظ الدليل من المحرر نسخةً جديدة ويُسجَّل الحفظ، وحفظ المحتوى نفسه لا ينشئ نسخة', function (): void {
    $save = fn (string $html) => $this->actingAs($this->admin)
        ->post(route('admin.finalProject.guide.save', [$this->project, 'ar']), ['html' => $html]);

    $save(guidePage('ONE'))->assertSessionHasNoErrors()->assertRedirect();
    $save(guidePage('ONE'))->assertSessionHasNoErrors();
    $save(guidePage('TWO'))->assertSessionHasNoErrors();

    $guide = FinalProjectGuide::query()->where('final_project_id', $this->project->id)->where('locale', 'ar')->sole();
    $versions = $guide->versions()->get();

    expect($versions->pluck('version')->all())->toBe([2, 1])
        ->and($versions->first()->html)->toContain('TWO')
        ->and($versions->first()->created_by)->toBe($this->admin->id)
        ->and($guide->is_available)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'final_project_guide.saved')->where('actor_id', $this->admin->id)->count())->toBe(2);
});

it('D-127: رفع ملف HTML يحلّ محلّ المحتوى، وملف ليس HTML من محتواه يُرفض ولو كان اسمه .html', function (): void {
    $html = UploadedFile::fake()->createWithContent('guide.html', guidePage('UPLOADED'));

    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.guide.save', [$this->project, 'ar']), ['file' => $html])
        ->assertSessionHasNoErrors();

    $version = FinalProjectGuideVersion::query()->sole();

    expect($version->html)->toContain('UPLOADED')
        ->and($version->source)->toBe(FinalProjectGuideVersion::SOURCE_UPLOAD);

    $pdf = UploadedFile::fake()->createWithContent('guide.html', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.guide.save', [$this->project, 'ar']), ['file' => $pdf])
        ->assertSessionHasErrors(['file' => __('admin.final_project.guide.errors.not_html')]);

    expect(FinalProjectGuideVersion::query()->count())->toBe(1);
});

it('D-127, المادة 14: يُرفض دليل في تنسيقه أصفر أو ذهبي بأي صيغة، ويُقبل ذِكر الكلمة في النص', function (): void {
    foreach ([
        '<style>.box{background:#fff8e8}</style>',
        '<p style="color: gold">x</p>',
        '<svg><rect fill="rgb(255, 204, 0)"/></svg>',
    ] as $offender) {
        $this->actingAs($this->admin)
            ->post(route('admin.finalProject.guide.save', [$this->project, 'ar']), ['html' => guidePage('X', $offender)])
            ->assertSessionHasErrors('html');
    }

    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.guide.save', [$this->project, 'ar']), ['html' => guidePage('X', '<p>a gold medal, a yellow card</p>')])
        ->assertSessionHasNoErrors();

    expect(FinalProjectGuideVersion::query()->count())->toBe(1);
});

it('D-127: يُرفض دليل فارغ أو أكبر من 2 ميغابايت', function (): void {
    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.guide.save', [$this->project, 'ar']), ['html' => '   '])
        ->assertSessionHasErrors('html');

    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.guide.save', [$this->project, 'ar']), ['html' => guidePage('X', str_repeat('a', GuideContent::MAX_BYTES))])
        ->assertSessionHasErrors(['html' => __('admin.final_project.guide.errors.too_large')]);

    expect(FinalProjectGuideVersion::query()->count())->toBe(0);
});

it('D-127: الاسترجاع ينشئ نسخة جديدة بمحتوى القديمة ولا يحذف شيئًا', function (): void {
    makeGuide($this->project, 'ar', [], guidePage('OLD'));
    makeGuide($this->project, 'ar', [], guidePage('NEW'));

    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.guide.restore', [$this->project, 'ar']), ['version' => 1])
        ->assertSessionHasNoErrors();

    $versions = FinalProjectGuideVersion::query()->orderBy('version')->get();

    expect($versions->pluck('version')->all())->toBe([1, 2, 3])
        ->and($versions->last()->html)->toContain('OLD')
        ->and($versions->last()->source)->toBe(FinalProjectGuideVersion::SOURCE_RESTORE)
        ->and($versions->last()->restored_from)->toBe(1);

    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.guide.restore', [$this->project, 'ar']), ['version' => 99])
        ->assertSessionHasErrors('version');
});

it('D-127: نسخ دليل دفعة سابقة ينقل الصفحات بلغتيها ولا ينقل الإتاحة ولا النشر', function (): void {
    $older = makeFinalProject(makeCohort());
    makeGuide($older, 'ar', ['is_available' => true, 'is_published' => true], guidePage('FROM-OLDER-AR'));
    makeGuide($older, 'en', [], guidePage('FROM-OLDER-EN'));

    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.guide.copy', $this->project), ['source_cohort_id' => $older->cohort_id])
        ->assertSessionHasNoErrors();

    $copied = FinalProjectGuide::query()->where('final_project_id', $this->project->id)->with('currentVersion')->get()->keyBy('locale');

    expect($copied)->toHaveCount(2)
        ->and($copied['ar']->currentVersion->html)->toContain('FROM-OLDER-AR')
        ->and($copied['ar']->currentVersion->source)->toBe(FinalProjectGuideVersion::SOURCE_COPY)
        ->and($copied['ar']->is_available)->toBeFalse()
        ->and($copied['ar']->is_published)->toBeFalse();

    // Copying this project onto itself is refused.
    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.guide.copy', $this->project), ['source_cohort_id' => $this->cohort->id])
        ->assertSessionHasErrors('source_cohort_id');
});

it('D-127: لا تُتاح لغة بلا محتوى، والإتاحة تُشعر المنسّق الأساسي وتُسجَّل', function (): void {
    $this->actingAs($this->admin)
        ->put(route('admin.finalProject.guide.availability', [$this->project, 'ar']), ['available' => '1'])
        ->assertSessionHasErrors('available');

    makeGuide($this->project);

    $this->actingAs($this->admin)
        ->put(route('admin.finalProject.guide.availability', [$this->project, 'ar']), ['available' => '1'])
        ->assertSessionHasNoErrors();

    expect(FinalProjectGuide::query()->where('locale', 'ar')->sole()->is_available)->toBeTrue()
        ->and(Notification::query()->where('user_id', $this->coordinator->id)->where('type', 'final_project_guide_available')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'final_project_guide.made_available')->count())->toBe(1);
});

it('D-127: لا أحد غير المشرف العام يعدّل الدليل أو يتيحه — 403', function (): void {
    makeGuide($this->project);

    foreach ([$this->coordinator, $this->trainer, $this->participant] as $actor) {
        $this->actingAs($actor)->post(route('admin.finalProject.guide.save', [$this->project, 'ar']), ['html' => guidePage('HACK')])->assertForbidden();
        $this->actingAs($actor)->put(route('admin.finalProject.guide.availability', [$this->project, 'ar']), ['available' => '1'])->assertForbidden();
        $this->flushSession();
    }

    expect(FinalProjectGuideVersion::query()->count())->toBe(1)
        ->and(FinalProjectGuide::query()->sole()->is_available)->toBeFalse();
})->group('authz');

/*
|--------------------------------------------------------------------------
| The primary coordinator's side
|--------------------------------------------------------------------------
*/

it('D-127: المنسّق الأساسي لا ينشر الدليل قبل إتاحته، وينشره بعدها', function (): void {
    makeGuide($this->project);
    $press = fn () => $this->actingAs($this->coordinator)
        ->put(route('coordinator.finalProject.guide.publication', [$this->project, 'ar']), ['published' => '1']);

    $press()->assertSessionHas('error', __('coordinator.final_project.errors.guide_not_available'));
    expect(FinalProjectGuide::query()->sole()->is_published)->toBeFalse();

    FinalProjectGuide::query()->update(['is_available' => true]);

    $press()->assertRedirect();

    expect(FinalProjectGuide::query()->sole()->is_published)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'final_project_guide.published')->where('actor_id', $this->coordinator->id)->count())->toBe(1);
})->group('authz');

it('D-127: الدليل الإنجليزي لا يُنشر قبل العربي — يُرفض برسالة تشرح السبب', function (): void {
    makeGuide($this->project, 'ar', ['is_available' => true]);
    makeGuide($this->project, 'en', ['is_available' => true]);

    $this->actingAs($this->coordinator)
        ->put(route('coordinator.finalProject.guide.publication', [$this->project, 'en']), ['published' => '1'])
        ->assertSessionHas('error', __('coordinator.final_project.errors.needs_arabic'));

    expect(FinalProjectGuide::query()->where('locale', 'en')->sole()->is_published)->toBeFalse();

    FinalProjectGuide::query()->where('locale', 'ar')->update(['is_published' => true]);

    $this->actingAs($this->coordinator)
        ->put(route('coordinator.finalProject.guide.publication', [$this->project, 'en']), ['published' => '1'])
        ->assertRedirect();

    expect(FinalProjectGuide::query()->where('locale', 'en')->sole()->is_published)->toBeTrue();
})->group('authz');

it('D-127: المشرف العام والمدرب ومنسّق غير أساسي لا ينشرون الدليل — 403', function (): void {
    makeGuide($this->project, 'ar', ['is_available' => true]);
    $second = makeCoordinator($this->cohort);
    $this->cohort->update(['primary_coordinator_id' => $this->coordinator->id]);

    foreach ([$this->admin, $this->trainer, $second] as $actor) {
        $this->actingAs($actor)
            ->put(route('coordinator.finalProject.guide.publication', [$this->project, 'ar']), ['published' => '1'])
            ->assertForbidden();
        $this->flushSession();
    }

    expect(FinalProjectGuide::query()->sole()->is_published)->toBeFalse();
})->group('authz');

it('D-127: إلغاء إتاحة الدليل يوقف نشره فورًا', function (): void {
    makeGuide($this->project, 'ar', ['is_available' => true, 'is_published' => true]);

    $this->actingAs($this->admin)
        ->put(route('admin.finalProject.guide.availability', [$this->project, 'ar']), ['available' => '0'])
        ->assertSessionHasNoErrors();

    $guide = FinalProjectGuide::query()->sole();

    expect($guide->is_available)->toBeFalse()
        ->and($guide->is_published)->toBeFalse();

    $this->actingAs($this->participant)->get(route('finalProject.guide'))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Who reads it, and what they are told
|--------------------------------------------------------------------------
*/

it('D-127, BR-15: المتدرب يرى زر الدليل ويفتحه فقط إذا نُشر الدليل والمشروع معًا — وإلا 403 ويُسجَّل الرفض', function (): void {
    $guide = makeGuide($this->project, 'ar', ['is_available' => true]);

    // Available, not published.
    $this->actingAs($this->participant)->get(route('finalProject'))->assertDontSee(route('finalProject.guide'));
    $this->actingAs($this->participant)->get(route('finalProject.guide'))->assertForbidden();

    // Published, but the project itself locked.
    $guide->update(['is_published' => true]);
    $this->project->update(['is_unlocked' => false]);
    $this->actingAs($this->participant)->get(route('finalProject.guide'))->assertForbidden();

    // Both published.
    $this->project->update(['is_unlocked' => true]);
    $this->actingAs($this->participant)->get(route('finalProject'))->assertSee(route('finalProject.guide'));
    $this->actingAs($this->participant)->get(route('finalProject.guide'))->assertOk()->assertSee(GUIDE_CANARY.'-ar', escape: false);

    expect(AuditLog::query()->where('actor_id', $this->participant->id)->where('action', 'like', 'access.denied%')->count())->toBeGreaterThanOrEqual(2);
})->group('authz');

it('D-127, BR-23: رابط المتدرب يفتح دليل دفعته وحدها، والرابط برقم مشروع دفعة أخرى يُرفض للمدرب — 403', function (): void {
    makeGuide($this->project, 'ar', ['is_available' => true, 'is_published' => true]);

    $otherCohort = makeCohort();
    $otherProject = makeFinalProject($otherCohort, ['is_unlocked' => true]);
    makeGuide($otherProject, 'ar', ['is_available' => true, 'is_published' => true], guidePage('OTHER-COHORT'));

    $this->actingAs($this->participant)->get(route('finalProject.guide'))->assertOk()->assertDontSee('OTHER-COHORT', escape: false);
    $this->actingAs($this->trainer)->get(route('finalProjectGuide.show', $otherProject))->assertForbidden();
    $this->actingAs($this->participant)->get(route('finalProjectGuide.show', $otherProject))->assertForbidden();
})->group('authz');

it('D-127: المدرب والمنسّق غير الأساسي يطّلعان بعد النشر فقط، والمنسّق الأساسي منذ الإتاحة', function (): void {
    $guide = makeGuide($this->project, 'ar', ['is_available' => true]);
    $second = makeCoordinator($this->cohort);
    $this->cohort->update(['primary_coordinator_id' => $this->coordinator->id]);

    $this->actingAs($this->coordinator)->get(route('finalProjectGuide.show', $this->project))->assertOk();
    $this->actingAs($this->trainer)->get(route('finalProjectGuide.show', $this->project))->assertForbidden();
    $this->actingAs($second)->get(route('finalProjectGuide.show', $this->project))->assertForbidden();
    $this->actingAs($this->trainer)->get(route('trainer.finalProject', ['cohort' => $this->cohort->id]))
        ->assertDontSee(route('finalProjectGuide.show', $this->project));

    $guide->update(['is_published' => true]);

    $this->actingAs($this->trainer)->get(route('finalProjectGuide.show', $this->project))->assertOk();
    $this->actingAs($second)->get(route('finalProjectGuide.show', $this->project))->assertOk();
    $this->actingAs($this->trainer)->get(route('trainer.finalProject', ['cohort' => $this->cohort->id]))
        ->assertSee(route('finalProjectGuide.show', $this->project));
})->group('authz');

it('D-127: الدليل الإنجليزي لمن واجهته إنجليزية فقط وبعد نشر العربي، وإلا يُعرض العربي', function (): void {
    makeGuide($this->project, 'ar', ['is_available' => true, 'is_published' => true]);
    $english = makeGuide($this->project, 'en', ['is_available' => true, 'is_published' => true]);

    // Today the platform offers Arabic alone (config athar.locales.supported,
    // D-19): an English session is ignored, so everyone reads Arabic.
    $this->actingAs($this->participant)->withSession(['locale' => 'en'])
        ->get(route('finalProject.guide'))->assertOk()->assertSee(GUIDE_CANARY.'-ar', escape: false);

    config(['athar.locales.supported' => ['ar', 'en']]);
    $english->update(['is_published' => false]);

    $this->actingAs($this->participant)->withSession(['locale' => 'en'])
        ->get(route('finalProject.guide'))->assertOk()->assertSee(GUIDE_CANARY.'-ar', escape: false);

    $english->update(['is_published' => true]);

    $this->actingAs($this->participant)->withSession(['locale' => 'en'])
        ->get(route('finalProject.guide'))->assertOk()->assertSee(GUIDE_CANARY.'-en', escape: false);
    $this->actingAs($this->participant)->withSession(['locale' => 'ar'])
        ->get(route('finalProject.guide'))->assertOk()->assertSee(GUIDE_CANARY.'-ar', escape: false);
});

it('D-127, المادة 24: صفحة الدليل تُخدم بسياسة لا يعمل فيها إلا سكربت المنصة — سكربت الصفحة بلا nonce', function (): void {
    makeGuide($this->project, 'ar', ['is_available' => true, 'is_published' => true]);

    $response = $this->actingAs($this->participant)->get(route('finalProject.guide'))->assertOk();

    $policy = (string) $response->headers->get('Content-Security-Policy');
    preg_match("/script-src 'nonce-([A-Za-z0-9]+)'/", $policy, $nonce);
    $body = (string) $response->getContent();

    expect($nonce)->toHaveCount(2)
        ->and($policy)->not->toContain('unsafe-inline\' \'nonce')
        ->and($policy)->toContain("connect-src 'none'")
        ->and($policy)->toContain("form-action 'none'")
        ->and($policy)->toContain("base-uri 'none'")
        ->and(substr_count($body, 'nonce="'.$nonce[1].'"'))->toBe(1)
        ->and($body)->toContain('<script>window.pwned = true;</script>')
        ->and($body)->toContain('data-copied="'.e(__('project.guide.copied')).'"')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store');
});

it('D-127: نشر الدليل والمشروع مفتوح يُشعر المتدربين بإشعار وبريد زرّه يفتح الدليل، والمدربين بإشعار — مرة واحدة', function (): void {
    Mail::fake();
    makeGuide($this->project, 'ar', ['is_available' => true]);

    $press = fn (bool $on) => $this->actingAs($this->coordinator)
        ->put(route('coordinator.finalProject.guide.publication', [$this->project, 'ar']), ['published' => $on ? '1' : '0']);

    $press(true);
    $press(false);
    $press(true);

    expect(Notification::query()->where('user_id', $this->participant->id)->where('type', 'final_project_guide_published')->count())->toBe(1)
        ->and(Notification::query()->where('user_id', $this->participant->id)->where('type', 'final_project_guide_published')->sole()->link)->toBe(route('finalProject.guide'))
        ->and(Notification::query()->where('user_id', $this->trainer->id)->where('type', 'final_project_guide_published_staff')->count())->toBe(1);

    Mail::assertQueued(AtharLetter::class, fn (AtharLetter $letter): bool => $letter->copyKey === 'emails.final_project_guide_published'
        && $letter->hasTo($this->participant->email)
        && $letter->ctaUrl === route('finalProject.guide'));
    expect(Mail::queued(AtharLetter::class, fn (AtharLetter $letter): bool => $letter->copyKey === 'emails.final_project_guide_published')->count())->toBe(1);
});

it('D-127: الدليل المنشور والمشروع مقفل يخرج مع نشر المشروع إشعارًا واحدًا وبريدًا زرّه يفتح الدليل', function (): void {
    Mail::fake();
    $this->project->update(['is_unlocked' => false, 'is_available' => true]);
    makeGuide($this->project, 'ar', ['is_available' => true]);

    $this->actingAs($this->coordinator)
        ->put(route('coordinator.finalProject.guide.publication', [$this->project, 'ar']), ['published' => '1']);

    expect(Notification::query()->where('user_id', $this->participant->id)->count())->toBe(0);

    $this->actingAs($this->coordinator)
        ->put(route('coordinator.finalProject.publication', $this->project), ['published' => '1']);

    $notices = Notification::query()->where('user_id', $this->participant->id)->get();

    expect($notices)->toHaveCount(1)
        ->and($notices->first()->type)->toBe('final_project_unlocked')
        ->and($notices->first()->body)->toContain(explode(':', __('notifications.types.final_project_unlocked.body_with_guide'))[0])
        ->and($notices->first()->link)->toBe(route('finalProject.guide'))
        ->and(FinalProjectGuide::query()->sole()->announced_at)->not->toBeNull();

    Mail::assertQueued(AtharLetter::class, fn (AtharLetter $letter): bool => $letter->copyKey === 'emails.final_project_unlocked_with_guide'
        && $letter->ctaUrl === route('finalProject.guide'));
    Mail::assertNotQueued(AtharLetter::class, fn (AtharLetter $letter): bool => $letter->copyKey === 'emails.final_project_guide_published');
});

it('D-127: نشر الدليل الإنجليزي لا يُرسل إشعارًا', function (): void {
    Event::fake([FinalProjectGuidePublished::class, FinalProjectUnlocked::class]);
    makeGuide($this->project, 'ar', ['is_available' => true, 'is_published' => true, 'announced_at' => riyadhAt('2026-09-27 10:00:00'), 'staff_announced_at' => riyadhAt('2026-09-27 10:00:00')]);
    makeGuide($this->project, 'en', ['is_available' => true]);

    $this->actingAs($this->coordinator)
        ->put(route('coordinator.finalProject.guide.publication', [$this->project, 'en']), ['published' => '1'])
        ->assertRedirect();

    Event::assertNotDispatched(FinalProjectGuidePublished::class);
    expect(Notification::query()->count())->toBe(0);
});

it('D-127: المشرف العام يعاين الدليل غير المنشور بأي لغة، وشاشته تعرض حالته', function (): void {
    makeGuide($this->project, 'en');

    $this->actingAs($this->admin)
        ->get(route('finalProjectGuide.show', [$this->project, 'lang' => 'en']))
        ->assertOk()
        ->assertSee(GUIDE_CANARY.'-en', escape: false);

    $this->actingAs($this->admin)
        ->get(route('admin.finalProject.index', ['cohort' => $this->cohort->id, 'guide' => 'en']))
        ->assertOk()
        ->assertSee(__('admin.final_project.guide.title'))
        ->assertSee(__('admin.final_project.guide.states.not_available'));
});

it('D-127: محرر الدليل يعرض الصفحة كما حُفظت — مرمَّزة مرة واحدة لا مرتين، فالحفظ بلا تعديل لا يغيّرها', function (): void {
    makeGuide($this->project, 'ar', [], guidePage('R&D "quoted"'));

    $response = $this->actingAs($this->admin)
        ->get(route('admin.finalProject.index', ['cohort' => $this->cohort->id, 'guide' => 'ar']))
        ->assertOk();

    expect($response->getContent())
        ->toContain('&lt;!DOCTYPE html&gt;')
        ->toContain('R&amp;D &quot;quoted&quot;')
        ->not->toContain('&amp;lt;')
        ->not->toContain('&amp;amp;');
});

/*
|--------------------------------------------------------------------------
| What the independent reviews found (D-127, second round)
|--------------------------------------------------------------------------
*/

it('D-127, المادة 24: يُرفض دليل فيه ما ينقل المتدرب أو يرسل طلبًا دون ضغطة — meta refresh وbase وmeta referrer وpreconnect', function (): void {
    foreach ([
        '<meta http-equiv="refresh" content="0;url=https://evil.example/login">',
        '<meta HTTP-EQUIV=Refresh content="0;url=https://evil.example/">',
        '<base href="https://evil.example/">',
        '<meta name="referrer" content="unsafe-url">',
        '<link rel="preconnect" href="https://tracker.example">',
    ] as $offender) {
        $this->actingAs($this->admin)
            ->post(route('admin.finalProject.guide.save', [$this->project, 'ar']), ['html' => guidePage('X', $offender)])
            ->assertSessionHasErrors('html');
    }

    // The harmless Content-Type declaration a saved page carries is fine.
    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.guide.save', [$this->project, 'ar']), ['html' => guidePage('X', '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">')])
        ->assertSessionHasNoErrors();

    expect(FinalProjectGuideVersion::query()->count())->toBe(1);
});

it('D-127, المادة 7: فحص الألوان لا يُلتفّ عليه — سمة بلا علامات تنصيص، ورمز HTML، وهروب CSS، وoklch، ووسم style مفتوح', function (): void {
    foreach ([
        '<p style=color:gold>x</p>',
        '<p style="color:&#103;old">x</p>',
        '<style>.a{color:gol\64}</style>',
        '<style>.a{color:oklch(0.9 0.19 95)}</style>',
        '<style>.a{color:#fff8e8}'.str_repeat('x', 1_500_000),
    ] as $offender) {
        $this->actingAs($this->admin)
            ->post(route('admin.finalProject.guide.save', [$this->project, 'ar']), ['html' => guidePage('X', $offender)])
            ->assertSessionHasErrors('html');
    }

    expect(FinalProjectGuideVersion::query()->count())->toBe(0);
});

it('D-127: يُرفض ملف ليس بترميز UTF-8 برسالة تشرح الحل', function (): void {
    $latin1 = UploadedFile::fake()->createWithContent('guide.html', mb_convert_encoding(guidePage('Caf'."\u{00E9}"), 'ISO-8859-1', 'UTF-8'));

    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.guide.save', [$this->project, 'ar']), ['file' => $latin1])
        ->assertSessionHasErrors(['file' => __('admin.final_project.guide.errors.not_utf8')]);
});

it('D-127, المادة 24: صفحة الدليل لا تجلب صورة ولا خطًّا ولا نمطًا من عنوان تختاره — الصور data: وحدها والخطوط من /fonts/ وحدها', function (): void {
    makeGuide($this->project, 'ar', ['is_available' => true, 'is_published' => true]);

    $policy = (string) $this->actingAs($this->participant)->get(route('finalProject.guide'))->headers->get('Content-Security-Policy');

    expect($policy)->toContain("style-src 'unsafe-inline';")
        ->and($policy)->toContain('img-src data: '.asset('brand/icons/favicon-mark.svg').';')
        ->and($policy)->toContain('font-src '.asset('fonts').'/;')
        ->and($policy)->not->toContain('https:;')
        ->and($policy)->not->toContain("'self'");
});

it('D-127: إيقاف نشر العربي أو إلغاء إتاحته يوقف الإنجليزي معه — لا شاشة تقول «منشور» عن صفحة لا يراها أحد', function (): void {
    $arabic = makeGuide($this->project, 'ar', ['is_available' => true, 'is_published' => true]);
    makeGuide($this->project, 'en', ['is_available' => true, 'is_published' => true]);

    $this->actingAs($this->coordinator)
        ->put(route('coordinator.finalProject.guide.publication', [$this->project, 'ar']), ['published' => '0'])
        ->assertSessionHas('status');

    expect(FinalProjectGuide::query()->where('locale', 'en')->sole()->is_published)->toBeFalse();

    $arabic->update(['is_published' => true]);
    FinalProjectGuide::query()->where('locale', 'en')->update(['is_published' => true]);

    $this->actingAs($this->admin)
        ->put(route('admin.finalProject.guide.availability', [$this->project, 'ar']), ['available' => '0']);

    expect(FinalProjectGuide::query()->where('locale', 'en')->sole()->is_published)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'final_project_guide.unpublished')->count())->toBe(3);
});

it('D-127: حفظ المحتوى المعروض نفسه أو استرجاع النسخة المعروضة يقول إن شيئًا لم يتغيّر', function (): void {
    makeGuide($this->project, 'ar', [], guidePage('SAME'));

    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.guide.save', [$this->project, 'ar']), ['html' => guidePage('SAME')])
        ->assertSessionHas('warning', __('admin.final_project.guide.unchanged_page'));

    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.guide.restore', [$this->project, 'ar']), ['version' => 1])
        ->assertSessionHas('warning', __('admin.final_project.guide.unchanged_page'));

    expect(FinalProjectGuideVersion::query()->count())->toBe(1);
});

it('D-127, المادة 8: سجل نسخ الدليل من دفعة أخرى يسمّي مصدره', function (): void {
    $older = makeFinalProject(makeCohort());
    makeGuide($older, 'ar', [], guidePage('FROM-OLDER'));

    $this->actingAs($this->admin)
        ->post(route('admin.finalProject.guide.copy', $this->project), ['source_cohort_id' => $older->cohort_id]);

    $entry = AuditLog::query()->where('action', 'final_project_guide.saved')->where('actor_id', $this->admin->id)->sole();

    expect($entry->after['copied_from_project'])->toBe($older->id)
        ->and($entry->after['copied_from_cohort'])->toBe($older->cohort_id);
});

it('D-129, BR-33: الدليل لا يُفتح أثناء المعاينة — تُعرض صفحة داخل إطار المنصة وشريطها بدلًا منه', function (): void {
    makeGuide($this->project, 'ar', ['is_available' => true, 'is_published' => true]);
    $sysadmin = makeSystemAdmin();

    $this->actingAs($sysadmin)->post(route('admin.users.preview', $this->participant))->assertRedirect();

    $this->get(route('finalProject.guide'))
        ->assertOk()
        ->assertSee(__('project.guide.preview_title'))
        ->assertDontSee(GUIDE_CANARY.'-ar', escape: false);
});
