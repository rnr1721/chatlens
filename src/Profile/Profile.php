<?php

declare(strict_types=1);

namespace ChatLens\Profile;

/**
 * An analysis profile: what to look for (the prompt) and how to classify (the categories).
 * Profiles are plain YAML files — see profiles/_template.yaml.
 */
final class Profile
{
    /**
     * @param array<string, string>   $titles     lang code => report title
     * @param array<string, Category> $categories ordered as in the file
     */
    public function __construct(
        public readonly string $id,
        public readonly array $titles,
        public readonly string $description,
        public readonly string $prompt,
        public readonly array $categories,
        public readonly string $fallback,
        public readonly int $reasonWords = 20,
        public readonly int $maxQuotes = 2,
        public readonly int $quoteWords = 15,
    ) {
        if (count($categories) < 2) {
            throw new \InvalidArgumentException("Profile '{$id}': at least two categories are required");
        }
        if (!isset($categories[$fallback])) {
            throw new \InvalidArgumentException(
                "Profile '{$id}': fallback category '{$fallback}' is not among categories: "
                . implode(', ', array_keys($categories))
            );
        }
    }

    public function title(string $lang): string
    {
        return $this->titles[$lang] ?? $this->titles['en'] ?? (reset($this->titles) ?: $this->id);
    }

    public function category(string $key): ?Category
    {
        return $this->categories[$key] ?? null;
    }

    /** @return list<string> */
    public function categoryKeys(): array
    {
        return array_keys($this->categories);
    }

    /** Stable fingerprint of everything that affects the model's answer. */
    public function fingerprint(): string
    {
        $parts = [$this->prompt, $this->fallback, $this->reasonWords, $this->maxQuotes, $this->quoteWords];
        foreach ($this->categories as $c) {
            $parts[] = [$c->key, $c->description];
        }

        return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE));
    }
}
