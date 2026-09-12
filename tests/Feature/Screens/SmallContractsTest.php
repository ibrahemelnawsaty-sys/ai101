<?php

declare(strict_types=1);

/**
 * Four small contracts between two files that nothing compared (D-78):
 *   · the preference table and the request that saves it;
 *   · a print sheet's title section and a layout with no yield for it;
 *   · a select template reading a variable its class never published;
 *   · a disabled switch whose hidden companion said "off".
 *
 * @see PRD §9.16, §9.6, §9.8.2 · BR-33 · D-66, D-78
 */

use App\Models\DigitalCard;
use App\Models\NotificationPreference;
use App\Support\NotificationTypes;

/*
|--------------------------------------------------------------------------
| The preference table
|--------------------------------------------------------------------------
*/

it('D-78: كل نوع مبذور معروف في كتالوج الإشعارات بالعربية والإنجليزية', function (): void {
    $seeded = array_column(Database\Seeders\SeedContent::section('notifications'), 'type');

    foreach (['ar', 'en'] as $locale) {
        $known = array_keys((array) trans('notifications.types', [], $locale));
        expect(array_values(array_diff($seeded, $known)))->toBe([]);
    }
});

it('D-78: متدرّب حقيقي بلا صفوف يرى جدول تفضيلاته كاملًا، والنموذج كما يُصيَّر يقبله طلبه', function (): void {
    $participant = makeParticipant(makeCohort());

    $html = (string) $this->actingAs($participant)->get(route('profile'))->assertOk()->getContent();

    preg_match_all('/<input type="hidden" name="prefs\[([a-z_]+)\]\[(platform|email)\]" value="([01])">/', $html, $m, PREG_SET_ORDER);

    $types = array_values(array_unique(array_column($m, 1)));
    sort($types);
    $expected = NotificationTypes::forRole('participant');
    sort($expected);

    expect($types)->toBe($expected)
        ->and($html)->not->toContain('notifications.types.');

    $prefs = [];
    foreach ($m as [, $type, $channel]) {
        $prefs[$type][$channel] = '1';
    }
    $prefs['session_reminder']['email'] = '0';

    $this->actingAs($participant)
        ->put(route('profile.notifications'), ['prefs' => $prefs])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    $row = NotificationPreference::query()->where('user_id', $participant->id)->where('type', 'session_reminder')->sole();
    expect($row->email_enabled)->toBeFalse()->and($row->in_app_enabled)->toBeTrue();
});

it('D-78: صفّ مخزَّن بنوع غير معروف لا يُصيَّر، والمدرّب يرى أنواعه وحدها', function (): void {
    $participant = makeParticipant(makeCohort());
    NotificationPreference::query()->create([
        'user_id' => $participant->id, 'type' => 'grade_published', 'in_app_enabled' => true, 'email_enabled' => true,
    ]);

    $this->actingAs($participant)->get(route('profile'))->assertOk()->assertDontSee('prefs[grade_published]', false);

    expect(NotificationTypes::forRole('trainer'))->toEqualCanonicalizing(['submission_new', 'message_received', 'attendance_incomplete'])
        ->and(NotificationTypes::forRole('admin'))->toBe(['message_received']);
});

/*
|--------------------------------------------------------------------------
| Print sheets
|--------------------------------------------------------------------------
*/

it('D-78: البطاقة المطبوعة تحمل عنوانها، ولا تُوسَم صفحة تحقّق رسمية', function (): void {
    $participant = makeParticipant(makeCohort());
    DigitalCard::factory()->create(['user_id' => $participant->id]);

    $this->actingAs($participant)
        ->get(route('participant.card.print'))
        ->assertOk()
        ->assertSee('<title>'.e((string) __('card.title')).' · ', false)
        ->assertDontSee(e((string) __('verify.shared.privacy_note')), false);
});

it('D-78: الجدول المطبوع يحمل عنوانه', function (): void {
    freezeAt(riyadhAt('2026-10-01 12:00:00'));
    $participant = makeParticipant(makeCohort());

    $this->actingAs($participant)
        ->get(route('schedule.pdf'))
        ->assertOk()
        ->assertSee('<title>'.e((string) __('schedule.print_title')).' · ', false);
});

it('D-78: صفحة التحقّق العامة من البطاقة تُبقي سطر الجهة المُصدِرة', function (): void {
    $this->get(route('card.verify', 'not-a-real-token'))
        ->assertSee('<title>'.e((string) __('verify.shared.verified_by')), false)
        ->assertSee(e((string) __('verify.shared.verified_by')), false);
});

/*
|--------------------------------------------------------------------------
| The select and the switch
|--------------------------------------------------------------------------
*/

it('D-78: القائمة الأصلية تُصيَّر بلا :options وتبدأ بخيار الإرشاد', function (): void {
    $this->blade('<x-ui.select name="gender" label="G"><option value="m">M</option></x-ui.select>')
        ->assertSee('<select', false)
        ->assertSee('<option value="">'.e((string) __('ui.select.placeholder')).'</option>', false);
});

it('D-78: placeholder=false يُسقط الخيار الفارغ — إلا لقائمة مطلوبة', function (): void {
    $this->blade('<x-ui.select name="gender" label="G" :placeholder="false"><option value="m">M</option></x-ui.select>')
        ->assertDontSee('<option value="">', false);

    $this->blade('<x-ui.select name="gender" label="G" required :placeholder="false"><option value="m">M</option></x-ui.select>')
        ->assertSee('<option value="">', false);
});

it('D-78: مفتاح معطَّل يحمل صنفه ويُرسل حالته المعروضة', function (): void {
    $this->blade('<x-ui.toggle name="prefs[certificate_issued][email]" :checked="true" :disabled="true" label="x" />')
        ->assertSee('ui-switch--disabled', false)
        ->assertSee('<input type="hidden" name="prefs[certificate_issued][email]" value="1">', false);

    $this->blade('<x-ui.toggle name="prefs[session_reminder][email]" :checked="false" label="x" />')
        ->assertSee('<input type="hidden" name="prefs[session_reminder][email]" value="0">', false);
});

it('D-78: مربّع اختيار معطَّل مُحدَّد يُرسل قيمته لا الصفر', function (): void {
    $this->blade('<x-ui.checkbox name="agree" :checked="true" :disabled="true" label="x" />')
        ->assertSee('<input type="hidden" name="agree" value="1">', false)
        ->assertSee('ui-check--disabled', false);
});
