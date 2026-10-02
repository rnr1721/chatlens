<?php

declare(strict_types=1);

namespace ChatLens\Telegram;

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

/**
 * Streams a Telegram Desktop chat export (result.json) without loading it into memory.
 *
 * Supports single-chat exports ("Export chat history" → JSON). Full account exports
 * ("Export Telegram data") contain many chats and are rejected with a clear error.
 */
final class TelegramExportReader
{
    private const METADATA_SCAN_LIMIT = 4 * 1024 * 1024;

    /** @var array<string, mixed>|null */
    private ?array $metadata = null;

    public function __construct(private readonly string $file)
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new \InvalidArgumentException("Telegram export not found or not readable: {$file}");
        }
    }

    public function file(): string
    {
        return $this->file;
    }

    /**
     * Top-level fields of the export except "messages" (name, type, id).
     * In Telegram exports "messages" is the last key, so only the head of the file is read.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        if ($this->metadata !== null) {
            return $this->metadata;
        }

        $fh = fopen($this->file, 'rb');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open {$this->file}");
        }

        $head = '';
        $metadata = null;

        while (!feof($fh) && strlen($head) < self::METADATA_SCAN_LIMIT) {
            $chunk = fread($fh, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $head .= $chunk;

            if (preg_match('/"chats"\s*:\s*\{/', $head)) {
                fclose($fh);
                throw new \RuntimeException(
                    'This looks like a full account export. Export a single chat instead: '
                    . 'open the chat in Telegram Desktop → ⋮ → Export chat history → format JSON.'
                );
            }

            if (preg_match('/"messages"\s*:\s*\[/', $head, $m, PREG_OFFSET_CAPTURE)) {
                $metaPart = rtrim(substr($head, 0, $m[0][1]), " \t\r\n,");
                $decoded = json_decode($metaPart . '}', true);
                $metadata = is_array($decoded) ? $decoded : $this->fallbackMetadata($metaPart);
                break;
            }
        }
        fclose($fh);

        if ($metadata === null) {
            throw new \RuntimeException("No \"messages\" array found in {$this->file} — is it a Telegram chat export?");
        }

        return $this->metadata = $metadata;
    }

    public function chatName(): string
    {
        $name = $this->metadata()['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : pathinfo($this->file, PATHINFO_FILENAME);
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    public function messages(): \Generator
    {
        $this->metadata(); // validates the file shape before streaming

        $items = Items::fromFile($this->file, [
            'pointer' => '/messages',
            'decoder' => new ExtJsonDecoder(true),
        ]);

        foreach ($items as $message) {
            if (is_array($message)) {
                yield $message;
            }
        }
    }

    /** @return array<string, mixed> */
    private function fallbackMetadata(string $head): array
    {
        $meta = [];
        if (preg_match('/"name"\s*:\s*("(?:[^"\\\\]|\\\\.)*")/u', $head, $m)) {
            $meta['name'] = json_decode($m[1]);
        }

        return $meta;
    }
}
