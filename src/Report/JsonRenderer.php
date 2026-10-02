<?php

declare(strict_types=1);

namespace ChatLens\Report;

use ChatLens\Analysis\AnalysisResult;
use ChatLens\Analysis\Quote;
use ChatLens\Collect\ChatUser;

/**
 * Machine-readable report — for feeding into other tools, CRMs, spreadsheets.
 */
final class JsonRenderer implements RendererInterface
{
    public function __construct(private readonly string $lang = 'en')
    {
    }

    public function format(): string
    {
        return 'json';
    }

    public function render(AnalysisResult $result): string
    {
        $categories = [];
        foreach ($result->profile->categories as $key => $c) {
            $categories[] = [
                'key' => $key,
                'label' => $c->label($this->lang),
                'description' => $c->description,
                'count' => $result->counts()[$key] ?? 0,
            ];
        }

        $users = [];
        foreach ($result->classifications as $c) {
            $users[] = [
                'id' => $c->userId,
                'name' => $c->name,
                'category' => $c->category,
                'reason' => $c->reason,
                'quotes' => array_map(static fn (Quote $q) => ['text' => $q->text, 'verified' => $q->verified], $c->quotes),
                'messages_analyzed' => $c->analyzedMessages,
                'messages_total' => $c->totalMessages,
                'category_corrected' => $c->categoryCorrected,
            ];
        }

        return json_encode([
            'chat' => $result->chatName,
            'profile' => $result->profile->id,
            'title' => $result->profile->title($this->lang),
            'provider' => $result->provider,
            'model' => $result->model,
            'generated_at' => $result->generatedAt->format(DATE_ATOM),
            'meta' => $result->meta,
            'stats' => $result->stats->toArray(),
            'categories' => $categories,
            'users' => $users,
            'not_analyzed' => array_map(
                static fn (ChatUser $u) => ['id' => $u->id, 'name' => $u->name],
                $result->failed
            ),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }
}
