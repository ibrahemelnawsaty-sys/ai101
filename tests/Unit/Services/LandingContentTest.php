<?php

declare(strict_types=1);

/**
 * D-114: the three services behind the landing-page content editor.
 *
 *   LandingCatalog        — the page's texts, their sections and their rules;
 *   LandingOverrides      — the published copy (or the preview's draft);
 *   LandingContentLoader  — the translation loader that lays one over the file.
 *
 * @see BR-31, BR-36 · PRD §9.1 · D-114
 */

use App\Models\LandingContent;
use App\Services\Landing\LandingCatalog;
use App\Services\Landing\LandingContentLoader;
use App\Services\Landing\LandingOverrides;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\FileLoader;

/*
|--------------------------------------------------------------------------
| LandingCatalog
|--------------------------------------------------------------------------
*/

it('BR-31: الفهرس يحمل كل نص في ملف الصفحة مرة واحدة، في قسم واحد', function (): void {
    $catalog = new LandingCatalog;
    $file = array_keys(Arr::dot(require lang_path('ar/landing.php')));
    $keys = $catalog->keys();

    expect($keys)->toHaveCount(count($file))
        ->and(array_unique($keys))->toHaveCount(count($keys))
        ->and(array_map(static fn (string $key): string => substr($key, strlen('landing.')), $keys))->toEqualCanonicalizing($file);

    expect($catalog->has('landing.hero.register'))->toBeTrue()
        ->and($catalog->has('landing.hero'))->toBeFalse()
        ->and($catalog->defaultOf('landing.hero.register', 'en'))->toBe(trans('landing.hero.register', [], 'en'))
        ->and($catalog->defaultOf('landing.nope', 'ar'))->toBe('')
        ->and($catalog->sectionKeys('hero'))->toContain('landing.hero.register')
        ->and($catalog->sectionKeys('not-a-section'))->toBeNull();
});

it('BR-31: نص في الملف لم تذكره الخريطة يُجمع في «نصوص أخرى» ولا يضيع', function (): void {
    $root = sys_get_temp_dir().'/landing-catalog-'.bin2hex(random_bytes(4));
    mkdir($root.'/ar', 0777, true);
    file_put_contents($root.'/ar/landing.php', "<?php return ['hero' => ['register' => 'R'], 'brand_new' => ['line' => 'N']];");

    $catalog = new LandingCatalog(new FileLoader(new Filesystem, [$root]));
    $other = collect($catalog->sections())->firstWhere('key', LandingCatalog::OTHER_SECTION);

    expect($other['groups'][0]['id'])->toBe('unmapped')
        ->and($other['groups'][0]['fields'])->toBe(['landing.brand_new.line'])
        ->and($catalog->sections())->toBe($catalog->sections());

    (new Filesystem)->deleteDirectory($root);
});

it('BR-31: صيغ العدد تُقرأ بعلاماتها، والنص العادي ليس صيغ عدد', function (): void {
    expect(LandingCatalog::pluralForms('one line'))->toBeNull()
        ->and(LandingCatalog::pluralForms('{1} one|[2,*] :count many'))->toBe([
            ['marker' => '{1}', 'text' => 'one'],
            ['marker' => '[2,*]', 'text' => ':count many'],
        ])
        ->and(LandingCatalog::pluralForms('first|second'))->toBe([
            ['marker' => '', 'text' => 'first'],
            ['marker' => '', 'text' => 'second'],
        ])
        ->and(LandingCatalog::placeholders(':count of :total, :count again'))->toBe([':count', ':total']);
});

