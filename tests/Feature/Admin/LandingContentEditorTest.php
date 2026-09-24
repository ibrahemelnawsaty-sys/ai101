<?php

declare(strict_types=1);

/**
 * D-114: the landing-page content editor — «محتوى صفحة الهبوط».
 *
 * Every sentence of the public page is a text in lang/{ar,en}/landing.php that
 * the centre may now replace from the admin panel, in Arabic, in English or in
 * both, with a live preview of the real page that stores nothing, a publish
 * that carries texts, cohort settings and questions together, and a reset per
 * section or for the whole page (BR-31).
 *
 * @see BR-31, BR-33, BR-36 · PRD §9.1, §9.18 · CONSTITUTION Art. 5, 8, 24 · D-114
 */

use App\Models\AuditLog;
use App\Models\LandingContent;
use App\Models\LandingSetting;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-09-20 12:00:00'));

    $this->cohort = makeCohort(['status' => 'open']);
    $this->admin = makeAdmin();
});

/** The publish endpoint, as the editor's script calls it. */
function publishLanding(object $test, array $body): Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->admin)->putJson(route('admin.landing.update'), $body);
}

/*
|--------------------------------------------------------------------------
| The editor
|--------------------------------------------------------------------------
*/

it('D-114: التبويب اسمه «محتوى صفحة الهبوط» ويعرض كل نصوص الصفحة بلغتيها', function (): void {
    $response = $this->actingAs($this->admin)->get(route('admin.landing.edit'))->assertOk();

    $response->assertSee('محتوى صفحة الهبوط', escape: false);

    // Every text the file holds reaches the editor — nothing is left out
    // because the section map fell behind the file.
    $file = Illuminate\Support\Arr::dot(require lang_path('ar/landing.php'));

    foreach (array_keys($file) as $name) {
        $response->assertSee('"key":"landing.'.$name.'"', escape: false);
    }

    expect(__('nav.admin.landing'))->toBe('محتوى صفحة الهبوط');
});

/*
|--------------------------------------------------------------------------
| BR-31 — publishing replaces the file's text on the public page
|--------------------------------------------------------------------------
*/

it('BR-31: نص يُنشر من المحرّر يحلّ محلّ نص الملف في صفحة الهبوط', function (): void {
    $this->get(route('home'))->assertSee(__('landing.hero.try_lab'), escape: false);

    $published = publishLanding($this, [
        'texts' => [['key' => 'landing.hero.try_lab', 'ar' => 'CANARY-TRY-LAB', 'en' => '']],
    ])->assertOk()->json('state.published');

    // The editor replaces its published layer with this answer.
    expect($published['landing.hero.try_lab'])->toBe(['ar' => 'CANARY-TRY-LAB', 'en' => null]);

    expect(LandingContent::query()->findOrFail('landing.hero.try_lab'))
        ->ar->toBe('CANARY-TRY-LAB')
        ->en->toBeNull();

    $this->get(route('home'))->assertOk()->assertSee('CANARY-TRY-LAB', escape: false);
});

it('BR-31: الإرجاع للأصل يحذف التعديل فيعود نص الملف ويتبعه', function (): void {
    $original = trans('landing.hero.try_lab');

    publishLanding($this, ['texts' => [['key' => 'landing.hero.try_lab', 'ar' => 'CANARY-TRY-LAB', 'en' => null]]])->assertOk();

    // The editor's "back to original" sends the file's own text.
    publishLanding($this, ['texts' => [['key' => 'landing.hero.try_lab', 'ar' => $original, 'en' => '']]])->assertOk();

    expect(LandingContent::query()->find('landing.hero.try_lab'))->toBeNull();

    $this->get(route('home'))
        ->assertSee($original, escape: false)
        ->assertDontSee('CANARY-TRY-LAB', escape: false);
});

it('BR-31: نص يُسقط قيمة حيّة مثل :count يُرفض ولا يُحفظ', function (): void {
    publishLanding($this, [
        'texts' => [['key' => 'landing.footer.whatsapp_message', 'ar' => 'رسالة بلا اسم البرنامج', 'en' => null]],
    ])->assertStatus(422)->assertJsonValidationErrors(['texts.0.ar']);

    expect(LandingContent::query()->count())->toBe(0);
});

