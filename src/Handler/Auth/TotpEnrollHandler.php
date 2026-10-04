<?php

declare(strict_types=1);

namespace LexNova\Handler\Auth;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Frontend\SveltePageRenderer;
use LexNova\InputFilter\TotpEnrollmentInputFilter;
use LexNova\Service\AuditService;
use LexNova\Service\AuthSessionService;
use LexNova\Service\CredentialLimitService;
use LexNova\Service\StepUpService;
use LexNova\Service\TotpService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
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
        private readonly TotpService $totp,
        private readonly UserService $users,
        private readonly SveltePageRenderer $renderer,
        private readonly CredentialLimitService $credentialLimits,
        private readonly StepUpService $stepUp,
        private readonly AuthSessionService $sessions,
        private readonly AuditService $audit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);
        $userId = (int) $session->get('user_id');
        $user = $this->users->findById($userId);

        if ($user === null) {
            return new RedirectResponse('/user/security');
        }

        $existingKeyCount = $this->users->countTotpKeys($userId);
        $limit = $this->credentialLimits->limit('totp');
        $requiresStepUp = $this->users->mfaRequired($userId)
            || $this->users->hasPasskey($userId)
            || $this->users->hasActiveTotpKey($userId);

        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $errors = [];

        if ($request->getMethod() === 'POST') {
            $body = (array) ($request->getParsedBody() ?? []);

            if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
                $errors[] = 'Invalid session token.';
            } else {
                $input = new TotpEnrollmentInputFilter();
                $body['label'] = isset($body['label']) && $body['label'] !== '' ? $body['label'] : 'Default';
                $input->setData($body);
                $validInput = $input->isValid();
                $values = $input->getValues();
                $code = $values['code'] ?? '';
                $enrollSecret = (string) ($session->get('totp_enrolling_secret') ?? '');
                $label = $values['label'] ?? 'Default';

                if (!$validInput) {
                    $errors = $input->getErrorMessages();
                } elseif ($existingKeyCount >= $limit) {
                    $errors[] = 'The TOTP credential limit has been reached. Remove an existing key before adding another.';
                } elseif ($enrollSecret === '' || time() - (int) ($session->get('totp_enrolling_created_at') ?? 0) > 600) {
                    $session->unset('totp_enrolling_secret');
                    $session->unset('totp_enrolling_created_at');
                    $errors[] = 'Enrollment session expired. Please reload the page.';
                } elseif ($this->totp->verifyPlain($enrollSecret, $code)) {
                    $this->credentialLimits->assertCanAdd('totp', $this->users->countTotpKeys($userId));
                    if ($requiresStepUp && !$this->stepUp->consume($session, 'auth.totp.add', 'user:' . $userId)) {
                        $errors[] = 'Verify an existing authenticator before adding TOTP.';
                    } else {
                        $encrypted = $this->totp->encrypt($enrollSecret);
                        $keyId = $this->users->addTotpKey($userId, $encrypted, $label);
                        $session->unset('totp_enrolling_secret');
                        $session->unset('totp_enrolling_created_at');
                        $session->set('auth_setup_required', false);
                        $this->sessions->revokeOtherSessions($userId, (int) $session->get('auth_session_id'));
                        $this->audit->log(
                            $userId,
                            (string) $user['username'],
                            'auth.totp_enrolled',
                            'user:' . $userId,
                            'totp:' . $keyId,
                            (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''),
                        );
                        $msg = $existingKeyCount === 0
                            ? 'TOTP two-factor authentication has been enabled.'
                            : 'Additional TOTP key enrolled successfully.';
                        $session->set('flash_messages', [$msg]);

                        return new RedirectResponse('/user/security');
                    }
                } else {
                    $errors[] = 'Invalid code — please wait for the next 30-second window and try again.';
                }
            }
        }

        // GET or failed POST: generate or restore in-progress secret
        $enrollSecret = $session->get('totp_enrolling_secret');
        if (is_string($enrollSecret)
            && time() - (int) ($session->get('totp_enrolling_created_at') ?? 0) > 600
        ) {
            $session->unset('totp_enrolling_secret');
            $session->unset('totp_enrolling_created_at');
            $enrollSecret = null;
        }
        if ($existingKeyCount >= $limit) {
            $errors[] = 'The TOTP credential limit has been reached. Remove an existing key before adding another.';
        }
        if (!is_string($enrollSecret) || $enrollSecret === '') {
            if ($existingKeyCount >= $limit) {
                return new HtmlResponse($this->renderer->render('totp-enroll', [
                    'errors' => $errors,
                    'csrfToken' => $guard->generateToken(),
                    'existingKeyCount' => $existingKeyCount,
                    'totpLimit' => $limit,
                    'limitReached' => true,
                    'requiresStepUp' => $requiresStepUp,
                    'currentUserId' => $userId,
                    'secret' => '',
                    'uri' => '',
                    'qrSvg' => '',
                ], 'TOTP einrichten · LexNova'));
            }
            $data = $this->totp->generate('LexNova Admin', (string) $user['username']);
            $enrollSecret = $data['secret'];
            $session->set('totp_enrolling_secret', $enrollSecret);
            $session->set('totp_enrolling_created_at', time());
            $uri = $data['uri'];
        } else {
            $uri = $this->totp->getProvisioningUri(
                $enrollSecret,
                'LexNova Admin',
                (string) $user['username'],
            );
        }

        return new HtmlResponse($this->renderer->render('totp-enroll', [
            'errors' => $errors,
            'csrfToken' => $guard->generateToken(),
            'qrSvg' => $this->buildQrSvg($uri),
            'secret' => $enrollSecret,
            'uri' => $uri,
            'existingKeyCount' => $existingKeyCount,
            'totpLimit' => $limit,
            'limitReached' => $existingKeyCount >= $limit,
            'requiresStepUp' => $requiresStepUp,
            'currentUserId' => $userId,
        ], 'TOTP einrichten · LexNova'));
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
