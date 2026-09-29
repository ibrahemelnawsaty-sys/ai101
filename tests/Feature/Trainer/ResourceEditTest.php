<?php

declare(strict_types=1);

/**
 * Phase 2 (2-E) — the training kit's «edit» button opens something.
 *
 * It linked to `?edit={id}`, which the controller never read, and no route
 * updated a resource at all: a trainer who mistyped a title could only archive
 * the material and upload it again (losing its download count).
 *
 * What is built is the METADATA only, in a side drawer — never the file:
 * title, description, week, session, and the address of a link or video. The
 * type, the stored file, its size, who uploaded it and how many times it was
 * downloaded are not editable, whatever a form posts. Editing tells nobody
 * (the cohort was told when the material was ADDED, FR-RES-09), and every edit
 * is written to the audit trail with what it changed.
 *
 * @see BR-22, BR-23, BR-33 · FR-RES-08, FR-RES-10 · PRD §9.12 · CONSTITUTION art. 5, art. 8, art. 22 · D-136
 */

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\Resource;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));

    $this->cohort = makeCohort();
    $this->trainer = makeTrainer($this->cohort);
    $this->w1 = makeWeek($this->cohort, 1, ['title' => 'Week alpha']);
    $this->w2 = makeWeek($this->cohort, 2, ['title' => 'Week beta']);

    $this->file = Resource::factory()->create([
        'cohort_id' => $this->cohort->id, 'week_id' => $this->w1->id, 'type' => 'file',
        'title' => 'Alpha guide', 'description' => 'covers prompt basics',
        'file_url' => 'resources/keep-me.pdf', 'size' => 12345, 'download_count' => 7,
        'uploaded_by' => $this->trainer->id,
    ]);
    $this->link = Resource::factory()->create([
        'cohort_id' => $this->cohort->id, 'week_id' => null, 'type' => 'link',
        'title' => 'Beta link', 'description' => 'reading list',
        'file_url' => null, 'external_url' => 'https://example.com/old', 'download_count' => 3,
        'uploaded_by' => $this->trainer->id,
    ]);
});

/**
 * The drawer's own markup, or '' when no drawer was drawn. The page also holds
 * the «add a resource» form and the row actions (whose archive URL is the same
 * path as the update URL), so the checks look inside the drawer alone.
 */
function drawerOf(Illuminate\Testing\TestResponse $page): string
{
    $html = (string) $page->getContent();
    $at = strpos($html, 'resource-edit-title');

    return $at === false ? '' : substr($html, $at);
}

function editPage(object $test, Resource $resource, array $extra = []): Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->trainer)->get(route('trainer.resources', ['cohort' => $test->cohort->id, 'edit' => $resource->id] + $extra));
}

/*
|--------------------------------------------------------------------------
| The drawer
|--------------------------------------------------------------------------
*/

it('FR-RES-10: زر «تعديل» يفتح درجًا فيه نموذج PATCH بقيم المورد الحالية', function (): void {
    $page = editPage($this, $this->file)->assertOk();

    $page->assertSee(route('trainer.resources.update', $this->file), escape: false)
        ->assertSee('name="_method" value="PATCH"', escape: false)
        ->assertSee('Alpha guide')
        ->assertSee('covers prompt basics')
        ->assertSee(__('trainer.resources.edit_title'));
});

