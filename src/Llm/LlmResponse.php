<?php

declare(strict_types=1);

namespace ChatLens\Llm;

final class LlmResponse
{
    public function __construct(
        public readonly string $content,
        public readonly bool $truncated = false,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly string $raw = '',
    ) {
    }
}
