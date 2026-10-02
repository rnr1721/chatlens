<?php

declare(strict_types=1);

namespace ChatLens\Analysis;

/**
 * Parses the model's answer, recovering from typical defects:
 * markdown fences, chatter around the JSON, trailing commas, an answer cut off by max_tokens.
 *
 * Strategies, in order: direct → close_brackets → salvage_users (extract every complete
 * user object from a broken array).
 */
final class JsonRepair
{
    public const DIRECT = 'direct';
    public const CLOSE_BRACKETS = 'close_brackets';
    public const SALVAGE = 'salvage_users';

    /**
     * @return array{users: list<array<string, mixed>>, strategy: string}|null
     */
    public function parseUsers(string $raw): ?array
    {
        $s = trim($raw);
        $s = (string) preg_replace('/^```(?:json)?\s*/i', '', $s);
        $s = (string) preg_replace('/\s*```\s*$/', '', $s);
        $s = trim($s);

        $start = strpos($s, '{');
        if ($start === false) {
            return null;
        }
        $end = strrpos($s, '}');
        $full = substr($s, $start);
        $s = ($end !== false && $end > $start) ? substr($s, $start, $end - $start + 1) : $full;

        $s = $this->removeTrailingCommas($s);

        if (($users = $this->usersFrom(json_decode($s, true))) !== null) {
            return ['users' => $users, 'strategy' => self::DIRECT];
        }

        // A truncated answer: close what was left open. Try both the full tail and the text up
        // to the last "}" and keep whichever yields more users.
        $best = null;
        $closed = $this->closeBrackets($s);
        foreach ([$this->closeBrackets($this->removeTrailingCommas($full)), $closed] as $candidate) {
            $users = $this->usersFrom(json_decode($candidate, true));
            if ($users !== null && ($best === null || count($users) > count($best))) {
                $best = $users;
            }
        }
        if ($best !== null) {
            return ['users' => $best, 'strategy' => self::CLOSE_BRACKETS];
        }

        $salvaged = $this->salvageUserObjects($closed);
        if ($salvaged !== []) {
            return ['users' => $salvaged, 'strategy' => self::SALVAGE];
        }

        return null;
    }

    /** @return list<array<string, mixed>>|null */
    private function usersFrom(mixed $json): ?array
    {
        if (!is_array($json)) {
            return null;
        }

        $list = isset($json['users']) && is_array($json['users'])
            ? $json['users']
            : $json; // the model returned a bare list of users

        $users = array_values(array_filter($list, static fn ($u) => is_array($u) && isset($u['id'])));

        return $users === [] ? null : $users;
    }

    /** Removes commas before } or ], ignoring the contents of strings. */
    private function removeTrailingCommas(string $s): string
    {
        $out = '';
        $len = strlen($s);
        $inStr = false;
        $esc = false;

        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($inStr) {
                $out .= $c;
                if ($esc) {
                    $esc = false;
                } elseif ($c === '\\') {
                    $esc = true;
                } elseif ($c === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($c === '"') {
                $inStr = true;
                $out .= $c;
                continue;
            }
            if ($c === ',') {
                $j = $i + 1;
                while ($j < $len && ctype_space($s[$j])) {
                    $j++;
                }
                if ($j < $len && ($s[$j] === '}' || $s[$j] === ']')) {
                    continue;
                }
            }
            $out .= $c;
        }

        return $out;
    }

    /** Closes an unterminated string and unclosed { [ in the right order. */
    private function closeBrackets(string $s): string
    {
        $stack = [];
        $inStr = false;
        $esc = false;
        $len = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($inStr) {
                if ($esc) {
                    $esc = false;
                } elseif ($c === '\\') {
                    $esc = true;
                } elseif ($c === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($c === '"') {
                $inStr = true;
            } elseif ($c === '{' || $c === '[') {
                $stack[] = $c;
            } elseif (($c === '}' || $c === ']') && $stack) {
                array_pop($stack);
            }
        }

        if ($inStr) {
            if ($esc) {
                $s = substr($s, 0, -1);
            }
            $s .= '"';
        }

        $s = rtrim($s, " \t\r\n,:");
        while ($stack) {
            $s .= array_pop($stack) === '{' ? '}' : ']';
        }

        return $s;
    }

    /** @return list<array<string, mixed>> */
    private function salvageUserObjects(string $s): array
    {
        if (!preg_match('/"users"\s*:\s*\[/', $s, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $rest = substr($s, $m[0][1] + strlen($m[0][0]));
        $len = strlen($rest);
        $depth = 0;
        $inStr = false;
        $esc = false;
        $objStart = null;
        $users = [];

        for ($i = 0; $i < $len; $i++) {
            $c = $rest[$i];
            if ($inStr) {
                if ($esc) {
                    $esc = false;
                } elseif ($c === '\\') {
                    $esc = true;
                } elseif ($c === '"') {
                    $inStr = false;
                }
                continue;
            }
            if ($c === '"') {
                $inStr = true;
            } elseif ($c === '{') {
                if ($depth === 0) {
                    $objStart = $i;
                }
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0 && $objStart !== null) {
                    $candidate = $this->removeTrailingCommas(substr($rest, $objStart, $i - $objStart + 1));
                    $user = json_decode($candidate, true);
                    if (is_array($user) && isset($user['id'])) {
                        $users[] = $user;
                    }
                    $objStart = null;
                }
            } elseif ($c === ']' && $depth === 0) {
                break;
            }
        }

        return $users;
    }
}
