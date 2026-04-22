<?php

declare(strict_types=1);

namespace LexNova\Handler\Install\Step;

use LexNova\Service\InstallService;

/**
 * Verifies the one-time install password entered by the user.
 */
final class UnlockStep
{
    /**
     * @return array{installerUnlocked: bool, errors: list<string>}
     */
    public function handle(InstallService $install, string $installPwInput): array
    {
        $storedHash        = $install->readPasswordHash();
        $installerUnlocked = false;
        $errors            = [];

        if ($storedHash === null || !$install->verifyPassword($installPwInput, $storedHash)) {
            $errors[] = 'Invalid install password.';
        } else {
            $installerUnlocked = true;
        }

        return compact('installerUnlocked', 'errors');
    }
}
