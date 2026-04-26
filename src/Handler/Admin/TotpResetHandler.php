<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Resets (disables + wipes) TOTP for a user.
 * POST /admin/totp/reset/{id:\d+}
 *
 * Any admin can reset any user's TOTP — use as recovery when a user has lost
 * their authenticator. FIDO2-authenticated reset will replace this manual flow
 * once FIDO2 is implemented.
 */
final readonly class TotpResetHandler implements RequestHandlerInterface
{
    public function __construct(private readonly UserService $users) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
        $guard   = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body    = (array) ($request->getParsedBody() ?? []);
        $id      = (int) $request->getAttribute('id', 0);

        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            $session->set('flash_errors', ['Invalid session token.']);
            return new RedirectResponse('/admin');
        }

        if ($id > 0 && $this->users->findById($id) !== null) {
            $this->users->setTotpSecret($id, null, false);
            $session->set('flash_messages', ['TOTP has been disabled and wiped for the selected user.']);
        } else {
            $session->set('flash_errors', ['User not found.']);
        }

        return new RedirectResponse('/admin');
    }
}
