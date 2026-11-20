<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Push\ChangeNotifier;
use App\Push\Subscription;
use App\Push\SubscriptionStore;
use App\Untis\ConfigLoader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Subscribing a browser to change notifications, called by
 * public/assets/push.js from the dashboard footer. Not token-gated, same
 * reasoning as ticking homework off: anyone who can load the dashboard
 * (already restricted at the network level) can ask to be told about
 * changes. Both routes 404 when `features.notifications` is off.
 *
 * Only JSON bodies are accepted: a cross-site page cannot send one without
 * a CORS preflight this app never answers, so another site open in the same
 * browser cannot quietly subscribe its own endpoint to the family timetable.
 */
final class PushController extends AbstractController
{
    public function __construct(
        private readonly ConfigLoader $config,
        private readonly SubscriptionStore $subscriptions,
        private readonly ChangeNotifier $notifier,
        private readonly Translator $translator,
    ) {
    }

    /**
     * Stores the subscription. With `test: true` (set when the user has just
     * pressed the button, not on the silent re-sync on every page load) a
     * confirmation push goes out to that browser straight away, so a
     * misconfiguration shows up now rather than on the next real change.
     */
    #[Route('/push/subscribe', name: 'push_subscribe', methods: ['POST'])]
    public function subscribe(Request $request): JsonResponse
    {
        $body = $this->jsonBody($request);
        $subscription = $body['subscription'] ?? null;
        $endpoint = (string) ($subscription['endpoint'] ?? '');
        $p256dh = (string) ($subscription['keys']['p256dh'] ?? '');
        $auth = (string) ($subscription['keys']['auth'] ?? '');

        if (!SubscriptionStore::isAllowedEndpoint($endpoint)
            || !preg_match('/^[A-Za-z0-9_-]{80,100}$/', $p256dh)
            || !preg_match('/^[A-Za-z0-9_-]{16,32}$/', $auth)) {
            return $this->json(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        $origin = (string) $request->headers->get('Origin', '');
        $subscription = new Subscription($endpoint, $p256dh, $auth, preg_match('#^https://[^/\s]+$#', $origin) ? $origin : '');
        $this->subscriptions->add($subscription);

        if (true === ($body['test'] ?? false)) {
            $sent = $this->notifier->broadcast([[
                'title' => $this->translator->trans('push.test_title'),
                'body' => $this->translator->trans('push.test_body'),
                'url' => '/',
            ]], $subscription);

            if (0 === $sent) {
                $this->subscriptions->remove($endpoint);

                return $this->json(['ok' => false], Response::HTTP_BAD_GATEWAY);
            }
        }

        return $this->json(['ok' => true]);
    }

    #[Route('/push/unsubscribe', name: 'push_unsubscribe', methods: ['POST'])]
    public function unsubscribe(Request $request): JsonResponse
    {
        $this->subscriptions->remove((string) ($this->jsonBody($request)['endpoint'] ?? ''));

        return $this->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        if (!$this->config->notificationsEnabled()) {
            throw $this->createNotFoundException();
        }

        if ('json' !== $request->getContentTypeFormat()) {
            throw new UnsupportedMediaTypeHttpException();
        }

        $parsed = json_decode($request->getContent(), true);

        return \is_array($parsed) ? $parsed : [];
    }
}
