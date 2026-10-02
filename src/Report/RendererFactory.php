<?php

declare(strict_types=1);

namespace ChatLens\Report;

use ChatLens\I18n\Translator;

final class RendererFactory
{
    public const FORMATS = ['html', 'json', 'csv'];

    public function __construct(private readonly string $templateFile, private readonly string $langDir)
    {
    }

    public function create(string $format, string $lang): RendererInterface
    {
        return match (strtolower($format)) {
            'html' => new HtmlRenderer($this->templateFile, new Translator($this->langDir, $lang)),
            'json' => new JsonRenderer($lang),
            'csv' => new CsvRenderer($lang),
            default => throw new \InvalidArgumentException(
                "Unknown report format '{$format}'. Supported: " . implode(', ', self::FORMATS)
            ),
        };
    }
}
