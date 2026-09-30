<?php

declare(strict_types=1);

/**
 * `label-hidden` — a control whose words are for assistive technology only.
 *
 * Three screens already handed `label-hidden` to <x-ui.checkbox> and <x-ui.input>
 * (the trainer roster, the certificate candidates, the message composer). Neither
 * component declared it, so Blade did what it does with an unknown attribute: it
 * spilled `label-hidden=""` onto the control and left the label in plain view. On
 * the roster that meant a sentence beside every one of the forty checkboxes.
 *
 * The label must stay in the DOM — a control with no name fails Article 18 — but
 * it is drawn off-screen with the shared `.ui-sr` rule.
 *
 * @see D-143 · PRD §5.8 · CONSTITUTION Articles 15, 18
 */

use Illuminate\Support\Facades\Blade;

it('D-143: a checkbox with label-hidden keeps its name for a screen reader and does not draw it', function (): void {
    $html = Blade::render('<x-ui.checkbox name="user_id[]" :value="7" label="Select Sara" label-hidden />');

    expect($html)->toContain('<span class="ui-check__title ui-sr">')
        ->and($html)->toContain('Select Sara')
        ->and($html)->not->toContain('label-hidden');
});

it('D-143: a checkbox without label-hidden still draws its label', function (): void {
    $html = Blade::render('<x-ui.checkbox name="agree" label="I agree" />');

    expect($html)->toContain('<span class="ui-check__title">')
        ->and($html)->not->toContain('ui-sr');
});

it('D-143: an input with label-hidden keeps a real <label for> that is only drawn off-screen', function (): void {
    $html = Blade::render('<x-ui.input name="body" label="Write a message" label-hidden />');

    expect($html)->toContain('<label class="ui-field__label ui-sr" for="f-body">')
        ->and($html)->toContain('Write a message')
        ->and($html)->not->toContain('label-hidden');
});

it('D-143: an input without label-hidden still draws its label', function (): void {
    $html = Blade::render('<x-ui.input name="body" label="Write a message" />');

    expect($html)->toContain('<label class="ui-field__label" for="f-body">')
        ->and($html)->not->toContain('ui-sr');
});

it('D-143: the hidden label is a component prop, not a leaked attribute, everywhere it is used', function (): void {
    foreach (['admin/certificates', 'trainer/attendance', 'participant/messages'] as $view) {
        $source = (string) file_get_contents(resource_path('views/'.$view.'.blade.php'));

        expect($source)->toContain('label-hidden');
    }

    $contract = (string) file_get_contents(app_path('View/Components/Ui/Checkbox.php'));
    $input = (string) file_get_contents(app_path('View/Components/Ui/Input.php'));

    expect($contract)->toContain('labelHidden')
        ->and($input)->toContain('labelHidden');
});

it('D-143: a list of boxes with one name gives each its own id, so each label points at its own box', function (): void {
    $html = Blade::render(
        '<x-ui.checkbox name="user_id[]" value="aaa-111" label="Select Sara" label-hidden />'
        .'<x-ui.checkbox name="user_id[]" value="bbb-222" label="Select Omar" label-hidden />',
    );

    expect($html)->toContain('id="c-user-id-aaa-111"')
        ->and($html)->toContain('for="c-user-id-aaa-111"')
        ->and($html)->toContain('id="c-user-id-bbb-222"')
        ->and($html)->toContain('for="c-user-id-bbb-222"')
        ->and(substr_count($html, 'id="c-user-id"'))->toBe(0);
});

it('D-143: an explicit id and a plain single box keep the id they always had', function (): void {
    expect(Blade::render('<x-ui.checkbox name="agree" label="I agree" />'))->toContain('id="c-agree"')
        ->and(Blade::render('<x-ui.checkbox name="rows[]" id="mine" value="1" label="One" />'))->toContain('id="mine"');
});
