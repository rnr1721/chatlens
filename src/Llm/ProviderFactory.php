<?php

declare(strict_types=1);

namespace ChatLens\Llm;

use ChatLens\Config\Config;
use ChatLens\Llm\Provider\AnthropicProvider;
use ChatLens\Llm\Provider\FakeProvider;
use ChatLens\Llm\Provider\OpenAiCompatibleProvider;
use Psr\Log\LoggerInterface;

/**
 * Creates providers from the "providers" section of the config.
 * To add a new API family: implement LlmProviderInterface and add a driver here.
 */
final class ProviderFactory
{
    public function __construct(private readonly Config $config, private readonly ?LoggerInterface $logger = null)
    {
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys((array) $this->config->get('providers', []));
    }

    public function create(string $name, ?string $modelOverride = null): LlmProviderInterface
    {
        $cfg = $this->config->get("providers.{$name}");
        if (!is_array($cfg)) {
            throw new \InvalidArgumentException(sprintf(
                "Unknown provider '%s'. Configured: %s",
                $name,
                implode(', ', $this->names())
            ));
        }

        $model = (string) ($modelOverride ?: ($cfg['model'] ?? ''));
        if ($model === '') {
            throw new \InvalidArgumentException("Provider '{$name}': model is not set");
        }

        $driver = (string) ($cfg['driver'] ?? 'openai');
        $temperature = array_key_exists('temperature', $cfg) && $cfg['temperature'] !== null
            ? (float) $cfg['temperature']
            : null;
        $timeout = isset($cfg['timeout']) ? (int) $cfg['timeout'] : null;

        return match ($driver) {
            'openai' => new OpenAiCompatibleProvider(
                http: $this->http(),
                name: $name,
                baseUrl: (string) ($cfg['base_url'] ?? throw new \InvalidArgumentException("Provider '{$name}': base_url is required")),
                model: $model,
                apiKey: $this->apiKey($name, $cfg, required: !$this->isLocal((string) $cfg['base_url'])),
                jsonMode: (bool) ($cfg['json_mode'] ?? true),
                temperature: $temperature,
                timeout: $timeout,
            ),
            'anthropic' => new AnthropicProvider(
                http: $this->http(),
                name: $name,
                model: $model,
                apiKey: (string) $this->apiKey($name, $cfg, required: true),
                baseUrl: (string) ($cfg['base_url'] ?? 'https://api.anthropic.com'),
                version: (string) ($cfg['version'] ?? '2023-06-01'),
                structuredOutput: (bool) ($cfg['structured_output'] ?? true),
                temperature: $temperature,
                timeout: $timeout,
            ),
            'fake' => new FakeProvider($name, $model),
            default => throw new \InvalidArgumentException("Provider '{$name}': unknown driver '{$driver}' (openai | anthropic | fake)"),
        };
    }

    private function http(): HttpClient
    {
        return new HttpClient(
            timeout: (int) $this->config->get('http.timeout', 300),
            connectTimeout: (int) $this->config->get('http.connect_timeout', 20),
            retries: (int) $this->config->get('http.retries', 3),
            logger: $this->logger,
        );
    }

    /** @param array<string, mixed> $cfg */
    private function apiKey(string $name, array $cfg, bool $required): ?string
    {
        $key = $cfg['api_key'] ?? null;
        if (is_string($key) && $key !== '') {
            return $key;
        }
        if ($required) {
            throw new \InvalidArgumentException(
                "Provider '{$name}': API key is not set. Put it into .env (see .env.example)."
            );
        }

        return null;
    }

    private function isLocal(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return in_array($host, ['localhost', '127.0.0.1', '::1', 'host.docker.internal'], true)
            || str_ends_with($host, '.local');
    }
}
