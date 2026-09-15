<?php

declare(strict_types=1);

namespace App\Untis;

/**
 * How a TimetableProvider fetch was served: freshly from WebUntis, or from
 * the filesystem cache, and either way when that data was actually fetched
 * and when the cache entry expires. `fetchedAt`/`expiresAt` are derived from
 * the cache item's own expiry metadata (expiresAt - cache_ttl), not stored
 * separately, so they stay correct even if cache_ttl changes between runs.
 */
final class CacheInfo
{
    public function __construct(
        public readonly \DateTimeImmutable $fetchedAt,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly bool $fromCache,
    ) {
    }

    /**
     * Minutes left before the cache entry expires, rounded up so this reads
     * "1 min left" up until the last second rather than "0 min left" for
     * most of the final minute.
     */
    public function minutesLeft(\DateTimeImmutable $now): int
    {
        return max(0, (int) ceil(($this->expiresAt->getTimestamp() - $now->getTimestamp()) / 60));
    }
}
