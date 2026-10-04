<?php

declare(strict_types=1);

namespace LexNova\Handler\Auth;

use Laminas\Diactoros\Response\JsonResponse;
use LexNova\Service\StepUpService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class StepUpHandler implements RequestHandlerInterface
{
    public function __construct(private StepUpService $stepUp)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            return new JsonResponse(['error' => 'Invalid session token.'], 400);
        }

        try {
            $path = $request->getUri()->getPath();
            if (str_ends_with($path, '/options')) {
                return new JsonResponse($this->stepUp->begin(
                    $session,
                    trim((string) ($body['action'] ?? '')),
                    trim((string) ($body['target'] ?? '')),
                    $ip,
                ));
            }
            if (str_ends_with($path, '/finish')) {
                $credential = $body['credential'] ?? null;
                if (!is_string($credential) || strlen($credential) > 65536) {
                    return new JsonResponse(['error' => 'Invalid FIDO2 response.'], 400);
                }
                $this->stepUp->finishPasskey($session, $credential);

                return new JsonResponse(['verified' => true]);
            }
            $this->stepUp->verifyTotp(
                $session,
                (string) ($body['code'] ?? ''),
                trim((string) ($body['action'] ?? '')),
                trim((string) ($body['target'] ?? '')),
                $ip,
            );

            return new JsonResponse(['verified' => true]);
        } catch (\Throwable $error) {
            return new JsonResponse(['error' => $error instanceof \InvalidArgumentException
                ? 'Invalid step-up request.'
                : 'Step-up authentication failed.'], 400);
        }
    }
}
