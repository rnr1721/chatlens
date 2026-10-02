<?php

declare(strict_types=1);

namespace ChatLens\Report;

use ChatLens\Analysis\AnalysisResult;
use ChatLens\I18n\Translator;

/**
 * Renders a self-contained HTML report from a plain PHP template.
 * The template receives: $result, $t (translator), $lang, $e (escaper), $groups, $counts, $total.
 */
final class HtmlRenderer implements RendererInterface
{
    public function __construct(private readonly string $templateFile, private readonly Translator $translator)
    {
        if (!is_file($templateFile)) {
            throw new \InvalidArgumentException("Report template not found: {$templateFile}");
        }
    }

    public function format(): string
    {
        return 'html';
    }

    public function render(AnalysisResult $result): string
    {
        $vars = [
            'result' => $result,
            't' => $this->translator->t(...),
            'lang' => $this->translator->locale(),
            'e' => static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'groups' => $result->byCategory(),
            'counts' => $result->counts(),
            'total' => count($result->classifications),
        ];

        return (static function (string $__template, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            ob_start();
            try {
                require $__template;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }

            return (string) ob_get_clean();
        })($this->templateFile, $vars);
    }
}
