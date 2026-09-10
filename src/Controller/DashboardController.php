<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Untis\ConfigLoader;
use App\Untis\Lesson;
use App\Untis\TimetableProvider;
use App\Untis\UntisException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    /** Accent colours handed to the students in config order. */
    private const ACCENTS = ['#0F6E5C', '#6B3FA0', '#1D4F91', '#8A5000'];

    public function __construct(
        private readonly TimetableProvider $provider,
        private readonly ConfigLoader $config,
        private readonly Translator $translator,
    ) {
    }

    #[Route('/', name: 'dashboard', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        $timezone = $this->config->timezone();
        $day = new \DateTimeImmutable('today', $timezone);

        $requested = (string) $request->query->get('day', '');
        if ('tomorrow' === $requested) {
            $day = $day->modify('+1 day');
        } elseif ('' !== $requested) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $requested, $timezone);
            if (false !== $parsed) {
                $day = $parsed;
            }
        }

        $students = $this->provider->fetchDay($day);
        foreach ($students as $index => $student) {
            $students[$index]['accent'] = self::ACCENTS[$index % \count(self::ACCENTS)];
        }

        $changes = 0;
        foreach ($students as $student) {
            foreach ($student['lessons'] as $lesson) {
                /** @var Lesson $lesson */
                if ($lesson->isChanged()) {
                    ++$changes;
                }
            }
        }

        return $this->render('dashboard.html.twig', [
            'students' => $students,
            'day_label' => $this->formatDay($day),
            'is_today' => $day->format('Y-m-d') === (new \DateTimeImmutable('today', $timezone))->format('Y-m-d'),
            'changes' => $changes,
            'refresh_seconds' => $this->config->refreshSeconds(),
            'updated_at' => (new \DateTimeImmutable('now', $timezone))->format('H:i'),
        ]);
    }

    /**
     * One-off helper for filling in the config: shows which element the account
     * itself is and which children are linked to it.
     *
     * Guarded by the `setup_token` config key. Without a matching `?token=`
     * the route behaves as if it did not exist, so it stays safe to leave in
     * place on a publicly reachable deployment.
     */
    #[Route('/setup/{accountId}', name: 'setup', methods: ['GET'])]
    public function setup(string $accountId, Request $request): JsonResponse
    {
        $expected = (string) ($this->config->load()['setup_token'] ?? '');
        $given = (string) $request->query->get('token', '');
        if ('' === $expected || !hash_equals($expected, $given)) {
            throw $this->createNotFoundException();
        }

        try {
            $client = $this->provider->connect($accountId);
        } catch (UntisException $exception) {
            return $this->json(['error' => $exception->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        $person = $client->getPerson();
        $payload = [
            'own_element' => [
                'element_id' => $person?->elementId,
                'element_type' => $person?->elementType,
                'display_name' => $person?->displayName,
            ],
            'children' => array_map(
                static fn ($child) => [
                    'element_id' => $child->elementId,
                    'display_name' => $child->displayName,
                ],
                $client->getChildren(),
            ),
            'raw_user_data' => $client->getRawUserData()['userData'] ?? null,
        ];

        $client->logout();

        return $this->json($payload);
    }

    private function formatDay(\DateTimeInterface $day): string
    {
        return $this->translator->trans('date.long', [
            'weekday' => $this->translator->weekday((int) $day->format('N')),
            'day' => (int) $day->format('j'),
            'month' => $this->translator->month((int) $day->format('n')),
        ]);
    }
}
