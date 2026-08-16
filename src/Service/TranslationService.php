<?php

declare(strict_types=1);

namespace LexNova\Service;

use Laminas\I18n\Translator\Loader\PhpArray;

final class TranslationService
{
    /** @var array<string, array<string, string>> */
    private array $catalogues = [];

    private readonly PhpArray $loader;

    public function __construct(
        private readonly string $translationsPath,
        private readonly string $defaultLocale,
    ) {
        $this->loader = new PhpArray();
    }

    public function translate(string $message, ?string $locale = null): string
    {
        foreach ($this->localeChain($locale ?? $this->defaultLocale) as $candidate) {
            $catalogue = $this->catalogue($candidate);
            if (isset($catalogue[$message])) {
                return $catalogue[$message];
            }
        }

        return $message;
    }

    /** @return array<string, string> */
    public function messages(?string $locale = null): array
    {
        $messages = [];
        foreach (array_reverse($this->localeChain($locale ?? $this->defaultLocale)) as $candidate) {
            $messages = array_replace($messages, $this->catalogue($candidate));
        }

        return $messages;
    }

    /** @return list<string> */
    private function localeChain(string $locale): array
    {
        $normalized = str_replace('-', '_', $locale);
        if (preg_match('/^[a-zA-Z]{2,8}(?:_[a-zA-Z0-9]{1,8})*$/D', $normalized) !== 1) {
            return ['en'];
        }

        $language = strtolower(explode('_', $normalized, 2)[0]);
        $chain = [$normalized];
        if ($language !== strtolower($normalized)) {
            $chain[] = $language;
        }
        if (!in_array('en', $chain, true)) {
            $chain[] = 'en';
        }

        return array_values(array_unique($chain));
    }

    /** @return array<string, string> */
    private function catalogue(string $locale): array
    {
        if (isset($this->catalogues[$locale])) {
            return $this->catalogues[$locale];
        }

        $file = $this->translationsPath . '/' . $locale . '.php';
        if (!is_file($file)) {
            return $this->catalogues[$locale] = [];
        }

        $messages = $this->loader->load($locale, $file);
        if ($messages === null) {
            return $this->catalogues[$locale] = [];
        }

        $catalogue = [];
        foreach ($messages->getArrayCopy() as $key => $value) {
            if (is_string($value)) {
                $catalogue[$key] = $value;
            }
        }

        return $this->catalogues[$locale] = $catalogue;
    }
}
