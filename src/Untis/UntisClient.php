<?php

declare(strict_types=1);

namespace App\Untis;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * One authenticated WebUntis session.
 *
 * Two login paths are supported, because schools enable different ones:
 *
 *   - authenticate     POST /WebUntis/jsonrpc.do          username + password
 *   - getUserData2017  POST /WebUntis/jsonrpc_intern.do   username + TOTP
 *
 * Both end up with a JSESSIONID that the remaining JSON-RPC calls reuse.
 */
final class UntisClient
{
    public const ELEMENT_CLASS = 1;
    public const ELEMENT_TEACHER = 2;
    public const ELEMENT_SUBJECT = 3;
    public const ELEMENT_ROOM = 4;
    public const ELEMENT_STUDENT = 5;

    /** Adjacent periods closer than this many minutes are merged into one block. */
    private const MERGE_GAP_MINUTES = 5;

    private const ELEMENT_TYPE_NAMES = [
        'CLASS' => self::ELEMENT_CLASS,
        'KLASSE' => self::ELEMENT_CLASS,
        'TEACHER' => self::ELEMENT_TEACHER,
        'SUBJECT' => self::ELEMENT_SUBJECT,
        'ROOM' => self::ELEMENT_ROOM,
        'STUDENT' => self::ELEMENT_STUDENT,
    ];

    private ?string $sessionId = null;
    private ?Person $person = null;

    /** @var list<Person> */
    private array $children = [];

