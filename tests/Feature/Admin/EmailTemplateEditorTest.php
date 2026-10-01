<?php

declare(strict_types=1);

/**
 * Phase 2 (2-F) — the «edit» button of an e-mail template edits it.
 *
 * It linked to a page that ignored the template it was given, and the code said
 * outright that changing a template "is a translation change, not a form
 * submission". BR-31 says otherwise: every changeable text is managed from the
 * admin panel. So the SUBJECT and the BODY of a template are overrides stored in
 * the database over the file's defaults — the pattern of the landing page's
 * editor (D-114), the system administrator's alone (D-117).
 *
 * What is asked of the server, not of the form:
 *
 *  · a letter that goes out carries the edited words — the real Mailable is
 *    rendered here, not a copy of it — while its heading, button, security notes
 *    and links stay the file's whatever was posted;
 *  · a text keeps every live value its original carries and adds none the letter
 *    does not supply (EmailTemplates, tested on its own);
 *  · going back is the absence of a row; a text equal to the original is not a row;
 *  · the preview renders through the same path as a real letter, stores nothing,
 *    sends nothing, and does not outlive its request;
 *  · nobody but the system administrator reads or writes any of it, and nothing
 *    is written from inside an account preview (BR-33);
 *  · every change is in the audit trail with what it changed (art. 8).
 *
 * No test here claims a letter was DELIVERED: none is sent (D-02).
 *
 * @see BR-31, BR-33, BR-36 · PRD §9.16, §9.18 · CONSTITUTION art. 5, art. 6, art. 8, art. 22 · D-02, D-114, D-117, D-136
 */

use App\Mail\AtharLetter;
use App\Models\AuditLog;
use App\Models\EmailTemplateOverride;
use App\Services\Mail\EmailTemplates;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-12 12:00:00'));
    Mail::fake();

    $this->sys = makeSystemAdmin();
});

/** The welcome letter as a participant would receive it. */
function welcomeLetter(): AtharLetter
{
    return new AtharLetter(
        'emails.welcome',
        ['program' => 'AI 101', 'cohort' => 'الدفعة الأولى', 'datetime' => 'الأحد 12 أكتوبر'],
        'https://athar.example/dashboard',
    );
}

function saveTemplate(object $test, string $template, array $fields, ?object $as = null): Illuminate\Testing\TestResponse
{
    return ($as ?? $test)->actingAs($as ?? $test->sys)->put(route('admin.settings.template.update', $template), $fields);
}

/*
|--------------------------------------------------------------------------
| The page
|--------------------------------------------------------------------------
*/

it('BR-31: صفحة القالب تعرض الموضوع والنص بقيمتهما الحالية وأصلهما والمتغيّرات المحمية، وما لا يتغيّر', function (): void {
    $page = $this->actingAs($this->sys)->get(route('admin.settings.template', 'sessions_digest'))->assertOk();

    $page->assertSee('name="subject"', escape: false)
        ->assertSee('name="body"', escape: false)
        // The original, so a reset is a known thing.
        ->assertSee('مواعيد جلساتك القادمة في :program')
        // The protected value, named.
        ->assertSee(':program')
        // What the letter keeps whatever is typed: its heading and its button.
        ->assertSee(__('emails.sessions_digest.heading'))
        ->assertSee(__('emails.sessions_digest.cta'))
        ->assertSee(route('admin.settings.template.preview', 'sessions_digest'), escape: false);
});

it('BR-31: قالب بلا نصّ (announcement) يعرض الموضوع وحده', function (): void {
    $page = $this->actingAs($this->sys)->get(route('admin.settings.template', 'announcement'))->assertOk();

    $page->assertSee('name="subject"', escape: false)->assertDontSee('name="body"', escape: false);
});

it('BR-31: قالب غير موجود أو مشترك أو بلا حقل قابل للتعديل — 404', function (): void {
    foreach (['nope', 'common', 'broadcast'] as $template) {
        Illuminate\Support\Facades\Auth::forgetGuards();
        $this->actingAs($this->sys)->get(route('admin.settings.template', $template))->assertNotFound();
    }
});

