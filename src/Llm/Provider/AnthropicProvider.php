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
 * Claude via the Anthropic Messages API.
 * With structured output enabled, the profile's categories are passed as a JSON schema enum,
 * so the answer is guaranteed to be valid JSON with known category keys.
 */
final class AnthropicProvider implements LlmProviderInterface
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $name,
        private readonly string $model,
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.anthropic.com',
        private readonly string $version = '2023-06-01',
        private readonly bool $structuredOutput = true,
        private readonly ?float $temperature = null,
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
            'max_tokens' => $request->maxTokens,
            'system' => $request->system,
            'messages' => [
                ['role' => 'user', 'content' => $request->user],
            ],
        ];
        if ($this->temperature !== null) {
            $body['temperature'] = $this->temperature;
        }
        if ($this->structuredOutput && $request->jsonSchema !== null) {
            $body['output_config'] = [
                'format' => ['type' => 'json_schema', 'schema' => $request->jsonSchema],
            ];
        }

        $response = $this->http->postJson(
            rtrim($this->baseUrl, '/') . '/v1/messages',
            ['x-api-key' => $this->apiKey, 'anthropic-version' => $this->version],
            $body,
            $this->timeout,
        );

        $json = json_decode($response['body'], true);

        if ($response['status'] !== 200) {
            $message = is_array($json) ? ($json['error']['message'] ?? null) : null;
            throw new LlmException(sprintf(
                '%s: HTTP %d — %s',
                $this->name,
                $response['status'],
                $message ?? mb_substr($response['body'], 0, 300)
            ));
        }

        if (!is_array($json)) {
            throw new LlmException("{$this->name}: response is not JSON");
        }

        $content = '';
        foreach ($json['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $content .= (string) ($block['text'] ?? '');
            }
        }
        $content = trim($content);

        $stopReason = (string) ($json['stop_reason'] ?? '');
        $truncated = in_array($stopReason, ['max_tokens', 'model_context_window_exceeded'], true);
        $usage = $json['usage'] ?? [];

        if ($stopReason === 'refusal') {
            throw new LlmException("{$this->name}: the model declined to answer this batch");
        }

        if ($content === '') {
            if ($truncated) {
                throw new OutputTruncatedException("{$this->name}: empty answer, output limit reached (stop_reason={$stopReason})");
            }
            throw new LlmException("{$this->name}: empty answer (stop_reason={$stopReason})");
        }

        return new LlmResponse(
            content: $content,
            truncated: $truncated,
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            raw: $response['body'],
        );
    }
}
