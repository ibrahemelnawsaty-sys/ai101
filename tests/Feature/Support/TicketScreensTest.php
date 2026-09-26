<?php

declare(strict_types=1);

/**
 * «Support» screens (D-124), as Article 17 asks of every screen: a normal,
 * a loading, an empty and an error state — the empty one with copy written
 * for this screen, and for each of the support team's two tabs — and the
 * forms each reader is offered, which are exactly the ones the policy lets
 * them send.
 *
 * @see D-124 · CONSTITUTION art. 5, art. 17, art. 18
 */

use App\Models\AuditLog;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    freezeAt(riyadhAt('2026-10-05 10:00:00'));
    Storage::fake('private');
    Mail::fake();

    $this->cohort = makeCohort(['status' => 'running']);
    $this->coordinator = makeCoordinator($this->cohort);
    $this->participant = makeParticipant($this->cohort);
    $this->supervisor = makeAdmin();
});

function tkScreenTicket(object $test): SupportTicket
{
    $test->actingAs($test->participant)->post(route('support.store'), [
        'subject' => 'لا تظهر المهمة الثالثة',
        'category' => 'program',
        'body' => 'فتحت صفحة المهام ولم أجدها.',
    ])->assertSessionHasNoErrors();

    return SupportTicket::query()->sole();
}

function tkXpath(string $html): DOMXPath
{
    $dom = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return new DOMXPath($dom);
}

/** The number on the rail's badge beside the support tab, or null without one. */
function tkRailBadge(string $html): ?string
{
    $node = tkXpath($html)->query('//aside//a[@href="'.route('support.index').'"]//span[contains(@class,"side__badge")]/span[contains(@class,"u-num")]')->item(0);

    return $node === null ? null : trim($node->textContent);
}

it('D-124: المتدرب بلا تذاكر يرى حالة فارغة بنصّها الخاص وزر فتح تذكرة، ثم تذكرته بحالتها وموضعها بالدور', function (): void {
    $this->actingAs($this->participant)
        ->get(route('support.index'))
        ->assertOk()
        ->assertSee('data-screen="support"', false)
        ->assertSee('data-state="empty"', false)
        ->assertSee(__('empty.support.title'))
        ->assertSee(__('empty.support.body'))
        ->assertSee(route('support.create'), false);

    $ticket = tkScreenTicket($this);

    $this->actingAs($this->participant)
        ->get(route('support.index'))
        ->assertSee('data-state="normal"', false)
        ->assertSee($ticket->number)
        ->assertSee($ticket->subject)
        ->assertSee(__('enums.support_ticket_status.open'))
        ->assertSee(__('support.at', ['level' => __('enums.support_ticket_level.coordinator')]))
        ->assertSee(__('enums.support_ticket_category.program'));
});

it('D-124: فريق الدعم يرى «بانتظاري» و«كل التذاكر» بنصّ فارغ لكل منهما، وعدّاد ما بانتظاره في التبويب والشريط', function (): void {
    $this->actingAs($this->coordinator)
        ->get(route('support.index'))
        ->assertOk()
        ->assertSee(__('support.tabs.waiting'))
        ->assertSee(__('support.tabs.all'))
        ->assertSee(__('support.index.waiting_empty_title'))
        ->assertDontSee(route('support.create'), false);

    $this->actingAs($this->coordinator)
        ->get(route('support.index', ['tab' => 'all']))
        ->assertSee(__('support.index.all_empty_title'));

    $ticket = tkScreenTicket($this);

    $waiting = (string) $this->actingAs($this->coordinator)
        ->get(route('support.index'))
        ->assertSee($ticket->number)
        ->assertSee(e($this->participant->email), false)
        ->getContent();

    expect(tkRailBadge($waiting))->toBe('1');

    // It moves up: no longer waiting on the coordinator, waiting on the supervisor.
    $this->actingAs($this->coordinator)->post(route('support.escalate', $ticket));

    $after = (string) $this->actingAs($this->coordinator)->get(route('support.index'))->getContent();
    expect(tkRailBadge($after))->toBeNull()
        ->and($after)->toContain(e(__('support.index.waiting_empty_title')));

    $supervisorPage = (string) $this->actingAs($this->supervisor)->get(route('support.index'))->assertSee($ticket->number)->getContent();
    expect(tkRailBadge($supervisorPage))->toBe('1');

    // The participant's rail has no support tab: the account menu carries it.
    $mine = (string) $this->actingAs($this->participant)->get(route('support.index'))->getContent();
    expect(tkRailBadge($mine))->toBeNull()
        ->and(tkXpath($mine)->query('//aside//a[@href="'.route('support.index').'"]')->length)->toBe(0);
});

