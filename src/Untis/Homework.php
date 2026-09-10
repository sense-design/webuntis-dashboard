<?php

declare(strict_types=1);

namespace App\Untis;

/**
 * One open homework assignment for a student. Completed assignments are
 * dropped in UntisClient, so everything that reaches the dashboard is still
 * outstanding.
 */
final class Homework
{
    public function __construct(
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
