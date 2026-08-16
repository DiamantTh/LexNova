<?php

declare(strict_types=1);

namespace LexNova\Handler\Auth;

use Laminas\Diactoros\Response\JsonResponse;
use LexNova\InputFilter\PasskeyCredentialInputFilter;
use LexNova\Service\AuditService;
use LexNova\Service\Fail2BanLogService;
use LexNova\Service\PasskeyService;
use LexNova\Service\RateLimitService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class PasskeyLoginHandler implements RequestHandlerInterface
{
    public function __construct(
        private PasskeyService $passkeys,
        private RateLimitService $rateLimit,
        private AuditService $audit,
        private Fail2BanLogService $fail2ban,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            return new JsonResponse(['error' => 'Invalid session token.'], 400);
        }
        if (!$this->passkeys->isConfigured()) {
            return new JsonResponse(['error' => 'Passkeys are not configured. Set app.base_url first.'], 503);
        }
        if ($this->rateLimit->isBlocked($ip, 'passkey')) {
            $this->fail2ban->record($ip);

            return new JsonResponse(['error' => 'Too many failed attempts.'], 429);
        }

        if (str_ends_with($request->getUri()->getPath(), '/options')) {
            $options = $this->passkeys->createAuthenticationOptions();
            $session->set('passkey_login', ['options' => $options, 'created_at' => time()]);

            return new JsonResponse(json_decode($options, true, flags: JSON_THROW_ON_ERROR));
        }

        $pending = $session->get('passkey_login');
        $session->unset('passkey_login');
        if (!is_array($pending) || time() - (int) ($pending['created_at'] ?? 0) > 300) {
            return new JsonResponse(['error' => 'Passkey challenge expired.'], 400);
        }

        try {
            $input = new PasskeyCredentialInputFilter(false);
            $input->setData($body);
            if (!$input->isValid()) {
                throw new \InvalidArgumentException('Invalid Passkey response.');
            }
            $user = $this->passkeys->finishAuthentication(
                (string) $pending['options'],
                $input->getValues()['credential'],
            );
            $this->rateLimit->recordSuccess($ip, 'passkey');
            $session->regenerate();
            $session->set('user_id', $user['id']);
            $session->set('username', $user['username']);
            $session->set('role', $user['role']);
            $this->audit->log($user['id'], $user['username'], 'auth.passkey_success', 'user:' . $user['id'], null, $ip);

            return new JsonResponse(['redirect' => '/verwaltung']);
        } catch (\Throwable) {
            $this->rateLimit->recordFailure($ip, 'passkey');
            $this->fail2ban->record($ip);
            $this->audit->log(null, null, 'auth.passkey_failed', null, null, $ip);

            return new JsonResponse(['error' => 'Passkey authentication failed.'], 401);
        }
    }
}
