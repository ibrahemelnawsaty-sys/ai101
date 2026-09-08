<?php

declare(strict_types=1);

/**
 * GET /register had no test that rendered it, and it was a live 500: Blade's
 * directive parser mangled a nested multi-line array inside `@json([...])` and
 * emitted invalid PHP. Every auth page also inherited the login page's <title>.
 *
 * @see PRD §9.2 · CONSTITUTION art. 5, art. 17
 */
it('صفحة التسجيل تعرض النموذج حين يكون التسجيل مفتوحًا', function (): void {
    freezeAt(riyadhAt('2026-09-20 12:00:00'));

    // openCohort() wants all four: an open cohort, a seat, a deadline that has
    // not passed, and the landing switch left on.
    makeCohort([
        'status' => 'open',
        'capacity' => 60,
        'registration_closes_at' => riyadhAt('2026-10-01 23:59:00'),
    ]);

    $body = $this->get(route('register'))->assertOk()->getContent();

    expect($body)->toContain('<form')
        ->and($body)->not->toContain('auth.register.');
});

it('صفحة التسجيل تُعرض حتى بلا دفعة مفتوحة', function (): void {
    $this->get(route('register'))->assertOk();
});

it('كل صفحة مصادقة تحمل عنوانها هي لا عنوان صفحة الدخول', function (): void {
    $login = __('auth.login.title');

    $register = $this->get(route('register'))->assertOk()->getContent();
    $forgot = $this->get(route('password.request'))->assertOk()->getContent();

    expect(titleOf($register))->toContain(__('auth.register.title'))
        ->and(titleOf($register))->not->toContain($login)
        ->and(titleOf($forgot))->toContain(__('auth.forgot.title'))
        ->and(titleOf($forgot))->not->toContain($login);
});

function titleOf(string $html): string
{
    preg_match('/<title>(.*?)<\/title>/su', $html, $m);

    return $m[1] ?? '';
}