it('FR-RES-10: الدرج يُغلق بالرابط ‎×‎ إلى القائمة بمرشّحاتها — مسار نسبي، فيعمل الإغلاق (و‎Esc‎) بلا سكربت أيضًا', function (): void {
    $page = editPage($this, $this->file, ['q' => 'guide', 'state' => 'all'])->assertOk();
    $drawer = drawerOf($page);

    $path = route('trainer.resources', ['cohort' => $this->cohort->id, 'q' => 'guide', 'state' => 'all'], false);

    // The × is a LINK only when the drawer was given a local path: an absolute
    // URL is dropped by the component (only a path is a safe destination), and
    // the panel then closes by hiding itself while `?edit=` stays in the address.
    expect(preg_match('/<a\s+href="([^"]+)"\s+class="ui-iconbtn ui-drawer__close"/', $drawer, $close))->toBe(1)
        ->and(html_entity_decode($close[1]))->toBe($path)
        // Escape and the backdrop go to the same place: the script receives the
        // path too (Blade's @js writes it JSON-escaped, `\/trainer\/resources`).
        ->and((string) $page->getContent())->toContain("closeHref: '\\/trainer\\/resources");
});

it('FR-RES-10: بلا ‎?edit‎ لا يُرسم درج، ومعرّف مورد دفعة أخرى أو غير موجود لا يفتح شيئًا', function (): void {
    $plain = $this->actingAs($this->trainer)->get(route('trainer.resources', ['cohort' => $this->cohort->id]))->assertOk();
    expect(drawerOf($plain))->toBe('');

    $foreign = Resource::factory()->create(['cohort_id' => makeCohort()->id, 'title' => 'Zed foreign', 'type' => 'file']);

    foreach ([$foreign->id, 'not-a-resource'] as $id) {
        Illuminate\Support\Facades\Auth::forgetGuards();
        $page = $this->actingAs($this->trainer)
            ->get(route('trainer.resources', ['cohort' => $this->cohort->id, 'edit' => $id]))
            ->assertOk()
            ->assertDontSee('Zed foreign');

        expect(drawerOf($page))->toBe('');
    }
})->group('authz');

it('FR-RES-10: مورد نوعه ملف لا يعرض الدرج حقل الرابط ولا حقل ملف؛ ونوعه رابط يعرض الرابط', function (): void {
    $file = drawerOf(editPage($this, $this->file)->assertOk());
    expect($file)->not->toBe('')
        ->and($file)->not->toContain('name="url"')
        ->and($file)->not->toContain('type="file"');

    Illuminate\Support\Facades\Auth::forgetGuards();
    $link = drawerOf(editPage($this, $this->link)->assertOk());
    expect($link)->toContain('name="url"')->toContain('https://example.com/old');
});

/*
|--------------------------------------------------------------------------
| The update
|--------------------------------------------------------------------------
*/

it('FR-RES-10: تعديل العنوان والوصف والأسبوع والجلسة يُحفظ، ويُغلق الدرج بالعودة إلى القائمة بمرشّحاتها', function (): void {
    $session = sessionInCohort($this->cohort, riyadhAt('2026-10-14 18:00:00'), riyadhAt('2026-10-14 21:00:00'), ['week_id' => $this->w2->id]);

    $response = $this->actingAs($this->trainer)->patch(
        route('trainer.resources.update', ['resource' => $this->file->id, 'q' => 'guide', 'state' => 'all']),
        ['title' => 'Alpha guide v2', 'description' => 'rewritten', 'week_id' => $this->w2->id, 'session_id' => $session->id],
    );

    $response->assertSessionHasNoErrors()->assertSessionHas('status', __('trainer.resources.updated'))
        ->assertRedirect(route('trainer.resources', ['cohort' => $this->cohort->id, 'q' => 'guide', 'state' => 'all']));

    $fresh = $this->file->fresh();

    expect($fresh->title)->toBe('Alpha guide v2')
        ->and($fresh->description)->toBe('rewritten')
        ->and($fresh->week_id)->toBe($this->w2->id)
        ->and($fresh->session_id)->toBe($session->id);
});

it('FR-RES-10: الأسبوع والجلسة والوصف يُفرَّغ فيعود المورد «عامًّا»', function (): void {
    $this->actingAs($this->trainer)->patch(route('trainer.resources.update', $this->file), [
        'title' => 'Alpha guide', 'description' => '', 'week_id' => '', 'session_id' => '',
    ])->assertSessionHasNoErrors();

    $fresh = $this->file->fresh();

    expect($fresh->week_id)->toBeNull()->and($fresh->session_id)->toBeNull()->and($fresh->description)->toBeNull();
});

