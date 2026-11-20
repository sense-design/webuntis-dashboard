<?php

declare(strict_types=1);

namespace App\Push;

/**
 * The browsers that asked for change notifications, in
 * var/push-subscriptions.json - runtime state like var/settings.yaml, never
 * untis.yaml. Keyed by endpoint, so subscribing the same browser twice (the
 * page re-sends its subscription on every load, see assets/push.js) is a
 * no-op rather than a duplicate.
 */
final class SubscriptionStore
{
    /**
     * Push services an endpoint may point at. The notifier POSTs to whatever
     * endpoint is stored here, so accepting any URL would let anyone who can
     * reach /push/subscribe make this server send requests into the local
     * network. Every browser's own push service is covered.
     */
    private const ALLOWED_HOST_SUFFIXES = [
        '.push.apple.com',             // Safari, every iOS browser
        'fcm.googleapis.com',          // Chrome, Edge on Android, most Chromium browsers
        'android.googleapis.com',
        '.push.services.mozilla.com',  // Firefox
        '.notify.windows.com',         // Edge on Windows
    ];

    public function __construct(private readonly string $file)
    {
    }

    /** @return list<Subscription> */
    public function all(): array
    {
        return array_values(array_map(
            static fn (array $row): Subscription => new Subscription($row['endpoint'], $row['p256dh'], $row['auth'], $row['origin'] ?? ''),
            $this->read(),
        ));
    }

    /** False when the subscription is new; true when this exact one was already stored. */
    public function add(Subscription $subscription): bool
    {
        $rows = $this->read();
        $row = [
            'endpoint' => $subscription->endpoint,
            'p256dh' => $subscription->p256dh,
            'auth' => $subscription->auth,
            'origin' => $subscription->origin,
        ];

        if (($rows[$subscription->endpoint] ?? null) === $row) {
            return true;
        }

        $rows[$subscription->endpoint] = $row;
        $this->write($rows);

        return false;
    }

    public function remove(string $endpoint): void
    {
        $rows = $this->read();
        if (isset($rows[$endpoint])) {
            unset($rows[$endpoint]);
            $this->write($rows);
        }
    }

    public static function isAllowedEndpoint(string $endpoint): bool
    {
        $url = parse_url($endpoint);
        if (false === $url || 'https' !== ($url['scheme'] ?? '') || !isset($url['host']) || isset($url['user']) || isset($url['port'])) {
            return false;
        }

        $host = strtolower($url['host']);
        foreach (self::ALLOWED_HOST_SUFFIXES as $suffix) {
            if ($host === ltrim($suffix, '.') || ('.' === $suffix[0] && str_ends_with($host, $suffix))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, array{endpoint: string, p256dh: string, auth: string, origin?: string}> */
    private function read(): array
    {
        if (!is_readable($this->file)) {
            return [];
        }

        $parsed = json_decode((string) file_get_contents($this->file), true);

        return \is_array($parsed) ? $parsed : [];
    }

    /** @param array<string, array<string, string>> $rows */
    private function write(array $rows): void
    {
        $dir = \dirname($this->file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Could not create %s.', $dir));
        }

        $json = json_encode($rows, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        if (false === file_put_contents($this->file, $json, \LOCK_EX)) {
            throw new \RuntimeException(\sprintf('Could not write %s.', $this->file));
        }
    }
}
