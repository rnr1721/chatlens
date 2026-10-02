<?php

declare(strict_types=1);

namespace ChatLens\Llm\Provider;

use ChatLens\Llm\HttpClient;
use ChatLens\Llm\LlmException;
use ChatLens\Llm\LlmProviderInterface;
use ChatLens\Llm\LlmRequest;
use ChatLens\Llm\LlmResponse;
use ChatLens\Llm\OutputTruncatedException;

/**
 * Any OpenAI-compatible Chat Completions endpoint:
 * DeepSeek, OpenAI, OpenRouter, Groq, Together, Ollama, LM Studio, vLLM…
 */
final class OpenAiCompatibleProvider implements LlmProviderInterface
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $name,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly ?string $apiKey = null,
        private readonly bool $jsonMode = true,
        private readonly ?float $temperature = 0.2,
        private readonly ?int $timeout = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function complete(LlmRequest $request): LlmResponse
    {
        $body = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $request->system],
                ['role' => 'user', 'content' => $request->user],
            ],
            'max_tokens' => $request->maxTokens,
        ];
        if ($this->temperature !== null) {
            $body['temperature'] = $this->temperature;
        }
        if ($this->jsonMode) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $headers = $this->apiKey ? ['Authorization' => 'Bearer ' . $this->apiKey] : [];
        $url = rtrim($this->baseUrl, '/') . '/chat/completions';

        $response = $this->http->postJson($url, $headers, $body, $this->timeout);

        if ($response['status'] !== 200) {
            throw new LlmException(sprintf(
                '%s: HTTP %d — %s',
                $this->name,
                $response['status'],
                mb_substr($response['body'], 0, 300)
            ));
        }

        $json = json_decode($response['body'], true);
        if (!is_array($json)) {
            throw new LlmException("{$this->name}: response is not JSON");
        }

        $choice = $json['choices'][0] ?? [];
        $content = trim((string) ($choice['message']['content'] ?? ''));
        $truncated = ($choice['finish_reason'] ?? '') === 'length';
        $usage = $json['usage'] ?? [];

        if ($content === '') {
            $info = sprintf('finish_reason=%s, completion_tokens=%s', $choice['finish_reason'] ?? '?', $usage['completion_tokens'] ?? '?');
            if ($truncated) {
                throw new OutputTruncatedException("{$this->name}: empty answer, output limit reached ({$info})");
            }
            throw new LlmException("{$this->name}: empty answer ({$info})");
        }

        return new LlmResponse(
            content: $content,
            truncated: $truncated,
            inputTokens: (int) ($usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            raw: $response['body'],
        );
    }
}
