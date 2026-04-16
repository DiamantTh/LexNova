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
     * Password generator settings.
     *
     * diceware.word_count : number of words (6 ≈ 77.5 bits, 7 ≈ 90.4 bits)
     * diceware.separator  : word separator character(s)
     */
    'generator' => [
        'diceware' => [
            'word_count' => 6,
            'separator'  => '-',
        ],
    ],
];
