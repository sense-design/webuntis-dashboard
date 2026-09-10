<?php

declare(strict_types=1);

namespace App\Twig;

use App\I18n\Translator;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

/**
 * Exposes {{ t('key', {name: value}) }} and the {{ locale }} global, both backed
 * by the app-wide language from untis.yaml.
 */
final class I18nExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private readonly Translator $translator)
    {
    }

    /** @return list<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', $this->translator->trans(...)),
        ];
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return ['locale' => $this->translator->locale()];
    }
}