it('BR-31: قواعد النص المعدَّل — الطول والقيم الحية وصيغ العدد، والفارغ رجوع للأصل', function (): void {
    $catalog = new LandingCatalog;

    // Empty is "back to the original", never an error.
    expect($catalog->issues('landing.footer.whatsapp_message', 'ar', '  '))->toBe([])
        ->and($catalog->issues('landing.footer.whatsapp_message', 'ar', 'بلا اسم'))->toBe(['placeholders'])
        ->and($catalog->issues('landing.footer.whatsapp_message', 'ar', 'عن :program'))->toBe([])
        ->and($catalog->issues('landing.hero.register', 'ar', str_repeat('x', LandingCatalog::MAX_LENGTH + 1)))->toBe(['too_long'])
        // A counted text must stay counted, with the original's markers in order.
        ->and($catalog->issues('landing.sections.seats_choice', 'ar', 'تبقّى :count'))->toContain('plural')
        ->and($catalog->issues('landing.sections.seats_choice', 'en', '{1} one|{0} none|[2,*] :count'))->toContain('plural')
        ->and($catalog->issues('landing.sections.seats_choice', 'en', '{0} none|{1} one|[2,*] :count left'))->toBe([]);

    expect($catalog->storable('landing.hero.register', 'ar', ''))->toBeNull()
        ->and($catalog->storable('landing.hero.register', 'ar', ' '.$catalog->defaultOf('landing.hero.register', 'ar').' '))->toBeNull()
        ->and($catalog->storable('landing.hero.register', 'ar', ' CANARY '))->toBe('CANARY');

    expect($catalog->isMultiline('landing.hero.registration_closed_body'))->toBeTrue()
        ->and($catalog->isMultiline('landing.hero.register'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| LandingOverrides
|--------------------------------------------------------------------------
*/

it('BR-31: المنشور يُقرأ مرة واحدة ويُعطى لكل لغة بأسماء الملف', function (): void {
    LandingContent::query()->create(['key' => 'landing.hero.register', 'ar' => 'AR-ONE', 'en' => null]);
    LandingContent::query()->create(['key' => 'landing.lab.title', 'ar' => '', 'en' => 'EN-TWO']);

    $overrides = new LandingOverrides(app(Translator::class));

    expect($overrides->published())->toBe([
        'landing.hero.register' => ['ar' => 'AR-ONE', 'en' => null],
        'landing.lab.title' => ['ar' => null, 'en' => 'EN-TWO'],
    ])
        ->and($overrides->forLocale('ar'))->toBe(['hero.register' => 'AR-ONE'])
        ->and($overrides->forLocale('en'))->toBe(['lab.title' => 'EN-TWO']);

    // Cached for the request: a later row is not seen until forget().
    LandingContent::query()->create(['key' => 'landing.nav.faq', 'ar' => 'AR-THREE']);
    expect($overrides->forLocale('ar'))->not->toHaveKey('nav.faq');

    $overrides->forget();
    expect($overrides->forLocale('ar'))->toHaveKey('nav.faq');
});

it('BR-31: المعاينة تحلّ محلّ المنشور ثم تنتهي، ولا تكتب شيئًا', function (): void {
    LandingContent::query()->create(['key' => 'landing.hero.register', 'ar' => 'PUBLISHED']);

    $overrides = new LandingOverrides(app(Translator::class));
    $overrides->preview(['landing.hero.register' => ['ar' => 'DRAFT', 'en' => null], 'other.key' => ['ar' => 'X', 'en' => null]]);

    expect($overrides->forLocale('ar'))->toBe(['hero.register' => 'DRAFT']);

    $overrides->endPreview();

    expect($overrides->forLocale('ar'))->toBe(['hero.register' => 'PUBLISHED'])
        ->and(LandingContent::query()->count())->toBe(1);
});

it('المادة 7: جدول لا يُقرأ يعيد نصوص الملف لا صفحة خطأ', function (): void {
    Schema::drop('landing_contents');

    $overrides = new LandingOverrides(app(Translator::class));

    expect($overrides->published())->toBe([])
        ->and($overrides->forLocale('ar'))->toBe([]);
});

it('BR-31: مترجم غير مترجم لارافيل لا يُفرَّغ ولا يُكسر', function (): void {
    $translator = Mockery::mock(Translator::class);
    $overrides = new LandingOverrides($translator);

    $overrides->preview([]);
    $overrides->endPreview();
    $overrides->forget();

    expect($overrides->forLocale('ar'))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| LandingContentLoader
|--------------------------------------------------------------------------
*/

it('BR-31: المحمِّل يضع المنشور فوق الملف لمجموعة الصفحة وحدها', function (): void {
    LandingContent::query()->create(['key' => 'landing.hero.register', 'ar' => 'OVERRIDE']);
    LandingContent::query()->create(['key' => 'landing.hero', 'ar' => 'NOT-A-LINE']);

    $inner = new FileLoader(new Filesystem, [lang_path()]);
    $loader = new LandingContentLoader($inner, app());

    expect($loader->load('ar', 'landing')['hero']['register'])->toBe('OVERRIDE')
        ->and($loader->load('ar', 'landing')['hero'])->toBeArray()
        ->and($loader->load('ar', 'landing', '*')['hero']['register'])->toBe('OVERRIDE')
        ->and($loader->load('ar', 'admin'))->toBe($inner->load('ar', 'admin'))
        ->and($loader->load('ar', 'landing', 'vendor-package'))->toBe($inner->load('ar', 'landing', 'vendor-package'));
});

it('BR-31: المحمِّل يمرّر المساحات المسمّاة وملفات JSON إلى المحمِّل الأصلي', function (): void {
    $inner = Mockery::mock(Loader::class);
    $inner->shouldReceive('addNamespace')->once()->with('pkg', '/path');
    $inner->shouldReceive('addJsonPath')->once()->with('/json');
    $inner->shouldReceive('namespaces')->once()->andReturn(['pkg' => '/path']);

    $loader = new LandingContentLoader($inner, app());
    $loader->addNamespace('pkg', '/path');
    $loader->addJsonPath('/json');

    expect($loader->namespaces())->toBe(['pkg' => '/path']);
});
