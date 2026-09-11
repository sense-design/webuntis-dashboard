<?php

declare(strict_types=1);

namespace App\Untis;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads config/untis.yaml, which holds the school, the accounts and the
 * students that should appear on the dashboard.
 *
 * A handful of display/behaviour settings can also be changed at runtime
 * from `/admin`, without touching the hand-written, credential-holding
 * untis.yaml. Those overrides live in a separate file (var/settings.yaml)
 * and take precedence over the matching untis.yaml key when present.
 */
final class ConfigLoader
{
    /** @var array<string, mixed>|null */
    private ?array $config = null;

    /** @var array<string, mixed>|null */
    private ?array $settings = null;

    public function __construct(
        private readonly string $configFile,
        private readonly string $settingsFile,
    ) {
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
        return (int) ($this->settings()['cache_ttl'] ?? $this->load()['cache_ttl'] ?? 300);
    }

    public function refreshSeconds(): int
    {
        return (int) ($this->settings()['refresh_seconds'] ?? $this->load()['refresh_seconds'] ?? 600);
    }

    /** App-wide UI language; English when unset. */
    public function locale(): string
    {
        return (string) ($this->settings()['locale'] ?? $this->load()['locale'] ?? 'en');
    }

    /**
     * `system` (follow the browser's own light/dark setting - the default),
     * `light` or `dark` to pin it regardless. An unrecognised value falls
     * back to `system` rather than being trusted as-is.
     */
    public function theme(): string
    {
        $value = (string) ($this->settings()['theme'] ?? $this->load()['theme'] ?? 'system');

        return \in_array($value, ['system', 'light', 'dark'], true) ? $value : 'system';
    }

    /**
     * Every optional feature is on by default; `features:` in untis.yaml (or
     * an admin-saved override) only needs to list the ones to switch off.
     */
    public function homeworkEnabled(): bool
    {
        return (bool) ($this->settings()['features']['homework'] ?? $this->load()['features']['homework'] ?? true);
    }

    public function examsEnabled(): bool
    {
        return (bool) ($this->settings()['features']['exams'] ?? $this->load()['features']['exams'] ?? true);
    }

    public function freePeriodsEnabled(): bool
    {
        return (bool) ($this->settings()['features']['free_periods'] ?? $this->load()['features']['free_periods'] ?? true);
    }

    /**
     * The effective `hide_subjects` list for one student, keyed by name: an
     * admin-saved override when there is one (even an empty list, meaning
     * "hide nothing"), otherwise whatever untis.yaml has for that student.
     * Subject names carry stray double spaces sometimes, so callers compare
     * against this loosely rather than expecting an exact match.
     *
     * @return list<string>
     */
    public function hiddenSubjects(string $studentName): array
    {
        $overrides = $this->settings()['hide_subjects'] ?? null;
        if (\is_array($overrides) && \array_key_exists($studentName, $overrides)) {
            return array_values((array) $overrides[$studentName]);
        }

        foreach ($this->load()['students'] as $student) {
            if (($student['name'] ?? null) === $studentName) {
                return array_values($student['hide_subjects'] ?? []);
            }
        }

        return [];
    }

    /**
     * The current admin-editable settings, as they would be shown pre-filled
     * on the `/admin` form: saved overrides where they exist, the matching
     * untis.yaml value or built-in default otherwise.
     *
     * @return array{locale: string, theme: string, cache_ttl: int, refresh_seconds: int, features: array{homework: bool, exams: bool, free_periods: bool}}
     */
    public function currentSettings(): array
    {
        return [
            'locale' => $this->locale(),
            'theme' => $this->theme(),
            'cache_ttl' => $this->cacheTtl(),
            'refresh_seconds' => $this->refreshSeconds(),
            'features' => [
                'homework' => $this->homeworkEnabled(),
                'exams' => $this->examsEnabled(),
                'free_periods' => $this->freePeriodsEnabled(),
            ],
        ];
    }

    /**
     * Persist admin-editable settings to var/settings.yaml, replacing
     * whatever was there. config/untis.yaml itself is never written to, so
     * its comments and the credentials next to these keys are never at risk
     * from the admin UI.
     *
     * @param array<string, mixed> $values
     */
    public function saveSettings(array $values): void
    {
        $dir = \dirname($this->settingsFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new UntisException(sprintf('Could not create %s.', $dir));
        }

        $yaml = "# Written by the admin UI at /admin. Delete this file to fall back\n"
            ."# to config/untis.yaml and the built-in defaults.\n"
            .Yaml::dump($values, 4);

        if (false === file_put_contents($this->settingsFile, $yaml, \LOCK_EX)) {
            throw new UntisException(sprintf('Could not write %s.', $this->settingsFile));
        }

        $this->settings = $values;
    }

    /**
     * Admin-saved overrides from var/settings.yaml. Missing or unreadable is
     * the normal state until something has been saved from `/admin`.
     *
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        if (null !== $this->settings) {
            return $this->settings;
        }

        if (!is_readable($this->settingsFile)) {
            return $this->settings = [];
        }

        $parsed = Yaml::parseFile($this->settingsFile);

        return $this->settings = \is_array($parsed) ? $parsed : [];
    }
}
