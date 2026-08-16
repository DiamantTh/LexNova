<?php

declare(strict_types=1);

namespace LexNova\Service;

use Psr\Clock\ClockInterface;

/** Produces entity-only HTML for privacy-friendly public contact text and mail links. */
final readonly class EmailObfuscator
{
    /** @param array{format?:string,date_format?:string,strip_www?:bool,custom_pattern?:string} $subjectConfig */
    public function __construct(
        private ClockInterface $clock,
        private array $subjectConfig = [],
    ) {
    }

    /**
     * Encodes every Unicode code point as a numeric entity. The result is safe
     * for direct HTML insertion because no original markup character survives.
     */
    public function obfuscate(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $result = '';
        $length = mb_strlen($value, 'UTF-8');

        for ($index = 0; $index < $length; ++$index) {
            $codePoint = mb_ord(mb_substr($value, $index, 1, 'UTF-8'), 'UTF-8');
            $result .= random_int(0, 1) === 0
                ? '&#' . $codePoint . ';'
                : '&#x' . dechex($codePoint) . ';';
        }

        return $result;
    }

    public function mailto(string $email, string $domain = '', bool $rawLabel = false): string
    {
        $domain = $domain === '' ? 'unknown' : strtolower($domain);
        if (($this->subjectConfig['strip_www'] ?? true) === true) {
            $domain = (string) preg_replace('/^www\./i', '', $domain);
        }

        $href = 'mailto:' . rawurlencode($email) . '?subject=' . rawurlencode($this->subject($domain));
        $label = $rawLabel
            ? htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : $this->obfuscate($email);

        return '<a href="' . $this->obfuscate($href) . '">' . $label . '</a>';
    }

    private function subject(string $domain): string
    {
        $now = $this->clock->now();

        return match ((string) ($this->subjectConfig['format'] ?? 'domain_datetime_tz')) {
            'domain_date' => "[{$domain}] " . $now->format('Y-m-d'),
            'domain_only' => "[{$domain}]",
            'custom' => $now->format((string) ($this->subjectConfig['custom_pattern'] ?? 'Y-m-d')),
            default => "[{$domain}]/" . $now->format(
                (string) ($this->subjectConfig['date_format'] ?? 'Y-n-j/H:i'),
            ) . ' ' . $now->format('T'),
        };
    }
}
