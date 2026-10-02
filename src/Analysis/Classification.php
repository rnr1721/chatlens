<?php

declare(strict_types=1);

namespace ChatLens\Analysis;

/**
 * The verdict for one chat participant.
 */
final class Classification
{
    /**
     * @param list<Quote> $quotes
     */
    public function __construct(
        public readonly string $userId,
        public readonly string $name,
        public readonly string $category,
        public readonly string $reason,
        public readonly array $quotes,
        public readonly int $analyzedMessages,
        public readonly int $totalMessages,
        public readonly bool $fromCache = false,
        public readonly bool $categoryCorrected = false,
    ) {
    }
}