it('FR-RES-10: رابط المورد من نوع رابط أو فيديو يُعدَّل، ويُشترط https', function (): void {
    $this->actingAs($this->trainer)->patch(route('trainer.resources.update', $this->link), [
        'title' => 'Beta link', 'url' => 'https://example.com/new',
    ])->assertSessionHasNoErrors();
    expect($this->link->fresh()->external_url)->toBe('https://example.com/new');

    foreach (['http://example.com/plain', 'javascript:alert(1)', ''] as $bad) {
        $this->actingAs($this->trainer)->patch(route('trainer.resources.update', $this->link), [
            'title' => 'Beta link', 'url' => $bad,
        ])->assertSessionHasErrors('url');
    }

    expect($this->link->fresh()->external_url)->toBe('https://example.com/new');
});

it('BR-22: ما ليس بياناتٍ لا يتغيّر مهما أُرسل — النوع والملف والحجم والعدّاد والرافع والدفعة', function (): void {
    $other = makeCohort();

    $this->actingAs($this->trainer)->patch(route('trainer.resources.update', $this->file), [
        'title' => 'Alpha guide v2',
        'type' => 'link', 'file_url' => 'resources/evil.pdf', 'size' => 1, 'download_count' => 9999,
        'uploaded_by' => makeUser('trainer')->id, 'cohort_id' => $other->id, 'external_url' => 'https://evil.example',
        'url' => 'https://evil.example', 'deleted_at' => '2026-01-01 00:00:00',
    ])->assertSessionHasNoErrors();

    $fresh = $this->file->fresh();

    expect($fresh->title)->toBe('Alpha guide v2')
        ->and($fresh->type->value)->toBe('file')
        ->and($fresh->file_url)->toBe('resources/keep-me.pdf')
        ->and($fresh->size)->toBe(12345)
        ->and($fresh->download_count)->toBe(7)
        ->and($fresh->uploaded_by)->toBe($this->trainer->id)
        ->and($fresh->cohort_id)->toBe($this->cohort->id)
        ->and($fresh->external_url)->toBeNull()
        ->and($fresh->deleted_at)->toBeNull();
})->group('authz');

it('FR-RES-10: مورد مؤرشَف يمكن تعديل بياناته ويبقى مؤرشَفًا', function (): void {
    $this->file->forceFill(['deleted_at' => riyadhAt('2026-10-01 10:00:00')])->save();

    $this->actingAs($this->trainer)->patch(route('trainer.resources.update', $this->file), ['title' => 'Renamed while archived'])
        ->assertSessionHasNoErrors();

    $fresh = Resource::query()->withTrashed()->findOrFail($this->file->id);

    expect($fresh->title)->toBe('Renamed while archived')->and($fresh->deleted_at)->not->toBeNull();
});

it('FR-RES-10: الحقول الناقصة أو الخاطئة تعيد الدرج مفتوحًا بأخطائه وبما كُتب — ولا يُحفظ شيء', function (): void {
    $foreignWeek = makeWeek(makeCohort(), 1);
    $back = route('trainer.resources', ['cohort' => $this->cohort->id, 'edit' => $this->file->id]);

    $response = $this->actingAs($this->trainer)->from($back)->patch(route('trainer.resources.update', $this->file), [
        'title' => '', 'description' => str_repeat('x', 2001), 'week_id' => $foreignWeek->id,
    ]);

    $response->assertRedirect($back)->assertSessionHasErrors(['title', 'description', 'week_id']);

    // The drawer is open again, with what was typed (art. 17: nothing is lost).
    $this->get($back)->assertOk()->assertSee('name="_method" value="PATCH"', escape: false);
    expect($this->file->fresh()->title)->toBe('Alpha guide');
});