it('D-124: نموذج التذكرة الجديدة يعرض التصنيفات الأربعة وحدود المرفقات كما في الإعدادات', function (): void {
    $page = $this->actingAs($this->participant)->get(route('support.create'))->assertOk();

    foreach (['account', 'platform', 'program', 'other'] as $category) {
        $page->assertSee(__('enums.support_ticket_category.'.$category));
    }

    $page->assertSee('enctype="multipart/form-data"', false)
        ->assertSee('accept="'.e(App\Services\Tickets\TicketAttachments::ACCEPT).'"', false)
        ->assertSee(__('support.create.files_hint', ['count' => trans_choice('support.count.files', 3, ['count' => 3]), 'size' => 10]));
});

it('D-124: صفحة التذكرة تعرض لكل قارئ ما يملكه فقط — المتدرب الرد والإغلاق، والمنسّق الذي عنده الكتابة والمعالجة والتحويل، والمشرف ملاحظة داخلية قبل أن تصله', function (): void {
    $ticket = tkScreenTicket($this);
    $forms = static fn (string $html): array => collect(iterator_to_array(tkXpath($html)->query('//form[@method="POST"]')))
        ->map(fn (DOMElement $form): string => (string) $form->getAttribute('action'))
        ->filter(fn (string $action): bool => str_contains($action, '/support/'))
        ->values()
        ->all();

    $participantPage = (string) $this->actingAs($this->participant)->get(route('support.show', $ticket))
        ->assertOk()
        ->assertSee('data-screen="support"', false)
        ->getContent();

    expect($forms($participantPage))->toBe([route('support.reply', $ticket), route('support.close', $ticket)]);

    $coordinatorPage = (string) $this->actingAs($this->coordinator)->get(route('support.show', $ticket))->getContent();

    expect($forms($coordinatorPage))->toBe([route('support.note', $ticket), route('support.resolve', $ticket), route('support.escalate', $ticket)])
        ->and($coordinatorPage)->toContain(e(__('support.actions.internal')));

    $supervisorPage = (string) $this->actingAs($this->supervisor)->get(route('support.show', $ticket))->getContent();

    expect($forms($supervisorPage))->toBe([route('support.note', $ticket)])
        ->and($supervisorPage)->toContain(e(__('support.actions.internal_only')))
        ->and($supervisorPage)->not->toContain(e(__('support.actions.internal')).'<');
});

it('D-124: تعذّر جلب التذاكر يُظهر حالة الخطأ بنصّها وإعادة المحاولة، لا صفحة فارغة ولا عطلًا', function (): void {
    Schema::drop('support_ticket_attachments');
    Schema::drop('support_ticket_entries');
    Schema::drop('support_tickets');

    $this->actingAs($this->participant)
        ->get(route('support.index'))
        ->assertOk()
        ->assertSee('data-state="error"', false)
        ->assertSee(__('support.index.error_title'))
        ->assertSee(__('support.index.error_body'))
        ->assertSee(__('app.retry'))
        // Opening a ticket does not depend on the list.
        ->assertSee(route('support.create'), false);
});

it('D-124: هيكل التحميل بشكل القائمة القادمة — التبويبات وصفوف التذاكر', function (): void {
    $markup = renderSkeleton('support');

    expect($markup)->toBeScreenState('loading')
        ->and($markup)->toContain('data-skeleton-block="tabs"')
        ->and($markup)->toContain('data-skeleton-block="tickets"');
});

/** The value of the `seen` stamp the page printed into the form that posts to $action. */
function tkSeen(string $html, string $action): string
{
    $input = tkXpath($html)->query('//form[@action="'.$action.'"]//input[@name="seen"]')->item(0);

    expect($input)->toBeInstanceOf(DOMElement::class);

    return (string) $input->getAttribute('value');
}

it('D-124: عدّاد «بانتظاري» يظهر رقمه في التبويب ويُقرأ بجملته — لا شارة فارغة', function (): void {
    tkScreenTicket($this);

    $page = tkXpath((string) $this->actingAs($this->coordinator)->get(route('support.index'))->assertOk()->getContent());
    $badge = $page->query('//nav[contains(@class,"tkt-tabs")]//a[@aria-current="page"]//span[contains(@class,"ui-badge--count")]')->item(0);

    expect($badge)->toBeInstanceOf(DOMElement::class)
        ->and(trim((string) $page->query('.//span[contains(@class,"u-num")]', $badge)->item(0)?->textContent))->toBe('1')
        ->and(trim((string) $page->query('.//span[contains(@class,"ui-sr")]', $badge)->item(0)?->textContent))
        ->toBe(trans_choice('support.count.waiting', 1, ['count' => 1]));
});

