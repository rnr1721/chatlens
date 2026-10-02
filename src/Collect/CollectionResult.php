<?php

declare(strict_types=1);

namespace ChatLens\Collect;

final class CollectionResult
{
    /**
     * @param list<ChatUser> $users
     */
    public function __construct(
        public readonly array $users,
        public readonly int $scannedMessages,
        public readonly int $matchedMessages,
        public readonly int $skippedUsers,
    ) {
    }
}
