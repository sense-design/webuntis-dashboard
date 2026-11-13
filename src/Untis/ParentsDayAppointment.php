<?php

declare(strict_types=1);

namespace App\Untis;

/**
 * One booked parent-teacher slot. $room is '' until the school assigns one
 * (WebUntis sends "---" for that).
 */
final class ParentsDayAppointment
{
    /**
     * @param list<string> $subjects
     */
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly string $start,
        public readonly string $end,
        public readonly string $teacher,
        public readonly array $subjects = [],
        public readonly string $room = '',
    ) {
    }
}
