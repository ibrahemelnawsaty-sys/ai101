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
        ->assertSee(__('app.retry'));
});

it('D-124: هيكل التحميل بشكل القائمة القادمة — التبويبات وصفوف التذاكر', function (): void {
    $markup = renderSkeleton('support');

    expect($markup)->toBeScreenState('loading')
        ->and($markup)->toContain('data-skeleton-block="tabs"')
        ->and($markup)->toContain('data-skeleton-block="tickets"');
});
