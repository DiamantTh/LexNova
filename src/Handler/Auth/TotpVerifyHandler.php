<?php

declare(strict_types=1);

namespace LexNova\Handler\Auth;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\TotpService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Second factor: TOTP code entry after a successful password login.
 *
 * GET  /admin/totp/verify  – shows the code entry form
 * POST /admin/totp/verify  – validates the code and completes login
 *
 * Session must contain 'totp_pending_user_id' (set by LoginHandler).
 * On success the pending key is cleared and the user session is finalised.
 */
final readonly class TotpVerifyHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TotpService               $totp,
        private readonly UserService               $users,
        private readonly TemplateRendererInterface $renderer,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        if (!$session->has('totp_pending_user_id')) {
            return new RedirectResponse('/admin/login');
        }

        $userId = (int) $session->get('totp_pending_user_id');
        $guard  = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $errors = [];

        if ($request->getMethod() === 'POST') {
            $body = (array) ($request->getParsedBody() ?? []);

            if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
                $errors[] = 'Invalid session token.';
            } else {
                $code = trim((string) ($body['code'] ?? ''));
                $user = $this->users->findById($userId);

                if ($user !== null
                    && (bool) ($user['totp_enabled'] ?? false)
                    && $this->totp->verify((string) ($user['totp_secret'] ?? ''), $code)
                ) {
                    $session->unset('totp_pending_user_id');
                    $session->regenerate();
                    $session->set('user_id',  $userId);
                    $session->set('username', (string) $user['username']);
                    $session->set('role',     (string) $user['role']);
                    return new RedirectResponse('/admin');
                }

                $errors[] = 'Invalid authentication code. Try the next code if the current one just expired.';
            }
        }

        return new HtmlResponse($this->renderer->render('auth/totp_verify', [
            'errors'     => $errors,
            'csrf_token' => $guard->generateToken(),
        ]));
    }
}
