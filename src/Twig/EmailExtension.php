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
 *       - Subject-Format ist über security.email_subject konfigurierbar.
 *       - domain: optional; wird aus dem Request-Host ermittelt wenn leer.
 *
 * Formate (security.email_subject.format):
 *   domain_datetime_tz  →  [example.com]/2026-4-17/00:19 CEST   (Standard)
 *   domain_date         →  [example.com] 2026-04-17
 *   domain_only         →  [example.com]
 *   custom              →  security.email_subject.custom_pattern als PHP-date()-Format
 */
final class EmailExtension extends AbstractExtension
{
    /** @param array{format:string,date_format:string,strip_www:bool,custom_pattern:string} $subjectConfig */
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly array $subjectConfig = [
            'format' => 'domain_datetime_tz',
            'date_format' => 'Y-n-j/H:i',
            'strip_www' => true,
            'custom_pattern' => '',
        ],
    ) {
    }

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

        for ($i = 0; $i < $len; ++$i) {
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
     * Das Subject-Format hängt von security.email_subject.format ab.
     */
    public function mailto(string $email, string $domain = '', bool $raw = false): string
    {
        if ($domain === '') {
            $domain = 'unknown';
        }

        $stripWww = (bool) ($this->subjectConfig['strip_www'] ?? true);
        if ($stripWww) {
            $domain = (string) preg_replace('/^www\./i', '', strtolower($domain));
        } else {
            $domain = strtolower($domain);
        }

        $subject = $this->buildSubject($domain);

        // href: mailto:raw-email?subject=encoded-subject
        $href = 'mailto:' . rawurlencode($email)
            . '?subject=' . rawurlencode($subject);

        $encodedHref = $this->obfuscate($href);
        $encodedLabel = $raw ? htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                              : $this->obfuscate($email);

        return '<a href="' . $encodedHref . '">' . $encodedLabel . '</a>';
    }

    /**
     * Baut den mailto-Subject-String nach security.email_subject.format:
     *   domain_datetime_tz  →  [example.com]/2026-4-17/00:19 CEST
     *   domain_date         →  [example.com] 2026-04-17
     *   domain_only         →  [example.com]
     *   custom              →  custom_pattern als PHP-date()-Format
     */
    private function buildSubject(string $domain): string
    {
        $now = $this->clock->now();
        $format = (string) ($this->subjectConfig['format'] ?? 'domain_datetime_tz');

        return match ($format) {
            'domain_date' => "[{$domain}] " . $now->format('Y-m-d'),
            'domain_only' => "[{$domain}]",
            'custom' => $now->format((string) ($this->subjectConfig['custom_pattern'] ?? 'Y-m-d')),
            default => "[{$domain}]/" . $now->format(
                (string) ($this->subjectConfig['date_format'] ?? 'Y-n-j/H:i'),
            ) . ' ' . $now->format('T'),
        };
    }
}
