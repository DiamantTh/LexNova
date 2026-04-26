<?php

declare(strict_types=1);

namespace LexNova\Twig;

use Laminas\I18n\Translator\Translator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig-Extension für Übersetzungen via laminas-i18n.
 *
 * Stellt die Twig-Funktion trans() bereit:
 *   {{ trans('key') }}           → App-Standardsprache (app_locale)
 *   {{ trans('key', 'de') }}     → explizites BCP 47 Locale
 *   {{ trans('key', locale) }}   → Twig-Kontextvariable
 *
 * Exponiert außerdem die globale Twig-Variable `locale` (= app_locale in
 * BCP 47), die durch Render-Kontext-Variablen überschrieben werden kann –
 * z. B. für öffentliche Dokumentseiten, wo die Sprache aus der URL stammt.
 *
 * Wenn kein Übersetzungseintrag existiert, gibt Laminas den Schlüssel
 * unverändert zurück; englische Klartext-Schlüssel dienen damit als
 * automatischer Fallback.
 */
final class TranslationExtension extends AbstractExtension
{
    public function __construct(
        private readonly Translator $translator,
        /** BCP 47, z. B. "de" oder "de-CH" */
        private readonly string $defaultLocale,
    ) {
    }

    /** @return array<string, mixed> */
    public function getGlobals(): array
    {
        return ['locale' => $this->defaultLocale];
    }

    /** @return list<TwigFunction> */
    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('trans', $this->trans(...)),
        ];
    }

    /**
     * Übersetzt $key in das gegebene Locale (BCP 47).
     * Ohne $locale wird die App-Standardsprache verwendet.
     */
    public function trans(string $key, ?string $locale = null): string
    {
        $resolved = $this->normalizeLocale($locale ?? $this->defaultLocale);

        return $this->translator->translate($key, 'default', $resolved);
    }

    /** Konvertiert BCP 47 (de-CH) in das Laminas-Format (de_CH). */
    private function normalizeLocale(string $locale): string
    {
        return str_replace('-', '_', $locale);
    }
}
