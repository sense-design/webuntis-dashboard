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
     * @return list<array{name: string, lessons: list<Lesson>, error: ?string}>
     */
    public function fetchDay(\DateTimeInterface $day): array
    {
        $key = 'webuntis_dashboard.day.'.$day->format('Y-m-d');

        return $this->cache->get($key, function (ItemInterface $item) use ($day) {
            $item->expiresAfter($this->config->cacheTtl());

            return $this->fetchUncached($day);
        });
    }

    public function connect(string $accountId): UntisClient
    {
        $accounts = $this->config->accounts();
        if (!isset($accounts[$accountId])) {
            throw new UntisException(sprintf('No account "%s" in the config.', $accountId));
        }

        $account = $accounts[$accountId];
        $config = $this->config->load();

        $client = new UntisClient(
            $this->httpClient,
            $config['server'],
            $config['school'],
            $config['user_agent'] ?? 'webuntis-dashboard',
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

                    // WebUntis returns every parallel course of a year group for
                    // a student (e.g. both Religion and Praktische Philosophie),
                    // so drop the ones this student does not attend. Subject
                    // names carry stray double spaces, so compare loosely.
                    $hidden = $student['hide_subjects'] ?? [];
                    if ([] !== $hidden) {
                        $tidy = static fn (string $name): string => trim((string) preg_replace('/\s+/', ' ', $name));
                        $hidden = array_map($tidy, $hidden);
                        $lessons = array_values(array_filter(
                            $lessons,
                            static fn (Lesson $lesson) => !\in_array($tidy($lesson->subject), $hidden, true),
                        ));
                    }

                    $entry['lessons'] = $lessons;
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
}