    /** @var array<string, mixed> */
    private array $rawUserData = [];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $server,
        private readonly string $school,
        private readonly string $userAgent = 'webuntis-dashboard',
    ) {
    }

    public function getPerson(): ?Person
    {
        return $this->person;
    }

    /** @return list<Person> */
    public function getChildren(): array
    {
        return $this->children;
    }

    /** @return array<string, mixed> */
    public function getRawUserData(): array
    {
        return $this->rawUserData;
    }

    // -- authentication ---------------------------------------------------

    public function loginWithPassword(string $user, string $password): self
    {
        $result = $this->call('jsonrpc.do', [
            'id' => 'webuntis-dashboard',
            'method' => 'authenticate',
            'params' => [
                'user' => $user,
                'password' => $password,
                'client' => $this->userAgent,
            ],
            'jsonrpc' => '2.0',
        ]);

        if (empty($result['sessionId'])) {
            throw new UntisException('WebUntis accepted the login but returned no session.');
        }

        $this->sessionId = (string) $result['sessionId'];
        $this->person = new Person(
            (int) ($result['personId'] ?? 0),
            $this->normaliseElementType($result['personType'] ?? null),
        );

        return $this;
    }

    public function loginWithSecret(string $user, string $secret): self
    {
        $result = $this->call(
            'jsonrpc_intern.do',
            [
                'id' => 'webuntis-dashboard',
                'method' => 'getUserData2017',
                'params' => [[
                    'auth' => [
                        'clientTime' => (int) (microtime(true) * 1000),
                        'user' => $user,
                        'otp' => (int) Totp::generate($secret),
                    ],
                ]],
                'jsonrpc' => '2.0',
            ],
            ['m' => 'getUserData2017', 'v' => 'i3.5'],
        );

        $this->rawUserData = \is_array($result) ? $result : [];
        $userData = $result['userData'] ?? [];

        $this->person = new Person(
            (int) ($userData['elemId'] ?? 0),
            $this->normaliseElementType($userData['elemType'] ?? null),
            (string) ($userData['displayName'] ?? ''),
        );

        // Parent accounts list their children here. The key has changed across
        // WebUntis versions, so accept either spelling.
        $children = $userData['children'] ?? $userData['students'] ?? [];
        foreach ($children as $child) {
            $this->children[] = new Person(
                (int) ($child['id'] ?? 0),
                self::ELEMENT_STUDENT,
                (string) ($child['displayName'] ?? $child['name'] ?? ''),
            );
        }

        return $this;
    }

    public function logout(): void
    {
        if (null === $this->sessionId) {
            return;
        }

        try {
            $this->call('jsonrpc.do', [
                'id' => 'webuntis-dashboard',
                'method' => 'logout',
                'params' => new \stdClass(),
                'jsonrpc' => '2.0',
            ]);
        } catch (UntisException) {
            // A failed logout is not worth surfacing; the session expires anyway.
        } finally {
            $this->sessionId = null;
        }
    }

    // -- data -------------------------------------------------------------

    /**
     * Return the lessons of a single day, sorted by start time and merged.
     *
     * @return list<Lesson>
     */
    public function getTimetable(
        \DateTimeInterface $day,
        ?int $elementId = null,
        ?int $elementType = null,
    ): array {
        if (null === $elementId) {
            if (null === $this->person || 0 === $this->person->elementId) {
                throw new UntisException(
                    'No element id known for this account. Set element_id in the config.'
                );
            }
            $elementId = $this->person->elementId;
            $elementType ??= $this->person->elementType;
        }

        $stamp = (int) $day->format('Ymd');

        $result = $this->call('jsonrpc.do', [
            'id' => 'webuntis-dashboard',
            'method' => 'getTimetable',
            'params' => [
                'options' => [
                    'element' => [
                        'id' => $elementId,
                        'type' => $elementType ?? self::ELEMENT_STUDENT,
                    ],
                    'startDate' => $stamp,
                    'endDate' => $stamp,
                    'showInfo' => true,
                    'showSubstText' => true,
                    'showLsText' => true,
                    'showStudentgroup' => true,
                    'klasseFields' => ['id', 'name', 'longname'],
                    'roomFields' => ['id', 'name', 'longname'],
                    'subjectFields' => ['id', 'name', 'longname'],
                    'teacherFields' => ['id', 'name', 'longname'],
                ],
            ],
            'jsonrpc' => '2.0',
        ]);

        $lessons = array_map($this->toLesson(...), \is_array($result) ? $result : []);
        usort($lessons, static fn (Lesson $a, Lesson $b) => $a->start <=> $b->start);

        return $this->mergeAdjacent($lessons);
    }

    // -- plumbing ---------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $query
     *
     * @return array<mixed>
     */
    private function call(string $path, array $payload, array $query = []): array
    {
        $cookies = ['schoolname="_'.base64_encode($this->school).'"'];
        if (null !== $this->sessionId) {
            array_unshift($cookies, 'JSESSIONID='.$this->sessionId);
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                sprintf('https://%s/WebUntis/%s', $this->server, $path),
                [
                    'query' => array_merge(['school' => $this->school], $query),
                    'json' => $payload,
                    'headers' => [
                        'User-Agent' => $this->userAgent,
                        'Cookie' => implode('; ', $cookies),
                    ],
                    'timeout' => 20,
                ],
            );

            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new UntisException(sprintf(
                    'WebUntis answered with HTTP %d. Check the server host and the school login name.',
                    $status,
                ));
            }

            // The OTP endpoint returns the session in a Set-Cookie header.
            foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $cookie) {
                if (preg_match('/JSESSIONID=([^;]+)/', $cookie, $matches)) {
                    $this->sessionId = $matches[1];
                }
            }

            $body = $response->toArray(false);
        } catch (HttpExceptionInterface $exception) {
            throw new UntisException(
                sprintf('Could not reach %s: %s', $this->server, $exception->getMessage()),
                previous: $exception,
            );
        }

        if (isset($body['error'])) {
            throw new UntisException(sprintf(
                'WebUntis error %s: %s',
                $body['error']['code'] ?? '?',
                $body['error']['message'] ?? 'unknown',
            ));
        }

        if (!\array_key_exists('result', $body)) {
            throw new UntisException('WebUntis returned a payload without a result.');
        }

        return (array) $body['result'];
    }

    private function normaliseElementType(mixed $raw): int
    {
        if (\is_int($raw)) {
            return $raw;
        }
        if (\is_string($raw)) {
            return ctype_digit($raw)
                ? (int) $raw
                : (self::ELEMENT_TYPE_NAMES[strtoupper($raw)] ?? self::ELEMENT_STUDENT);
        }

        return self::ELEMENT_STUDENT;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toLesson(array $row): Lesson
    {
        $subjects = $this->names($row['su'] ?? []);
        $notes = array_values(array_filter([
            (string) ($row['substText'] ?? ''),
            (string) ($row['info'] ?? ''),
            (string) ($row['lstext'] ?? ''),
        ]));

        return new Lesson(
            start: $this->formatTime((int) ($row['startTime'] ?? 0)),
            end: $this->formatTime((int) ($row['endTime'] ?? 0)),
            subject: $subjects[0] ?? (string) ($row['activityType'] ?? 'Unterricht'),
            teachers: $this->names($row['te'] ?? []),
            rooms: $this->names($row['ro'] ?? []),
            replacedTeachers: $this->replacedNames($row['te'] ?? []),
            replacedRooms: $this->replacedNames($row['ro'] ?? []),
            code: isset($row['code']) ? (string) $row['code'] : null,
            notes: $notes,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     *
     * @return list<string>
     */
    private function names(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $name = (string) ($entry['longname'] ?? $entry['name'] ?? '');
            if ('' !== $name) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /**
     * Original teacher or room of a substituted period, when WebUntis reports it.
     *
     * @param array<int, array<string, mixed>> $entries
     *
     * @return list<string>
     */
    private function replacedNames(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $original = (string) ($entry['orgname'] ?? '');
            if ('' !== $original) {
                $out[] = $original;
            }
        }

        return $out;
    }

    /** Turn the WebUntis integer time 800 into 08:00. */
    private function formatTime(int $value): string
    {
        return sprintf('%02d:%02d', intdiv($value, 100), $value % 100);
    }

    private function toMinutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }

    /**
     * @param list<Lesson> $lessons
     *
     * @return list<Lesson>
     */
    private function mergeAdjacent(array $lessons): array
    {
        $merged = [];
        foreach ($lessons as $lesson) {
            $previous = end($merged) ?: null;

            $sameLesson = null !== $previous
                && $previous->subject === $lesson->subject
                && $previous->teachers === $lesson->teachers
                && $previous->rooms === $lesson->rooms
                && $previous->code === $lesson->code
                && $previous->notes === $lesson->notes;

            $gap = null !== $previous
                ? $this->toMinutes($lesson->start) - $this->toMinutes($previous->end)
                : \PHP_INT_MAX;

            if ($sameLesson && $gap >= 0 && $gap <= self::MERGE_GAP_MINUTES) {
                $previous->end = $lesson->end;
                continue;
            }

            $merged[] = $lesson;
        }

        return $merged;
    }
}
