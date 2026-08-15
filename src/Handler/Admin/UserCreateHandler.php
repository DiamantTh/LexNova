<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\InputFilter\UserCreateInputFilter;
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

        $body['authentication'] ??= 'password';
        $input = new UserCreateInputFilter();
        $input->setData($body);
        $validInput = $input->isValid();
        $values = $input->getValues();
        $username = $values['username'] ?? '';
        $password = $values['password'] ?? '';
        $role = $values['role'] ?? '';
        $passwordLoginEnabled = ($values['authentication'] ?? '') === 'password';
        $errors = $input->getErrorMessages();

        if ($validInput && $passwordLoginEnabled && ($pwErr = $this->passwords->validate($password)) !== null) {
            $errors[] = $pwErr;
        } elseif ($validInput && $this->users->findByUsername($username) !== null) {
            $errors[] = "Username '{$username}' already exists.";
        }

        if ($errors) {
            $session->set('flash_errors', $errors);
        } else {
            $this->users->create($username, $password, $role, $passwordLoginEnabled);
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
            $this->audit->log(
                (int) ($session->get('user_id') ?? 0),
                (string) ($session->get('username') ?? ''),
                'user.create',
                'user:' . $username,
                'role:' . $role . ';password-login:' . ($passwordLoginEnabled ? 'enabled' : 'disabled'),
                $ip,
            );
            $message = "User '{$username}' created.";
            if (!$passwordLoginEnabled) {
                $message .= ' Register at least one Passkey for this account before handing it over.';
            }
            $session->set('flash_messages', [$message]);
        }

        return new RedirectResponse('/admin');
    }
}
