<?php

declare(strict_types=1);

namespace App\Push;

/**
 * One browser's push subscription, as handed over by PushManager.subscribe().
 * `origin` is the dashboard's own origin as that browser saw it (the Origin
 * header of the subscribe request) - used as the VAPID `sub` contact claim,
 * since behind a reverse proxy the app itself may not know its public URL.
 */
final class Subscription
{
    public function __construct(
        public readonly string $endpoint,
        public readonly string $p256dh,
        public readonly string $auth,
        public readonly string $origin = '',
    ) {
    }
}
