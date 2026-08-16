<?php

declare(strict_types=1);

namespace LexNova\Handler\Auth;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\InputFilter\PasskeyLabelInputFilter;
use LexNova\Service\AuditService;
use LexNova\Service\PasskeyService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class PasskeyUpdateHandler implements RequestHandlerInterface
{
    public function __construct(private PasskeyService $passkeys, private AuditService $audit)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);
        $userId = (int) ($request->getAttribute('userId') ?? 0);
        $redirect = (int) ($session->get('user_id') ?? 0) === $userId ? '/user/security' : '/admin/users';
        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            $session->set('flash_errors', ['Invalid session token.']);

            return new RedirectResponse($redirect);
        }

        $input = new PasskeyLabelInputFilter();
        $input->setData($body);
        if (!$input->isValid()) {
            $session->set('flash_errors', $input->getErrorMessages());

            return new RedirectResponse($redirect);
        }

        $credentialId = (int) ($request->getAttribute('credentialId') ?? 0);
        $label = $input->getValues()['label'];
        if (!$this->passkeys->renameForUser($credentialId, $userId, $label)) {
            $session->set('flash_errors', ['Passkey not found.']);

            return new RedirectResponse($redirect);
        }

        $this->audit->log(
            (int) ($session->get('user_id') ?? 0),
            (string) ($session->get('username') ?? ''),
            'auth.passkey_renamed',
            'user:' . $userId,
            'passkey:' . $credentialId,
            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
        );
        $session->set('flash_messages', ['Passkey name updated.']);

        return new RedirectResponse($redirect);
    }
}
