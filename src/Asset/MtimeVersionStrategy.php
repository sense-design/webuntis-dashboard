<?php

declare(strict_types=1);

namespace App\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;

/**
 * Appends each asset file's own mtime as a `?v=` cache-buster, so
 * `nginx.conf.example`'s 7-day `expires` on /assets/ can stay aggressive
 * while a deploy still invalidates the browser cache immediately - no
 * manual version bump, no build step, no asset manifest.
 */
final class MtimeVersionStrategy implements VersionStrategyInterface
{
    public function __construct(private readonly string $publicDir)
    {
    }

    public function getVersion(string $path): string
    {
        $file = $this->publicDir.'/'.ltrim($path, '/');
        $mtime = is_readable($file) ? filemtime($file) : false;

        return false !== $mtime ? (string) $mtime : '';
    }

    public function applyVersion(string $path): string
    {
        $version = $this->getVersion($path);

        return '' === $version ? $path : sprintf('%s?v=%s', $path, $version);
    }
}
