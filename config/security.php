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
];
