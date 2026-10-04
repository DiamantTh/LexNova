<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\InputFilter\UserUpdateInputFilter;
use LexNova\Service\AuditService;
use LexNova\Service\AuthenticationPolicyService;
use LexNova\Service\AuthSessionService;
use LexNova\Service\PasswordService;
use LexNova\Service\StepUpService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class UserUpdateHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly UserService $users,
        private readonly PasswordService $passwords,
        private readonly AuditService $audit,
        private readonly AuthenticationPolicyService $policy,
        private readonly StepUpService $stepUp,
        private readonly AuthSessionService $sessions,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            $session->set('flash_errors', ['Invalid session token.']);

            return new RedirectResponse('/admin/users');
        }

        $userId = (int) ($request->getAttribute('id') ?? 0);
        $body['password_login_enabled'] ??= '0';
        $input = new UserUpdateInputFilter();
        $input->setData($body);
        $validInput = $input->isValid();
        $values = $input->getValues();
        $role = $values['role'] ?? '';
        $newPassword = $values['new_password'] ?? '';
        $passwordLoginEnabled = ($values['password_login_enabled'] ?? '') === '1';
        $errors = $input->getErrorMessages();

        if ($validInput && ($userId <= 0 || $this->users->findById($userId) === null)) {
            $errors[] = 'User not found.';
        } elseif ($validInput && $newPassword !== '' && ($pwErr = $this->passwords->validate($newPassword)) !== null) {
            $errors[] = $pwErr;
        } elseif ($validInput && !$passwordLoginEnabled && !$this->policy->canDisablePasswordLogin($userId)) {
            $errors[] = 'Password login can only be disabled after at least one FIDO2 credential has been enrolled.';
        } elseif ($validInput && ($existing = $this->users->findById($userId)) !== null
            && $existing['role'] === 'admin' && $role !== 'admin' && $this->users->countAdministrators() <= 1
        ) {
            $errors[] = 'The last administrator account cannot lose its administrator role.';
        }

        if ($errors) {
            $session->set('flash_errors', $errors);
        } else {
            $current = $this->users->findById($userId);
            $actorId = (int) ($session->get('user_id') ?? 0);
            $action = $newPassword !== ''
                ? 'auth.password.change'
                : (($current['password_login_enabled'] ?? null) !== $passwordLoginEnabled
                    ? ($passwordLoginEnabled ? 'auth.password.enable' : 'auth.password.disable')
                    : 'auth.policy.change');
            $target = 'user:' . $userId . '/account';
            if (!$this->stepUp->consume($session, $action, $target)) {
                $session->set('flash_errors', ['Verify with your own authenticator before changing account security.']);

                return new RedirectResponse('/admin/users');
            }
            $this->users->updateRole($userId, $role);
            if ($newPassword !== '') {
                $this->users->updatePassword($userId, $newPassword);
            }
            $this->users->setPasswordLoginEnabled($userId, $passwordLoginEnabled);
            $roleChanged = ($current['role'] ?? null) !== $role;
            if ($newPassword !== '' || ($current['password_login_enabled'] ?? null) !== $passwordLoginEnabled || $roleChanged) {
                if ($actorId === $userId) {
                    $this->sessions->revokeOtherSessions($userId, (int) $session->get('auth_session_id'));
                } else {
                    $this->sessions->revokeUser($userId);
                }
            }
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
            $detail = ($newPassword !== '' ? 'role+password' : 'role')
                . ';password-login:' . ($passwordLoginEnabled ? 'enabled' : 'disabled');
            $this->audit->log(
                (int) ($session->get('user_id') ?? 0),
                (string) ($session->get('username') ?? ''),
                'user.update',
                'user:' . $userId,
                $detail,
                $ip,
                $userId,
            );
            if ($newPassword !== '') {
                $this->audit->log($actorId, (string) ($session->get('username') ?? ''), 'auth.password_changed', 'user:' . $userId, null, $ip, $userId);
            }
            if (($current['password_login_enabled'] ?? null) !== $passwordLoginEnabled) {
                $this->audit->log(
                    $actorId,
                    (string) ($session->get('username') ?? ''),
                    $passwordLoginEnabled ? 'auth.password_login_enabled' : 'auth.password_login_disabled',
                    'user:' . $userId,
                    null,
                    $ip,
                    $userId,
                );
            }
            if ($roleChanged) {
                $this->audit->log($actorId, (string) ($session->get('username') ?? ''), 'auth.policy_changed', 'user:' . $userId, 'role:' . $role, $ip, $userId);
            }
            $session->set('flash_messages', ['User updated.']);
        }

        return new RedirectResponse('/admin/users');
    }
}
