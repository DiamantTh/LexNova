<?php

declare(strict_types=1);

namespace LexNova\Handler\Install\Step;

use LexNova\Service\InstallService;

/**
 * Generates the one-time install password on first visit if it does not exist yet.
 */
final class InitStep
{
    /**
     * @param  array<string, mixed>                                                                                $securityConfig
     * @return array{installReady: bool, generatedPassword: ?string, errors: list<string>, messages: list<string>}
     */
    public function handle(InstallService $install, array $securityConfig): array
    {
        $installReady = $install->readPasswordHash() !== null;
        $generatedPassword = null;
        $errors = [];
        $messages = [];

        if (!$installReady) {
            $generatedPassword = $install->initializePassword($securityConfig);
            if ($generatedPassword === null) {
                $errors[] = 'Failed to generate install password. Check data/ directory permissions.';
            } else {
                $installReady = true;
                $messages[] = 'Install password generated — copy it now, it will not be shown again.';
            }
        }

        return compact('installReady', 'generatedPassword', 'errors', 'messages');
    }
}