it('BR-31: صيغ العدد تبقى بعلاماتها وترتيبها وإلا رُفض النص', function (): void {
    publishLanding($this, [
        'texts' => [['key' => 'landing.sections.seats_choice', 'ar' => 'تبقّى :count مقعدًا', 'en' => null]],
    ])->assertStatus(422)->assertJsonValidationErrors(['texts.0.ar']);

    // The same forms with new words are accepted, and counted on the page.
    $forms = '{0} CANARY-NONE|{1} CANARY-ONE|{2} CANARY-TWO|[3,10] CANARY-FEW :count|[11,*] CANARY-MANY :count';

    publishLanding($this, [
        'texts' => [['key' => 'landing.sections.seats_choice', 'ar' => $forms, 'en' => null]],
    ])->assertOk();

    expect(trans_choice('landing.sections.seats_choice', 5, ['count' => 5]))->toBe('CANARY-FEW 5');
});

it('BR-31: لا يُكتب إلا نص من نصوص الصفحة — أي مفتاح آخر يُرفض', function (): void {
    foreach (['admin.landing.title', 'landing.hero', 'landing.nope.nothing'] as $key) {
        publishLanding($this, ['texts' => [['key' => $key, 'ar' => 'CANARY', 'en' => null]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['texts.0.key']);
    }

    expect(LandingContent::query()->count())->toBe(0);
});

it('BR-31: صف قديم لمفتاح لم يعد في الملف يُهمَل ولا يُحقن في الصفحة', function (): void {
    LandingContent::query()->create(['key' => 'landing.hero', 'ar' => 'CANARY-STALE']);

    $this->get(route('home'))->assertOk()->assertDontSee('CANARY-STALE', escape: false);
});

it('المادة 24: نص منشور لا يخرج من عنصر البيانات المنظّمة ولا من وسومه', function (): void {
    $payload = 'CANARY</script><script>window.pwned=1</script>';

    publishLanding($this, ['texts' => [
        ['key' => 'landing.meta.brand_alt', 'ar' => $payload, 'en' => null],
        ['key' => 'landing.hero.try_lab', 'ar' => '<b>CANARY-TAG</b>', 'en' => null],
    ]])->assertOk();

    $html = $this->get(route('home'))->assertOk()->getContent();

    expect($html)->not->toContain('<script>window.pwned=1</script>')
        ->and($html)->not->toContain('<b>CANARY-TAG</b>')
        ->and($html)->toContain('&lt;b&gt;CANARY-TAG&lt;/b&gt;');
});

/*
|--------------------------------------------------------------------------
| BR-31 — the two languages, separately or together
|--------------------------------------------------------------------------
*/

it('BR-31: العربية والإنجليزية تُحرَّران معًا أو كلٌّ على حدة', function (): void {
    publishLanding($this, ['texts' => [['key' => 'landing.lab.title', 'ar' => 'CANARY-AR', 'en' => 'CANARY-EN']]])->assertOk();

    // English alone: the Arabic override stays as published.
    publishLanding($this, ['texts' => [['key' => 'landing.lab.title', 'ar' => 'CANARY-AR', 'en' => 'CANARY-EN-2']]])->assertOk();

    expect(LandingContent::query()->findOrFail('landing.lab.title'))
        ->ar->toBe('CANARY-AR')
        ->en->toBe('CANARY-EN-2');

    $this->actingAs($this->admin)
        ->post(route('admin.landing.preview'), ['lang' => 'en'])
        ->assertOk()
        ->assertSee('CANARY-EN-2', escape: false)
        ->assertSee('lang="en"', escape: false);

    $this->get(route('home'))
        ->assertSee('CANARY-AR', escape: false)
        ->assertDontSee('CANARY-EN-2', escape: false);
});

/*
|--------------------------------------------------------------------------
| The live preview
|--------------------------------------------------------------------------
*/

it('BR-31: المعاينة تعرض الصفحة الحقيقية بالمسودة ولا تحفظ منها شيئًا', function (): void {
    $setting = LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'hero_title' => null,
        'is_registration_open' => true,
    ]);

    $state = json_encode([
        'texts' => ['landing.hero.try_lab' => ['ar' => 'CANARY-DRAFT-TEXT', 'en' => null]],
        'settings' => [
            'is_registration_open' => true,
            'countdown_enabled' => false,
            'seats_override' => null,
            'hero_title' => 'CANARY-DRAFT-TITLE',
            'hero_subtitle' => null,
            'about_body' => null,
        ],
        'faq' => [['key' => null, 'question' => 'CANARY-DRAFT-Q', 'answer' => 'CANARY-DRAFT-A']],
    ]);

    $this->actingAs($this->admin)
        ->post(route('admin.landing.preview'), ['state' => $state, 'lang' => 'ar'])
        ->assertOk()
        ->assertSee('CANARY-DRAFT-TEXT', escape: false)
        ->assertSee('CANARY-DRAFT-TITLE', escape: false)
        ->assertSee('CANARY-DRAFT-Q', escape: false)
        ->assertSee('noindex', escape: false);

    expect(LandingContent::query()->count())->toBe(0)
        ->and($setting->fresh()->hero_title)->toBeNull()
        ->and($setting->fresh()->faq)->toBe($setting->faq);

    $this->get(route('home'))
        ->assertDontSee('CANARY-DRAFT-TEXT', escape: false)
        ->assertDontSee('CANARY-DRAFT-TITLE', escape: false)
        ->assertDontSee('CANARY-DRAFT-Q', escape: false);
});

