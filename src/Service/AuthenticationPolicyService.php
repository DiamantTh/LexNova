<?php

declare(strict_types=1);

namespace LexNova\Service;

/** Central lockout checks for credential removal and password-policy changes. */
final readonly class AuthenticationPolicyService
{
    public function __construct(private UserService $users)
    {
    }

    public function canRemovePasskey(int $userId): bool
    {
        return $this->canRemove($userId, 'webauthn');
    }

    public function canRemoveTotpKey(int $userId, bool $active = true): bool
    {
        return $this->canRemove($userId, 'totp', active: $active);
    }

    public function canResetTotp(int $userId): bool
    {
        return $this->canRemove($userId, 'totp', removeAll: true);
    }

    public function canDisablePasswordLogin(int $userId): bool
    {
        return $this->users->countPasskeys($userId) > 0;
    }

    private function canRemove(int $userId, string $kind, bool $removeAll = false, bool $active = true): bool
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            return false;
        }
        $passkeys = $this->users->countPasskeys($userId);
        $totp = $this->users->countActiveKeys($userId);
        if ($removeAll) {
            $totpAfter = 0;
            $passkeysAfter = $passkeys;
        } elseif ($kind === 'webauthn') {
            $passkeysAfter = max(0, $passkeys - 1);
            $totpAfter = $totp;
        } else {
            $passkeysAfter = $passkeys;
            $totpAfter = $active ? max(0, $totp - 1) : $totp;
        }

        // Password-only access is an acceptable path only until MFA has been
        // explicitly enrolled. Once enrolled, losing every factor requires the
        // separately audited recovery path.
        if ($user['password_login_enabled'] === true && $user['mfa_required'] !== true) {
            return true;
        }
        if ($passkeysAfter > 0) {
            return true;
        }

        return $user['password_login_enabled'] === true && $totpAfter > 0;
    }
}
