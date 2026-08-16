<?php

declare(strict_types=1);

namespace LexNova\Frontend;

use LexNova\Service\TranslationService;

final class SveltePageRenderer
{
    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly string $manifestPath,
        private readonly string $assetBaseUrl = '/assets/app/',
        private readonly string $entry = 'src/main.ts',
        private readonly ?TranslationService $translations = null,
    ) {
    }

    /**
     * @param array<string, mixed> $bootstrap
     *
     * @throws \JsonException
     */
    public function render(
        string $page,
        array $bootstrap = [],
        string $title = 'LexNova',
        string $locale = 'de',
        string $theme = 'cerberus',
    ): string {
        $entry = $this->entryManifest();
        $script = $this->requiredAsset($entry, 'file');
        $styles = $this->assetList($entry['css'] ?? []);
        $imports = $this->importAssets($entry);
        $data = [
            ...$bootstrap,
            'page' => $page,
            'locale' => $locale,
            'title' => $title,
            'translations' => $this->translations?->messages($locale) ?? [],
        ];

        $json = json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
        );
        $robots = $page === 'public-document' ? 'index, follow' : 'noindex, nofollow';

        $headAssets = '';
        foreach (array_values(array_unique([...$styles, ...$imports['styles']])) as $style) {
            $headAssets .= '\n    <link rel="stylesheet" href="' . $this->escape($this->assetUrl($style)) . '">';
        }
        foreach ($imports['scripts'] as $preload) {
            $headAssets .= '\n    <link rel="modulepreload" href="' . $this->escape($this->assetUrl($preload)) . '">';
        }

        return '<!doctype html>\n'
            . '<html lang="' . $this->escape($locale) . '" data-theme="' . $this->escape($theme) . '">\n'
            . '<head>\n'
            . '    <meta charset="utf-8">\n'
            . '    <meta name="viewport" content="width=device-width, initial-scale=1">\n'
            . '    <meta name="color-scheme" content="light dark">\n'
            . '    <meta name="robots" content="' . $robots . '">\n'
            . '    <title>' . $this->escape($title) . '</title>'
            . $headAssets . '\n'
            . '</head>\n'
            . '<body>\n'
            . '    <div id="lexnova-app"></div>\n'
            . '    <script id="lexnova-bootstrap" type="application/json">' . $json . '</script>\n'
            . '    <script type="module" src="' . $this->escape($this->assetUrl($script)) . '"></script>\n'
            . '</body>\n'
            . '</html>\n';
    }

    /** @return array<string, mixed> */
    private function entryManifest(): array
    {
        $manifest = $this->manifest();
        $entry = $manifest[$this->entry] ?? null;
        if (!is_array($entry) || ($entry['isEntry'] ?? false) !== true) {
            throw new \RuntimeException("Frontend entry '{$this->entry}' is missing from the Vite manifest.");
        }

        return $entry;
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }
        if (!is_file($this->manifestPath) || !is_readable($this->manifestPath)) {
            throw new \RuntimeException('The built LexNova frontend manifest is missing. Run npm run build in frontend/.');
        }

        $content = file_get_contents($this->manifestPath);
        if ($content === false) {
            throw new \RuntimeException('The LexNova frontend manifest cannot be read.');
        }

        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException('The LexNova frontend manifest must contain a JSON object.');
        }

        return $this->manifest = $decoded;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array{scripts: list<string>, styles: list<string>}
     */
    private function importAssets(array $entry): array
    {
        $scripts = [];
        $styles = [];
        $seen = [];
        $queue = $this->assetList($entry['imports'] ?? []);
        $manifest = $this->manifest();

        while ($queue !== []) {
            $key = array_shift($queue);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $import = $manifest[$key] ?? null;
            if (!is_array($import)) {
                throw new \RuntimeException("Frontend import '{$key}' is missing from the Vite manifest.");
            }
            $scripts[] = $this->requiredAsset($import, 'file');
            $styles = [...$styles, ...$this->assetList($import['css'] ?? [])];
            $queue = [...$queue, ...$this->assetList($import['imports'] ?? [])];
        }

        return [
            'scripts' => array_values(array_unique($scripts)),
            'styles' => array_values(array_unique($styles)),
        ];
    }

    /** @param array<string, mixed> $entry */
    private function requiredAsset(array $entry, string $key): string
    {
        $asset = $entry[$key] ?? null;
        if (!is_string($asset) || $asset === '' || str_contains($asset, '..') || str_starts_with($asset, '/')) {
            throw new \RuntimeException("Vite manifest field '{$key}' contains an invalid asset path.");
        }

        return $asset;
    }

    /** @return list<string> */
    private function assetList(mixed $assets): array
    {
        if (!is_array($assets)) {
            throw new \RuntimeException('A Vite manifest asset list is invalid.');
        }

        $result = [];
        foreach ($assets as $asset) {
            if (!is_string($asset) || $asset === '' || str_contains($asset, '..') || str_starts_with($asset, '/')) {
                throw new \RuntimeException('A Vite manifest asset path is invalid.');
            }
            $result[] = $asset;
        }

        return $result;
    }

    private function assetUrl(string $asset): string
    {
        return rtrim($this->assetBaseUrl, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $asset)));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
