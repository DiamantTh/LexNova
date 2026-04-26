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

final readonly class UserCreateHandler implements RequestHandlerInterface
{
    private const ALLOWED_ROLES = ['admin'];

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

            return new RedirectResponse('/admin');
        }

        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $passwordConfirm = (string) ($body['password_confirm'] ?? '');
        $role = (string) ($body['role'] ?? 'admin');
        $errors = [];

        if ($username === '' || $password === '') {
            $errors[] = 'Username and password are required.';
        } elseif ($password !== $passwordConfirm) {
            $errors[] = 'Passwords do not match.';
        } elseif (($pwErr = $this->passwords->validate($password)) !== null) {
            $errors[] = $pwErr;
        } elseif (!in_array($role, self::ALLOWED_ROLES, true)) {
            $errors[] = 'Invalid role.';
        } elseif ($this->users->findByUsername($username) !== null) {
            $errors[] = "Username '{$username}' already exists.";
        }

        if ($errors) {
            $session->set('flash_errors', $errors);
        } else {
            $this->users->create($username, $password, $role);
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
            $this->audit->log(
                (int) ($session->get('user_id') ?? 0),
                (string) ($session->get('username') ?? ''),
                'user.create',
                'user:' . $username,
                'role:' . $role,
                $ip,
            );
            $session->set('flash_messages', ["User '{$username}' created."]);
        }

        return new RedirectResponse('/admin');
    }
}
