<?php

declare(strict_types=1);

namespace ChatLens\Telegram;

/**
 * Helpers for a single message object from a Telegram export.
 */
final class TelegramMessage
{
    /** Plain text of a message: "text" may be a string or a mixed list of strings and entity objects. */
    public static function text(array $message): string
    {
        if (isset($message['text_entities']) && is_array($message['text_entities'])) {
            $s = '';
            foreach ($message['text_entities'] as $entity) {
                $s .= is_array($entity) ? (string) ($entity['text'] ?? '') : '';
            }
            if (trim($s) !== '') {
                return trim($s);
            }
        }

        $text = $message['text'] ?? '';
        if (is_string($text)) {
            return trim($text);
        }

        if (is_array($text)) {
            $s = '';
            foreach ($text as $part) {
                $s .= is_string($part) ? $part : (string) ($part['text'] ?? '');
            }
            return trim($s);
        }

        return '';
    }

    /** A regular message written by someone (not a service event like "joined the group"). */
    public static function isAuthored(array $message): bool
    {
        return ($message['type'] ?? '') === 'message'
            && isset($message['from_id'])
            && (string) $message['from_id'] !== '';
    }

    public static function authorId(array $message): string
    {
        return UserId::normalize($message['from_id'] ?? '');
    }

    public static function authorName(array $message): string
    {
        $name = $message['from'] ?? null;

        return is_string($name) && $name !== '' ? $name : self::authorId($message);
    }
}
