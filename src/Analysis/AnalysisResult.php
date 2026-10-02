<?php

declare(strict_types=1);

namespace ChatLens\Analysis;

use ChatLens\Collect\ChatUser;
use ChatLens\Profile\Profile;

/**
 * Everything a report needs about one analysis run.
 */
final class AnalysisResult
{
    /**
     * @param list<Classification> $classifications
     * @param list<ChatUser>       $failed
     * @param array<string, mixed> $meta extra info for reports: scanned messages, filter, etc.
     */
    public function __construct(
        public readonly string $chatName,
        public readonly Profile $profile,
        public readonly string $provider,
        public readonly string $model,
        public readonly \DateTimeImmutable $generatedAt,
        public readonly array $classifications,
        public readonly array $failed,
        public readonly RunStats $stats,
        public readonly array $meta = [],
    ) {
    }

    /**
     * Classifications grouped by category, in profile order. Every category is present.
     *
     * @return array<string, list<Classification>>
     */
    public function byCategory(): array
    {
        $groups = array_fill_keys($this->profile->categoryKeys(), []);
        foreach ($this->classifications as $c) {
            $groups[$c->category][] = $c;
        }

        return $groups;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return array_map('count', $this->byCategory());
    }
}
