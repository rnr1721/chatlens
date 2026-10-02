<?php

declare(strict_types=1);

namespace ChatLens\Profile;

final class Category
{
    /**
     * @param array<string, string> $labels lang code => label
     */
    public function __construct(
        public readonly string $key,
        public readonly array $labels,
        public readonly string $description,
        public readonly string $color = '#667085',
        public readonly string $icon = '',
    ) {
    }

    public function label(string $lang): string
    {
        return $this->labels[$lang]
            ?? $this->labels['en']
            ?? (reset($this->labels) ?: $this->key);
    }
}
