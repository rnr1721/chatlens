<?php

declare(strict_types=1);

namespace ChatLens\Collect;

/**
 * A chat participant and the messages selected for analysis.
 */
final class ChatUser
{
    /**
     * @param list<string> $messages     messages that will be sent to the model
     * @param int          $totalMessages all text messages of this user in the chat
     */
    public function __construct(
        public readonly string $id,
        public string $name,
        public array $messages = [],
        public int $totalMessages = 0,
    ) {
    }
}
