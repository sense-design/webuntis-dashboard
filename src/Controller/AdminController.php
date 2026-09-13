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
 * Two small maintenance pages for options that are safe to change without
 * editing untis.yaml by hand: this controller never touches accounts,
 * students or any other credential in untis.yaml - those stay a manual
 * edit. `/admin` covers language, appearance, cache/refresh timing and the
 * optional features; `/admin/subjects` - a separate page on purpose, kept
 * out of the general settings form - covers which subjects each student
 * has hidden.
 *
 * Both are guarded by the `admin_token` config key, the same way `/setup`
 * is guarded by `setup_token`: without a matching `?token=` the route
 * behaves as if it did not exist. Saved values are written to
 * var/settings.yaml by ConfigLoader::saveSettings(), never to untis.yaml
 * itself.
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
        $given = $this->checkToken($request);

        if ($request->isMethod('POST')) {
            $this->config->saveSettings($this->readSubmittedSettings($request));

            return $this->redirectToRoute('admin', ['token' => $given, 'saved' => 1]);
        }

        return $this->render('admin.html.twig', [
            'settings' => $this->config->currentSettings(),
            'locales' => Translator::SUPPORTED,
            'themes' => self::THEMES,
            'token' => $given,
            'saved' => '1' === $request->query->get('saved'),
        ]);
    }

    /**
     * The posted form, sanitised to the same shape ConfigLoader::currentSettings()
     * returns. An unknown locale or theme is dropped back to the current one
     * rather than saved as-is, since nothing would recognise it.
     * `hide_subjects` is untouched by this form - see subjects() - so it is
     * carried forward as-is rather than defaulting back to untis.yaml.
     *
     * @return array{locale: string, theme: string, cache_ttl: int, refresh_seconds: int, features: array{homework: bool, exams: bool, free_periods: bool}, hide_subjects: array<string, list<string>>}
     */
    private function readSubmittedSettings(Request $request): array
    {
        $locale = (string) $request->request->get('locale', '');
        $theme = (string) $request->request->get('theme', '');

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
            'hide_subjects' => $this->config->allHiddenSubjects(),
        ];
    }

    /**
     * The hide-subjects page: a checklist per student of the subjects
     * WebUntis has on record for them, kept apart from the general settings
     * form since it needs a live WebUntis fetch to build (see
     * studentSubjectOptions()) rather than just reading untis.yaml.
     */
    #[Route('/admin/subjects', name: 'admin_subjects', methods: ['GET', 'POST'])]
    public function subjects(Request $request): Response
    {
        $given = $this->checkToken($request);

        if ($request->isMethod('POST')) {
            $settings = $this->config->currentSettings();
            $settings['hide_subjects'] = $this->readSubmittedHideSubjects($request);
            $this->config->saveSettings($settings);

            // hide_subjects is applied inside TimetableProvider::fetchDay()'s
            // cached result, not read fresh at render time like the settings
            // on the main /admin page, so without this a change here would
            // sit invisible for up to cache_ttl seconds on every day already
            // cached.
            $this->cache->clear();

            return $this->redirectToRoute('admin_subjects', ['token' => $given, 'saved' => 1]);
        }

        return $this->render('admin_subjects.html.twig', [
            'theme' => $this->config->theme(),
            'students' => $this->studentSubjectOptions(),
            'token' => $given,
            'saved' => '1' === $request->query->get('saved'),
        ]);
    }

    /**
     * Every currently configured student defaults to whatever is already
     * saved for them, untouched, and is only overwritten below for a
     * student whose checklist actually rendered on the form (see
     * hide_subjects_shown / admin_subjects.html.twig). That is what stops a
     * student whose live subject fetch happened to fail from silently
     * losing every hidden subject they had.
     *
     * @return array<string, list<string>>
     */
    private function readSubmittedHideSubjects(Request $request): array
    {
        $hideSubjects = $this->config->allHiddenSubjects();

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

        return $hideSubjects;
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

    /**
     * Guards both routes the same way `/setup` guards itself: without a
     * `?token=` matching `admin_token`, a 404 as if the route did not exist.
     * Returns the given token so callers can pass it straight back into
     * path()/redirectToRoute() without reading the query string twice.
     */
    private function checkToken(Request $request): string
    {
        $expected = (string) ($this->config->load()['admin_token'] ?? '');
        $given = (string) $request->query->get('token', '');
        if ('' === $expected || !hash_equals($expected, $given)) {
            throw $this->createNotFoundException();
        }

        return $given;
    }
}
