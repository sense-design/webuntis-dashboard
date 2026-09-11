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
        [$elementId, $elementType] = $this->resolveElement($elementId, $elementType);

        $lessons = array_map(
            $this->toLesson(...),
            $this->timetableRows($day, $day, $elementId, $elementType),
        );
        usort($lessons, static fn (Lesson $a, Lesson $b) => $a->start <=> $b->start);

        return $this->mergeAdjacent($lessons);
    }

    /**
     * Map of subject short name to long name, harvested from the timetable
     * over [$from, $to] (e.g. "07_WP_BI" => "Biologie"). The homework feed
     * names its subject only by the short code, so this is how it reaches the
     * page with the same label the timetable shows.
     *
     * @return array<string, string>
     */
    public function getSubjectNames(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?int $elementId = null,
        ?int $elementType = null,
    ): array {
        [$elementId, $elementType] = $this->resolveElement($elementId, $elementType);

        $names = [];
        foreach ($this->timetableRows($from, $to, $elementId, $elementType) as $row) {
            foreach ($row['su'] ?? [] as $subject) {
                $short = (string) ($subject['name'] ?? '');
                $long = (string) ($subject['longname'] ?? '');
                if ('' !== $short && '' !== $long) {
                    $names[$short] = $long;
                }
            }
        }

        return $names;
    }

    /**
     * Every distinct subject name this element's timetable shows over
     * [$from, $to], sorted. Resolved exactly like getTimetable() resolves
     * each lesson's subject (longname, falling back to the short code when
     * a subject has none) - unlike getSubjectNames(), which only knows
     * subjects that have both a short code and a longname, so it misses the
     * short-code-only ones (a common shape for AG/elective slots). The
     * `hide_subjects` config matches against this same resolved name, so
     * this is what builds that picklist in /admin.
     *
     * @return list<string>
     */
    public function getSubjects(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?int $elementId = null,
        ?int $elementType = null,
    ): array {
        [$elementId, $elementType] = $this->resolveElement($elementId, $elementType);

        $subjects = [];
        foreach ($this->timetableRows($from, $to, $elementId, $elementType) as $row) {
            $names = $this->names($row['su'] ?? []);
            $subject = $names[0] ?? (string) ($row['activityType'] ?? 'Unterricht');
            if ('' !== $subject) {
                $subjects[$subject] = true;
            }
        }

        $result = array_keys($subjects);
        sort($result, \SORT_STRING | \SORT_FLAG_CASE);

        return $result;
    }

    /**
     * Outstanding homework for the account's student, due between $from and
     * $to, sorted by due date. Completed assignments are dropped.
     *
     * WebUntis keys homework by lesson, so this reads the mobile app's
     * /api/homeworks/lessons endpoint rather than jsonrpc.do, which has no
     * homework method. The session already carries the student context; an
     * explicit $elementId only matters for a parent account with more than
     * one child, where the response mixes them. The feed names subjects by
     * short code only, so the timetable over the same range is read to
     * resolve them to long names.
     *
     * @return list<Homework>
     */
    public function getHomework(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?int $elementId = null,
        ?int $elementType = null,
    ): array {
        // Three weeks of upcoming timetable is enough to see every subject the
        // student regularly attends. It looks forward only: reaching back over
        // the summer break trips WebUntis' "single school year" guard.
        $now = new \DateTimeImmutable('today');
        $subjectNames = $this->getSubjectNames(
            $now,
            $now->modify('+20 days'),
            $elementId,
            $elementType,
        );

        $body = $this->apiGet('api/homeworks/lessons', [
            'startDate' => (int) $from->format('Ymd'),
            'endDate' => (int) $to->format('Ymd'),
        ]);

        $data = $body['data'] ?? [];

        $subjects = [];
        foreach ($data['lessons'] ?? [] as $lesson) {
            $code = (string) ($lesson['subject'] ?? '');
            $subjects[(int) ($lesson['id'] ?? 0)] = $subjectNames[$code] ?? $code;
        }

        $teachers = [];
        foreach ($data['teachers'] ?? [] as $teacher) {
            $teachers[(int) ($teacher['id'] ?? 0)] = (string) ($teacher['name'] ?? '');
        }

        // A record ties a homework to its teacher and to the students it was
        // set for; the homework body itself carries neither.
        $teacherOf = [];
        $studentsOf = [];
        foreach ($data['records'] ?? [] as $record) {
            $homeworkId = (int) ($record['homeworkId'] ?? 0);
            $teacherOf[$homeworkId] = $teachers[(int) ($record['teacherId'] ?? 0)] ?? '';
            $studentsOf[$homeworkId] = array_map('intval', $record['elementIds'] ?? []);
        }

        $homework = [];
        foreach ($data['homeworks'] ?? [] as $row) {
            if ($row['completed'] ?? false) {
                continue;
            }

            $id = (int) ($row['id'] ?? 0);
            if (null !== $elementId
                && isset($studentsOf[$id])
                && !\in_array($elementId, $studentsOf[$id], true)
            ) {
                continue;
            }

            $homework[] = new Homework(
                id: $id,
                subject: $subjects[(int) ($row['lessonId'] ?? 0)] ?? '',
                text: trim((string) ($row['text'] ?? '')),
                assignedOn: $this->parseDateStamp((int) ($row['date'] ?? 0)),
                dueOn: $this->parseDateStamp((int) ($row['dueDate'] ?? 0)),
                teacher: $teacherOf[$id] ?? '',
                remark: trim((string) ($row['remark'] ?? '')),
            );
        }

        usort($homework, static fn (Homework $a, Homework $b) => $a->dueOn <=> $b->dueOn);

        return $homework;
    }

    /**
     * Upcoming exams for the account's student, on or after $from and before
     * $to, sorted by date and start time.
     *
     * WebUntis keys this by class rather than by student, so this reads the
     * mobile app's /api/exams endpoint (jsonrpc.do has no exam method) with
     * klasseId -1, which returns every exam visible to the session, and each
     * exam is then matched against its own assignedStudents list; an explicit
     * $elementId only matters for a parent account with more than one child,
     * where the response mixes them. The feed names subjects by short code
     * only, so the timetable over the same range is read to resolve them to
     * long names, mirroring getHomework().
     *
     * @return list<Exam>
     */
    public function getExams(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        ?int $elementId = null,
        ?int $elementType = null,
    ): array {
        $subjectNames = $this->getSubjectNames($from, $to, $elementId, $elementType);

        $body = $this->apiGet('api/exams', [
            'startDate' => (int) $from->format('Ymd'),
            'endDate' => (int) $to->format('Ymd'),
            'klasseId' => -1,
            'withGrades' => 'false',
        ]);

        $exams = [];
        foreach ($body['data']['exams'] ?? [] as $row) {
            $students = array_map(
                static fn (array $student): int => (int) ($student['id'] ?? 0),
                $row['assignedStudents'] ?? [],
            );
            if (null !== $elementId && [] !== $students && !\in_array($elementId, $students, true)) {
                continue;
            }

            $code = (string) ($row['subject'] ?? '');
            $exams[] = new Exam(
                subject: $subjectNames[$code] ?? $code,
                type: (string) ($row['examType'] ?? ''),
                name: trim((string) ($row['name'] ?? '')),
                text: trim((string) ($row['text'] ?? '')),
                date: $this->parseDateStamp((int) ($row['examDate'] ?? 0)),
                start: $this->formatTime((int) ($row['startTime'] ?? 0)),
                end: $this->formatTime((int) ($row['endTime'] ?? 0)),
                teachers: array_values(array_filter(array_map('strval', $row['teachers'] ?? []))),
                rooms: array_values(array_filter(array_map('strval', $row['rooms'] ?? []))),
            );
        }

        usort(
            $exams,
            static fn (Exam $a, Exam $b) => [$a->date, $a->start] <=> [$b->date, $b->start],
        );

        return $exams;
    }

    // -- plumbing ---------------------------------------------------------

    /**
     * Fall back to the logged-in account's own element when the caller did
     * not name one (single-student accounts).
     *
     * @return array{0: int, 1: int} element id and type
     */
    private function resolveElement(?int $elementId, ?int $elementType): array
    {
        if (null === $elementId) {
            if (null === $this->person || 0 === $this->person->elementId) {
                throw new UntisException(
                    'No element id known for this account. Set element_id in the config.'
                );
            }
            $elementId = $this->person->elementId;
            $elementType ??= $this->person->elementType;
        }

        return [$elementId, $elementType ?? self::ELEMENT_STUDENT];
    }

    /**
     * Raw getTimetable period rows for one element over [$from, $to].
     *
     * @return list<array<string, mixed>>
     */
    private function timetableRows(
        \DateTimeInterface $from,
        \DateTimeInterface $to,
        int $elementId,
        int $elementType,
    ): array {
        $result = $this->call('jsonrpc.do', [
            'id' => 'webuntis-dashboard',
            'method' => 'getTimetable',
            'params' => [
                'options' => [
                    'element' => ['id' => $elementId, 'type' => $elementType],
                    'startDate' => (int) $from->format('Ymd'),
                    'endDate' => (int) $to->format('Ymd'),
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

        return \is_array($result) ? array_values($result) : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $query
     *
     * @return array<mixed>
     */
    private function call(string $path, array $payload, array $query = []): array
    {
        try {
            $response = $this->httpClient->request(
                'POST',
                sprintf('https://%s/WebUntis/%s', $this->server, $path),
                [
                    'query' => array_merge(['school' => $this->school], $query),
                    'json' => $payload,
                    'headers' => [
                        'User-Agent' => $this->userAgent,
                        'Cookie' => $this->cookieHeader(),
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

    /**
     * GET against the internal REST API (/WebUntis/api/...), which the mobile
     * app uses for data jsonrpc.do does not expose. Returns the decoded body;
     * the caller digs out the part it needs.
     *
     * @param array<string, string|int> $query
     *
     * @return array<mixed>
     */
    private function apiGet(string $path, array $query = []): array
    {
        if (null === $this->sessionId) {
            throw new UntisException('Not logged in.');
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                sprintf('https://%s/WebUntis/%s', $this->server, $path),
                [
                    'query' => array_merge(['school' => $this->school], $query),
                    'headers' => [
                        'User-Agent' => $this->userAgent,
                        'Cookie' => $this->cookieHeader(),
                    ],
                    'timeout' => 20,
                ],
            );

            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new UntisException(sprintf(
                    'WebUntis answered with HTTP %d for %s.',
                    $status,
                    $path,
                ));
            }

            return $response->toArray(false);
        } catch (HttpExceptionInterface $exception) {
            throw new UntisException(
                sprintf('Could not reach %s: %s', $this->server, $exception->getMessage()),
                previous: $exception,
            );
        }
    }

    private function cookieHeader(): string
    {
        $cookies = ['schoolname="_'.base64_encode($this->school).'"'];
        if (null !== $this->sessionId) {
            array_unshift($cookies, 'JSESSIONID='.$this->sessionId);
        }

        return implode('; ', $cookies);
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

    /** Turn the WebUntis integer date 20260908 into a DateTimeImmutable. */
    private function parseDateStamp(int $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Ymd', (string) $value);

        return false !== $date ? $date : new \DateTimeImmutable('@0');
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
