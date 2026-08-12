<?php

declare(strict_types=1);

namespace LexNova\Handler\Auth;

use Laminas\Diactoros\Response\JsonResponse;
use LexNova\Service\AuditService;
use LexNova\Service\PasskeyService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class PasskeyRegisterHandler implements RequestHandlerInterface
{
    public function __construct(private PasskeyService $passkeys, private UserService $users, private AuditService $audit)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);
        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            return new JsonResponse(['error' => 'Invalid session token.'], 400);
        }
        if (!$this->passkeys->isConfigured()) {
            return new JsonResponse(['error' => 'Passkeys are not configured. Set app.base_url first.'], 503);
        }
        $userId = (int) $session->get('user_id');
        $user = $this->users->findById($userId);
        if ($user === null) {
            return new JsonResponse(['error' => 'User not found.'], 404);
        }

        if (str_ends_with($request->getUri()->getPath(), '/options')) {
            $options = $this->passkeys->createRegistrationOptions(['id' => $userId, 'username' => (string) $user['username']]);
            $session->set('passkey_registration', ['options' => $options, 'created_at' => time()]);

            return new JsonResponse(json_decode($options, true, flags: JSON_THROW_ON_ERROR));
        }

        $pending = $session->get('passkey_registration');
        $session->unset('passkey_registration');
        if (!is_array($pending) || time() - (int) ($pending['created_at'] ?? 0) > 300) {
            return new JsonResponse(['error' => 'Passkey challenge expired.'], 400);
        }
        try {
            $id = $this->passkeys->finishRegistration($userId, (string) $pending['options'], (string) ($body['credential'] ?? ''), (string) ($body['label'] ?? 'Passkey'));
            $this->audit->log($userId, (string) $user['username'], 'auth.passkey_enrolled', 'passkey:' . $id, null, (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));

            return new JsonResponse(['redirect' => '/admin']);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Passkey registration failed.'], 400);
        }
    }
}
