<?php

declare(strict_types=1);

namespace ChatLens\Llm;

final class LlmRequest
{
    /**
     * @param array<string, mixed>|null $jsonSchema expected answer schema (used by providers that support it)
     */
    public function __construct(
        public readonly string $system,
        public readonly string $user,
        public readonly int $maxTokens = 8192,
        public readonly ?array $jsonSchema = null,
    ) {
    }
}
