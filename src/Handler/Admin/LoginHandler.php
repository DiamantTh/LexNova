<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\AuditService;
use LexNova\Service\RateLimitService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class LoginHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly UserService               $users,
        private readonly RateLimitService          $rateLimit,
        private readonly AuditService              $audit,
        private readonly TemplateRendererInterface $renderer,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        // Already logged in → go to dashboard
        if ($session->has('user_id')) {
            return new RedirectResponse('/admin');
        }

        $errors = [];

        if ($request->getMethod() === 'POST') {
            $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
            $body  = (array) ($request->getParsedBody() ?? []);
            $ip    = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');

            if ($this->rateLimit->isBlocked($ip, 'login')) {
                $seconds = $this->rateLimit->secondsRemaining($ip, 'login');
                $errors[] = "Too many failed attempts. Try again in {$seconds} seconds.";
            } elseif (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
                $errors[] = 'Invalid session token.';
            } else {
                $username = trim((string) ($body['username'] ?? ''));
                $password = (string) ($body['password'] ?? '');
                $user     = $this->users->verifyCredentials($username, $password);

                if ($user !== null) {
                    $this->rateLimit->recordSuccess($ip, 'login');
                    $session->regenerate();

                    if ($this->users->hasActiveTotpKey((int) $user['id'])) {
                        // Password OK but TOTP required — park pending state and redirect
                        $session->set('totp_pending_user_id', (int) $user['id']);
                        return new RedirectResponse('/admin/totp/verify');
                    }

                    $this->audit->log(
                        (int) $user['id'],
                        (string) $user['username'],
                        'auth.login',
                        'user:' . $user['id'],
                        null,
                        $ip,
                    );

                    $session->set('user_id',  (int) $user['id']);
                    $session->set('username', (string) $user['username']);
                    $session->set('role',     (string) $user['role']);
                    return new RedirectResponse('/admin');
                }

                $this->rateLimit->recordFailure($ip, 'login');
                $this->audit->log(
                    null, $username, 'auth.login_failed',
                    null, 'username: ' . $username, $ip,
                );
                $errors[] = 'Invalid username or password.';
            }
        }

        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        return new HtmlResponse($this->renderer->render('admin/login', [
            'errors'     => $errors,
            'csrf_token' => $guard->generateToken(),
        ]));
    }
}
