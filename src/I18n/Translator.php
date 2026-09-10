<?php

declare(strict_types=1);

namespace App\I18n;

use App\Untis\ConfigLoader;
use Symfony\Component\Yaml\Yaml;

/**
 * Tiny message catalogue. The app has a handful of user-facing strings in two
 * languages, so this stays a pair of YAML files (translations/<locale>.yaml)
 * rather than pulling in symfony/translation and its cache layer.
 *
 * The language is app-wide: it comes from `locale` in untis.yaml and never
 * changes within a request. Keys are dot paths into the YAML tree
 * ("status.one"); placeholders are written as {name} and filled from the params
 * passed to trans().
 */
final class Translator
{
    /** Locales that have a catalogue in translations/. The first is the fallback. */
    public const SUPPORTED = ['en', 'de'];

    private ?string $locale = null;

    /** @var array<string, mixed>|null Parsed catalogue for the active locale. */
    private ?array $catalogue = null;

    public function __construct(
        private readonly string $translationsDir,
        private readonly ConfigLoader $config,
    ) {
    }

    public function locale(): string
    {
        if (null === $this->locale) {
            $configured = $this->config->locale();
            $this->locale = \in_array($configured, self::SUPPORTED, true)
                ? $configured
                : self::SUPPORTED[0];
        }

        return $this->locale;
    }

    /**
     * @param array<string, string|int> $params replaced as {name} in the message
     */
    public function trans(string $key, array $params = []): string
    {
        $message = $this->lookup($key);
        if (!\is_string($message)) {
            return $key;
        }

        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['{'.$name.'}'] = (string) $value;
        }

        return strtr($message, $replacements);
    }

    public function weekday(int $isoDay): string
    {
        $name = $this->lookup('weekdays.'.$isoDay);

        return \is_string($name) ? $name : (string) $isoDay;
    }

    /** Abbreviated weekday name, falling back to the full name. */
    public function weekdayShort(int $isoDay): string
    {
        $name = $this->lookup('weekdays_short.'.$isoDay);

        return \is_string($name) ? $name : $this->weekday($isoDay);
    }

    public function month(int $month): string
    {
        $name = $this->lookup('months.'.$month);

        return \is_string($name) ? $name : (string) $month;
    }

    /** Walk a dot path into the catalogue. */
    private function lookup(string $key): mixed
    {
        $node = $this->catalogue();
        foreach (explode('.', $key) as $segment) {
            if (!\is_array($node) || !\array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }

        return $node;
    }

    /** @return array<string, mixed> */
    private function catalogue(): array
    {
        return $this->catalogue ??= Yaml::parseFile(sprintf(
            '%s/%s.yaml',
            $this->translationsDir,
            $this->locale(),
        ));
    }
}
