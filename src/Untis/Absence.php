<?php

declare(strict_types=1);

namespace App\Untis;

/**
 * One absence entry for a student, past or ongoing. WebUntis logs an
 * absence as a single continuous block - $startDate and $endDate differ for
 * a multi-day absence (a week off sick, say), same as $startTime/$endTime
 * for a partial day.
 */
final class Absence
{
    public function __construct(
        public readonly \DateTimeImmutable $startDate,
        public readonly \DateTimeImmutable $endDate,
        public readonly string $startTime,
        public readonly string $endTime,
        public readonly string $reason,
        public readonly string $text,
        public readonly bool $excused,
        public readonly string $createdBy,
        public readonly string $excusedBy,
    ) {
    }
}