it('BR-23: جلسة أو أسبوع من دفعة أخرى يُرفضان ولو كانا موجودين', function (): void {
    $foreign = makeCohort();
    $foreignSession = sessionInCohort($foreign, riyadhAt('2026-10-14 18:00:00'), riyadhAt('2026-10-14 21:00:00'));

    $this->actingAs($this->trainer)->patch(route('trainer.resources.update', $this->file), [
        'title' => 'Alpha guide', 'session_id' => $foreignSession->id,
    ])->assertSessionHasErrors('session_id');

    expect($this->file->fresh()->session_id)->toBeNull();
})->group('authz');

it('FR-RES-10: التعديل يُدقَّق بما تغيّر (قبل وبعد) ولا يُنبّه أحدًا ولا يمس عدّاد التحميل', function (): void {
    Notification::query()->delete();

    $this->actingAs($this->trainer)->patch(route('trainer.resources.update', $this->file), [
        'title' => 'Alpha guide v2', 'description' => 'covers prompt basics', 'week_id' => $this->w1->id,
    ]);

    $entry = AuditLog::query()->where('action', 'resource.updated')->where('entity_id', $this->file->id)->sole();

    expect($entry->actor_id)->toBe($this->trainer->id)
        ->and($entry->before)->toEqual(['title' => 'Alpha guide'])
        ->and($entry->after)->toEqual(['title' => 'Alpha guide v2'])
        ->and(Notification::query()->count())->toBe(0)
        ->and($this->file->fresh()->download_count)->toBe(7);
});

it('FR-RES-10: حفظ بلا أي تغيير لا يكتب سجلًّا فارغًا', function (): void {
    $this->actingAs($this->trainer)->patch(route('trainer.resources.update', $this->file), [
        'title' => 'Alpha guide', 'description' => 'covers prompt basics', 'week_id' => $this->w1->id,
    ])->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('action', 'resource.updated')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Who may — the server decides (art. 5)
|--------------------------------------------------------------------------
*/

it('BR-23: مدرّب دفعة أخرى ومتدرّب ومنسّق لا يعدّلون — 403 والبيانات كما هي', function (): void {
    $outsider = makeTrainer(makeCohort());
    $participant = makeParticipant($this->cohort);
    $coordinator = makeCoordinator($this->cohort);

    foreach ([$outsider, $participant, $coordinator] as $who) {
        Illuminate\Support\Facades\Auth::forgetGuards();
        $this->actingAs($who)->patch(route('trainer.resources.update', $this->file), ['title' => 'Hijacked'])->assertForbidden();
    }

    expect($this->file->fresh()->title)->toBe('Alpha guide');
})->group('authz');

it('BR-33: أثناء معاينة حساب لا يُعدَّل مورد', function (): void {
    $sysadmin = makeSystemAdmin();
    $this->actingAs($sysadmin)->post(route('admin.users.preview', $this->trainer))->assertRedirect();

    $this->patch(route('trainer.resources.update', $this->file), ['title' => 'Edited in preview'])->assertForbidden();

    expect($this->file->fresh()->title)->toBe('Alpha guide');
})->group('authz');

/*
|--------------------------------------------------------------------------
| A refused upload says what happened (it used to print a translation key)
|--------------------------------------------------------------------------
*/

it('FR-RES-10: رفض ملف عند الرفع يقول الجملة العربية لا مفتاح الترجمة', function (): void {
    Illuminate\Support\Facades\Storage::fake('private');

    $response = $this->actingAs($this->trainer)->post(route('trainer.resources.store', ['cohort' => $this->cohort->id]), [
        'title' => 'Fake pdf', 'resource_type' => 'file', 'file' => fakeUpload('report.pdf', 'exe'),
    ]);

    $message = (string) collect(session('errors')?->getBag('default')->all())->first();

    expect($message)->not->toContain('errors.file.')->and($message)->not->toBe('');
});
