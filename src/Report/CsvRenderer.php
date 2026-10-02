<?php

declare(strict_types=1);

namespace ChatLens\Report;

use ChatLens\Analysis\AnalysisResult;
use ChatLens\Analysis\Quote;

/**
 * Flat CSV (UTF-8 with BOM, so Excel opens Cyrillic correctly).
 */
final class CsvRenderer implements RendererInterface
{
    public function __construct(private readonly string $lang = 'en', private readonly string $delimiter = ',')
    {
    }

    public function format(): string
    {
        return 'csv';
    }

    public function render(AnalysisResult $result): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");

        fputcsv($fh, ['id', 'name', 'category', 'category_label', 'reason', 'quotes', 'messages_analyzed', 'messages_total'], $this->delimiter, '"', '');

        foreach ($result->classifications as $c) {
            fputcsv($fh, [
                $c->userId,
                $c->name,
                $c->category,
                $result->profile->category($c->category)?->label($this->lang) ?? $c->category,
                $c->reason,
                implode(' | ', array_map(static fn (Quote $q) => ($q->verified ? '' : '[?] ') . $q->text, $c->quotes)),
                $c->analyzedMessages,
                $c->totalMessages,
            ], $this->delimiter, '"', '');
        }

        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }
}
