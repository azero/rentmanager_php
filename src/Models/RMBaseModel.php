<?php

declare(strict_types=1);

namespace RentManager\Models;

use ArrayAccess;
use JsonSerializable;

/**
 * Permissive Rent Manager model object.
 *
 * The Python SDK uses Pydantic with extra fields allowed. PHP mirrors that by
 * storing every received field and exposing it through properties and arrays.
 */
class RMBaseModel implements ArrayAccess, JsonSerializable
{
    /** @var array<string,mixed> */
    private array $attributes = [];

    /** @param array<string,mixed>|object|null $data */
    final public function __construct(array|object|null $data = null)
    {
        if (is_object($data)) {
            $data = get_object_vars($data);
        }
        foreach ($data ?? [] as $key => $value) {
            $this->attributes[(string) $key] = $value;
        }
    }

    /** @param array<string,mixed>|object|null $data */
    public static function from(array|object|null $data): static
    {
        return new static($data);
    }

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function __isset(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists((string) $offset, $this->attributes);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[(string) $offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            throw new \InvalidArgumentException('Rent Manager model offsets must be named fields.');
        }
        $this->attributes[(string) $offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[(string) $offset]);
    }
}
