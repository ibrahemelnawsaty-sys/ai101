<?php

declare(strict_types=1);

/**
 * Phase 2 (2-F) — what the e-mail template editor may change, and what it may
 * never break.
 *
 * The rules under test are the SERVER's (art. 5): the browser mirrors them and
 * decides nothing.
 *
 *   · only the SUBJECT and the BODY of a template are editable — its heading,
 *     button, security notes and links are fixed whatever anyone posts;
 *   · a text keeps every live value its original carries (`:program`, `:name`…),
 *     because the letter would otherwise go out without it; and it may add none
 *     the ORIGINAL of that same field does not carry, because the recipient would
 *     read ":secret" in their inbox. Each sender passes its own values to each
 *     field (the token letter gives `:program` to its subject and its body only),
 *     so the one thing that is proven to reach a field is what that field's own
 *     original already uses — the rule asks for nothing more and allows nothing
 *     less than that;
 *   · a text equal to its original, or empty, is not an override: "back to the
 *     original" is the absence of a row, so the letter follows the file again;
 *   · the defaults are the FILE's, never the published copy, so "the original"
 *     always means the file.
 *
 * @see BR-31, BR-36 · PRD §9.16, §9.18 · CONSTITUTION art. 5, art. 6, art. 22 · D-114, D-136
 */

use App\Services\Mail\EmailTemplates;

beforeEach(function (): void {
    $this->templates = new EmailTemplates;
});

it('BR-31: الفهرس يضمّ القوالب ذات الموضوع أو النص، ويستبعد المشترك «common» وما ليس فيه حقل قابل للتعديل', function (): void {
    $keys = $this->templates->keys();

    expect($keys)->toContain('welcome', 'grade_recorded', 'password_reset', 'support_ticket_opened')
        ->and($keys)->not->toContain('common')
        // `broadcast` has only a button label: its subject and words are the
        // administrator's own, written on the send screen.
        ->and($keys)->not->toContain('broadcast');
});

it('BR-31: الحقول القابلة للتعديل الموضوع والنص وحدهما — وما لا نصّ له لا يُعرض', function (): void {
    expect($this->templates->fieldsOf('welcome'))->toBe(['subject', 'body'])
        // `announcement` carries a subject and a heading but no body.
        ->and($this->templates->fieldsOf('announcement'))->toBe(['subject'])
        ->and($this->templates->fieldsOf('common'))->toBe([])
        ->and($this->templates->fieldsOf('no-such-template'))->toBe([]);

    foreach ($this->templates->keys() as $key) {
        foreach ($this->templates->fieldsOf($key) as $field) {
            expect(['subject', 'body'])->toContain($field);
        }
    }
});

it('BR-31: النص الأصلي يُقرأ من الملف لا من المنشور — وقالب غير معروف يعيد فراغًا', function (): void {
    expect($this->templates->defaultOf('sessions_digest', 'subject'))->toBe('مواعيد جلساتك القادمة في :program')
        ->and($this->templates->defaultOf('welcome', 'heading'))->toBe('')       // not an editable field
        ->and($this->templates->defaultOf('nope', 'subject'))->toBe('');
});

it('BR-31: المتغيّرات المحمية للحقل هي ما في أصله وحده', function (): void {
    // sessions_digest.subject: ':program' only.
    expect($this->templates->requiredIn('sessions_digest', 'subject'))->toBe(['program']);

    // grade_recorded.body: three live values.
    expect($this->templates->requiredIn('grade_recorded', 'body'))->toEqualCanonicalizing(['item', 'score', 'max']);

    // A field with none has none.
    expect($this->templates->requiredIn('password_reset', 'body'))->toBe([]);
});

it('BR-31: ما تُعرَض به المعاينة من قيم هو ما في كل حقول القالب — للمعاينة فقط لا للقبول', function (): void {
    expect($this->templates->allowedIn('grade_revised'))->toEqualCanonicalizing(['item', 'score', 'max', 'reason']);
    expect($this->templates->allowedIn('password_reset'))->toBe([]);
});

it('BR-31: نص يحتفظ بمتغيّراته ويغيّر الكلمات مقبول', function (): void {
    expect($this->templates->problemWith('sessions_digest', 'subject', 'جلساتك القادمة في :program — لا تفوّتها'))->toBeNull()
        ->and($this->templates->problemWith('grade_recorded', 'body', 'رصدنا :item بدرجة :score من :max.'))->toBeNull();
});

