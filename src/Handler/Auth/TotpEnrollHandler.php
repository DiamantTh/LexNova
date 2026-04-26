<?php

declare(strict_types=1);

namespace LexNova\Handler\Auth;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;
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
 * TOTP enrollment for the currently logged-in admin.
 *
 * GET  /admin/totp/enroll  – shows QR code + compatibility notice
 * POST /admin/totp/enroll  – verifies one code before activating TOTP
 *
 * The plain-text secret is kept only in the server-side session until the
 * user successfully confirms a code. After that it is encrypted with
 * XSalsa20-Poly1305 and stored in the database.
 */
final readonly class TotpEnrollHandler implements RequestHandlerInterface
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
        $userId  = (int) $session->get('user_id');
        $user    = $this->users->findById($userId);

        if ($user === null) {
            return new RedirectResponse('/admin');
        }

        $existingKeyCount = $this->users->countActiveKeys($userId);

        $guard  = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $errors = [];

        if ($request->getMethod() === 'POST') {
            $body = (array) ($request->getParsedBody() ?? []);

            if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
                $errors[] = 'Invalid session token.';
            } else {
                $code         = trim((string) ($body['code'] ?? ''));
                $enrollSecret = (string) ($session->get('totp_enrolling_secret') ?? '');
                $label        = trim((string) ($body['label'] ?? 'Default'));
                if ($label === '') {
                    $label = 'Default';
                }

                if ($enrollSecret === '') {
                    $errors[] = 'Enrollment session expired. Please reload the page.';
                } elseif ($this->totp->verifyPlain($enrollSecret, $code)) {
                    $encrypted = $this->totp->encrypt($enrollSecret);
                    $this->users->addTotpKey($userId, $encrypted, $label);
                    $session->unset('totp_enrolling_secret');
                    $msg = $existingKeyCount === 0
                        ? 'TOTP two-factor authentication has been enabled.'
                        : 'Additional TOTP key enrolled successfully.';
                    $session->set('flash_messages', [$msg]);
                    return new RedirectResponse('/admin');
                } else {
                    $errors[] = 'Invalid code — please wait for the next 30-second window and try again.';
                }
            }
        }

        // GET or failed POST: generate or restore in-progress secret
        $enrollSecret = $session->get('totp_enrolling_secret');
        if (!is_string($enrollSecret) || $enrollSecret === '') {
            $data         = $this->totp->generate('LexNova Admin', (string) $user['username']);
            $enrollSecret = $data['secret'];
            $session->set('totp_enrolling_secret', $enrollSecret);
            $uri = $data['uri'];
        } else {
            $uri = $this->totp->getProvisioningUri(
                $enrollSecret,
                'LexNova Admin',
                (string) $user['username'],
            );
        }

        return new HtmlResponse($this->renderer->render('admin/totp_enroll', [
            'errors'           => $errors,
            'csrf_token'       => $guard->generateToken(),
            'qr_svg'           => $this->buildQrSvg($uri),
            'secret'           => $enrollSecret,
            'uri'              => $uri,
            'existing_key_count' => $existingKeyCount,
        ]));
    }

    private function buildQrSvg(string $uri): string
    {
        $result = Builder::create()
            ->writer(new SvgWriter())
            ->data($uri)
            ->errorCorrectionLevel(ErrorCorrectionLevel::High)
            ->size(250)
            ->margin(10)
            ->build();

        // Strip XML declaration so the SVG embeds cleanly in HTML
        return (string) preg_replace('/^<\?xml[^>]*\?>\s*/i', '', $result->getString());
    }
}
