<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Base for every screen's read model.
 *
 * The Blade layer in this project does no logic: a view asks for
 * `$attendance->rateVariant` and prints it. That only works if something has
 * already decided what the variant is, which is what a subclass of this does.
 * The rule it enforces is the one that matters: a view may only read what a
 * presenter deliberately published.
 *
 * Two deliberate choices:
 *
 *  1. Unknown property reads THROW rather than returning null. A typo in a
 *     Blade file is otherwise invisible — the page renders with a silent gap
 *     and nobody notices until a participant does. Failing loudly in
 *     development is the whole point (CONSTITUTION art. 7).
 *
 *  2. Values are computed once, by the presenter, on the server. A view that
 *     could compute `attendanceRate >= 75` itself would be a second place the
 *     rule lives, and the two would drift (CONSTITUTION art. 5, art. 6).
 *
 * @implements \ArrayAccess<string, mixed>
 * @implements Arrayable<string, mixed>
 */
abstract class ViewModel implements \ArrayAccess, \JsonSerializable, Arrayable
{
    /** @var array<string, mixed> */
    protected array $data = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function make(array $data = []): static
    {
        return new static($data); // @phpstan-ignore-line — subclasses keep the signature
    }

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->data)) {
            return $this->data[$name];
        }

        // A computed accessor wins over stored data only when the key is absent,
        // so a presenter can pre-resolve an expensive field into $data and the
        // accessor stops being called.
        $accessor = 'get'.ucfirst($name);

        if (method_exists($this, $accessor)) {
            return $this->{$accessor}();
        }

        throw new \OutOfBoundsException(sprintf(
            '%s does not publish [%s]. Published: %s.',
            static::class,
            $name,
            $this->data === [] ? '(nothing)' : implode(', ', array_keys($this->data)),
        ));
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->data)
            || method_exists($this, 'get'.ucfirst($name));
    }

    /**
     * Read models are built once and read many times. Blocking writes keeps a
     * view from quietly becoming the place a value is decided.
     */
    public function __set(string $name, mixed $value): void
    {
        throw new \BadMethodCallException(sprintf(
            '%s is read-only; [%s] must be set by the presenter that built it.',
            static::class,
            $name,
        ));
    }

    public function has(string $name): bool
    {
        return $this->__isset($name);
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->__isset($name) ? $this->__get($name) : $default;
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->__isset((string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->__get((string) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->__set((string) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->__set((string) $offset, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (mixed $v): mixed => $v instanceof Arrayable ? $v->toArray() : $v,
            $this->data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
