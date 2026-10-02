<?php

declare(strict_types=1);

namespace ChatLens\Analysis;

use ChatLens\Collect\ChatUser;
use ChatLens\Llm\LlmException;
use ChatLens\Llm\LlmProviderInterface;
use ChatLens\Llm\LlmRequest;
use ChatLens\Llm\LlmResponse;
use ChatLens\Llm\OutputTruncatedException;
use ChatLens\Profile\Profile;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Classifies chat participants in batches.
 *
 *  1. Users with a cached answer are taken from the cache.
 *  2. The rest are sent in batches of `chunkSize`.
 *  3. If the answer is cut by the output limit, the batch is split in half recursively.
 *  4. Users the model skipped or a broken answer lost are retried in small batches.
 *  5. Every answer is validated: unknown categories fall back, quotes are checked
 *     against the real messages.
 */
final class Classifier
{
    public const QUOTES_MARK = 'mark';
    public const QUOTES_DROP = 'drop';

    private readonly LoggerInterface $logger;

    // state of the current run
    private Profile $profile;
    private string $system = '';
    /** @var array<string, mixed> */
    private array $schema = [];
    /** @var array<string, string> user id => cache key */
    private array $cacheKeys = [];
    /** @var array<string, Classification> */
    private array $results = [];
    private RunStats $stats;
    private float $lastCallAt = 0.0;

    public function __construct(
        private readonly LlmProviderInterface $provider,
        private readonly ResultCache $cache,
        private readonly PromptBuilder $prompts = new PromptBuilder(),
        private readonly JsonRepair $parser = new JsonRepair(),
        private readonly QuoteVerifier $verifier = new QuoteVerifier(),
        private readonly int $chunkSize = 8,
        private readonly int $retryChunkSize = 3,
        private readonly int $maxTokens = 8192,
        private readonly int $delayMs = 1000,
        private readonly string $quotePolicy = self::QUOTES_MARK,
        ?LoggerInterface $logger = null,
    ) {
        if ($chunkSize < 1 || $retryChunkSize < 1) {
            throw new \InvalidArgumentException('chunk sizes must be >= 1');
        }
        if (!in_array($quotePolicy, [self::QUOTES_MARK, self::QUOTES_DROP], true)) {
            throw new \InvalidArgumentException("analysis.quotes must be 'mark' or 'drop'");
        }
        $this->logger = $logger ?? new NullLogger();
        $this->stats = new RunStats();
    }

    /**
     * @param list<ChatUser> $users
     */
    public function classify(array $users, Profile $profile, string $chatName, string $reasonLanguage = PromptBuilder::AUTO_LANGUAGE): ClassificationRun
    {
        $this->profile = $profile;
        $this->system = $this->prompts->system($profile, $chatName, $reasonLanguage);
        $this->schema = $this->prompts->responseSchema($profile);
        $this->results = [];
        $this->cacheKeys = [];
        $this->stats = new RunStats();

        $pending = [];
        foreach ($users as $user) {
            $key = $this->cache->key($this->provider->name(), $this->provider->model(), $this->system, $user->name, $user->messages);
            $this->cacheKeys[$user->id] = $key;

            $cached = $this->cache->get($key);
            if ($cached !== null) {
                $this->results[$user->id] = $this->build($user, $cached, fromCache: true);
                $this->stats->cacheHits++;
            } else {
                $pending[] = $user;
            }
        }

        $this->logger->info('{cached} users from cache, {pending} to analyse with {provider} ({model})', [
            'cached' => $this->stats->cacheHits,
            'pending' => count($pending),
            'provider' => $this->provider->name(),
            'model' => $this->provider->model(),
        ]);

        $missing = [];
        $batches = array_chunk($pending, $this->chunkSize);
        foreach ($batches as $i => $batch) {
            array_push($missing, ...$this->processBatch($batch, sprintf('batch %d/%d', $i + 1, count($batches))));
        }

        $failed = [];
        if ($missing !== []) {
            $this->stats->retried = count($missing);
            $retryBatches = array_chunk($missing, $this->retryChunkSize);
            $this->logger->notice('Retrying {n} users the model did not return', ['n' => count($missing)]);

            foreach ($retryBatches as $i => $batch) {
                array_push($failed, ...$this->processBatch($batch, sprintf('retry %d/%d', $i + 1, count($retryBatches))));
            }
        }

        // keep the original order of users
        $ordered = [];
        foreach ($users as $user) {
            if (isset($this->results[$user->id])) {
                $ordered[] = $this->results[$user->id];
            }
        }

        return new ClassificationRun($ordered, $failed, $this->stats);
    }