it('BR-31: نص يُسقط متغيّرًا حيًّا يحمله أصله يُرفض ويُسمّى المتغيّر', function (): void {
    $problem = $this->templates->problemWith('sessions_digest', 'subject', 'جلساتك القادمة');

    expect($problem)->toBe(['code' => 'missing', 'names' => ['program']]);

    $problem = $this->templates->problemWith('grade_recorded', 'body', 'رصدنا :item بدرجة :score.');

    expect($problem)->toBe(['code' => 'missing', 'names' => ['max']]);
});

it('BR-31: نص يضيف متغيّرًا لا يوفّره قالبه يُرفض — لئلا يقرأ المستلم «:secret» في بريده', function (): void {
    expect($this->templates->problemWith('password_reset', 'body', 'أهلًا :name، هذا رابطك'))
        ->toBe(['code' => 'unknown', 'names' => ['name']])
        ->and($this->templates->problemWith('sessions_digest', 'subject', 'جلساتك في :program و:secret'))
        ->toBe(['code' => 'unknown', 'names' => ['secret']]);
});

it('BR-31: متغيّر يستعمله حقل آخر من القالب نفسه لا يجوز هنا — لا دليل أن مُرسِل الرسالة يمرّره لهذا الحقل', function (): void {
    // ':reason' appears in grade_revised's body only. The subject's own
    // original does not carry it, so nothing proves the sender hands it there.
    expect($this->templates->problemWith('grade_revised', 'subject', 'عُدّلت درجة :item — السبب: :reason'))
        ->toBe(['code' => 'unknown', 'names' => ['reason']]);

    // The same value in the field that DOES carry it is fine.
    expect($this->templates->problemWith('grade_revised', 'body', 'عُدّلت درجتك في «:item» إلى :score من :max — السبب: :reason'))->toBeNull();
});

it('BR-31: الأوقات والروابط ليست متغيّرات — 12:30 وhttps:// لا تُحسب', function (): void {
    expect($this->templates->problemWith('password_reset', 'subject', 'رابط الساعة 12:30 على https://athar.example/reset'))->toBeNull();
});

it('BR-31: المتغيّر يُكشف كما يستبدله لارافيل — بأي حالة أحرف وملتصقًا بما قبله', function (): void {
    // Laravel replaces ":Name" and ":NAME" too, and inside a word.
    expect($this->templates->problemWith('password_reset', 'subject', 'عزيزي:Name'))
        ->toBe(['code' => 'unknown', 'names' => ['name']]);
    expect($this->templates->problemWith('sessions_digest', 'subject', 'جلساتك في :PROGRAM'))->toBeNull();
});

it('BR-31: الطول الأقصى للموضوع 200 حرف وللنص 4000 — عند الحد يُقبل وبعده يُرفض', function (): void {
    expect($this->templates->problemWith('password_reset', 'subject', str_repeat('ا', 200)))->toBeNull()
        ->and($this->templates->problemWith('password_reset', 'subject', str_repeat('ا', 201)))->toBe(['code' => 'long', 'names' => [], 'max' => 200])
        ->and($this->templates->problemWith('password_reset', 'body', str_repeat('ا', 4000)))->toBeNull()
        ->and($this->templates->problemWith('password_reset', 'body', str_repeat('ا', 4001)))->toBe(['code' => 'long', 'names' => [], 'max' => 4000]);
});

it('BR-31: حقل ليس قابلًا للتعديل يُرفض مهما كان نصه', function (): void {
    expect($this->templates->problemWith('welcome', 'heading', 'عنوان جديد'))->toBe(['code' => 'field', 'names' => []])
        ->and($this->templates->problemWith('announcement', 'body', 'نص'))->toBe(['code' => 'field', 'names' => []])
        ->and($this->templates->problemWith('nope', 'subject', 'x'))->toBe(['code' => 'field', 'names' => []]);
});

it('BR-31: النص المطابق للأصل أو الفارغ ليس تجاوزًا — العودة للأصل غياب صف', function (): void {
    $default = $this->templates->defaultOf('sessions_digest', 'subject');

    expect($this->templates->isOverride('sessions_digest', 'subject', $default))->toBeFalse()
        ->and($this->templates->isOverride('sessions_digest', 'subject', '  '.$default.'  '))->toBeFalse()
        ->and($this->templates->isOverride('sessions_digest', 'subject', ''))->toBeFalse()
        ->and($this->templates->isOverride('sessions_digest', 'subject', null))->toBeFalse()
        ->and($this->templates->isOverride('sessions_digest', 'subject', 'جلساتك في :program'))->toBeTrue();
});