it('المادة 24: المعاينة وحدها تُؤطَّر، ومن الأصل نفسه فقط', function (): void {
    $preview = $this->actingAs($this->admin)->post(route('admin.landing.preview'))->assertOk();

    expect($preview->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($preview->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'");

    foreach ([route('home'), route('admin.landing.edit')] as $url) {
        $response = $this->actingAs($this->admin)->get($url)->assertOk();

        expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
    }
});

/*
|--------------------------------------------------------------------------
| Cohort settings and questions ride in the same publish
|--------------------------------------------------------------------------
*/

it('BR-31: عنوان البنر ونص «عن البرنامج» المكتوبان في المحرّر يظهران في الصفحة', function (): void {
    publishLanding($this, [
        'settings' => [
            'is_registration_open' => true,
            'countdown_enabled' => false,
            'seats_override' => 7,
            'hero_title' => 'CANARY-HERO-TITLE',
            'hero_subtitle' => 'CANARY-HERO-SUBTITLE',
            'about_body' => "CANARY-ABOUT-ONE\n\nCANARY-ABOUT-TWO",
        ],
    ])->assertOk();

    $setting = LandingSetting::query()->where('cohort_id', $this->cohort->id)->sole();

    expect($setting->hero_title)->toBe('CANARY-HERO-TITLE')
        ->and($setting->hero_text)->toBe('CANARY-HERO-SUBTITLE')
        ->and($setting->seats_remaining_override)->toBe(7);

    $this->get(route('home'))
        ->assertSee('CANARY-HERO-TITLE', escape: false)
        ->assertSee('CANARY-HERO-SUBTITLE', escape: false)
        ->assertSee('<p class="lead">CANARY-ABOUT-ONE</p>', escape: false)
        ->assertSee('<p class="lead">CANARY-ABOUT-TWO</p>', escape: false);
});

it('BR-31: الأسئلة الشائعة تُنشر قائمةً مرتّبة، والمفاتيح يحفظها الخادم أو يولّدها', function (): void {
    LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'faq' => [
            ['key' => 'kept-one', 'question' => 'Q-ONE', 'answer' => 'A-ONE'],
            ['key' => 'kept-two', 'question' => 'Q-TWO', 'answer' => 'A-TWO'],
        ],
    ]);

    publishLanding($this, [
        'faq' => [
            ['key' => 'kept-two', 'question' => 'Q-TWO-EDITED', 'answer' => 'A-TWO'],
            ['key' => 'forged-key', 'question' => 'Q-NEW', 'answer' => 'A-NEW'],
        ],
    ])->assertOk();

    $faq = LandingSetting::query()->where('cohort_id', $this->cohort->id)->sole()->faq;

    expect($faq)->toHaveCount(2)
        ->and($faq[0])->toBe(['key' => 'kept-two', 'question' => 'Q-TWO-EDITED', 'answer' => 'A-TWO'])
        ->and($faq[1]['key'])->not->toBe('forged-key')
        ->and($faq[1]['question'])->toBe('Q-NEW');

    $this->get(route('home'))
        ->assertSeeInOrder(['Q-TWO-EDITED', 'Q-NEW'], escape: false)
        ->assertDontSee('Q-ONE', escape: false);
});

it('BR-31: الأسئلة المبذورة بلا مفتاح تظهر في المحرّر وتنال مفتاحًا من الخادم عند أول نشر', function (): void {
    // The seeder writes {question, answer} only; the old editor skipped every
    // such entry, so the questions a visitor read were the ones nobody could edit.
    LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'faq' => [['question' => 'CANARY-SEEDED-Q', 'answer' => 'CANARY-SEEDED-A']],
    ]);

    $this->actingAs($this->admin)->get(route('admin.landing.edit'))
        ->assertOk()
        ->assertSee('"question":"CANARY-SEEDED-Q"', escape: false);

    publishLanding($this, [
        'faq' => [['key' => null, 'question' => 'CANARY-SEEDED-Q', 'answer' => 'CANARY-SEEDED-A-EDITED']],
    ])->assertOk();

    $faq = LandingSetting::query()->where('cohort_id', $this->cohort->id)->sole()->faq;

    expect($faq)->toHaveCount(1)
        ->and($faq[0]['key'])->toBeString()->not->toBeEmpty()
        ->and($faq[0]['answer'])->toBe('CANARY-SEEDED-A-EDITED');
});

