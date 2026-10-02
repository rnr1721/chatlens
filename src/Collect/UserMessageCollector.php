<?php

declare(strict_types=1);

namespace ChatLens\Collect;

use ChatLens\Filter\UserFilterInterface;
use ChatLens\Telegram\TelegramMessage;

/**
 * Groups a stream of Telegram messages by author, keeping at most N messages per user.
 * Memory use is bounded by (users × N), not by the size of the chat.
 */
final class UserMessageCollector
{
    public const TAKE_LAST = 'last';
    public const TAKE_FIRST = 'first';

    public function __construct(
        private readonly int $maxMessagesPerUser = 30,
        private readonly int $maxMessageLength = 800,
        private readonly int $minMessages = 1,
        private readonly string $take = self::TAKE_LAST,
    ) {
        if (!in_array($take, [self::TAKE_LAST, self::TAKE_FIRST], true)) {
            throw new \InvalidArgumentException("collect.take must be 'last' or 'first', got '{$take}'");
        }
        if ($maxMessagesPerUser < 1) {
            throw new \InvalidArgumentException('max_messages_per_user must be >= 1');
        }
    }

    /**
     * @param iterable<array<string, mixed>> $messages
     * @param callable(int $scanned, int $users): void|null $progress called every 10 000 messages
     */
    public function collect(iterable $messages, UserFilterInterface $filter, ?callable $progress = null): CollectionResult
    {
        /** @var array<string, ChatUser> $users */
        $users = [];
        $scanned = 0;
        $matched = 0;

        foreach ($messages as $message) {
            $scanned++;
            if ($progress !== null && $scanned % 10000 === 0) {
                $progress($scanned, count($users));
            }

            if (!TelegramMessage::isAuthored($message)) {
                continue;
            }

            $id = TelegramMessage::authorId($message);
            if ($id === '' || !$filter->accepts($id)) {
                continue;
            }

            $text = TelegramMessage::text($message);
            if ($text === '') {
                continue;
            }

            $matched++;
            $user = $users[$id] ??= new ChatUser($id, TelegramMessage::authorName($message));
            $user->name = TelegramMessage::authorName($message); // keep the latest display name
            $user->totalMessages++;

            if ($this->take === self::TAKE_FIRST && count($user->messages) >= $this->maxMessagesPerUser) {
                continue;
            }

            $user->messages[] = $this->truncate($text);

            if (count($user->messages) > $this->maxMessagesPerUser) {
                array_shift($user->messages); // TAKE_LAST: sliding window over the newest messages
            }
        }

        $kept = array_values(array_filter(
            $users,
            fn (ChatUser $u) => $u->totalMessages >= $this->minMessages
        ));

        return new CollectionResult($kept, $scanned, $matched, count($users) - count($kept));
    }

    private function truncate(string $text): string
    {
        if (mb_strlen($text) <= $this->maxMessageLength) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $this->maxMessageLength)) . '…';
    }
}