it('D-124: نموذج أُرسل بعد أن تغيّرت التذكرة يعود إلى صفحتها بالسبب ونصُّه محفوظ — وبلا ختم الصفحة، أو ممن لا يقرؤها، 403', function (): void {
    $ticket = tkScreenTicket($this);

    // The participant's page, drawn before the coordinator resolved it.
    $participantPage = (string) $this->actingAs($this->participant)->get(route('support.show', $ticket))->getContent();
    $replySeen = tkSeen($participantPage, route('support.reply', $ticket));

    // The coordinator's page, drawn while the ticket was with them.
    $coordinatorPage = (string) $this->actingAs($this->coordinator)->get(route('support.show', $ticket))->getContent();
    $resolveSeen = tkSeen($coordinatorPage, route('support.resolve', $ticket));

    // Moved up from another tab: the resolve form above is now stale.
    $this->actingAs($this->coordinator)->post(route('support.escalate', $ticket))->assertSessionHasNoErrors();

    $this->actingAs($this->coordinator)
        ->post(route('support.resolve', $ticket), ['summary' => 'CANARY-SUMMARY', 'seen' => $resolveSeen])
        ->assertRedirect(route('support.show', $ticket))
        ->assertSessionHasErrors(['message' => __('support.errors.moved_on')])
        ->assertSessionHasInput('summary', 'CANARY-SUMMARY');

    expect(AuditLog::query()->where('action', 'access.denied')->where('entity_id', $ticket->id)->get()
        ->filter(fn (AuditLog $row): bool => ($row->after['reason'] ?? null) === 'support.stale_form')->count())->toBe(1);

    // The participant writes while the ticket closes under them (they closed
    // it from another tab): told it is closed, the text kept.
    $this->actingAs($this->participant)->post(route('support.close', $ticket), ['seen' => $replySeen])->assertSessionHasNoErrors();

    $this->actingAs($this->participant)
        ->post(route('support.reply', $ticket), ['body' => 'CANARY-LATE', 'seen' => $replySeen])
        ->assertRedirect(route('support.show', $ticket))
        ->assertSessionHasErrors(['message' => __('support.errors.closed')])
        ->assertSessionHasInput('body', 'CANARY-LATE');

    // A request no page drew — no stamp — is refused as before.
    $this->actingAs($this->participant)->post(route('support.reply', $ticket), ['body' => 'x'])->assertForbidden();

    // Someone who cannot read the ticket gets the 403 whatever the stamp says.
    $this->actingAs(makeParticipant($this->cohort))
        ->post(route('support.reply', $ticket), ['body' => 'x', 'seen' => $replySeen])
        ->assertForbidden();

    expect($ticket->entries()->where('body', 'CANARY-LATE')->exists())->toBeFalse()
        ->and($ticket->fresh()->status->value)->toBe('closed');
});

it('D-124: نموذج فريق الدعم يقول على زرّه لمن يذهب النص — للمتدرب أو ملاحظة داخلية — ومن لا يكتب للمتدرب يرى «ملاحظة داخلية» وحدها', function (): void {
    $ticket = tkScreenTicket($this);

    $coordinatorPage = (string) $this->actingAs($this->coordinator)->get(route('support.show', $ticket))->getContent();
    $noteForm = tkXpath($coordinatorPage)->query('//form[@action="'.route('support.note', $ticket).'"]')->item(0);

    expect($noteForm)->toBeInstanceOf(DOMElement::class)
        ->and((string) $noteForm?->getAttribute('x-data'))->toContain('internal')
        ->and($noteForm?->textContent)->toContain(__('support.actions.submit_message'))
        ->and($noteForm?->textContent)->toContain(__('support.actions.internal'))
        // The label follows the checkbox as it is ticked.
        ->and(tkXpath($coordinatorPage)->query('//form[@action="'.route('support.note', $ticket).'"]//button//span[@x-text]')->length)->toBe(1);

    $supervisorPage = (string) $this->actingAs($this->supervisor)->get(route('support.show', $ticket))->getContent();
    $supervisorForm = tkXpath($supervisorPage)->query('//form[@action="'.route('support.note', $ticket).'"]')->item(0);

    expect($supervisorForm?->textContent)->toContain(__('support.actions.submit_internal'))
        ->and($supervisorForm?->textContent)->toContain(__('support.actions.compose_internal'))
        ->and($supervisorForm?->textContent)->not->toContain(__('support.actions.submit_message'));
});

it('D-124: صفحة التذكرة المغلقة تعطي المتدرب زر تذكرة جديدة كما يقول نصّها — وفريق الدعم لا يراه', function (): void {
    $ticket = tkScreenTicket($this);
    $this->actingAs($this->participant)->post(route('support.close', $ticket))->assertSessionHasNoErrors();

    $this->actingAs($this->participant)
        ->get(route('support.show', $ticket))
        ->assertSee(route('support.create'), false);

    $this->actingAs($this->coordinator)
        ->get(route('support.show', $ticket))
        ->assertDontSee(route('support.create'), false);
});