it('BR-31: إغلاق التسجيل من المحرّر يُغلقه على الخادم من الطلب التالي', function (): void {
    publishLanding($this, [
        'settings' => [
            'is_registration_open' => false,
            'countdown_enabled' => false,
            'seats_override' => null,
            'hero_title' => null,
            'hero_subtitle' => null,
            'about_body' => null,
        ],
    ])->assertOk();

    $this->get(route('home'))->assertSee('data-registration="closed"', escape: false);
});

/*
|--------------------------------------------------------------------------
| A publish changes only what the draft changed (review of D-114)
|--------------------------------------------------------------------------
*/

it('BR-31: إعداد واحد يُنشر وحده — لا يُغلق التسجيل ولا يمحو غيره', function (): void {
    $setting = LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'is_registration_open' => true,
        'countdown_enabled' => true,
        'hero_title' => 'CANARY-KEEP-TITLE',
        'about_body' => 'CANARY-KEEP-ABOUT',
    ]);

    publishLanding($this, ['settings' => ['hero_subtitle' => 'CANARY-ONLY-THIS']])->assertOk();

    $fresh = $setting->fresh();

    expect($fresh->hero_text)->toBe('CANARY-ONLY-THIS')
        ->and($fresh->is_registration_open)->toBeTrue()
        ->and($fresh->countdown_enabled)->toBeTrue()
        ->and($fresh->hero_title)->toBe('CANARY-KEEP-TITLE')
        ->and($fresh->about_body)->toBe('CANARY-KEEP-ABOUT');

    // A switch that is not a boolean is refused, never read as "closed".
    publishLanding($this, ['settings' => ['is_registration_open' => 'garbage']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['settings.is_registration_open']);

    expect($setting->fresh()->is_registration_open)->toBeTrue();
});

