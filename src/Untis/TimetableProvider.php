<?php

declare(strict_types=1);

namespace App\Untis;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches one day for every configured student.
 *
 * Students that share an account share a single WebUntis session, so a parent
 * account logs in once no matter how many children are on the dashboard. A failing
 * student does not take the page down; the error is carried in the result.
 */
final class TimetableProvider
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ConfigLoader $config,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{0: list<array{name: string, lessons: list<Lesson>, error: ?string}>, 1: CacheInfo}
     */
    public function fetchDay(\DateTimeInterface $day): array
    {
        return $this->cachedGet(
            'webuntis_dashboard.day.'.$day->format('Y-m-d'),
            fn () => $this->fetchUncached($day),
        );
    }

    /**
     * A full school week (Monday-Friday) for every configured student, each
     * student's lessons grouped by date. $monday must already be a Monday -
     * callers resolve which Monday to ask for (e.g. from a day picked by
     * the pager), the same way fetchDay() takes an exact day rather than
     * snapping one itself.
     *
     * @return array{0: list<array{name: string, days: array<string, list<Lesson>>, error: ?string}>, 1: CacheInfo}
     */
    public function fetchWeek(\DateTimeInterface $monday): array
    {
        return $this->cachedGet(
            'webuntis_dashboard.week.'.$monday->format('Y-m-d'),
            fn () => $this->fetchWeekUncached($monday, $monday->modify('+4 days')),
        );
    }

    /**
     * Open homework for every configured student, newest due date last. Not
     * tied to a day: WebUntis is asked for a window around today and the
     * client keeps whatever is still outstanding.
     *
     * @return array{0: list<array{name: string, homework: list<Homework>, error: ?string}>, 1: CacheInfo}
     */
    public function fetchHomework(): array
    {
        return $this->cachedGet('webuntis_dashboard.homework', fn () => $this->fetchHomeworkUncached());
    }

    /**
     * Upcoming exams for every configured student, soonest first. Not tied
     * to a day: WebUntis is asked for a window starting today.
     *
     * @return array{0: list<array{name: string, exams: list<Exam>, error: ?string}>, 1: CacheInfo}
     */
    public function fetchExams(): array
    {
        return $this->cachedGet('webuntis_dashboard.exams', fn () => $this->fetchExamsUncached());
    }

    /**
     * Absences on record for every configured student over the current
     * school year to date, most recent first. Not tied to a day: WebUntis is
     * asked for a window starting at the school year's own start date (see
     * UntisClient::getCurrentSchoolyear()) and reaching 14 days into the
     * future for any already-planned absence (e.g. a doctor's appointment
     * entered ahead of time), capped at the school year's end.
     *
     * @return array{0: list<array{name: string, absences: list<Absence>, error: ?string}>, 1: CacheInfo}
     */
    public function fetchAbsences(): array
    {
        return $this->cachedGet('webuntis_dashboard.absences', fn () => $this->fetchAbsencesUncached());
    }

    /**
     * Wraps CacheInterface::get() so every cached fetch also reports how it
     * was served: fetched fresh just now, or from an earlier fetch still
     * within cache_ttl - shown in the footer so "why hasn't this changed
     * yet" has an answer. $hit is set from inside the compute callback,
     * which Symfony only calls on a cache miss, so its final value tells
     * the two apart. Expiry comes back through $metadata rather than being
     * stored in the payload, so it stays correct even if cache_ttl changes
     * between one write and the next read.
     *
     * @template T
     *
     * @param callable(): T $compute
     *
     * @return array{0: T, 1: CacheInfo}
     */
    private function cachedGet(string $key, callable $compute): array
    {
        $timezone = $this->config->timezone();
        $ttl = $this->config->cacheTtl();
        $hit = true;
        $metadata = [];

        $value = $this->cache->get($key, function (ItemInterface $item) use ($compute, $ttl, &$hit) {
            $hit = false;
            $item->expiresAfter($ttl);

            return $compute();
        }, null, $metadata);

        $expiresAt = isset($metadata[ItemInterface::METADATA_EXPIRY])
            ? (new \DateTimeImmutable('@'.(int) $metadata[ItemInterface::METADATA_EXPIRY]))->setTimezone($timezone)
            : (new \DateTimeImmutable('now', $timezone))->modify(\sprintf('+%d seconds', $ttl));
        $fetchedAt = $expiresAt->modify(\sprintf('-%d seconds', $ttl));

        return [$value, new CacheInfo($fetchedAt, $expiresAt, $hit)];
    }

    /**
     * Every subject name WebUntis has on record for each configured student
     * over the next two weeks - unfiltered by hide_subjects, so a subject
     * that is already hidden still shows up to be un-hidden. Used to build
     * the /admin hide_subjects picklist. Unlike fetchDay()/fetchHomework(),
     * this is not cached: /admin is opened rarely, and freshness there
     * matters more than sparing WebUntis a request.
     *
     * @return list<array{name: string, subjects: list<string>, error: ?string}>
     */
    public function fetchSubjects(): array
    {
        $from = new \DateTimeImmutable('today', $this->config->timezone());
        $to = $from->modify('+14 days');

        /** @var array<string, UntisClient> $sessions */
        $sessions = [];
        $results = [];

        try {
            foreach ($this->config->students() as $student) {
                $entry = ['name' => $student['name'], 'subjects' => [], 'error' => null];

                try {
                    $accountId = $student['account'];
                    $sessions[$accountId] ??= $this->connect($accountId);

                    $entry['subjects'] = $sessions[$accountId]->getSubjects(
                        $from,
                        $to,
                        isset($student['element_id']) ? (int) $student['element_id'] : null,
                        isset($student['element_type']) ? (int) $student['element_type'] : null,
                    );
                } catch (UntisException $exception) {
                    $this->logger->warning('WebUntis subject lookup failed for {student}: {message}', [
                        'student' => $student['name'],
                        'message' => $exception->getMessage(),
                    ]);
                    $entry['error'] = $exception->getMessage();
                }

                $results[] = $entry;
            }
        } finally {
            foreach ($sessions as $session) {
                $session->logout();
            }
        }

        return $results;
    }

    public function connect(string $accountId): UntisClient
    {
        $accounts = $this->config->accounts();
        if (!isset($accounts[$accountId])) {
            throw new UntisException(sprintf('No account "%s" in the config.', $accountId));
        }

        $account = $accounts[$accountId];
        [$server, $school] = $this->config->serverAndSchool($account);

        $client = new UntisClient(
            $this->httpClient,
            $server,
            $school,
            $this->config->load()['user_agent'] ?? 'webuntis-dashboard',
        );

        return match ($account['method'] ?? 'secret') {
            'password' => $client->loginWithPassword($account['user'], $account['password']),
            'secret' => $client->loginWithSecret($account['user'], $account['secret']),
            default => throw new UntisException(sprintf(
                'Unknown authentication method "%s".',
                $account['method'],
            )),
        };
    }

    /**
     * @return list<array{name: string, lessons: list<Lesson>, error: ?string}>
     */
    private function fetchUncached(\DateTimeInterface $day): array
    {
        /** @var array<string, UntisClient> $sessions */
        $sessions = [];
        $results = [];

        try {
            foreach ($this->config->students() as $student) {
                $entry = ['name' => $student['name'], 'lessons' => [], 'error' => null];

                try {
                    $accountId = $student['account'];
                    $sessions[$accountId] ??= $this->connect($accountId);

                    $lessons = $sessions[$accountId]->getTimetable(
                        $day,
                        isset($student['element_id']) ? (int) $student['element_id'] : null,
                        isset($student['element_type']) ? (int) $student['element_type'] : null,
                    );

                    $entry['lessons'] = $this->withoutHiddenSubjects($student['name'], $lessons);
                } catch (UntisException $exception) {
                    $this->logger->warning('WebUntis lookup failed for {student}: {message}', [
                        'student' => $student['name'],
                        'message' => $exception->getMessage(),
                    ]);
                    $entry['error'] = $exception->getMessage();
                }

                $results[] = $entry;
            }
        } finally {
            foreach ($sessions as $session) {
                $session->logout();
            }
        }

        return $results;
    }

    /**
     * @return list<array{name: string, days: array<string, list<Lesson>>, error: ?string}>
     */
    private function fetchWeekUncached(\DateTimeInterface $monday, \DateTimeInterface $friday): array
    {
        /** @var array<string, UntisClient> $sessions */
        $sessions = [];
        $results = [];

        try {
            foreach ($this->config->students() as $student) {
                $entry = ['name' => $student['name'], 'days' => [], 'error' => null];

                try {
                    $accountId = $student['account'];
                    $sessions[$accountId] ??= $this->connect($accountId);

                    $days = $sessions[$accountId]->getTimetableRange(
                        $monday,
                        $friday,
                        isset($student['element_id']) ? (int) $student['element_id'] : null,
                        isset($student['element_type']) ? (int) $student['element_type'] : null,
                    );

                    foreach ($days as $date => $lessons) {
                        $days[$date] = $this->withoutHiddenSubjects($student['name'], $lessons);
                    }

                    $entry['days'] = $days;
                } catch (UntisException $exception) {
                    $this->logger->warning('WebUntis week lookup failed for {student}: {message}', [
                        'student' => $student['name'],
                        'message' => $exception->getMessage(),
                    ]);
                    $entry['error'] = $exception->getMessage();
                }

                $results[] = $entry;
            }
        } finally {
            foreach ($sessions as $session) {
                $session->logout();
            }
        }

        return $results;
    }

    /**
     * WebUntis returns every parallel course of a year group for a student
     * (e.g. both Religion and Praktische Philosophie), so drop the ones this
     * student does not attend. Subject names carry stray double spaces, so
     * compare loosely. Shared by fetchUncached() and fetchWeekUncached().
     *
     * @param list<Lesson> $lessons
     *
     * @return list<Lesson>
     */
    private function withoutHiddenSubjects(string $studentName, array $lessons): array
    {
        $hidden = $this->config->hiddenSubjects($studentName);
        if ([] === $hidden) {
            return $lessons;
        }

        $tidy = static fn (string $name): string => trim((string) preg_replace('/\s+/', ' ', $name));
        $hidden = array_map($tidy, $hidden);

        return array_values(array_filter(
            $lessons,
            static fn (Lesson $lesson) => !\in_array($tidy($lesson->subject), $hidden, true),
        ));
    }

    /**
     * @return list<array{name: string, homework: list<Homework>, error: ?string}>
     */
    private function fetchHomeworkUncached(): array
    {
        $today = new \DateTimeImmutable('today', $this->config->timezone());
        $from = $today->modify('-30 days');
        $to = $today->modify('+30 days');

        /** @var array<string, UntisClient> $sessions */
        $sessions = [];
        $results = [];

        try {
            foreach ($this->config->students() as $student) {
                $entry = ['name' => $student['name'], 'homework' => [], 'error' => null];

                try {
                    $accountId = $student['account'];
                    $sessions[$accountId] ??= $this->connect($accountId);

                    $entry['homework'] = $sessions[$accountId]->getHomework(
                        $from,
                        $to,
                        isset($student['element_id']) ? (int) $student['element_id'] : null,
                        isset($student['element_type']) ? (int) $student['element_type'] : null,
                    );
                } catch (UntisException $exception) {
                    $this->logger->warning('WebUntis homework lookup failed for {student}: {message}', [
                        'student' => $student['name'],
                        'message' => $exception->getMessage(),
                    ]);
                    $entry['error'] = $exception->getMessage();
                }

                $results[] = $entry;
            }
        } finally {
            foreach ($sessions as $session) {
                $session->logout();
            }
        }

        return $results;
    }

    /**
     * @return list<array{name: string, exams: list<Exam>, error: ?string}>
     */
    private function fetchExamsUncached(): array
    {
        $today = new \DateTimeImmutable('today', $this->config->timezone());
        $to = $today->modify('+60 days');

        /** @var array<string, UntisClient> $sessions */
        $sessions = [];
        $results = [];

        try {
            foreach ($this->config->students() as $student) {
                $entry = ['name' => $student['name'], 'exams' => [], 'error' => null];

                try {
                    $accountId = $student['account'];
                    $sessions[$accountId] ??= $this->connect($accountId);

                    $entry['exams'] = $sessions[$accountId]->getExams(
                        $today,
                        $to,
                        isset($student['element_id']) ? (int) $student['element_id'] : null,
                        isset($student['element_type']) ? (int) $student['element_type'] : null,
                    );
                } catch (UntisException $exception) {
                    $this->logger->warning('WebUntis exams lookup failed for {student}: {message}', [
                        'student' => $student['name'],
                        'message' => $exception->getMessage(),
                    ]);
                    $entry['error'] = $exception->getMessage();
                }

                $results[] = $entry;
            }
        } finally {
            foreach ($sessions as $session) {
                $session->logout();
            }
        }

        return $results;
    }

    /**
     * @return list<array{name: string, absences: list<Absence>, error: ?string}>
     */
    private function fetchAbsencesUncached(): array
    {
        $today = new \DateTimeImmutable('today', $this->config->timezone());
        $lookahead = $today->modify('+14 days');

        /** @var array<string, UntisClient> $sessions */
        $sessions = [];
        /** @var array<string, array{start: \DateTimeImmutable, end: \DateTimeImmutable}> $schoolyears */
        $schoolyears = [];
        $results = [];

        try {
            foreach ($this->config->students() as $student) {
                $entry = ['name' => $student['name'], 'absences' => [], 'error' => null];

                try {
                    $accountId = $student['account'];
                    $sessions[$accountId] ??= $this->connect($accountId);
                    $schoolyears[$accountId] ??= $sessions[$accountId]->getCurrentSchoolyear();

                    // Bounded to the school's own current school year, not a
                    // fixed rolling window - the end is still capped at
                    // school-year end even though a near-future planned
                    // absence (e.g. a doctor's appointment entered ahead of
                    // time) is looked for up to 14 days out.
                    $entry['absences'] = $sessions[$accountId]->getAbsences(
                        $schoolyears[$accountId]['start'],
                        min($lookahead, $schoolyears[$accountId]['end']),
                        isset($student['element_id']) ? (int) $student['element_id'] : null,
                        isset($student['element_type']) ? (int) $student['element_type'] : null,
                    );
                } catch (UntisException $exception) {
                    $this->logger->warning('WebUntis absences lookup failed for {student}: {message}', [
                        'student' => $student['name'],
                        'message' => $exception->getMessage(),
                    ]);
                    $entry['error'] = $exception->getMessage();
                }

                $results[] = $entry;
            }
        } finally {
            foreach ($sessions as $session) {
                $session->logout();
            }
        }

        return $results;
    }
}
