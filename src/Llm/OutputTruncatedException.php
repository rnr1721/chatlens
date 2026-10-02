<?php

declare(strict_types=1);

namespace ChatLens\Llm;

/**
 * The model hit max_tokens before producing anything usable.
 * The classifier reacts by splitting the batch into smaller ones.
 */
final class OutputTruncatedException extends LlmException
{
}
