<?php

declare(strict_types=1);

return [
    'password' => [
        'algo' => PASSWORD_ARGON2ID,
        'options' => [
            'memory_cost' => 131072,
            'time_cost' => 4,
            'threads' => 2,
        ],
    ],

    /*
     * Login password policy (applied to all user accounts).
     *
     * min_length : minimum accepted length (8–256, default 16)
     * max_length : maximum accepted length (default 256)
     * ascii_only : restrict to printable ASCII 0x20–0x7E to prevent
     *              keyboard/OS-layout lockouts (Golem-scenario)
     */
    'password_policy' => [
        'min_length' => 16,
        'max_length' => 256,
        'ascii_only' => true,
    ],

    /*
     * E-Mail-Subject-Format im mailto-Filter.
     *
     * Vordefinierte Formate (format-Schlüssel):
     *   'domain_datetime_tz'  → [example.com]/2026-4-17/00:19 CEST   (Standard, wie WP-Snippet)
     *   'domain_date'         → [example.com] 2026-04-17
     *   'domain_only'         → [example.com]
     *   'custom'              → nutzt den Wert aus 'custom_pattern' direkt als PHP date()-Format
     *
     * date_format : PHP date()-Format für den Zeitstempel-Teil (ignoriert bei domain_only/custom)
     * strip_www   : www.-Prefix aus der Domain entfernen
     */
    'email_subject' => [
        'format'         => 'domain_datetime_tz',
        'date_format'    => 'Y-n-j/H:i',
        'strip_www'      => true,
        'custom_pattern' => '',
    ],

    /*
     * diceware.word_count : number of words (6 ≈ 77.5 bits, 7 ≈ 90.4 bits)
     * diceware.separator  : word separator character(s)
     */
    'generator' => [
        'diceware' => [
            'word_count' => 6,
            'separator'  => '-',
        ],
        /*
         * random.length          : password length (20 → ~131 bits with all pools)
         * random.require_upper   : enforce at least one uppercase letter
         * random.require_digits  : enforce at least one digit
         * random.require_symbols : enforce at least one printable symbol
         */
        'random' => [
            'length'          => 20,
            'require_upper'   => true,
            'require_digits'  => true,
            'require_symbols' => true,
        ],
    ],
];
