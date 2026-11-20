<?php

declare(strict_types=1);

namespace App\Push;

use App\I18n\Translator;
use App\Untis\ConfigLoader;
use App\Untis\Lesson;
use App\Untis\TimetableProvider;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Compares every student's timetable for today and the next WINDOW_DAYS days
 * against the snapshot the previous run left in var/push-state.json, and
 * sends a Web Push message to every subscribed browser for whatever changed
 * since: a lesson cancelled (or no longer there), back on, moved to another
 * room, taken over by another teacher, added, or replaced by another subject.
 *
 * Run every few minutes from bin/check-changes (cron on bare metal, a loop in
 * docker/entrypoint.sh). The snapshot is the baseline, not WebUntis' own
 * "this differs from the plan" flags, so a change is reported exactly once,
 * when it first shows up, rather than on every run for as long as it stands.
 * Nothing is reported for what was never seen before: the very first run, a
 * newly configured student, a day just entering the window, or a student
 * whose hide_subjects changed (that would otherwise read as lessons vanishing)
 * only record a baseline.
 */
final class ChangeNotifier
{
    /** Today plus this many days ahead are watched - a full week either way. */
    private const WINDOW_DAYS = 7;

    /** Lines per notification before the rest is summed up as "+N more". */
    private const MAX_LINES = 6;

    public function __construct(
        private readonly TimetableProvider $provider,
        private readonly ConfigLoader $config,
        private readonly Translator $translator,
        private readonly SubscriptionStore $subscriptions,
        private readonly WebPush $webPush,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $stateFile,
    ) {
    }

    /**
     * One full check. Returns the number of notifications that were sent
     * (one per student with changes, times the number of subscribed
     * browsers), for the caller to log.
     *
     * $simulate (bin/check-changes --simulate) is an end-to-end test from
     * the server side: it pretends the first student's first lesson with a
     * room on an upcoming day was in another room last time, so that run
     * reports a room change. Only the comparison baseline is faked, in
     * memory - what gets saved is the real snapshot, as on any other run, so
     * the next regular run carries on normally and reports nothing extra.
     */
    public function run(bool $simulate = false): int
    {
        if (!$this->config->notificationsEnabled() || [] === $this->subscriptions->all()) {
            // Nobody to tell: do not poll WebUntis every few minutes for
            // nothing. Dropping the snapshot also means whoever subscribes
            // later starts from a fresh baseline instead of being told about
            // everything that changed while nobody was listening.
            if (is_file($this->stateFile)) {
                unlink($this->stateFile);
            }

            return 0;
        }

        $timezone = $this->config->timezone();
        $now = new \DateTimeImmutable('now', $timezone);
        $today = $now->setTime(0, 0);
        $until = $today->modify(\sprintf('+%d days', self::WINDOW_DAYS));

        $previous = $this->readState();
        $state = ['students' => []];
        $messages = [];
        $simulated = false;

        foreach ($this->provider->fetchRangeFresh($today, $until) as $student) {
            $name = $student['name'];
            $old = $previous['students'][$name] ?? null;

            // A failed fetch keeps the last good snapshot, so the next
            // successful run still diffs against it instead of re-baselining.
            if (null !== $student['error']) {
                if (null !== $old) {
                    $state['students'][$name] = $old;
                }
                continue;
            }

            $snapshot = [
                'hidden' => $this->config->hiddenSubjects($name),
                'dates' => [],
            ];
            for ($date = $today; $date <= $until; $date = $date->modify('+1 day')) {
                $key = $date->format('Y-m-d');
                $snapshot['dates'][$key] = $this->fingerprints($student['days'][$key] ?? []);
            }

            // Every lesson in the whole window gone at once, while there were
            // some before, is far more likely a WebUntis hiccup than a real
            // week of cancellations - keep the old snapshot and look again
            // next run rather than sending a wall of "cancelled".
            if (null !== $old && $this->isEmpty($snapshot['dates']) && !$this->isEmpty($old['dates'])) {
                $this->logger->warning('Every lesson for {student} vanished at once; not treating it as a change.', ['student' => $name]);
                $state['students'][$name] = $old;
                continue;
            }

            $state['students'][$name] = $snapshot;

            if ($simulate && !$simulated) {
                $fake = $this->simulatedBaseline($old ?? $snapshot, $snapshot, $today->format('Y-m-d'));
                if (null !== $fake) {
                    $old = $fake;
                    $simulated = true;
                }
            }

            if (null === $old || ($old['hidden'] ?? []) !== $snapshot['hidden']) {
                continue;
            }

            $changes = [];
            foreach ($snapshot['dates'] as $date => $lessons) {
                if (!isset($old['dates'][$date])) {
                    continue;
                }
                foreach ($this->diff($old['dates'][$date], $lessons) as $change) {
                    // A change to a lesson that is already over is old news.
                    if ($date === $today->format('Y-m-d') && $change['end'] <= $now->format('H:i')) {
                        continue;
                    }
                    $changes[] = ['date' => $date] + $change;
                }
            }

            if ([] !== $changes) {
                $messages[] = $this->message($name, $changes, $today);
            }
        }

        $this->writeState($state);

        if ([] === $messages) {
            return 0;
        }

        // The dashboard a notification opens must already show the change,
        // not a cached copy from before it - same as "Refresh now".
        $this->cache->clear();

        return $this->broadcast($messages);
    }

