<?php

declare(strict_types=1);

namespace App\Untis;

/**
 * One upcoming exam for a student. Only exams still ahead of today are
 * fetched, so everything that reaches the dashboard is still to come.
 */
final class Exam
{
    /**
     * @param list<string> $teachers
     * @param list<string> $rooms
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $type,
        public readonly string $name,
        public readonly string $text,
        public readonly \DateTimeImmutable $date,
        public readonly string $start,
        public readonly string $end,
        public readonly array $teachers = [],
        public readonly array $rooms = [],
    ) {
    }
}
