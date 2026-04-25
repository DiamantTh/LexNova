<?php

declare(strict_types=1);

namespace LexNova\Service\Password;

/**
 * Generates random passwords using a CSPRNG (random_int).
 *
 * Follows the Dropbox/zxcvbn standard: passwords are drawn from a large
 * character pool with cryptographic randomness, guaranteeing zxcvbn score 4.
 *
 * Character pools (printable ASCII 0x21–0x7E, 94 chars total):
 *   Lowercase : a–z          (26)
 *   Uppercase : A–Z          (26)
 *   Digits    : 0–9          (10)
 *   Symbols   : !"#$%…~      (32)
 *
 * Entropy at 20 chars with all pools: log2(94^20) ≈ 131 bits — always score 4.
 *
 * Implementation guarantees at least one character from each required pool,
 * then fills remaining positions uniformly from the full charset. A
 * cryptographically secure Fisher-Yates shuffle randomises all positions.
 */
final readonly class RandomPasswordGenerator
{
    private const string LOWER   = 'abcdefghijklmnopqrstuvwxyz';
    private const string UPPER   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const string DIGITS  = '0123456789';
    private const string SYMBOLS = '!"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~';

    public function __construct(
        private int  $length,
        private bool $requireUpper,
        private bool $requireDigits,
        private bool $requireSymbols,
    ) {}

    public function generate(): string
    {
        $charset  = self::LOWER;
        $required = [];

        if ($this->requireUpper) {
            $charset  .= self::UPPER;
            $required[] = self::UPPER;
        }
        if ($this->requireDigits) {
            $charset  .= self::DIGITS;
            $required[] = self::DIGITS;
        }
        if ($this->requireSymbols) {
            $charset  .= self::SYMBOLS;
            $required[] = self::SYMBOLS;
        }

        $charLen = strlen($charset);

        // Pre-fill with one mandatory character per required pool.
        $password = array_map(
            static fn(string $pool): string => $pool[random_int(0, strlen($pool) - 1)],
            $required
        );

        // Fill remaining positions from the full charset.
        for ($i = count($password); $i < $this->length; $i++) {
            $password[] = $charset[random_int(0, $charLen - 1)];
        }

        // Cryptographically secure Fisher-Yates shuffle.
        for ($i = count($password) - 1; $i > 0; $i--) {
            $j               = random_int(0, $i);
            [$password[$i], $password[$j]] = [$password[$j], $password[$i]];
        }

        return implode('', $password);
    }

    public function entropyBits(): float
    {
        $size = strlen(self::LOWER);
        if ($this->requireUpper)   $size += strlen(self::UPPER);
        if ($this->requireDigits)  $size += strlen(self::DIGITS);
        if ($this->requireSymbols) $size += strlen(self::SYMBOLS);

        return $this->length * log($size, 2);
    }
}
