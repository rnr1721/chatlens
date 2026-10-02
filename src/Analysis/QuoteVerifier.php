<?php

declare(strict_types=1);

namespace ChatLens\Analysis;

/**
 * Checks that a quote returned by the model really occurs in the user's messages.
 *
 * Matching is tolerant to case, whitespace, quotation marks and edge punctuation.
 * Quotes shortened with "…" or "..." match when every fragment occurs, in order,
 * within a single message.
 */
final class QuoteVerifier
{
    /** @param list<string> $messages */
    public function verify(string $quote, array $messages): bool
    {
        $fragments = array_values(array_filter(
            array_map(
                fn (string $f) => $this->normalize($f),
                preg_split('/\s*(?:\.\.\.|…)\s*/u', $quote) ?: []
            ),
            static fn (string $f) => $f !== ''
        ));

        if ($fragments === []) {
            return false;
        }

        foreach ($messages as $message) {
            $haystack = $this->normalize($message);
            $offset = 0;
            $found = true;

            foreach ($fragments as $fragment) {
                $pos = mb_strpos($haystack, $fragment, $offset);
                if ($pos === false) {
                    $found = false;
                    break;
                }
                $offset = $pos + mb_strlen($fragment);
            }

            if ($found) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $s = str_replace(['«', '»', '“', '”', '„', '"', '’', '‘', '`'], '', $s);
        $s = str_replace(['ё'], ['е'], $s);
        $s = (string) preg_replace('/\s+/u', ' ', $s);

        return trim($s, " \t\n\r\0\x0B.,!?;:'—–-()");
    }
}
