<?php

declare(strict_types=1);

namespace ChatLens\Telegram;

/**
 * Telegram exports identify authors as "user123…" or "channel123…" (anonymous admins, linked channels).
 * The prefix matters: stripping it would merge a user and a channel that share digits.
 * A bare number (as people usually paste into id lists) is treated as a user id.
 */
final class UserId
{
    public static function normalize(string|int|null $raw): string
    {
        $id = strtolower(trim((string) $raw));

        if ($id === '') {
            return '';
        }

        if (ctype_digit($id)) {
            return 'user' . $id;
        }

        return $id;
    }
}
