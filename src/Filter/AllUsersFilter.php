<?php

declare(strict_types=1);

namespace ChatLens\Filter;

final class AllUsersFilter implements UserFilterInterface
{
    public function accepts(string $userId): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'all chat participants';
    }
}
