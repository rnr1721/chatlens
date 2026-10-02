<?php

declare(strict_types=1);

namespace ChatLens\Analysis;

final class RunStats
{
    public int $requests = 0;
    public int $cacheHits = 0;
    public int $inputTokens = 0;
    public int $outputTokens = 0;
    public int $splits = 0;
    public int $retried = 0;
    public int $unverifiedQuotes = 0;
    public int $correctedCategories = 0;
    /** @var array<string, int> parse strategy => count */
    public array $parseStrategies = [];

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
