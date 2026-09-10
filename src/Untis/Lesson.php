<?php

declare(strict_types=1);

namespace App\Untis;

/**
 * One block in a student's day. Adjacent periods of the same lesson are
 * merged into a single block, which is why $end is not readonly.
 */
final class Lesson
{
    /**
     * @param list<string> $teachers
     * @param list<string> $rooms
     * @param list<string> $replacedTeachers
     * @param list<string> $replacedRooms
     * @param list<string> $notes
     */
    public function __construct(
        public readonly string $start,
        public string $end,
        public readonly string $subject,
        public readonly array $teachers = [],
        public readonly array $rooms = [],
        public readonly array $replacedTeachers = [],
        public readonly array $replacedRooms = [],
        public readonly ?string $code = null,
        public readonly array $notes = [],
    ) {
    }

    public function isCancelled(): bool
    {
        return 'cancelled' === $this->code;
    }

    public function isChanged(): bool
    {
        return \in_array($this->code, ['cancelled', 'irregular'], true);
    }

    public function teacherLine(): string
    {
        return implode(', ', $this->teachers);
    }

    public function roomLine(): string
    {
        return implode(', ', $this->rooms);
    }
}
