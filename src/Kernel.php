<?php

declare(strict_types=1);

namespace ChatLens;

use ChatLens\Config\Config;
use Symfony\Component\Dotenv\Dotenv;

/**
 * Bootstraps the application: loads .env and the config file, resolves paths.
 */
final class Kernel
{
    private readonly Config $config;

    public function __construct(private readonly string $rootDir, ?string $configFile = null)
    {
        $envFile = $rootDir . '/.env';
        if (is_file($envFile)) {
            (new Dotenv())->usePutenv()->load($envFile);
        }

        $configFile ??= $rootDir . '/config/config.php';
        if (!is_file($configFile)) {
            throw new \RuntimeException("Config file not found: {$configFile}");
        }

        $this->config = new Config(require $configFile);

        $timezone = $this->config->get('timezone');
        if (is_string($timezone) && $timezone !== '') {
            date_default_timezone_set($timezone);
        }
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function rootDir(): string
    {
        return $this->rootDir;
    }

    /** Resolves a path relative to the project root (absolute paths are returned as is). */
    public function path(string $path): string
    {
        if ($path === '' || $path[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]~', $path)) {
            return $path;
        }

        return $this->rootDir . '/' . $path;
    }

    /** Shortcut: resolves a configured path, e.g. configPath('paths.cache'). */
    public function configPath(string $key): string
    {
        return $this->path((string) $this->config->get($key, ''));
    }
}
