<?php

declare(strict_types=1);

namespace ChatLens\Analysis;

/**
 * File cache of per-user model answers.
 *
 * The key covers everything that influences the answer (provider, model, system prompt,
 * user's name and messages) — but not the batch the user happened to be in. So re-running
 * with another chunk size, or after an interrupted run, never pays twice for the same user.
 */
final class ResultCache
{
    public function __construct(private readonly string $dir, private readonly bool $readEnabled = true)
    {
    }

    /** @param list<string> $messages */
    public function key(string $provider, string $model, string $system, string $userName, array $messages): string
    {
        return hash('sha256', json_encode(
            [$provider, $model, $system, $userName, $messages],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        if (!$this->readEnabled) {
            return null;
        }

        $file = $this->resultFile($key);
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $result */
    public function put(string $key, array $result): void
    {
        $file = $this->resultFile($key);
        $this->ensureDir(dirname($file));
        file_put_contents($file, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    /** Keeps the raw answer for debugging; returns the file path. */
    public function saveRaw(string $kind, string $content): string
    {
        $dir = $this->dir . '/' . $kind;
        $this->ensureDir($dir);
        $file = sprintf('%s/%s_%s.txt', $dir, date('Ymd-His'), substr(hash('crc32b', $content . microtime()), 0, 8));
        file_put_contents($file, $content);

        return $file;
    }

    private function resultFile(string $key): string
    {
        return sprintf('%s/results/%s/%s.json', $this->dir, substr($key, 0, 2), $key);
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create cache directory {$dir}");
        }
    }
}