    /**
     * $baseline with one lesson of $snapshot - the first one after $today
     * that has a room - put back in a made-up room, so diffing $snapshot
     * against it yields exactly one room change. Null when the window has no
     * such lesson (holidays), for run() to try the next student.
     *
     * @param array{hidden: list<string>, dates: array<string, array<string, array<string, mixed>>>} $baseline
     * @param array{hidden: list<string>, dates: array<string, array<string, array<string, mixed>>>} $snapshot
     *
     * @return array{hidden: list<string>, dates: array<string, array<string, array<string, mixed>>>}|null
     */
    private function simulatedBaseline(array $baseline, array $snapshot, string $today): ?array
    {
        foreach ($snapshot['dates'] as $date => $lessons) {
            if ($date <= $today) {
                continue;
            }
            foreach ($lessons as $key => $lesson) {
                if (!$lesson['cancelled'] && [] !== $lesson['rooms']) {
                    $baseline['hidden'] = $snapshot['hidden'];
                    $baseline['dates'][$date] = $lessons;
                    $baseline['dates'][$date][$key]['rooms'] = [$this->translator->trans('push.simulated_room')];

                    return $baseline;
                }
            }
        }

        return null;
    }

    /**
     * Sends one push per message to every subscribed browser, dropping the
     * subscriptions their push service says are gone for good.
     *
     * @param list<array<string, string>> $messages
     */
    public function broadcast(array $messages, ?Subscription $only = null): int
    {
        $sent = 0;
        foreach (null !== $only ? [$only] : $this->subscriptions->all() as $subscription) {
            foreach ($messages as $message) {
                $status = $this->webPush->send($subscription, $message);
                if (404 === $status || 410 === $status) {
                    $this->subscriptions->remove($subscription->endpoint);
                    continue 2;
                }
                if ($status >= 200 && $status < 300) {
                    ++$sent;
                }
            }
        }

        return $sent;
    }

    /**
     * The comparable essence of one day: what the family would notice
     * changing. Keyed by start time and subject; two blocks sharing both (a
     * rare parallel split) get a running suffix so neither overwrites the
     * other.
     *
     * @param list<Lesson> $lessons
     *
     * @return array<string, array{start: string, end: string, subject: string, teachers: list<string>, rooms: list<string>, cancelled: bool}>
     */
    private function fingerprints(array $lessons): array
    {
        $result = [];
        foreach ($lessons as $lesson) {
            $teachers = $lesson->teachers;
            $rooms = $lesson->rooms;
            sort($teachers);
            sort($rooms);

            $key = $lesson->start.'|'.$lesson->subject;
            for ($n = 2; isset($result[$key]); ++$n) {
                $key = $lesson->start.'|'.$lesson->subject.'|'.$n;
            }

            $result[$key] = [
                'start' => $lesson->start,
                'end' => $lesson->end,
                'subject' => $lesson->subject,
                'teachers' => $teachers,
                'rooms' => $rooms,
                'cancelled' => $lesson->isCancelled(),
            ];
        }

        return $result;
    }

