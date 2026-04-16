<?php

declare(strict_types=1);

namespace LexNova\Service\Password;

use RuntimeException;

/**
 * Generates passphrases using the EFF Large Wordlist + Diceware algorithm.
 *
 * Algorithm: roll 5 six-sided dice per word, map the resulting 5-digit key
 * (11111–66666) to the corresponding word from the EFF Large Wordlist.
 *
 * Entropy: log2(7776^wordCount) ≈ 12.92 × wordCount bits
 *   6 words → ~77.5 bits  (recommended minimum)
 *   7 words → ~90.4 bits
 *
 * @see https://www.eff.org/deeplinks/2016/07/new-wordlists-random-passphrases
 */
final readonly class DicewareGenerator implements PasswordGeneratorInterface
{
    /** Number of possible words in the EFF Large Wordlist (6^5). */
    private const int WORDLIST_SIZE = 7776;

    /** @var array<string, string> Dice key (e.g. "31254") → word */
    private array $wordlist;

    public function __construct(
        private int    $wordCount,
        private string $separator,
        string         $wordlistPath,
    ) {
        $wl = require $wordlistPath;

        if (!is_array($wl) || count($wl) !== self::WORDLIST_SIZE) {
            throw new RuntimeException(
                'EFF Large Wordlist must contain exactly 7776 entries.'
            );
        }

        $this->wordlist = $wl;
    }

    public function generate(): string
    {
        $words = [];

        for ($w = 0; $w < $this->wordCount; $w++) {
            // Simulate rolling 5 six-sided dice (values 1–6 each).
            $key = '';
            for ($d = 0; $d < 5; $d++) {
                $key .= (string) random_int(1, 6);
            }

            $words[] = $this->wordlist[$key]
                ?? throw new RuntimeException("EFF wordlist key '{$key}' not found.");
        }

        return implode($this->separator, $words);
    }

    public function entropyBits(): float
    {
        // Each word is drawn from 7776 equally likely choices: log2(7776) ≈ 12.92 bits.
        return $this->wordCount * log(self::WORDLIST_SIZE, 2);
    }
}
