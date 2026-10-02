<?php

declare(strict_types=1);

use function ChatLens\Support\env;

/*
 * ChatLens configuration.
 *
 * Relative paths are resolved from the project root.
 * Secrets (API keys) come from the environment / .env — never put them here.
 * Every value below can be overridden per run with CLI options
 * (see `./chatlens analyze --help`).
 */
return [

    // Time zone for report dates. null = PHP's default (php.ini / TZ).
    'timezone' => env('CHATLENS_TIMEZONE'),

    'paths' => [
        'input'    => 'data/input/result.json',  // Telegram export (single chat, JSON)
        'output'   => 'data/output',             // reports
        'cache'    => 'data/cache',              // per-user LLM results + raw responses
        'profiles' => 'profiles',                // analysis profiles (*.yaml)
        'lang'     => 'lang',                    // report UI translations
        'template' => 'templates/report.html.php',
    ],

    'defaults' => [
        'provider'        => env('CHATLENS_PROVIDER', 'deepseek'),
        'profile'         => 'ad-loyalty',
        'lang'            => env('CHATLENS_LANG', 'en'),  // report UI language: en | ru | uk
        'reason_language' => 'auto',                       // language of the model's explanations; "auto" = same as messages
        'formats'         => ['html'],                     // html | json | csv
    ],

    // How messages are gathered per user before they are sent to the model.
    'collect' => [
        'max_messages_per_user' => 30,
        'max_message_length'    => 800,   // longer messages are truncated with "…"
        'min_messages'          => 1,     // users with fewer text messages are skipped
        'take'                  => 'last', // last | first — which messages to keep when a user has more than the limit
    ],

    'analysis' => [
        'chunk_size'       => 8,     // users per request
        'retry_chunk_size' => 3,     // users per request when retrying the ones the model skipped
        'max_tokens'       => 8192,
        'delay_ms'         => 1000,  // pause between real API calls (cache hits are not delayed)
        'quotes'           => 'mark', // mark | drop — what to do with quotes not found in the user's messages
    ],

    /*
     * LLM providers. "driver" decides which adapter is used:
     *   openai    — any OpenAI-compatible Chat Completions API (DeepSeek, OpenAI, OpenRouter, Ollama, LM Studio…)
     *   anthropic — Claude Messages API
     *   fake      — offline deterministic stub for trying the pipeline without a key
     *
     * temperature: null means "don't send it" (some newer models deprecate the parameter).
     */
    'providers' => [

        'deepseek' => [
            'driver'      => 'openai',
            'base_url'    => 'https://api.deepseek.com',
            'model'       => env('DEEPSEEK_MODEL', 'deepseek-flash'),
            'api_key'     => env('DEEPSEEK_API_KEY'),
            'json_mode'   => true,
            'temperature' => 0.2,
        ],

        'openai' => [
            'driver'      => 'openai',
            'base_url'    => 'https://api.openai.com/v1',
            'model'       => env('OPENAI_MODEL', 'gpt-4.1-mini'),
            'api_key'     => env('OPENAI_API_KEY'),
            'json_mode'   => true,
            'temperature' => 0.2,
        ],

        'claude' => [
            'driver'            => 'anthropic',
            'base_url'          => 'https://api.anthropic.com',
            'model'             => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
            'api_key'           => env('ANTHROPIC_API_KEY'),
            'version'           => '2023-06-01',
            'structured_output' => true,  // JSON schema with the profile's categories as an enum
            'temperature'       => null,
        ],

        'ollama' => [
            'driver'      => 'openai',
            'base_url'    => env('OLLAMA_URL', 'http://localhost:11434/v1'),
            'model'       => env('OLLAMA_MODEL', 'qwen2.5:14b'),
            'api_key'     => null,
            'json_mode'   => true,
            'temperature' => 0.2,
            'timeout'     => 600,
        ],

        'fake' => [
            'driver' => 'fake',
            'model'  => 'fake-1',
        ],
    ],

    'http' => [
        'timeout'         => 300,
        'connect_timeout' => 20,
        'retries'         => 3,   // on network errors, 429 and 5xx (with exponential backoff, honours Retry-After)
    ],
];