    /**
     * @param array<string, array{start: string, end: string, subject: string, teachers: list<string>, rooms: list<string>, cancelled: bool}> $old
     * @param array<string, array{start: string, end: string, subject: string, teachers: list<string>, rooms: list<string>, cancelled: bool}> $new
     *
     * @return list<array{start: string, end: string, subject: string, kind: string, from?: string, to?: string}>
     */
    private function diff(array $old, array $new): array
    {
        $changes = [];
        $added = [];
        $removed = [];

        foreach ($new as $key => $lesson) {
            if (!isset($old[$key])) {
                $added[] = $lesson;
                continue;
            }

            $before = $old[$key];
            if ($lesson['cancelled'] !== $before['cancelled']) {
                $changes[] = $this->change($lesson, $lesson['cancelled'] ? 'cancelled' : 'reinstated');
                continue;
            }
            if ($lesson['cancelled']) {
                continue;
            }
            if ($lesson['rooms'] !== $before['rooms'] && [] !== $lesson['rooms']) {
                $changes[] = $this->change($lesson, 'room', implode(', ', $before['rooms']), implode(', ', $lesson['rooms']));
            }
            if ($lesson['teachers'] !== $before['teachers'] && [] !== $lesson['teachers']) {
                $changes[] = $this->change($lesson, 'teacher', implode(', ', $before['teachers']), implode(', ', $lesson['teachers']));
            }
            // The second half of a double period dropped without a trace
            // (rather than kept and marked cancelled): the block just ends
            // earlier, with nothing of the same subject taking up the rest.
            if ($lesson['end'] < $before['end'] && !$this->continues($lesson, $new, $before['end'])) {
                $changes[] = $this->change($lesson, 'shortened', $before['end'], $lesson['end']);
            }
        }

        foreach ($old as $key => $lesson) {
            if (!isset($new[$key]) && !$lesson['cancelled']) {
                $removed[] = $lesson;
            }
        }

        // A double period split in two (one half cancelled), or two halves
        // merged back into one, shows up as a block appearing or vanishing
        // inside one that is still there - not a lesson of its own.
        $added = array_values(array_filter($added, fn (array $lesson): bool => $lesson['cancelled'] || !$this->coveredBy($lesson, $old)));
        $removed = array_values(array_filter($removed, fn (array $lesson): bool => !$this->coveredBy($lesson, $new)));

        foreach ($added as $lesson) {
            if ($lesson['cancelled']) {
                $changes[] = $this->change($lesson, 'cancelled');
                continue;
            }

            // Another subject at the same time as one that went away: one
            // replacement, not an addition plus a cancellation.
            foreach ($removed as $i => $gone) {
                if ($gone['start'] === $lesson['start']) {
                    $changes[] = $this->change($lesson, 'replaced', $gone['subject']);
                    unset($removed[$i]);
                    continue 2;
                }
            }

            $changes[] = $this->change($lesson, 'added');
        }

        foreach ($removed as $lesson) {
            $changes[] = $this->change($lesson, 'cancelled');
        }

        usort($changes, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

        return $changes;
    }

    /**
     * @param array{start: string, end: string, subject: string, cancelled: bool} $lesson
     * @param array<string, array{start: string, end: string, subject: string, cancelled: bool}> $others
     */
    private function coveredBy(array $lesson, array $others): bool
    {
        foreach ($others as $other) {
            if ($other['subject'] === $lesson['subject']
                && $other['start'] !== $lesson['start']
                && $other['start'] <= $lesson['start']
                && $lesson['start'] < $other['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether another block of $lesson's subject picks up where it now ends,
     * before $until - i.e. the lesson was split rather than cut short.
     *
     * @param array{end: string, subject: string} $lesson
     * @param array<string, array{start: string, subject: string}> $others
     */
    private function continues(array $lesson, array $others, string $until): bool
    {
        foreach ($others as $other) {
            if ($other['subject'] === $lesson['subject'] && $other['start'] >= $lesson['end'] && $other['start'] < $until) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{start: string, end: string, subject: string} $lesson
     *
     * @return array{start: string, end: string, subject: string, kind: string, from: string, to: string}
     */
    private function change(array $lesson, string $kind, string $from = '', string $to = ''): array
    {
        return [
            'start' => $lesson['start'],
            'end' => $lesson['end'],
            'subject' => $lesson['subject'],
            'kind' => $kind,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * @param non-empty-list<array{date: string, start: string, end: string, subject: string, kind: string, from: string, to: string}> $changes
     *
     * @return array{title: string, body: string, url: string}
     */
    private function message(string $student, array $changes, \DateTimeImmutable $today): array
    {
        $timezone = $this->config->timezone();
        $lines = [];
        foreach (\array_slice($changes, 0, self::MAX_LINES) as $change) {
            $date = new \DateTimeImmutable($change['date'], $timezone);
            $lines[] = $this->translator->trans('push.line', [
                'day' => $this->dayLabel($date, $today),
                'time' => $change['start'],
                // Subject names carry stray double spaces sometimes.
                'subject' => trim((string) preg_replace('/\s+/', ' ', $change['subject'])),
                'change' => $this->changeLabel($change),
            ]);
        }
        if (\count($changes) > self::MAX_LINES) {
            $lines[] = $this->translator->trans('push.more', ['count' => \count($changes) - self::MAX_LINES]);
        }

        return [
            'title' => $this->translator->trans('push.title', ['name' => $student]),
            'body' => implode("\n", $lines),
            'url' => '/?day='.$changes[0]['date'],
        ];
    }

    private function dayLabel(\DateTimeImmutable $date, \DateTimeImmutable $today): string
    {
        $days = (int) $today->diff($date)->format('%r%a');

        return match ($days) {
            0 => $this->translator->trans('push.today'),
            1 => $this->translator->trans('push.tomorrow'),
            default => $this->translator->trans('date.compact', [
                'weekday' => $this->translator->weekdayShort((int) $date->format('N')),
                'day' => $date->format('d'),
                'month' => $date->format('m'),
            ]),
        };
    }

    /** @param array{kind: string, from: string, to: string} $change */
    private function changeLabel(array $change): string
    {
        return match ($change['kind']) {
            'room' => '' === $change['from']
                ? $this->translator->trans('push.room_new', ['to' => $change['to']])
                : $this->translator->trans('push.room', ['from' => $change['from'], 'to' => $change['to']]),
            'teacher' => $this->translator->trans('push.teacher', ['to' => $change['to']]),
            'shortened' => $this->translator->trans('push.shortened', ['from' => $change['from'], 'to' => $change['to']]),
            'replaced' => $this->translator->trans('push.replaced', ['from' => $change['from']]),
            default => $this->translator->trans('push.'.$change['kind']),
        };
    }

    /** @param array<string, array<string, mixed>> $dates */
    private function isEmpty(array $dates): bool
    {
        foreach ($dates as $lessons) {
            if ([] !== $lessons) {
                return false;
            }
        }

        return true;
    }

    /** @return array{students: array<string, array{hidden: list<string>, dates: array<string, array<string, array<string, mixed>>>}>} */
    private function readState(): array
    {
        if (!is_readable($this->stateFile)) {
            return ['students' => []];
        }

        $parsed = json_decode((string) file_get_contents($this->stateFile), true);

        return \is_array($parsed) && \is_array($parsed['students'] ?? null) ? $parsed : ['students' => []];
    }

    /** @param array<string, mixed> $state */
    private function writeState(array $state): void
    {
        $dir = \dirname($this->stateFile);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Could not create %s.', $dir));
        }

        file_put_contents(
            $this->stateFile,
            json_encode($state, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            \LOCK_EX,
        );
    }
}
