<?php

declare(strict_types=1);

namespace ChatLens\Llm\Provider;

use ChatLens\Llm\LlmProviderInterface;
use ChatLens\Llm\LlmRequest;
use ChatLens\Llm\LlmResponse;

/**
 * Offline stub for trying the pipeline and the report without an API key.
 *
 * It does NOT understand anything: the category is picked deterministically from a hash
 * of the user's messages, and the first words of a message are used as a quote.
 */
final class FakeProvider implements LlmProviderInterface
{
    public function __construct(private readonly string $name = 'fake', private readonly string $model = 'fake-1')
    {
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
        $input = json_decode($request->user, true, 512, JSON_THROW_ON_ERROR);
        $categories = $request->jsonSchema['properties']['users']['items']['properties']['category']['enum'] ?? ['unclear'];

        $users = [];
        foreach ($input['users'] ?? [] as $user) {
            $messages = $user['messages'] ?? [];
            $index = crc32(implode("\n", $messages)) % count($categories);
            $first = (string) ($messages[0] ?? '');
            $words = preg_split('/\s+/u', trim($first)) ?: [];

            $users[] = [
                'id' => $user['id'],
                'category' => $categories[$index],
                'reason' => sprintf('Fake provider: %d message(s) seen, category chosen by hash.', count($messages)),
                'quotes' => $first !== '' ? [implode(' ', array_slice($words, 0, 8))] : [],
            ];
        }

        $content = json_encode(['users' => $users], JSON_UNESCAPED_UNICODE);

        return new LlmResponse($content, false, (int) (strlen($request->user) / 4), (int) (strlen($content) / 4), $content);
    }
}
