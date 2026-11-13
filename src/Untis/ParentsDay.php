<?php

declare(strict_types=1);

namespace App\Untis;

/**
 * One parent-teacher day ("Elternsprechtag", at some schools "LET") the
 * school has open in WebUntis, with the appointments booked on it for one
 * student. An event with nothing booked yet still comes back, with an empty
 * $appointments list, so the dashboard can point out the booking deadline.
 */
final class ParentsDay
{
    /**
     * @param list<ParentsDayAppointment> $appointments
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly \DateTimeImmutable $startDate,
        public readonly \DateTimeImmutable $endDate,
        public readonly ?\DateTimeImmutable $bookingUntil,
        public readonly array $appointments = [],
    ) {
    }
}
