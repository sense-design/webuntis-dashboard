<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Untis\ConfigLoader;
use App\Untis\TimetableProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A small settings form for the handful of display/behaviour options that
 * are safe to change without editing untis.yaml by hand: language, cache and
 * refresh timing, the optional features, and which subjects each student
 * has hidden. It never touches accounts, students or any other credential
 * in untis.yaml - those stay a manual edit.
 *
 * Guarded by the `admin_token` config key, the same way `/setup` is guarded
 * by `setup_token`: without a matching `?token=` the route behaves as if it
 * did not exist. Saved values are written to var/settings.yaml by
 * ConfigLoader::saveSettings(), never to untis.yaml itself.
 */
final class AdminController extends AbstractController
{
    /** Valid `theme` values; the first is the default (follow the system setting). */
    private const THEMES = ['system', 'light', 'dark'];

    public function __construct(
        private readonly ConfigLoader $config,
        private readonly Translator $translator,
        private readonly TimetableProvider $provider,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    #[Route('/admin', name: 'admin', methods: ['GET', 'POST'])]
    public function admin(Request $request): Response
    {
        $expected = (string) ($this->config->load()['admin_token'] ?? '');
        $given = (string) $request->query->get('token', '');
        if ('' === $expected || !hash_equals($expected, $given)) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $this->config->saveSettings($this->readSubmittedSettings($request));

            // hide_subjects is applied inside TimetableProvider::fetchDay()'s
            // cached result, not read fresh at render time like the other
            // settings, so without this a change here would sit invisible
            // for up to cache_ttl seconds on every day already cached.
            $this->cache->clear();

            return $this->redirectToRoute('admin', ['token' => $given, 'saved' => 1]);
        }

        return $this->render('admin.html.twig', [
            'settings' => $this->config->currentSettings(),
            'locales' => Translator::SUPPORTED,
            'themes' => self::THEMES,
            'students' => $this->studentSubjectOptions(),
            'token' => $given,
            'saved' => '1' === $request->query->get('saved'),
        ]);
    }

    /**
     * The posted form, sanitised to the same shape ConfigLoader::currentSettings()
     * returns, plus `hide_subjects`. An unknown locale or theme is dropped
     * back to the current one rather than saved as-is, since nothing would
     * recognise it.
     *
     * @return array{locale: string, theme: string, cache_ttl: int, refresh_seconds: int, features: array{homework: bool, exams: bool, free_periods: bool}, hide_subjects: array<string, list<string>>}
     */
    private function readSubmittedSettings(Request $request): array
    {
        $locale = (string) $request->request->get('locale', '');
        $theme = (string) $request->request->get('theme', '');

        // Every currently configured student defaults to whatever is
        // already saved for them, untouched, and is only overwritten below
        // for a student whose checklist actually rendered on the form (see
        // hide_subjects_shown / the template). That is what stops a student
        // whose live subject fetch happened to fail during an unrelated
        // save (a locale change, say) from silently losing every hidden
        // subject they had.
        $hideSubjects = [];
        foreach ($this->config->students() as $student) {
            $hideSubjects[$student['name']] = $this->config->hiddenSubjects($student['name']);
        }

        $posted = $request->request->all('hide_subjects');
        foreach ($request->request->all('hide_subjects_shown') as $studentName) {
            if (!\array_key_exists($studentName, $hideSubjects)) {
                continue;
            }

            $subjects = \is_array($posted[$studentName] ?? null) ? $posted[$studentName] : [];
            $hideSubjects[$studentName] = array_values(array_unique(array_filter(
                array_map(static fn (mixed $value): string => trim((string) $value), $subjects),
                static fn (string $value): bool => '' !== $value,
            )));
        }

        return [
            'locale' => \in_array($locale, Translator::SUPPORTED, true) ? $locale : $this->config->locale(),
            'theme' => \in_array($theme, self::THEMES, true) ? $theme : $this->config->theme(),
            'cache_ttl' => max(0, $request->request->getInt('cache_ttl', $this->config->cacheTtl())),
            'refresh_seconds' => max(0, $request->request->getInt('refresh_seconds', $this->config->refreshSeconds())),
            'features' => [
                'homework' => $request->request->getBoolean('feature_homework'),
                'exams' => $request->request->getBoolean('feature_exams'),
                'free_periods' => $request->request->getBoolean('feature_free_periods'),
            ],
            'hide_subjects' => $hideSubjects,
        ];
    }

    /**
     * Every configured student's known subjects, each flagged with whether
     * it is currently hidden, for the checklist under "Fächer ausblenden".
     * A subject that is currently hidden but did not turn up in this fetch
     * (an every-other-week AG slot that has not run this cycle, say) is
     * added to the list anyway, pre-checked, so it stays represented on the
     * form instead of silently falling off the moment the picklist happens
     * not to include it. Subject names carry stray double spaces sometimes,
     * so the comparison is loose throughout - the same way
     * TimetableProvider::fetchUncached() applies it for real.
     *
     * @return list<array{name: string, error: ?string, subjects: list<array{name: string, hidden: bool}>}>
     */
    private function studentSubjectOptions(): array
    {
        $tidy = static fn (string $name): string => trim((string) preg_replace('/\s+/', ' ', $name));

        return array_map(
            function (array $student) use ($tidy): array {
                $hidden = $this->config->hiddenSubjects($student['name']);
                $hiddenTidy = array_map($tidy, $hidden);

                $names = $student['subjects'];
                $namesTidy = array_map($tidy, $names);
                foreach ($hidden as $subject) {
                    if (!\in_array($tidy($subject), $namesTidy, true)) {
                        $names[] = $subject;
                    }
                }
                sort($names, \SORT_STRING | \SORT_FLAG_CASE);

                return [
                    'name' => $student['name'],
                    'error' => $student['error'],
                    'subjects' => array_map(
                        static fn (string $subject): array => [
                            'name' => $subject,
                            'hidden' => \in_array($tidy($subject), $hiddenTidy, true),
                        ],
                        $names,
                    ),
                ];
            },
            $this->provider->fetchSubjects(),
        );
    }
}
