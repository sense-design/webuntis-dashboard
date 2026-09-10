<?php

declare(strict_types=1);

namespace App\Untis;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads config/untis.yaml, which holds the school, the accounts and the
 * students that should appear on the dashboard.
 */
final class ConfigLoader
{
    /** @var array<string, mixed>|null */
    private ?array $config = null;

    public function __construct(private readonly string $configFile)
    {
    }

    /** @return array<string, mixed> */
    public function load(): array
    {
        if (null !== $this->config) {
            return $this->config;
        }

        if (!is_readable($this->configFile)) {
            throw new UntisException(sprintf(
                'Config file %s is missing. Copy untis.yaml.dist and fill it in.',
                $this->configFile,
            ));
        }

        $parsed = Yaml::parseFile($this->configFile);
        if (!\is_array($parsed)) {
            throw new UntisException('Config file is empty or not valid YAML.');
        }

        foreach (['server', 'school', 'accounts', 'students'] as $key) {
            if (!isset($parsed[$key])) {
                throw new UntisException(sprintf('Config key "%s" is missing.', $key));
            }
        }

        return $this->config = $parsed;
    }

    /** @return array<string, array<string, mixed>> */
    public function accounts(): array
    {
        $accounts = [];
        foreach ($this->load()['accounts'] as $account) {
            $accounts[$account['id']] = $account;
        }

        return $accounts;
    }

    /** @return list<array<string, mixed>> */
    public function students(): array
    {
        return array_values($this->load()['students']);
    }

    public function timezone(): \DateTimeZone
    {
        return new \DateTimeZone($this->load()['timezone'] ?? 'Europe/Berlin');
    }

    public function cacheTtl(): int
    {
        return (int) ($this->load()['cache_ttl'] ?? 300);
    }

    public function refreshSeconds(): int
    {
        return (int) ($this->load()['refresh_seconds'] ?? 600);
    }

    /** App-wide UI language; English when unset. */
    public function locale(): string
    {
        return (string) ($this->load()['locale'] ?? 'en');
    }
}