it('BR-31: قائمة القوالب في الإعدادات تُعلّم المعدَّل منها، ولا تعرض ما لا يُعدَّل', function (): void {
    saveTemplate($this, 'welcome', ['subject' => 'مرحبًا بك في :program', 'body' => '']);

    $list = $this->actingAs($this->sys)->get(route('admin.settings.edit'))->assertOk();

    $list->assertSee(route('admin.settings.template', 'welcome'), escape: false)
        ->assertSee(__('admin.email_editor.customised'))
        ->assertDontSee(route('admin.settings.template', 'broadcast'), escape: false)
        ->assertDontSee(route('admin.settings.template', 'common'), escape: false);
});

/*
|--------------------------------------------------------------------------
| Saving — and what the real letter then says
|--------------------------------------------------------------------------
*/

it('BR-31: الموضوع والنص المعدَّلان يصلان في الرسالة الحقيقية، وعنوانها وزرّها كما هما', function (): void {
    $before = welcomeLetter()->render();
    expect($before)->toContain(__('emails.welcome.heading'));

    saveTemplate($this, 'welcome', [
        'subject' => 'يسعدنا انضمامك إلى :program',
        'body' => 'مقعدك في :cohort محجوز، وننتظرك.',
    ])->assertSessionHasNoErrors()->assertSessionHas('status', __('admin.email_editor.saved'));

    $letter = welcomeLetter();
    $html = $letter->render();

    expect($letter->envelope()->subject)->toBe('يسعدنا انضمامك إلى AI 101')
        ->and($html)->toContain('مقعدك في الدفعة الأولى محجوز، وننتظرك.')
        ->and($html)->not->toContain('فُعّل حسابك والتحقت بالدفعة')
        // The fixed parts.
        ->and($html)->toContain(__('emails.welcome.heading'))
        ->and($html)->toContain(__('emails.welcome.cta'));
});

