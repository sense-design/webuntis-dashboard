<?php

declare(strict_types=1);

namespace App\Twig;

use App\Push\WebPush;
use App\Untis\ConfigLoader;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * {{ push_enabled() }} and {{ push_public_key() }} for the notification
 * toggle in the dashboard footer - functions rather than one more variable
 * handed to every render() call in DashboardController. The key is only
 * read (or, the very first time, generated) when the template actually asks
 * for it.
 */
final class PushExtension extends AbstractExtension
{
    public function __construct(
        private readonly ConfigLoader $config,
        private readonly WebPush $webPush,
    ) {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('push_enabled', $this->config->notificationsEnabled(...)),
            new TwigFunction('push_public_key', $this->webPush->publicKey(...)),
        ];
    }
}
