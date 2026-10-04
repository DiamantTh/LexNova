<?php

declare(strict_types=1);

namespace LexNova\Middleware;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\AuthSessionService;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AdminAuthMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthSessionService $sessions)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var SessionInterface|null $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        if ($session !== null
            && $session->has('user_id')
            && $session->get('role') === 'admin'
            && $this->sessions->isValid($session, (int) $session->get('user_id'))
        ) {
            if ($session->get('auth_setup_required') === true) {
                $path = $request->getUri()->getPath();
                $allowed = in_array($path, [
                    '/user/security',
                    '/admin/totp/enroll',
                    '/admin/passkeys/register/options',
                    '/admin/passkeys/register/finish',
                    '/admin/logout',
                ], true);
                if (!$allowed) {
                    return new RedirectResponse('/user/security');
                }
            }

            return $handler->handle($request);
        }

        // Password verified but TOTP step pending
        if ($session !== null && $session->has('totp_pending_user_id')) {
            return new RedirectResponse('/admin/totp/verify');
        }

        return new RedirectResponse('/admin/login');
    }
}
