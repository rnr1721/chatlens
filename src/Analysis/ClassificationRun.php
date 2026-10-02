<?php

declare(strict_types=1);

namespace ChatLens\Analysis;

use ChatLens\Collect\ChatUser;

final class ClassificationRun
{
    /**
     * @param list<Classification> $classifications
     * @param list<ChatUser>       $failed users the model never returned a verdict for
     */
    public function __construct(
        public readonly array $classifications,
        public readonly array $failed,
        public readonly RunStats $stats,
    ) {
    }
}
