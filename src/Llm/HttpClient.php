<?php

declare(strict_types=1);

namespace ChatLens\Llm;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Minimal JSON-over-HTTP client on top of curl, with retries for transient failures:
 * network errors, 408, 429, 5xx (and 529 "overloaded"). Honours Retry-After.
 */
final class HttpClient
{
    private const RETRYABLE_STATUSES = [408, 409, 425, 429, 500, 502, 503, 504, 529];

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly int $timeout = 300,
        private readonly int $connectTimeout = 20,
        private readonly int $retries = 3,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $body
     * @return array{status: int, body: string}
     */
    public function postJson(string $url, array $headers, array $body, ?int $timeout = null): array
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $headerLines = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            [$status, $responseBody, $retryAfter, $error] = $this->send($url, $headerLines, $payload, $timeout ?? $this->timeout);

            $retryable = $error !== null || in_array($status, self::RETRYABLE_STATUSES, true);
            if (!$retryable || $attempt > $this->retries) {
                if ($error !== null) {
                    throw new LlmException("HTTP request failed: {$error}");
                }

                return ['status' => $status, 'body' => $responseBody];
            }

            $delay = $retryAfter ?? min(60, 2 ** ($attempt - 1) * 2);
            $this->logger->warning('{reason} — retrying in {delay}s (attempt {attempt}/{max})', [
                'reason' => $error ?? "HTTP {$status}",
                'delay' => $delay,
                'attempt' => $attempt,
                'max' => $this->retries,
            ]);
            sleep($delay);
        }
    }

    /**
     * @param list<string> $headerLines
     * @return array{0: int, 1: string, 2: int|null, 3: string|null}
     */
    private function send(string $url, array $headerLines, string $payload, int $timeout): array
    {
        $retryAfter = null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                if (stripos($line, 'retry-after:') === 0) {
                    $value = trim(substr($line, 12));
                    if (ctype_digit($value)) {
                        $retryAfter = min(120, (int) $value);
                    }
                }
                return strlen($line);
            },
        ]);

        $body = curl_exec($ch);
        $error = $body === false ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, $body === false ? '' : (string) $body, $retryAfter, $error];
    }
}
