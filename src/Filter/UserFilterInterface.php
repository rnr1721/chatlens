<?php

declare(strict_types=1);

namespace ChatLens\Filter;

interface UserFilterInterface
{
    /** @param string $userId normalized id, e.g. "user123" */
    public function accepts(string $userId): bool;

    /** Human-readable description for logs. */
    public function describe(): string;
}
