<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Frontend\SveltePageRenderer;
use LexNova\InputFilter\LoginInputFilter;
use LexNova\Service\AuditService;
use LexNova\Service\AuthSessionService;
use LexNova\Service\Fail2BanLogService;
use LexNova\Service\RateLimitService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class LoginHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly UserService $users,
        private readonly AuthSessionService $sessions,
        private readonly RateLimitService $rateLimit,
        private readonly AuditService $audit,
        private readonly SveltePageRenderer $renderer,
        private readonly Fail2BanLogService $fail2ban,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        // Already logged in → go to dashboard
        if ($session->has('user_id')) {
            return new RedirectResponse('/verwaltung');
        }

        $errors = [];

        if ($request->getMethod() === 'POST') {
            $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
            $body = (array) ($request->getParsedBody() ?? []);
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');

            if ($this->rateLimit->isBlocked($ip, 'login')) {
                $this->fail2ban->record($ip);
                $seconds = $this->rateLimit->secondsRemaining($ip, 'login');
                $errors[] = "Too many failed attempts. Try again in {$seconds} seconds.";
            } elseif (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
                $errors[] = 'Invalid session token.';
            } else {
                $input = new LoginInputFilter();
                $input->setData($body);
                $validInput = $input->isValid();
                $values = $input->getValues();
                $username = $values['username'] ?? '';
                $password = $values['password'] ?? '';
                $user = $validInput ? $this->users->verifyCredentials($username, $password) : null;

                if ($user !== null) {
                    $this->rateLimit->recordSuccess($ip, 'login');
                    $session->regenerate();

                    $userId = (int) $user['id'];
                    if ($user['mfa_required'] === true) {
                        if (!$this->users->hasPasskey($userId) && !$this->users->hasActiveTotpKey($userId)) {
                            $this->audit->log($userId, (string) $user['username'], 'auth.login_blocked_no_factor', 'user:' . $userId, null, $ip);
                            $errors[] = 'Strong authentication is required for this account. Contact an administrator for audited recovery.';
                        } else {
                            $session->set('totp_pending_created_at', time());
                            $session->set('totp_pending_mfa', true);
                            $session->set('totp_pending_user_id', $userId);

                            return new RedirectResponse('/admin/totp/verify');
                        }
                    } else {
                        $session->set('auth_setup_required', !$this->users->hasPasskey($userId) && !$this->users->hasActiveTotpKey($userId));
                        $session->set('user_id', $userId);
                        $session->set('username', (string) $user['username']);
                        $session->set('role', (string) $user['role']);
                        $this->sessions->establish($session, $userId, 'password', 'single-factor');
                        $this->audit->log(
                            $userId,
                            (string) $user['username'],
                            'auth.login',
                            'user:' . $userId,
                            $session->get('auth_setup_required') ? 'bootstrap:security-enrollment-required' : null,
                            $ip,
                        );

                        return new RedirectResponse('/verwaltung');
                    }
                } else {
                    $this->rateLimit->recordFailure($ip, 'login');
                    $this->fail2ban->record($ip);
                    $this->audit->log(
                        null, $username, 'auth.login_failed',
                        null, 'username: ' . $username, $ip,
                    );
                    $errors[] = 'Invalid username or password.';
                }
            }
        }

        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        return new HtmlResponse($this->renderer->render('login', [
            'errors' => $errors,
            'csrfToken' => $guard->generateToken(),
        ], 'Anmeldung · LexNova'));
    }
}
