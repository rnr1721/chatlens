<?php

declare(strict_types=1);

namespace ChatLens\Report;

use ChatLens\Analysis\AnalysisResult;

interface RendererInterface
{
    /** File extension without a dot: "html", "json", "csv". */
    public function format(): string;

    public function render(AnalysisResult $result): string;
}
