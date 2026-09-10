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
            'current_day' => $day->format('Y-m-d'),
            'is_today' => $day->format('Y-m-d') === (new \DateTimeImmutable('today', $timezone))->format('Y-m-d'),
            'day_groups' => $this->dayGroups($day),
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

    /**
     * Day picker for the footer: the previous, current and coming week, one
     * entry per school day (Mon-Fri), grouped by week. The displayed day always
     * falls in the middle group, so the picker can page a week at a time in
     * either direction; a weekend date reached through `?day=` is kept so it
     * can still show as selected.
     *
     * @return list<array{label: string, days: list<array{day: string, label: string}>}>
     */
    private function dayGroups(\DateTimeImmutable $day): array
    {
        $shown = $day->format('Y-m-d');
        $weekStart = $day->modify('monday this week');

        $groups = [];
        foreach (['footer.week_previous' => -7, 'footer.week_current' => 0, 'footer.week_next' => 7] as $labelKey => $offset) {
            $start = $weekStart->modify(sprintf('%+d days', $offset));
            $days = [];
            for ($i = 0; $i < 7; ++$i) {
                $current = $start->modify(sprintf('+%d days', $i));
                $date = $current->format('Y-m-d');
                if ($i >= 5 && $date !== $shown) {
                    continue;
                }
                $days[] = ['day' => $date, 'label' => $this->formatDay($current)];
            }
            $groups[] = ['label' => $this->translator->trans($labelKey), 'days' => $days];
        }

        return $groups;
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
