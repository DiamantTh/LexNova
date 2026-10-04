<?php

declare(strict_types=1);

namespace LexNova\Service;

use Mezzio\Session\SessionInterface;

/** Action and target bound, short lived one-shot re-authentication grants. */
final readonly class StepUpService
{
    private const PENDING_KEY = 'step_up_pending';
    private const GRANT_KEY = 'step_up_grant';
    private const TTL_SECONDS = 120;

    public function __construct(
        private PasskeyService $passkeys,
        private UserService $users,
        private TotpService $totp,
        private AuditService $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function begin(SessionInterface $session, string $action, string $target, ?string $ip = null): array
    {
        $this->assertActionAndTarget($action, $target);
        $userId = (int) ($session->get('user_id') ?? 0);
        if ($userId <= 0 || (int) ($session->get('auth_session_id') ?? 0) <= 0) {
            throw new \RuntimeException('A fully authenticated account session is required.');
        }
        $user = $this->users->findById($userId);
        if ($user === null || !$this->users->hasPasskey($userId)) {
            throw new \RuntimeException('No FIDO2 authenticator is available for step-up.');
        }
        $options = $this->passkeys->createAuthenticationOptions([
            'id' => $userId,
            'username' => (string) $user['username'],
        ]);
        $session->set(self::PENDING_KEY, [
            'options' => $options,
            'user_id' => $userId,
            'action' => $action,
            'target' => $target,
            'ip' => $ip,
            'created_at' => time(),
        ]);

        return json_decode($options, true, flags: JSON_THROW_ON_ERROR);
    }

    public function finishPasskey(SessionInterface $session, string $credentialJson): void
    {
        $pending = $this->takePending($session);
        $user = $this->passkeys->finishAuthentication(
            (string) $pending['options'],
            $credentialJson,
            (int) $pending['user_id'],
        );
        $this->grant($session, $pending, (int) $user['id'], 'webauthn');
    }

    public function verifyTotp(SessionInterface $session, string $code, string $action, string $target, ?string $ip = null): void
    {
        $this->assertActionAndTarget($action, $target);
        if (in_array($action, ['auth.policy.change', 'auth.recovery'], true)) {
            throw new \RuntimeException('This action requires FIDO2 step-up.');
        }
        $userId = (int) ($session->get('user_id') ?? 0);
        $keys = $this->users->getActiveTotpKeys($userId);
        $matched = $this->totp->verifyAny($keys, $code);
        if ($matched === null) {
            throw new \RuntimeException('The TOTP code is invalid.');
        }
        $this->users->touchTotpKey($matched);
        $this->grant($session, [
            'action' => $action,
            'target' => $target,
            'ip' => $ip,
            'created_at' => time(),
        ], $userId, 'totp');
    }

    public function consume(SessionInterface $session, string $action, string $target, ?string $ip = null): bool
    {
        $grant = $session->get(self::GRANT_KEY);
        $session->unset(self::GRANT_KEY);
        if (!is_array($grant)
            || time() - (int) ($grant['created_at'] ?? 0) > self::TTL_SECONDS
            || (int) ($grant['user_id'] ?? 0) !== (int) ($session->get('user_id') ?? 0)
            || !hash_equals((string) ($grant['action'] ?? ''), $action)
            || !hash_equals((string) ($grant['target'] ?? ''), $target)
        ) {
            return false;
        }

        $this->audit->log(
            (int) $grant['user_id'],
            (string) ($session->get('username') ?? ''),
            'auth.stepup_consumed',
            $action,
            $target . ';method:' . (string) $grant['method'],
            $ip ?? (isset($grant['ip']) ? (string) $grant['ip'] : null),
            $this->effectiveUserId($target),
        );

        return true;
    }

    /** @return array<string, mixed> */
    private function takePending(SessionInterface $session): array
    {
        $pending = $session->get(self::PENDING_KEY);
        $session->unset(self::PENDING_KEY);
        if (!is_array($pending)
            || time() - (int) ($pending['created_at'] ?? 0) > self::TTL_SECONDS
            || (int) ($pending['user_id'] ?? 0) !== (int) ($session->get('user_id') ?? 0)
        ) {
            throw new \RuntimeException('The step-up challenge expired or is no longer valid.');
        }

        return $pending;
    }

    /** @param array<string, mixed> $pending */
    private function grant(SessionInterface $session, array $pending, int $userId, string $method): void
    {
        $session->set(self::GRANT_KEY, [
            'user_id' => $userId,
            'action' => (string) $pending['action'],
            'target' => (string) $pending['target'],
            'method' => $method,
            'ip' => $pending['ip'] ?? null,
            'created_at' => time(),
        ]);
        $this->audit->log(
            $userId,
            (string) ($session->get('username') ?? ''),
            'auth.stepup_verified',
            (string) $pending['action'],
            (string) $pending['target'] . ';method:' . $method,
            isset($pending['ip']) ? (string) $pending['ip'] : null,
            $this->effectiveUserId((string) $pending['target']),
        );
    }

    private function assertActionAndTarget(string $action, string $target): void
    {
        if (preg_match('/^auth\.(?:webauthn\.(?:add|delete|rename)|totp\.(?:add|delete|reset)|password\.(?:enable|disable|change)|policy\.change|recovery)$/D', $action) !== 1
            || strlen($target) > 255
            || preg_match('~^[a-zA-Z0-9:_./-]{1,255}$~D', $target) !== 1
        ) {
            throw new \InvalidArgumentException('Invalid step-up action or target.');
        }
    }

    private function effectiveUserId(string $target): ?int
    {
        return preg_match('/^user:(\d+)(?:\/|$)/D', $target, $matches) === 1
            ? (int) $matches[1]
            : null;
    }
}
