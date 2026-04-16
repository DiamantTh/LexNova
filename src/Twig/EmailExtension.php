<?php

declare(strict_types=1);

namespace LexNova\Twig;

use Psr\Clock\ClockInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Twig-Extension für datenschutzfreundliche E-Mail-Darstellung.
 *
 * Filter:
 *   {{ email | obfuscate }}
 *       Gibt die E-Mail-Adresse als HTML-Entity-kodierten String zurück
 *       (zufälliger Mix aus &#dd; und &#xhh; pro Zeichen, wie antispambot()).
 *       Ausgabe bereits als HTML-safe markiert – kein weiteres |raw nötig.
 *
 *   {{ email | mailto(domain, raw=false) }}
 *       Gibt einen vollständigen <a href="mailto:…">…</a>-Link zurück.
 *       - Adresse im href UND im Label werden über obfuscate() kodiert.
 *       - Subject: "[domain]/YYYY-M-D/HH:MM TZ"  (analog zum WP-Snippet)
 *       - domain: optional; wird aus dem Request-Host ermittelt wenn leer.
 */
final class EmailExtension extends AbstractExtension
{
    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter(
                'obfuscate',
                $this->obfuscate(...),
                ['is_safe' => ['html']],
            ),
            new TwigFilter(
                'mailto',
                $this->mailto(...),
                ['is_safe' => ['html']],
            ),
        ];
    }

    /**
     * Kodiert jeden Buchstaben/jede Ziffer zufällig als &#dd; oder &#xhh;,
     * Sonderzeichen (@, .) als &#dd; – identisch zu WP antispambot().
     */
    public function obfuscate(string $email): string
    {
        $out = '';
        $len = mb_strlen($email, 'UTF-8');

        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($email, $i, 1, 'UTF-8');
            $code = mb_ord($char, 'UTF-8');

            // @ immer als Entity, Rest 50/50 dezimal/hex vs. plain
            if ($char === '@') {
                $out .= '&#' . $code . ';';
            } elseif (random_int(0, 2) === 0) {
                $out .= $char; // gelegentlich plain lassen (erschwert Regex-Harvesting)
            } elseif (random_int(0, 1) === 0) {
                $out .= '&#' . $code . ';';
            } else {
                $out .= '&#x' . dechex($code) . ';';
            }
        }

        return $out;
    }

    /**
     * Erzeugt <a href="mailto:ENCODED?subject=…">ENCODED</a>.
     * Subject-Format: [domain]/YYYY-M-D/HH:MM TZ
     */
    public function mailto(string $email, string $domain = '', bool $raw = false): string
    {
        $now     = $this->clock->now();
        $tz      = $now->getTimezone()->getName();
        // Verwende kurze TZ-Abkürzung wenn möglich (z. B. "CEST"), sonst Offset
        $abbr    = $now->format('T');
        $ts      = $now->format('Y-n-j/H:i');

        if ($domain === '') {
            $domain = 'unknown';
        }
        // www. entfernen wie im WP-Snippet
        $domain = (string) preg_replace('/^www\./i', '', strtolower($domain));

        $subject = "[{$domain}]/{$ts} {$abbr}";

        // href: mailto:raw-email?subject=encoded-subject
        $href = 'mailto:' . rawurlencode($email)
            . '?subject=' . rawurlencode($subject);

        $encodedHref  = $this->obfuscate($href);
        $encodedLabel = $raw ? htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                              : $this->obfuscate($email);

        return '<a href="' . $encodedHref . '">' . $encodedLabel . '</a>';
    }
}
