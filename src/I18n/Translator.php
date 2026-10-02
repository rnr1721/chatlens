<?php

declare(strict_types=1);

namespace ChatLens\I18n;

/**
 * Tiny translator for report UI strings: lang/<code>.php returns a flat key => string array.
 * Missing keys fall back to English, then to the key itself.
 */
final class Translator
{
    /** @var array<string, string> */
    private array $messages;
    /** @var array<string, string> */
    private array $fallback;

    public function __construct(private readonly string $langDir, private readonly string $locale = 'en')
    {
        $this->fallback = $this->loadFile('en');
        $this->messages = $locale === 'en' ? $this->fallback : $this->loadFile($locale);

        if ($this->messages === [] && $locale !== 'en') {
            throw new \InvalidArgumentException(sprintf(
                "Unknown report language '%s'. Available: %s",
                $locale,
                implode(', ', self::available($langDir))
            ));
        }
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /** @param array<string, string|int|float> $params replaces {name} placeholders */
    public function t(string $key, array $params = []): string
    {
        $text = $this->messages[$key] ?? $this->fallback[$key] ?? $key;

        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }

        return $text;
    }

    /** @return list<string> */
    public static function available(string $langDir): array
    {
        return array_map(
            static fn (string $f) => pathinfo($f, PATHINFO_FILENAME),
            glob($langDir . '/*.php') ?: []
        );
    }

    /** @return array<string, string> */
    private function loadFile(string $locale): array
    {
        if (!preg_match('/^[a-z]{2}(?:[-_][A-Za-z]{2})?$/', $locale)) {
            return [];
        }
        $file = "{$this->langDir}/{$locale}.php";
        if (!is_file($file)) {
            return [];
        }
        $data = require $file;

        return is_array($data) ? $data : [];
    }
}
