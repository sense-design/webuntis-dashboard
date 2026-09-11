<?php

declare(strict_types=1);

namespace App\Untis;

/**
 * One open homework assignment for a student. Completed assignments (as
 * WebUntis itself sees them, e.g. ticked off in the official app) are
 * dropped in UntisClient, so everything that reaches the dashboard is still
 * outstanding there. $id is WebUntis' own id for the assignment, stable
 * across fetches - it is what HomeworkTracker keys the dashboard's own,
 * purely local "done" mark on.
 */
final class Homework
{
    public function __construct(
        public readonly int $id,
        public readonly string $subject,
        public readonly string $text,
        public readonly \DateTimeImmutable $assignedOn,
        public readonly \DateTimeImmutable $dueOn,
        public readonly string $teacher = '',
        public readonly string $remark = '',
    ) {
    }

    public function isOverdue(\DateTimeInterface $today): bool
    {
        return $this->dueOn->format('Y-m-d') < $today->format('Y-m-d');
    }
}