it('BR-31: نشر لغة واحدة يُبقي اللغة الأخرى كما نُشرت', function (): void {
    LandingContent::query()->create(['key' => 'landing.lab.title', 'ar' => 'CANARY-AR', 'en' => 'CANARY-EN-NEWER']);

    publishLanding($this, ['texts' => [['key' => 'landing.lab.title', 'ar' => 'CANARY-AR-2']]])->assertOk();

    expect(LandingContent::query()->findOrFail('landing.lab.title'))
        ->ar->toBe('CANARY-AR-2')
        ->en->toBe('CANARY-EN-NEWER');

    publishLanding($this, ['texts' => [['key' => 'landing.lab.title']]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['texts.0']);
});

it('BR-31: القيمة الحية تُفحص في كل صيغة عدد على حدة', function (): void {
    // ":count" kept in the 11+ form does not excuse its loss from the 3–10 form.
    publishLanding($this, ['texts' => [[
        'key' => 'landing.facts.chip_weeks',
        'ar' => '{1} أسبوع واحد|{2} أسبوعان|[3,10] أسابيع|[11,*] :count أسبوعًا',
    ]]])->assertStatus(422)->assertJsonValidationErrors(['texts.0.ar']);

    expect(LandingContent::query()->count())->toBe(0);
});

it('BR-31: مفتاح سؤال مكرَّر في النشر يُعطى مفتاحًا جديدًا', function (): void {
    LandingSetting::factory()->create([
        'cohort_id' => $this->cohort->id,
        'faq' => [['key' => 'k1', 'question' => 'Q1', 'answer' => 'A1']],
    ]);

    publishLanding($this, ['faq' => [
        ['key' => 'k1', 'question' => 'Q1', 'answer' => 'A1'],
        ['key' => 'k1', 'question' => 'Q2', 'answer' => 'A2'],
    ]])->assertOk();

    $keys = array_column(LandingSetting::query()->where('cohort_id', $this->cohort->id)->sole()->faq, 'key');

    expect($keys[0])->toBe('k1')
        ->and($keys[1])->not->toBe('k1');
});

it('BR-31: إعدادات مسودة كُتبت لدفعة لم تعد المعروضة تُرفض ولا تُكتب على غيرها', function (): void {
    LandingSetting::factory()->create(['cohort_id' => $this->cohort->id, 'is_registration_open' => true]);

    publishLanding($this, [
        'cohort_id' => (string) Illuminate\Support\Str::uuid(),
        'settings' => ['is_registration_open' => false],
    ])->assertStatus(409);

    expect(LandingSetting::query()->where('cohort_id', $this->cohort->id)->sole()->is_registration_open)->toBeTrue();
});

it('المادة 24: المعاينة لا تُفتح برابط GET يحمل مسودة', function (): void {
    $state = json_encode(['texts' => ['landing.hero.try_lab' => ['ar' => 'CANARY-FROM-LINK', 'en' => null]]]);

    $this->actingAs($this->admin)
        ->get(route('admin.landing.preview').'?state='.rawurlencode((string) $state))
        ->assertStatus(405);

    // A refused preview answers in place and flashes no draft into the session.
    $this->actingAs($this->admin)
        ->post(route('admin.landing.preview'), ['lang' => 'fr', 'state' => $state])
        ->assertStatus(422)
        ->assertSessionMissing('_old_input');
});

/*
|--------------------------------------------------------------------------
| Reset
|--------------------------------------------------------------------------
*/

it('BR-31: إرجاع قسم يحذف تعديلات نصوصه وحده ويُسجَّل في سجل التدقيق', function (): void {
    LandingContent::query()->create(['key' => 'landing.hero.try_lab', 'ar' => 'CANARY-HERO']);
    LandingContent::query()->create(['key' => 'landing.footer.rights', 'ar' => 'CANARY-FOOTER']);

    $this->actingAs($this->admin)
        ->deleteJson(route('admin.landing.reset'), ['section' => 'hero'])
        ->assertOk();

    expect(LandingContent::query()->pluck('key')->all())->toBe(['landing.footer.rights']);

    $log = AuditLog::query()->where('action', 'landing.content_reset')->sole();

    expect($log->before['texts'])->toHaveKey('landing.hero.try_lab')
        ->and($log->ip_address)->not->toBeNull();
});

it('BR-31: إرجاع الصفحة كلها يعيد كل نصوصها إلى الملف', function (): void {
    LandingContent::query()->create(['key' => 'landing.hero.try_lab', 'ar' => 'CANARY-HERO']);
    LandingContent::query()->create(['key' => 'landing.footer.rights', 'ar' => 'CANARY-FOOTER']);

    $this->actingAs($this->admin)->deleteJson(route('admin.landing.reset'))->assertOk();

    expect(LandingContent::query()->count())->toBe(0);

    $this->actingAs($this->admin)
        ->deleteJson(route('admin.landing.reset'), ['section' => 'not-a-section'])
        ->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Article 8 — every publish is audited
|--------------------------------------------------------------------------
*/

it('المادة 8: كل نشر لنصوص الصفحة يُسجَّل بقيمته قبل وبعد', function (): void {
    publishLanding($this, ['texts' => [['key' => 'landing.hero.try_lab', 'ar' => 'CANARY-AFTER', 'en' => null]]])->assertOk();

    $log = AuditLog::query()->where('action', 'landing.content_published')->sole();

    expect($log->actor_id)->toBe($this->admin->id)
        ->and($log->before['texts']['landing.hero.try_lab'])->toBe(['ar' => null, 'en' => null])
        ->and($log->after['texts']['landing.hero.try_lab'])->toBe(['ar' => 'CANARY-AFTER', 'en' => null]);
});

/*
|--------------------------------------------------------------------------
| Authorisation — admin only, and no writes during an account preview
|--------------------------------------------------------------------------
*/

it('403: المدرب والمتدرب لا يفتحون المحرّر ولا يعاينون ولا ينشرون ولا يُرجعون', function (): void {
    LandingContent::query()->create(['key' => 'landing.hero.try_lab', 'ar' => 'CANARY-KEEP']);

    foreach ([makeParticipant($this->cohort), makeTrainer($this->cohort)] as $user) {
        $this->actingAs($user)->get(route('admin.landing.edit'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.landing.preview'))->assertForbidden();
        $this->actingAs($user)
            ->putJson(route('admin.landing.update'), ['texts' => [['key' => 'landing.hero.try_lab', 'ar' => 'CANARY-TAMPERED', 'en' => null]]])
            ->assertForbidden();
        $this->actingAs($user)->deleteJson(route('admin.landing.reset'))->assertForbidden();
    }

    expect(LandingContent::query()->findOrFail('landing.hero.try_lab')->ar)->toBe('CANARY-KEEP');
})->group('authz');
