<?php

declare(strict_types=1);

namespace App\Untis;

use Symfony\Component\Yaml\Yaml;

/**
 * Tracks which homework has been ticked off from the dashboard.
 *
 * This is purely local: WebUntis is never told, so its own "completed" state
 * (set from the official app) is untouched and still decides which homework
 * UntisClient::getHomework() returns as outstanding in the first place. This
 * class only splits that same list into "still open" and "done" for display,
 * keyed by WebUntis' own homework id (var/homework-done.yaml, one flat list
 * of ids - gitignored state, not config, alongside var/settings.yaml).
 */
final class HomeworkTracker
{
    /** @var list<int>|null */
    private ?array $done = null;

    public function __construct(private readonly string $file)
    {
    }

    public function markDone(int $id): void
    {
        $done = $this->done();
        if (!\in_array($id, $done, true)) {
            $done[] = $id;
            $this->save($done);
        }
    }

    public function markOpen(int $id): void
    {
        $this->save(array_values(array_filter(
            $this->done(),
            static fn (int $existing): bool => $existing !== $id,
        )));
    }

    /**
     * $homework split into [still open, done], each keeping the given order
     * (already sorted by due date by UntisClient::getHomework()).
     *
     * @param list<Homework> $homework
     *
     * @return array{0: list<Homework>, 1: list<Homework>}
     */
    public function split(array $homework): array
    {
        $done = $this->done();
        $open = [];
        $finished = [];

        foreach ($homework as $item) {
            if (\in_array($item->id, $done, true)) {
                $finished[] = $item;
            } else {
                $open[] = $item;
            }
        }

        return [$open, $finished];
    }

    /**
     * Drop done ids that no longer show up in anyone's current homework -
     * the assignment fell out of the fetch window, or WebUntis itself now
     * considers it completed - so the file does not grow forever. Call once
     * per request with every id currently fetched, across all students; a
     * per-student id set would prune away the other students' marks.
     *
     * @param list<int> $currentIds
     */
    public function prune(array $currentIds): void
    {
        $done = $this->done();
        $kept = array_values(array_intersect($done, $currentIds));
        if ($kept !== $done) {
            $this->save($kept);
        }
    }

    /** @return list<int> */
    private function done(): array
    {
        if (null !== $this->done) {
            return $this->done;
        }

        if (!is_readable($this->file)) {
            return $this->done = [];
        }

        $parsed = Yaml::parseFile($this->file);
        $ids = \is_array($parsed) ? ($parsed['done'] ?? []) : [];

        return $this->done = \is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /** @param list<int> $ids */
    private function save(array $ids): void
    {
        $dir = \dirname($this->file);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new UntisException(sprintf('Could not create %s.', $dir));
        }

        $yaml = "# Homework ids marked done from the dashboard. Not synced to\n"
            ."# WebUntis; ids that no longer appear in a fetch are pruned.\n"
            .Yaml::dump(['done' => array_values($ids)]);

        if (false === file_put_contents($this->file, $yaml, \LOCK_EX)) {
            throw new UntisException(sprintf('Could not write %s.', $this->file));
        }

        $this->done = array_values($ids);
    }
}
