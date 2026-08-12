<?php

declare(strict_types=1);

namespace LexNova\Handler\Install\Step;

use LexNova\Service\InstallService;

/**
 * Loads a pre-generated installer password or securely initializes one from a
 * server environment variable. It never reveals a password in a web response.
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
            $environmentPassword = getenv('LEXNOVA_INSTALL_PASSWORD');
            if (is_string($environmentPassword) && $environmentPassword !== '') {
                if ($install->initializePassword($securityConfig, $environmentPassword) === null) {
                    $errors[] = 'Failed to initialize the install password from LEXNOVA_INSTALL_PASSWORD.';
                } else {
                    $installReady = true;
                    $messages[] = 'Installer password prepared from server configuration.';
                }
            } else {
                $errors[] = 'Installer password is not prepared. Run bin/lexnova install:prepare or configure LEXNOVA_INSTALL_PASSWORD on the server.';
            }
        }

        return compact('installReady', 'generatedPassword', 'errors', 'messages');
    }
}
