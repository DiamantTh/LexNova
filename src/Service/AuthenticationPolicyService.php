<?php

declare(strict_types=1);

namespace LexNova\Service;

/** Authentication-path checks and credential-specific self-service removal rules. */
final readonly class AuthenticationPolicyService
{
    public function __construct(
        private UserService $users,
        private TotpService $totp,
    ) {
    }

    /**
     * General lockout check, independent from the stricter per-credential
     * self-service removal rules.
     */
    public function hasValidAuthenticationPathAfterRemoval(int $userId, string $kind, int $authenticatorId): bool
    {
        $user = $this->users->findById($userId);
        $target = $this->users->findAuthenticator($authenticatorId, $userId);
        if ($user === null || $target === null || $target['kind'] !== $kind) {
            return false;
        }

        $passkeysAfter = $this->users->countPasskeys($userId) - ($kind === 'webauthn' ? 1 : 0);
        $totpGroups = $this->totp->uniqueCredentialGroups($this->users->getStoredTotpCredentials($userId, true));
        $totpAfter = count($totpGroups);
        if ($kind === 'totp' && $target['is_active']) {
            foreach ($totpGroups as $group) {
                if (in_array($authenticatorId, $group['authenticator_ids'], true)
                    && count($group['authenticator_ids']) === 1
                ) {
                    --$totpAfter;
                    break;
                }
            }
        }

        if ($user['password_login_enabled'] === true && $user['mfa_required'] !== true) {
            return true;
        }
        if ($passkeysAfter > 0) {
            return true;
        }

        return $user['password_login_enabled'] === true && $totpAfter > 0;
    }

    /**
     * @return array{
     *   allowed: bool,
     *   reason: ?string,
     *   warning: ?string,
     *   allowed_methods: list<string>,
     *   different_authenticator_methods: list<string>,
     *   excluded_authenticator_id: ?int
     * }
     */
    public function credentialRemovalPolicy(int $userId, string $kind, int $authenticatorId): array
    {
        $target = $this->users->findAuthenticator($authenticatorId, $userId);
        if ($target === null || $target['kind'] !== $kind) {
            return $this->denied('Credential not found.');
        }

        if ($kind === 'webauthn') {
            $count = $this->users->countPasskeys($userId);
            if ($count <= 1) {
                return $this->denied('The only FIDO2 credential cannot be removed in self-service. Use the authorized admin or recovery path.');
            }
            $lastTwo = $count === 2;

            return [
                'allowed' => true,
                'reason' => null,
                'warning' => $lastTwo ? 'Only one FIDO2 credential will remain. Confirm with the other FIDO2 credential.' : null,
                'allowed_methods' => ['webauthn'],
                'different_authenticator_methods' => $lastTwo ? ['webauthn'] : [],
                'excluded_authenticator_id' => $lastTwo ? $authenticatorId : null,
            ];
        }

        if ($kind !== 'totp') {
            return $this->denied('Unsupported credential type.');
        }

        $passkeyCount = $this->users->countPasskeys($userId);
        $groups = $this->totp->uniqueCredentialGroups($this->users->getStoredTotpCredentials($userId, true));
        $totpCount = count($groups);
        $removesUniqueSecret = false;
        if ($target['is_active']) {
            foreach ($groups as $group) {
                if (in_array($authenticatorId, $group['authenticator_ids'], true)
                    && count($group['authenticator_ids']) === 1
                ) {
                    $removesUniqueSecret = true;
                    break;
                }
            }
        }
        $totpAfter = $totpCount - ($removesUniqueSecret ? 1 : 0);

        if ($totpAfter === 0 && $removesUniqueSecret) {
            if ($passkeyCount === 0) {
                return $this->denied('The last TOTP credential requires a FIDO2 credential for removal. Use the authorized admin or recovery path.');
            }

            return [
                'allowed' => true,
                'reason' => null,
                'warning' => 'This removes the last TOTP credential. Confirm with FIDO2.',
                'allowed_methods' => ['webauthn'],
                'different_authenticator_methods' => [],
                'excluded_authenticator_id' => null,
            ];
        }

        $lastTwo = $totpCount === 2 && $totpAfter === 1;
        $methods = $totpAfter > 0 ? ['totp'] : [];
        if ($passkeyCount > 0) {
            $methods[] = 'webauthn';
        }

        return [
            'allowed' => $methods !== [],
            'reason' => $methods === [] ? 'No active authenticator can confirm this removal.' : null,
            'warning' => $lastTwo ? 'Only one TOTP authenticator will remain. Confirm with the other TOTP authenticator or FIDO2.' : null,
            'allowed_methods' => $methods,
            'different_authenticator_methods' => $lastTwo ? ['totp'] : [],
            'excluded_authenticator_id' => null,
        ];
    }

    /** @param array<string, mixed> $decision */
    public function acceptsRemovalProof(array $decision, StepUpGrant|false $grant, int $targetAuthenticatorId): bool
    {
        if ($grant === false
            || ($decision['allowed'] ?? false) !== true
        ) {
            return false;
        }

        return $this->allowsRemovalProof($decision, $grant->method, $grant->authenticatorId, $targetAuthenticatorId);
    }

    /** @param array<string, mixed> $decision */
    public function allowsRemovalProof(array $decision, string $method, int $authenticatorId, int $targetAuthenticatorId): bool
    {
        if (($decision['allowed'] ?? false) !== true || !in_array($method, $decision['allowed_methods'] ?? [], true)) {
            return false;
        }

        return !in_array($method, $decision['different_authenticator_methods'] ?? [], true)
            || $authenticatorId !== $targetAuthenticatorId;
    }

    /** Bulk reset is a recovery operation; this checks only post-reset reachability. */
    public function canResetTotp(int $userId): bool
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            return false;
        }
        if ($user['password_login_enabled'] === true && $user['mfa_required'] !== true) {
            return true;
        }

        return $this->users->countPasskeys($userId) > 0;
    }

    public function canDisablePasswordLogin(int $userId): bool
    {
        return $this->users->countPasskeys($userId) > 0;
    }

    public function activeTotpCredentialCount(int $userId): int
    {
        return $this->totp->uniqueCredentialCount($this->users->getStoredTotpCredentials($userId, true));
    }

    /**
     * @param  array{allowed: bool, allowed_methods: list<string>}|null $removalDecision
     * @return list<string>
     */
    public function stepUpMethodsFor(string $action, ?array $removalDecision = null): array
    {
        if ($action === 'auth.totp.delete' && $removalDecision !== null) {
            return $removalDecision['allowed'] === true ? $removalDecision['allowed_methods'] : [];
        }

        if (in_array($action, ['auth.webauthn.delete', 'auth.totp.reset', 'auth.policy.change', 'auth.recovery'], true)) {
            return ['webauthn'];
        }

        return ['webauthn', 'totp'];
    }

    /** @return array{allowed: false, reason: string, warning: null, allowed_methods: list<string>, different_authenticator_methods: list<string>, excluded_authenticator_id: null} */
    private function denied(string $reason): array
    {
        return [
            'allowed' => false,
            'reason' => $reason,
            'warning' => null,
            'allowed_methods' => [],
            'different_authenticator_methods' => [],
            'excluded_authenticator_id' => null,
        ];
    }
}
