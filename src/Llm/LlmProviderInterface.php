<?php

declare(strict_types=1);

namespace ChatLens\Llm;

interface LlmProviderInterface
{
    /**
     * @throws LlmException                on transport / API errors
     * @throws OutputTruncatedException    when the answer was cut by max_tokens and is empty
     */
    public function complete(LlmRequest $request): LlmResponse;

    /** Provider name as configured, e.g. "deepseek". */
    public function name(): string;

    public function model(): string;
}
