<?php

declare(strict_types=1);

namespace ChatLens\Analysis;

final class Quote
{
    public function __construct(public readonly string $text, public readonly bool $verified)
    {
    }
}
