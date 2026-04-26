<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
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
    private const ALLOWED_ROLES = ['admin'];

    public function __construct(
        private readonly UserService     $users,
        private readonly PasswordService $passwords,
        private readonly AuditService    $audit,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $guard  = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body   = (array) ($request->getParsedBody() ?? []);
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            $session->set('flash_errors', ['Invalid session token.']);
            return new RedirectResponse('/admin');
        }

        $userId      = (int) ($request->getAttribute('id') ?? 0);
        $role        = (string) ($body['role'] ?? 'admin');
        $newPassword = (string) ($body['new_password'] ?? '');
        $errors      = [];

        if ($userId <= 0 || $this->users->findById($userId) === null) {
            $errors[] = 'User not found.';
        } elseif (!in_array($role, self::ALLOWED_ROLES, true)) {
            $errors[] = 'Invalid role.';
        } elseif ($newPassword !== '' && ($pwErr = $this->passwords->validate($newPassword)) !== null) {
            $errors[] = $pwErr;
        }

        if ($errors) {
            $session->set('flash_errors', $errors);
        } else {
            $this->users->updateRole($userId, $role);
            if ($newPassword !== '') {
                $this->users->updatePassword($userId, $newPassword);
            }
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
            $detail = $newPassword !== '' ? 'role+password' : 'role';
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

        return new RedirectResponse('/admin');
    }
}
