<?php

declare(strict_types=1);

namespace ChatLens\Profile;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads analysis profiles from YAML files.
 * A profile can be referenced by name ("ad-loyalty" → profiles/ad-loyalty.yaml) or by path.
 */
final class ProfileLoader
{
    public function __construct(private readonly string $profilesDir)
    {
    }

    public function load(string $nameOrPath): Profile
    {
        $file = $this->resolve($nameOrPath);
        $data = Yaml::parseFile($file);

        if (!is_array($data)) {
            throw new \InvalidArgumentException("Profile {$file} is empty or not a YAML mapping");
        }

        return $this->fromArray(pathinfo($file, PATHINFO_FILENAME), $data, $file);
    }

    /** @return array<string, string> profile id => file, excluding files starting with "_" */
    public function available(): array
    {
        $result = [];
        foreach (glob($this->profilesDir . '/*.{yaml,yml}', GLOB_BRACE) ?: [] as $file) {
            $id = pathinfo($file, PATHINFO_FILENAME);
            if (!str_starts_with($id, '_')) {
                $result[$id] = $file;
            }
        }
        ksort($result);

        return $result;
    }

    /** @param array<string, mixed> $data */
    public function fromArray(string $id, array $data, string $source = 'array'): Profile
    {
        $prompt = trim((string) ($data['prompt'] ?? ''));
        if ($prompt === '') {
            throw new \InvalidArgumentException("Profile {$source}: 'prompt' is required");
        }

        $rawCategories = $data['categories'] ?? null;
        if (!is_array($rawCategories) || $rawCategories === []) {
            throw new \InvalidArgumentException("Profile {$source}: 'categories' must be a non-empty mapping");
        }

        $categories = [];
        foreach ($rawCategories as $key => $c) {
            $key = (string) $key;
            if (!preg_match('/^[a-z0-9_]+$/', $key)) {
                throw new \InvalidArgumentException(
                    "Profile {$source}: category key '{$key}' must be snake_case (a-z, 0-9, _)"
                );
            }
            $c = is_array($c) ? $c : ['description' => (string) $c];
            $description = trim((string) ($c['description'] ?? ''));
            if ($description === '') {
                throw new \InvalidArgumentException("Profile {$source}: category '{$key}' needs a description");
            }

            $categories[$key] = new Category(
                key: $key,
                labels: $this->localized($c['label'] ?? null, $key),
                description: $description,
                color: (string) ($c['color'] ?? '#667085'),
                icon: (string) ($c['icon'] ?? ''),
            );
        }

        $limits = is_array($data['limits'] ?? null) ? $data['limits'] : [];

        return new Profile(
            id: $id,
            titles: $this->localized($data['title'] ?? $data['name'] ?? null, $id),
            description: trim((string) ($data['description'] ?? '')),
            prompt: $prompt,
            categories: $categories,
            fallback: (string) ($data['fallback'] ?? array_key_last($categories)),
            reasonWords: max(5, (int) ($limits['reason_words'] ?? 20)),
            maxQuotes: max(0, (int) ($limits['quotes'] ?? 2)),
            quoteWords: max(3, (int) ($limits['quote_words'] ?? 15)),
        );
    }

    private function resolve(string $nameOrPath): string
    {
        if (is_file($nameOrPath)) {
            return $nameOrPath;
        }

        foreach (['yaml', 'yml'] as $ext) {
            $candidate = "{$this->profilesDir}/{$nameOrPath}.{$ext}";
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $known = implode(', ', array_keys($this->available())) ?: 'none';
        throw new \InvalidArgumentException("Profile '{$nameOrPath}' not found. Available: {$known}");
    }

    /** @return array<string, string> */
    private function localized(mixed $value, string $default): array
    {
        if (is_array($value)) {
            return array_map('strval', array_filter($value, 'is_scalar')) ?: ['en' => $default];
        }
        if (is_string($value) && $value !== '') {
            return ['en' => $value];
        }

        return ['en' => ucfirst(str_replace(['_', '-'], ' ', $default))];
    }
}