it('BR-31: نص فيه وسم يُطبع مُهرَّبًا في الرسالة — لا يُنفَّذ ولا يُضيف رابطًا', function (): void {
    saveTemplate($this, 'welcome', ['subject' => 'x :program', 'body' => '<script>alert(1)</script> <a href="https://evil.example">اضغط</a> :cohort'])
        ->assertSessionHasNoErrors();

    $html = welcomeLetter()->render();

    expect($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->and($html)->not->toContain('<a href="https://evil.example">');
});

it('BR-31: الجمل الأمنية في رسالة إعادة كلمة المرور الحقيقية ثابتة مهما عُدّل نصّها', function (): void {
    saveTemplate($this, 'password_reset', ['subject' => 'استعادة كلمة المرور', 'body' => 'اضغط الزر لاستعادة حسابك.'])
        ->assertSessionHasNoErrors();

    // The REAL mailable: password reset is an EmailTokenLink with a shell of
    // its own, not an AtharLetter.
    $user = new App\Models\User(['email' => 'trainee@example.test']);
    $letter = new App\Mail\EmailTokenLink($user, App\Enums\EmailTokenType::Reset, 'https://athar.example/reset/abc');
    $html = $letter->render();

    expect($letter->envelope()->subject)->toBe('استعادة كلمة المرور')
        ->and($html)->toContain('اضغط الزر لاستعادة حسابك.')
        ->and($html)->toContain(__('emails.password_reset.expiry_note'))
        ->and($html)->toContain(__('emails.password_reset.ignore_note'))
        ->and($html)->toContain(__('emails.password_reset.heading'));
});

it('BR-31: حقل يُفرَّغ أو يُعاد إلى نصّه الأصلي يحذف تجاوزه — ولا صفّ لنصّ مطابق للأصل', function (): void {
    saveTemplate($this, 'welcome', ['subject' => 'مرحبًا بك في :program', 'body' => 'نص جديد :cohort']);
    expect(EmailTemplateOverride::query()->count())->toBe(2);

    // Subject back to the file's own words, body blank: both go.
    // The FILE's own subject — the translator would now serve the override.
    // D-147: and the page says so — «the new wording is on its way» would be false.
    saveTemplate($this, 'welcome', ['subject' => app(EmailTemplates::class)->defaultOf('welcome', 'subject'), 'body' => '   '])
        ->assertSessionHas('status', __('admin.email_editor.saved_default'));

    expect(EmailTemplateOverride::query()->count())->toBe(0)
        ->and(welcomeLetter()->envelope()->subject)->toBe('أهلًا بك في AI 101');
});

it('BR-31: «أرجع للأصل» يحذف تعديلات القالب وحده ويُدقَّق، ولا يمسّ قالبًا آخر', function (): void {
    saveTemplate($this, 'welcome', ['subject' => 'مرحبًا بك في :program', 'body' => '']);
    saveTemplate($this, 'grade_recorded', ['subject' => 'درجتك في :item', 'body' => '']);

    $this->actingAs($this->sys)->delete(route('admin.settings.template.reset', 'welcome'))
        ->assertSessionHas('status', __('admin.email_editor.reset_done'));

    expect(EmailTemplateOverride::query()->pluck('key')->all())->toBe(['emails.grade_recorded.subject'])
        ->and(welcomeLetter()->envelope()->subject)->toBe('أهلًا بك في AI 101')
        ->and(AuditLog::query()->where('action', 'email_template.reset')->where('actor_id', $this->sys->id)->count())->toBe(1);
});

it('BR-31: نص يُسقط متغيّرًا يُرفض برسالة تسمّيه، وما كُتب يبقى في الحقل، ولا يُحفظ شيء', function (): void {
    $editor = route('admin.settings.template', 'sessions_digest');

    $this->actingAs($this->sys)->from($editor)->put(route('admin.settings.template.update', 'sessions_digest'), [
        'subject' => 'جلساتك القادمة بلا برنامج', 'body' => '',
    ])->assertRedirect($editor)->assertSessionHasErrors('subject');

    $message = session('errors')->first('subject');
    expect($message)->toContain(':program');

    $this->get($editor)->assertOk()->assertSee('جلساتك القادمة بلا برنامج');
    expect(EmailTemplateOverride::query()->count())->toBe(0);
});

it('BR-31: نص يضيف متغيّرًا لا يحمله حقله يُرفض — «:secret» لن تُطبع في بريد أحد', function (): void {
    $this->actingAs($this->sys)->put(route('admin.settings.template.update', 'password_reset'), [
        'subject' => 'استعادة', 'body' => 'أهلًا :name — رمزك :secret',
    ])->assertSessionHasErrors('body');

    expect(session('errors')->first('body'))->toContain(':name')->toContain(':secret')
        ->and(EmailTemplateOverride::query()->count())->toBe(0);
});

it('BR-31: ما ليس الموضوع ولا النص لا يُحفظ مهما أُرسل — العنوان والزر والملاحظات الأمنية', function (): void {
    saveTemplate($this, 'password_reset', [
        'subject' => 'استعادة', 'heading' => 'عنوان مزوَّر', 'cta' => 'اضغط هنا', 'expiry_note' => 'صالح للأبد',
        'ignore_note' => 'لا تتجاهل', 'preheader' => 'x', 'not_you' => 'y',
    ])->assertSessionHasNoErrors();

    expect(EmailTemplateOverride::query()->pluck('key')->all())->toBe(['emails.password_reset.subject']);

    $user = new App\Models\User(['email' => 'trainee@example.test']);
    $html = (new App\Mail\EmailTokenLink($user, App\Enums\EmailTokenType::Reset, 'https://athar.example/reset/abc'))->render();
    expect($html)->toContain(__('emails.password_reset.heading'))->not->toContain('عنوان مزوَّر')->not->toContain('صالح للأبد');
});

it('BR-31: الطول الأقصى — الموضوع 200 والنص 4000', function (): void {
    saveTemplate($this, 'password_reset', ['subject' => str_repeat('ا', 201), 'body' => str_repeat('ا', 4001)])
        ->assertSessionHasErrors(['subject', 'body']);

    saveTemplate($this, 'password_reset', ['subject' => str_repeat('ا', 200), 'body' => str_repeat('ا', 4000)])
        ->assertSessionHasNoErrors();

    expect(EmailTemplateOverride::query()->count())->toBe(2);
});

it('BR-31: الحفظ يُدقَّق بما تغيّر قبل وبعد لكل حقل، ولا يُرسل شيئًا ولا يُصفّ في الطابور', function (): void {
    saveTemplate($this, 'welcome', ['subject' => 'مرحبًا بك في :program', 'body' => '']);
    saveTemplate($this, 'welcome', ['subject' => 'أهلًا وسهلًا في :program', 'body' => '']);

    $entries = AuditLog::query()->where('action', 'email_template.updated')->where('actor_id', $this->sys->id)->orderBy('created_at')->get();

    expect($entries)->toHaveCount(2)
        ->and($entries[0]->before)->toEqual(['subject' => null])
        ->and($entries[0]->after)->toEqual(['subject' => 'مرحبًا بك في :program'])
        ->and($entries[1]->before)->toEqual(['subject' => 'مرحبًا بك في :program'])
        ->and($entries[1]->after)->toEqual(['subject' => 'أهلًا وسهلًا في :program']);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('BR-31: حفظ بلا أي تغيير لا يكتب سجلًّا', function (): void {
    saveTemplate($this, 'welcome', ['subject' => app(EmailTemplates::class)->defaultOf('welcome', 'subject'), 'body' => '']);

    expect(AuditLog::query()->where('action', 'email_template.updated')->count())->toBe(0);
});

it('BR-31: صفّ قديم لحقل لم يعد قابلًا للتعديل (زرّ القالب) لا يُطبَّق — الملف هو الذي يقرّر أي نص يوجد', function (): void {
    EmailTemplateOverride::query()->create(['key' => 'emails.welcome.cta', 'ar' => 'زر مزوَّر']);
    EmailTemplateOverride::query()->create(['key' => 'emails.no_such_template.subject', 'ar' => 'x']);

    $html = welcomeLetter()->render();

    expect($html)->toContain(__('emails.welcome.cta'))->not->toContain('زر مزوَّر');
});

it('BR-31: قاعدة بيانات لا تُقرأ تعيد نصوص الملف — الرسالة لا تفشل (المادة 7)', function (): void {
    Schema::drop('email_template_overrides');

    expect(welcomeLetter()->envelope()->subject)->toBe('أهلًا بك في AI 101');
});

/*
|--------------------------------------------------------------------------
| The preview — what the letter will look like, with nothing stored or sent
|--------------------------------------------------------------------------
*/

it('BR-31: المعاينة تعرض الرسالة بمسودّتها وقيم تجريبية عبر مسار الرسالة الحقيقية، بلا حفظ ولا إرسال ولا تدقيق', function (): void {
    $response = $this->actingAs($this->sys)->postJson(route('admin.settings.template.preview', 'welcome'), [
        'subject' => 'يسعدنا انضمامك إلى :program',
        'body' => 'مقعدك في :cohort محجوز.',
    ])->assertOk();

    $subject = $response->json('subject');
    $html = $response->json('html');

    expect($subject)->toContain('يسعدنا انضمامك إلى')->not->toContain(':program')
        ->and($html)->toContain('مقعدك في')->not->toContain(':cohort')
        ->and($html)->toContain(__('emails.welcome.heading'))
        // A preview is a picture: marked as one, in the letter's own frame.
        ->and($html)->toContain('<html');

    expect(EmailTemplateOverride::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'like', 'email_template.%')->count())->toBe(0);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('BR-31: المعاينة تُهرِّب الوسوم، وتتسامح مع متغيّر غير معروف بعرضه كما هو بدل أن تفشل', function (): void {
    $html = $this->actingAs($this->sys)->postJson(route('admin.settings.template.preview', 'welcome'), [
        'subject' => 'x', 'body' => '<b>قوي</b> :nothing_known',
    ])->assertOk()->json('html');

    expect($html)->toContain('&lt;b&gt;قوي&lt;/b&gt;')->and($html)->toContain(':nothing_known');
});

it('BR-31: المعاينة لا تعيش بعد طلبها — الرسالة التالية تقرأ المنشور لا المسودّة', function (): void {
    $this->actingAs($this->sys)->postJson(route('admin.settings.template.preview', 'welcome'), [
        'subject' => 'مسودّة لن تُنشر :program', 'body' => '',
    ])->assertOk();

    expect(welcomeLetter()->envelope()->subject)->toBe('أهلًا بك في AI 101');
});

it('BR-31: المعاينة تقبل نصًّا فارغًا (تعرض الأصل) ولا تقبل ما فوق الحد', function (): void {
    $this->actingAs($this->sys)->postJson(route('admin.settings.template.preview', 'welcome'), ['subject' => '', 'body' => ''])
        ->assertOk();

    // Over the real limit the preview answers with the save's own sentence; only
    // a request that would make the server render a book is refused outright.
    $this->actingAs($this->sys)->postJson(route('admin.settings.template.preview', 'welcome'), ['subject' => str_repeat('ا', 201)])
        ->assertOk();

    $this->actingAs($this->sys)->postJson(
        route('admin.settings.template.preview', 'welcome'),
        ['subject' => str_repeat('ا', EmailTemplates::PREVIEW_CEILING + 1)],
    )->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Who may — the server decides (art. 5)
|--------------------------------------------------------------------------
*/

it('D-117: المشرف العام والمدرب والمنسّق والمتدرب لا يقرؤون القالب ولا يعدّلونه ولا يعاينونه — 403 والبيانات كما هي', function (): void {
    $cohort = makeCohort();
    $people = [makeAdmin(), makeTrainer($cohort), makeCoordinator($cohort), makeParticipant($cohort)];

    foreach ($people as $who) {
        Illuminate\Support\Facades\Auth::forgetGuards();
        $this->actingAs($who)->get(route('admin.settings.template', 'welcome'))->assertForbidden();
        $this->actingAs($who)->put(route('admin.settings.template.update', 'welcome'), ['subject' => 'x :program'])->assertForbidden();
        $this->actingAs($who)->delete(route('admin.settings.template.reset', 'welcome'))->assertForbidden();
        $this->actingAs($who)->postJson(route('admin.settings.template.preview', 'welcome'), ['subject' => 'x'])->assertForbidden();
    }

    expect(EmailTemplateOverride::query()->count())->toBe(0);
})->group('authz');

it('BR-33: أثناء معاينة حساب لا يُحفظ قالب ولا يُرجَع — 403', function (): void {
    saveTemplate($this, 'welcome', ['subject' => 'مرحبًا بك في :program', 'body' => '']);

    $this->actingAs($this->sys)->post(route('admin.users.preview', makeAdmin()))->assertRedirect();

    $this->put(route('admin.settings.template.update', 'welcome'), ['subject' => 'مسروق :program'])->assertForbidden();
    $this->delete(route('admin.settings.template.reset', 'welcome'))->assertForbidden();

    expect(EmailTemplateOverride::query()->pluck('ar')->all())->toBe(['مرحبًا بك في :program']);
})->group('authz');

/*
|--------------------------------------------------------------------------
| Every template — a value nobody wrote a sample for would print as ":name"
|--------------------------------------------------------------------------
*/

it('BR-31: كل قالب قابل للتعديل تُفتح صفحته وتُعاين رسالته، ولا يبقى متغيّر غير مستبدَل في الموضوع ولا في الرسالة', function (): void {
    $templates = app(EmailTemplates::class);

    foreach ($templates->keys() as $key) {
        Illuminate\Support\Facades\Auth::forgetGuards();
        $this->actingAs($this->sys)->get(route('admin.settings.template', $key))->assertOk();

        $response = $this->actingAs($this->sys)->postJson(route('admin.settings.template.preview', $key), [])->assertOk();

        foreach ($templates->allowedIn($key) as $name) {
            expect($response->json('subject'))->not->toContain(':'.$name, "subject of {$key}");
            expect($response->json('html'))->not->toContain(':'.$name, "html of {$key}");
        }
    }
});

it('BR-31: معاينة إعادة كلمة المرور تمرّ بالصنف الحقيقي — فيها ملاحظة الصلاحية وجملة «إن لم تطلب هذا»', function (): void {
    $html = $this->actingAs($this->sys)->postJson(route('admin.settings.template.preview', 'password_reset'), [
        'subject' => '', 'body' => 'نص معدَّل للاستعادة.',
    ])->assertOk()->json('html');

    expect($html)->toContain('نص معدَّل للاستعادة.')
        ->and($html)->toContain(__('emails.password_reset.expiry_note'))
        ->and($html)->toContain(__('emails.password_reset.ignore_note'));
});

it('BR-31: صفوف القوالب التي تخصّ رسائل أخرى (فاتورة الاستلام ودعوة الرابط) تُعاين بنصّها الجديد', function (): void {
    foreach (['invitation_link', 'final_project_received'] as $key) {
        Illuminate\Support\Facades\Auth::forgetGuards();
        $response = $this->actingAs($this->sys)->postJson(route('admin.settings.template.preview', $key), [])->assertOk();

        expect($response->json('html'))->toContain('<html');
    }
});

it('BR-31: «أرجع للأصل» خارج نموذج الحفظ — النماذج المتداخلة لا يقبلها المتصفح فكان الزر يحفظ بدل أن يُرجع', function (): void {
    saveTemplate($this, 'welcome', ['subject' => 'مرحبًا بك في :program', 'body' => '']);

    $html = (string) $this->actingAs($this->sys)->get(route('admin.settings.template', 'welcome'))->assertOk()->getContent();

    $opens = strpos($html, 'id="email-template-form"');
    $closes = strpos($html, '</form>', (int) $opens);
    $reset = strpos($html, 'value="DELETE"', (int) $opens);

    expect($opens)->not->toBeFalse()
        ->and($reset)->not->toBeFalse()
        // The DELETE form starts only after the save form has closed.
        ->and($reset)->toBeGreaterThan($closes);
});

/*
|--------------------------------------------------------------------------
| The independent security review (Art. 27), each finding pinned
|--------------------------------------------------------------------------
*/

it('BR-31: حقل يُرسَل مصفوفة يُرفض بـ 422 لا بخطأ خادم — في الحفظ كما في المعاينة', function (): void {
    saveTemplate($this, 'welcome', ['subject' => ['a'], 'body' => 'نص'])->assertSessionHasErrors('subject');
    saveTemplate($this, 'welcome', ['subject' => 'موضوع', 'body' => ['a']])->assertSessionHasErrors('body');

    Illuminate\Support\Facades\Auth::forgetGuards();
    $this->actingAs($this->sys)->postJson(route('admin.settings.template.preview', 'welcome'), ['subject' => ['a']])
        ->assertUnprocessable();

    expect(EmailTemplateOverride::query()->count())->toBe(0);
});

it('BR-31: موضوع بفاصل سطر لا يُحفظ، وسبب الرفض بعبارة مفهومة', function (): void {
    $response = saveTemplate($this, 'welcome', ['subject' => "مرحبًا\r\nBcc: x@example.test", 'body' => '']);

    $response->assertSessionHasErrors('subject');
    expect(session('errors')->first('subject'))->toBe(__('admin.email_editor.errors.line'))
        ->and(EmailTemplateOverride::query()->count())->toBe(0);
});

it('BR-31: متغيّر بحالة أحرف لا يستبدلها لارافيل (:cOhort) لا يُحفظ — وإلا وصل الرسالة حرفيًا', function (): void {
    $response = saveTemplate($this, 'welcome', ['subject' => 'مرحبًا بك في :program', 'body' => 'التحقت بالدفعة :cOhort.']);

    $response->assertSessionHasErrors('body');
    expect(EmailTemplateOverride::query()->count())->toBe(0);
});

it('BR-31: المعاينة تقول الجملة نفسها التي يقولها الحفظ عند تجاوز الطول — لا اسم الحقل الإنجليزي', function (): void {
    $response = $this->actingAs($this->sys)->postJson(
        route('admin.settings.template.preview', 'welcome'),
        ['subject' => str_repeat('ا', 201)],
    )->assertOk();

    $saved = saveTemplate($this, 'welcome', ['subject' => str_repeat('ا', 201)]);
    $saved->assertSessionHasErrors('subject');

    expect($response->json('messages.subject'))->toBe(session('errors')->first('subject'))
        ->and($response->json('messages.subject'))->not->toContain('subject');
});

it('BR-31: الأصل المعروض تحت الحقل معزول اتجاهيًا — «:program» تبقى في صورتها', function (): void {
    $html = (string) $this->actingAs($this->sys)->get(route('admin.settings.template', 'sessions_digest'))->assertOk()->getContent();

    expect($html)->toContain("\u{2066}:program\u{2069}");
});

it('BR-31: رسالة «تعذّرت المعاينة» تحمل زرًا يعيدها — النص يقول «أعد المحاولة» فلا بدّ من زر', function (): void {
    $html = (string) $this->actingAs($this->sys)->get(route('admin.settings.template', 'welcome'))->assertOk()->getContent();

    expect($html)->toContain('x-on:click="render()"');
});

it('BR-31: منطقة إعلان الخطأ حيّة دائمًا في الصفحة — العنصر الذي يُظهَر بـ x-show وحده لا تعلنه قارئات الشاشة', function (): void {
    $html = (string) $this->actingAs($this->sys)->get(route('admin.settings.template', 'welcome'))->assertOk()->getContent();

    // The live region is a wrapper that is always there; only what is inside it
    // comes and goes.
    expect($html)->toMatch('/<div[^>]*role="status"[^>]*aria-live="polite"[^>]*>\s*<p class="hint hint--bad" x-show="messages\.subject"/')
        ->and($html)->toMatch('/<div[^>]*role="alert"[^>]*>\s*<p class="hint hint--bad" x-show="state === \'error\'"/');
});
