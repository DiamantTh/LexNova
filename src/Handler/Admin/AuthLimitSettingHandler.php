<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\AuditService;
use LexNova\Service\StepUpService;
use LexNova\Service\SystemSettingService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class AuthLimitSettingHandler implements RequestHandlerInterface
{
    public function __construct(
        private SystemSettingService $settings,
        private StepUpService $stepUp,
        private AuditService $audit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            $session->set('flash_errors', ['Invalid session token.']);

            return new RedirectResponse('/admin/security');
        }
        $totp = filter_var($body['totp_limit'] ?? null, FILTER_VALIDATE_INT);
        $webauthn = filter_var($body['webauthn_limit'] ?? null, FILTER_VALIDATE_INT);
        if ($totp === false || $webauthn === false || $totp < 1 || $totp > 100 || $webauthn < 1 || $webauthn > 100) {
            $session->set('flash_errors', ['Credential limits must be integers from 1 to 100. No stored credential will be removed.']);

            return new RedirectResponse('/admin/security');
        }
        if (!$this->stepUp->consume($session, 'auth.policy.change', 'instance:auth-limits')) {
            $session->set('flash_errors', ['Verify with your own authenticator before changing authentication policy.']);

            return new RedirectResponse('/admin/security');
        }

        $this->settings->setInt('auth.limit.totp', (int) $totp, 100, 1);
        $this->settings->setInt('auth.limit.webauthn', (int) $webauthn, 100, 1);
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        $this->audit->log(
            (int) ($session->get('user_id') ?? 0),
            (string) ($session->get('username') ?? ''),
            'auth.policy_limits_updated',
            'instance:auth-limits',
            'totp:' . $totp . ';webauthn:' . $webauthn,
            $ip,
        );
        $session->set('flash_messages', ['Authentication limits saved. Existing credentials were left untouched.']);

        return new RedirectResponse('/admin/security');
    }
}
