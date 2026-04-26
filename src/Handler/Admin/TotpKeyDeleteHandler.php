<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\AuditService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Deletes a single TOTP key belonging to a specific user.
 * POST /admin/users/{userId:\d+}/totp-keys/{keyId:\d+}/delete.
 *
 * Any admin may delete any user's key. UserService::deleteTotpKey() enforces
 * ownership by requiring both keyId and userId to match, so there is no risk
 * of deleting a key that belongs to a different user via URL manipulation.
 */
final readonly class TotpKeyDeleteHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly UserService $users,
        private readonly AuditService $audit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);

        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            $session->set('flash_errors', ['Invalid session token.']);

            return new RedirectResponse('/admin');
        }

        $userId = (int) $request->getAttribute('userId', 0);
        $keyId = (int) $request->getAttribute('keyId', 0);

        if ($userId <= 0 || $keyId <= 0 || $this->users->findById($userId) === null) {
            $session->set('flash_errors', ['User or key not found.']);

            return new RedirectResponse('/admin');
        }

        $deleted = $this->users->deleteTotpKey($keyId, $userId);

        if ($deleted) {
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
            $this->audit->log(
                (int) ($session->get('user_id') ?? 0),
                (string) ($session->get('username') ?? ''),
                'totp.key_delete',
                'user:' . $userId,
                'key:' . $keyId,
                $ip,
            );
            $session->set('flash_messages', ['TOTP key deleted.']);
        } else {
            $session->set('flash_errors', ['Key not found or does not belong to this user.']);
        }

        return new RedirectResponse('/admin');
    }
}
