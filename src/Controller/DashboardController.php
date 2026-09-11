<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Untis\ConfigLoader;
use App\Untis\Exam;
use App\Untis\Homework;
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

    /**
     * Ordinary passing time between two periods runs 5-15 minutes at most;
     * only a gap at least this long is an actual free period rather than the
     * normal walk to the next room.
     */
    private const FREE_PERIOD_MINUTES = 30;

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
            $students[$index]['entries'] = $this->withFreePeriods($student['lessons']);
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

        $previous = $this->adjacentSchoolDay($day, -1);
        $next = $this->adjacentSchoolDay($day, 1);

        return $this->render('dashboard.html.twig', [
            'view' => 'timetable',
            'students' => $students,
            'day_label' => $this->formatDay($day),
            'is_today' => $day->format('Y-m-d') === (new \DateTimeImmutable('today', $timezone))->format('Y-m-d'),
            'previous_day' => ['day' => $previous->format('Y-m-d'), 'label' => $this->formatDayShort($previous)],
            'next_day' => ['day' => $next->format('Y-m-d'), 'label' => $this->formatDayShort($next)],
            'changes' => $changes,
            'refresh_seconds' => $this->config->refreshSeconds(),
            'updated_at' => (new \DateTimeImmutable('now', $timezone))->format('H:i'),
        ]);
    }

    /**
     * The homework view: every student's outstanding homework, sorted by due
     * date. It has no day pager because homework is not day-scoped.
     */
    #[Route('/homework', name: 'homework', methods: ['GET'])]
    public function homework(): Response
    {
        $timezone = $this->config->timezone();
        $today = new \DateTimeImmutable('today', $timezone);

        $students = $this->provider->fetchHomework();
        foreach ($students as $index => $student) {
            $students[$index]['accent'] = self::ACCENTS[$index % \count(self::ACCENTS)];
            $students[$index]['homework'] = array_map(
                fn (Homework $homework): array => [
                    'subject' => $homework->subject,
                    'text' => $homework->text,
                    'remark' => $homework->remark,
                    'teacher' => $homework->teacher,
                    'due' => $this->formatDayCompact($homework->dueOn),
                    'overdue' => $homework->isOverdue($today),
                ],
                $student['homework'],
            );
        }

        return $this->render('dashboard.html.twig', [
            'view' => 'homework',
            'students' => $students,
            'is_today' => true,
            'refresh_seconds' => $this->config->refreshSeconds(),
            'updated_at' => (new \DateTimeImmutable('now', $timezone))->format('H:i'),
        ]);
    }

    /**
     * The exams view: every student's upcoming exams, sorted by date. It has
     * no day pager because, like homework, it is not day-scoped.
     */
    #[Route('/exams', name: 'exams', methods: ['GET'])]
    public function exams(): Response
    {
        $timezone = $this->config->timezone();

        $students = $this->provider->fetchExams();
        foreach ($students as $index => $student) {
            $students[$index]['accent'] = self::ACCENTS[$index % \count(self::ACCENTS)];
            $students[$index]['exams'] = array_map(
                fn (Exam $exam): array => [
                    'subject' => $exam->subject,
                    'type' => $exam->type,
                    'name' => $exam->name,
                    'text' => $exam->text,
                    'date' => $this->formatDayCompact($exam->date),
                    'start' => $exam->start,
                    'end' => $exam->end,
                    'teachers' => $exam->teachers,
                    'rooms' => $exam->rooms,
                ],
                $student['exams'],
            );
        }

        return $this->render('dashboard.html.twig', [
            'view' => 'exams',
            'students' => $students,
            'is_today' => true,
            'refresh_seconds' => $this->config->refreshSeconds(),
            'updated_at' => (new \DateTimeImmutable('now', $timezone))->format('H:i'),
        ]);
    }

    /**
     * Web app manifest, so the dashboard can be added to a phone home screen
     * with its own icon and open without browser chrome. The name is localised,
     * hence a controller rather than a static file. The icons are built from
     * public/icon.svg (see README).
     */
    #[Route('/manifest.webmanifest', name: 'manifest', methods: ['GET'])]
    public function manifest(): JsonResponse
    {
        return new JsonResponse([
            'name' => $this->translator->trans('app.name'),
            'short_name' => $this->translator->trans('app.name'),
            'description' => $this->translator->trans('app.description'),
            'lang' => $this->translator->locale(),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ECEEF0',
            'theme_color' => '#ECEEF0',
            'icons' => [
                ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], Response::HTTP_OK, ['Content-Type' => 'application/manifest+json']);
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
     * A student's lessons with a free-period marker spliced in wherever two
     * of them are at least FREE_PERIOD_MINUTES apart, so a schedule with a
     * gap in the middle reads as "next lesson at 09:50" rather than making
     * the reader compare every end and start time themselves.
     *
     * @param list<Lesson> $lessons
     *
     * @return list<array{type: string, lesson: ?Lesson, until: ?string}>
     */
    private function withFreePeriods(array $lessons): array
    {
        $entries = [];
        foreach ($lessons as $index => $lesson) {
            if ($index > 0) {
                $gap = $this->toMinutes($lesson->start) - $this->toMinutes($lessons[$index - 1]->end);
                if ($gap >= self::FREE_PERIOD_MINUTES) {
                    $entries[] = ['type' => 'gap', 'lesson' => null, 'until' => $lesson->start];
                }
            }
            $entries[] = ['type' => 'lesson', 'lesson' => $lesson, 'until' => null];
        }

        return $entries;
    }

    private function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    /**
     * The nearest school day before ($direction < 0) or after ($direction > 0)
     * the given day. Saturdays and Sundays are stepped over, so paging forward
     * from a Friday lands on the following Monday.
     */
    private function adjacentSchoolDay(\DateTimeImmutable $day, int $direction): \DateTimeImmutable
    {
        $step = $direction < 0 ? '-1 day' : '+1 day';
        do {
            $day = $day->modify($step);
        } while ((int) $day->format('N') >= 6);

        return $day;
    }

    private function formatDay(\DateTimeInterface $day): string
    {
        return $this->translator->trans('date.long', [
            'weekday' => $this->translator->weekday((int) $day->format('N')),
            'day' => (int) $day->format('j'),
            'month' => $this->translator->month((int) $day->format('n')),
        ]);
    }

    /**
     * Compact label for the pager: abbreviated weekday and a numeric date, so
     * both ends fit side by side on a phone (e.g. "Di, 08.09.2026").
     */
    private function formatDayShort(\DateTimeInterface $day): string
    {
        return $this->translator->trans('date.short', [
            'weekday' => $this->translator->weekdayShort((int) $day->format('N')),
            'day' => $day->format('d'),
            'month' => $day->format('m'),
            'year' => $day->format('Y'),
        ]);
    }

    /** Year-less date for homework due dates (e.g. "Fr, 11.09."). */
    private function formatDayCompact(\DateTimeInterface $day): string
    {
        return $this->translator->trans('date.compact', [
            'weekday' => $this->translator->weekdayShort((int) $day->format('N')),
            'day' => $day->format('d'),
            'month' => $day->format('m'),
        ]);
    }
}
