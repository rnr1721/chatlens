<?php

declare(strict_types=1);

namespace ChatLens\Support;

/**
 * Reads an environment variable (from the real environment or .env).
 * Empty strings are treated as "not set".
 */
function env(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    if ($value === false || $value === null || $value === '') {
        return $default;
    }

    return match (strtolower((string) $value)) {
        'true' => true,
        'false' => false,
        'null' => null,
        default => $value,
    };
}
