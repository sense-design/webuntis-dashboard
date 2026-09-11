<?php

declare(strict_types=1);

namespace App\Controller;

use App\I18n\Translator;
use App\Untis\ConfigLoader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A small settings form for the handful of display/behaviour options that
 * are safe to change without editing untis.yaml by hand: language, cache and
 * refresh timing, and the optional features. It never touches accounts,
 * students or any other credential in untis.yaml - those stay a manual edit.
 *
 * Guarded by the `admin_token` config key, the same way `/setup` is guarded
 * by `setup_token`: without a matching `?token=` the route behaves as if it
 * did not exist. Saved values are written to var/settings.yaml by
 * ConfigLoader::saveSettings(), never to untis.yaml itself.
 */
final class AdminController extends AbstractController
{
    public function __construct(
        private readonly ConfigLoader $config,
        private readonly Translator $translator,
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

            return $this->redirectToRoute('admin', ['token' => $given, 'saved' => 1]);
        }

        return $this->render('admin.html.twig', [
            'settings' => $this->config->currentSettings(),
            'locales' => Translator::SUPPORTED,
            'token' => $given,
            'saved' => '1' === $request->query->get('saved'),
        ]);
    }

    /**
     * The posted form, sanitised to the same shape ConfigLoader::currentSettings()
     * returns. An unknown locale is dropped back to the current one rather
     * than saved as-is, since nothing in the translator would use it.
     *
     * @return array{locale: string, cache_ttl: int, refresh_seconds: int, features: array{homework: bool, exams: bool, free_periods: bool}}
     */
    private function readSubmittedSettings(Request $request): array
    {
        $locale = (string) $request->request->get('locale', '');

        return [
            'locale' => \in_array($locale, Translator::SUPPORTED, true) ? $locale : $this->config->locale(),
            'cache_ttl' => max(0, $request->request->getInt('cache_ttl', $this->config->cacheTtl())),
            'refresh_seconds' => max(0, $request->request->getInt('refresh_seconds', $this->config->refreshSeconds())),
            'features' => [
                'homework' => $request->request->getBoolean('feature_homework'),
                'exams' => $request->request->getBoolean('feature_exams'),
                'free_periods' => $request->request->getBoolean('feature_free_periods'),
            ],
        ];
    }
}
