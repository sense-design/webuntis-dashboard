<?php

declare(strict_types=1);

namespace App\Untis;

/**
 * A WebUntis element that a timetable can be requested for.
 */
final class Person
{
    public function __construct(
        public readonly int $elementId,
        public readonly int $elementType,
        public readonly string $displayName = '',
    ) {
    }
}
