<?php

declare(strict_types=1);

namespace App\Presenters\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Builds the option lists that `<x-ui.select>` understands.
 *
 * The component expects `array<int, array{value: string, label: string}>` and
 * silently renders an empty control for anything else — a bare list of enum
 * values or an Eloquent collection produces a select with nothing in it. This
 * is the one place that turns either of those into the shape the component
 * documents, so no screen has to remember the contract.
 *
 * Labels come from the enum's own `label()` (which reads lang/{ar,en}/enums.php) or
 * from a closure the caller supplies, so no Arabic ever reaches a PHP file
 * (CONSTITUTION art. 15).
 *
 * @see PROJECT-CONTRACT §3, §12 · CONSTITUTION art. 6, art. 15
 */
final class Options
{
    /**
     * Every case of a backed enum, labelled from lang/{ar,en}/enums.php.
     *
     * @param  class-string  $enum
     * @return list<array{value: string, label: string}>
     */
    public static function fromEnum(string $enum): array
    {
        if (! enum_exists($enum)) {
            return [];
        }

        $options = [];

        foreach ($enum::cases() as $case) {
            $value = $case instanceof \BackedEnum ? (string) $case->value : $case->name;

            $options[] = [
                'value' => $value,
                'label' => method_exists($case, 'label') ? (string) $case->label() : $value,
            ];
        }

        return $options;
    }

    /**
     * A list of models, labelled by a closure.
     *
     * @param  iterable<int, Model>  $models
     * @param  callable(Model): string  $label
     * @return list<array{value: string, label: string}>
     */
    public static function fromModels(iterable $models, callable $label): array
    {
        $options = [];

        foreach ($models as $model) {
            $options[] = [
                'value' => (string) $model->getKey(),
                'label' => (string) $label($model),
            ];
        }

        return $options;
    }

    /**
     * A ready-made map of value => label.
     *
     * @param  array<string, string>  $pairs
     * @return list<array{value: string, label: string}>
     */
    public static function fromPairs(array $pairs): array
    {
        $options = [];

        foreach ($pairs as $value => $label) {
            $options[] = ['value' => (string) $value, 'label' => (string) $label];
        }

        return $options;
    }
}
