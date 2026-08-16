<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\InputFilter\UserUpdateInputFilter;
use LexNova\Service\AuditService;
use LexNova\Service\PasswordService;
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
        } elseif ($validInput && !$passwordLoginEnabled && !$this->users->hasPasskey($userId)) {
            $errors[] = 'Password login can only be disabled after at least one passkey has been enrolled.';
        }

        if ($errors) {
            $session->set('flash_errors', $errors);
        } else {
            $this->users->updateRole($userId, $role);
            if ($newPassword !== '') {
                $this->users->updatePassword($userId, $newPassword);
            }
            $this->users->setPasswordLoginEnabled($userId, $passwordLoginEnabled);
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
            );
            $session->set('flash_messages', ['User updated.']);
        }

        return new RedirectResponse('/admin/users');
    }
}