    /**
     * @param list<ChatUser> $users
     * @return list<ChatUser> users that got no verdict
     */
    private function processBatch(array $users, string $label): array
    {
        ['payload' => $payload, 'aliases' => $aliases] = $this->prompts->userPayload($users);

        try {
            $response = $this->call(new LlmRequest($this->system, $payload, $this->maxTokens, $this->schema));
        } catch (OutputTruncatedException $e) {
            if (count($users) > 1) {
                $this->stats->splits++;
                $halves = array_chunk($users, (int) ceil(count($users) / 2));
                $this->logger->warning('{label}: output limit reached, splitting {n} users into {a} + {b}', [
                    'label' => $label,
                    'n' => count($users),
                    'a' => count($halves[0]),
                    'b' => count($halves[1]),
                ]);

                return [
                    ...$this->processBatch($halves[0], $label . 'a'),
                    ...$this->processBatch($halves[1], $label . 'b'),
                ];
            }
            $this->logger->error('{label}: output limit reached even for a single user ({id}). Raise analysis.max_tokens.', [
                'label' => $label,
                'id' => $users[0]->id,
            ]);

            return $users;
        } catch (LlmException $e) {
            $this->logger->error('{label}: {error}', ['label' => $label, 'error' => $e->getMessage()]);

            return $users;
        }

        $parsed = $this->parser->parseUsers($response->content);
        if ($parsed === null) {
            $file = $this->cache->saveRaw('failed', $response->content);
            $this->logger->warning('{label}: could not parse the answer (saved to {file})', ['label' => $label, 'file' => $file]);

            return $users;
        }

        $strategy = $parsed['strategy'];
        $this->stats->parseStrategies[$strategy] = ($this->stats->parseStrategies[$strategy] ?? 0) + 1;

        $byRealId = [];
        foreach ($users as $user) {
            $byRealId[$user->id] = $user;
        }

        $answered = [];
        foreach ($parsed['users'] as $item) {
            $id = (string) $item['id'];
            $user = $aliases[$id] ?? $byRealId[$id] ?? null; // tolerate a model that echoes real ids
            if ($user === null || isset($answered[$user->id])) {
                continue;
            }
            if (!isset($item['category']) && !isset($item['loyalty'])) {
                continue; // an object cut off before its category — retry rather than guess
            }

            $result = $this->normalizeItem($item);
            $this->cache->put($this->cacheKeys[$user->id], $result);
            $this->results[$user->id] = $this->build($user, $result, fromCache: false);
            $answered[$user->id] = true;
        }

        $missing = array_values(array_filter($users, static fn (ChatUser $u) => !isset($answered[$u->id])));

        $this->logger->info('{label}: {ok}/{n} users{flags}', [
            'label' => $label,
            'ok' => count($answered),
            'n' => count($users),
            'flags' => ($strategy !== JsonRepair::DIRECT ? " [repaired: {$strategy}]" : '')
                . ($response->truncated ? ' [truncated]' : ''),
        ]);

        return $missing;
    }

    private function call(LlmRequest $request): LlmResponse
    {
        if ($this->lastCallAt > 0 && $this->delayMs > 0) {
            $wait = $this->delayMs / 1000 - (microtime(true) - $this->lastCallAt);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
        }

        $this->stats->requests++;
        try {
            $response = $this->provider->complete($request);
        } finally {
            $this->lastCallAt = microtime(true);
        }

        $this->stats->inputTokens += $response->inputTokens;
        $this->stats->outputTokens += $response->outputTokens;

        return $response;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{category: string, reason: string, quotes: list<string>}
     */
    private function normalizeItem(array $item): array
    {
        $quotes = [];
        foreach ((array) ($item['quotes'] ?? []) as $q) {
            if (is_string($q) && trim($q) !== '') {
                $quotes[] = trim($q);
            }
        }

        return [
            'category' => strtolower(trim((string) ($item['category'] ?? $item['loyalty'] ?? ''))),
            'reason' => trim((string) ($item['reason'] ?? '')),
            'quotes' => $quotes,
        ];
    }

    /** @param array<string, mixed> $result */
    private function build(ChatUser $user, array $result, bool $fromCache): Classification
    {
        $category = (string) ($result['category'] ?? '');
        $corrected = false;
        if ($this->profile->category($category) === null) {
            $category = $this->profile->fallback;
            $corrected = true;
            $this->stats->correctedCategories++;
        }

        $quotes = [];
        foreach (array_slice((array) ($result['quotes'] ?? []), 0, $this->profile->maxQuotes) as $text) {
            $verified = $this->verifier->verify((string) $text, $user->messages);
            if (!$verified) {
                $this->stats->unverifiedQuotes++;
                if ($this->quotePolicy === self::QUOTES_DROP) {
                    continue;
                }
            }
            $quotes[] = new Quote((string) $text, $verified);
        }

        return new Classification(
            userId: $user->id,
            name: $user->name,
            category: $category,
            reason: (string) ($result['reason'] ?? ''),
            quotes: $quotes,
            analyzedMessages: count($user->messages),
            totalMessages: $user->totalMessages,
            fromCache: $fromCache,
            categoryCorrected: $corrected,
        );
    }
}
