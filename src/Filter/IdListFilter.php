<?php

declare(strict_types=1);

namespace ChatLens\Filter;

use ChatLens\Telegram\UserId;

/**
 * Accepts only users from an explicit list.
 *
 * Supported file formats:
 *   - JSON list of ids:            ["user123", "456"]
 *   - JSON list of objects:        [{"id": "user123", "name": "…"}]  (output of `ids:export`)
 *   - plain text, one id per line  (blank lines and lines starting with # are ignored)
 */
final class IdListFilter implements UserFilterInterface
{
    /** @var array<string, true> */
    private array $ids = [];

    /** @param iterable<string|int> $ids */
    public function __construct(iterable $ids, private readonly string $source = 'list')
    {
        foreach ($ids as $id) {
            $normalized = UserId::normalize($id);
            if ($normalized !== '') {
                $this->ids[$normalized] = true;
            }
        }
    }

    public static function fromFile(string $file): self
    {
        if (!is_file($file)) {
            throw new \InvalidArgumentException("Id list not found: {$file}");
        }

        $content = (string) file_get_contents($file);
        $trimmed = ltrim($content, "\xEF\xBB\xBF \t\r\n");

        if ($trimmed !== '' && $trimmed[0] === '[') {
            $data = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            $ids = [];
            foreach ($data as $item) {
                if (is_array($item)) {
                    $item = $item['id'] ?? null;
                }
                if (is_string($item) || is_int($item)) {
                    $ids[] = $item;
                }
            }
        } else {
            $ids = array_filter(
                array_map('trim', preg_split('/\R/', $content) ?: []),
                static fn (string $line) => $line !== '' && $line[0] !== '#'
            );
        }

        return new self($ids, basename($file));
    }

    public function accepts(string $userId): bool
    {
        return isset($this->ids[$userId]);
    }

    public function count(): int
    {
        return count($this->ids);
    }

    public function describe(): string
    {
        return sprintf('%d users from %s', $this->count(), $this->source);
    }
}
