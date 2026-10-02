<?php

declare(strict_types=1);

namespace ChatLens\Config;

/**
 * Read-only access to a nested config array using dot notation: get('analysis.chunk_size').
 */
final class Config
{
    /** @param array<string, mixed> $items */
    public function __construct(private readonly array $items)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return $this->get($key, $this) !== $this;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }
}
