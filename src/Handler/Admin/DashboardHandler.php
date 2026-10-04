<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\HtmlResponse;
use LexNova\Frontend\SveltePageRenderer;
use LexNova\Service\AuditService;
use LexNova\Service\AuthenticationPolicyService;
use LexNova\Service\DocumentService;
use LexNova\Service\EntityService;
use LexNova\Service\Fail2BanLogService;
use LexNova\Service\PasskeyService;
use LexNova\Service\PasswordService;
use LexNova\Service\SystemSettingService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class DashboardHandler implements RequestHandlerInterface
{
    /** @param array<string, mixed> $generatorConfig */
    public function __construct(
        private readonly UserService $users,
        private readonly EntityService $entities,
        private readonly DocumentService $documents,
        private readonly PasswordService $passwords,
        private readonly PasskeyService $passkeys,
        private readonly AuditService $audit,
        private readonly SveltePageRenderer $renderer,
        private readonly Fail2BanLogService $fail2ban,
        private readonly SystemSettingService $settings,
        private readonly AuthenticationPolicyService $authPolicy,
        private readonly int $webauthnLimit = 10,
        private readonly int $totpLimit = 5,
        private readonly array $generatorConfig = [],
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        $errors = $session->get('flash_errors', []);
        $messages = $session->get('flash_messages', []);
        $session->unset('flash_errors');
        $session->unset('flash_messages');

        $editId = isset($request->getQueryParams()['doc_id'])
            ? (int) $request->getQueryParams()['doc_id']
            : null;
        $editDoc = $editId !== null ? $this->documents->findById($editId) : null;

        $editEntityId = isset($request->getQueryParams()['entity_id'])
            ? (int) $request->getQueryParams()['entity_id']
            : null;
        $editEntity = $editEntityId !== null ? $this->entities->findById($editEntityId) : null;

        $users = $this->users->list();

        // Load all TOTP keys per user (N+1 is acceptable — admin tool, few users).
        $totpKeys = [];
        $passkeys = [];
        foreach ($users as $u) {
            $totpKeys[(int) $u['id']] = $this->users->getTotpKeys((int) $u['id']);
            $passkeys[(int) $u['id']] = $this->passkeys->listForUser((int) $u['id']);
        }

        $path = $request->getUri()->getPath();
        $section = match ($path) {
            '/verwaltung/entities' => 'entities',
            '/verwaltung/documents' => 'documents',
            default => 'overview',
        };
        $page = match ($path) {
            '/user/security' => 'account-security',
            '/admin/users' => 'admin-users',
            '/admin/security' => 'admin-security',
            '/admin/audit' => 'admin-audit',
            '/admin', '/admin/' => 'admin-overview',
            default => 'workspace',
        };
        $currentUserId = (int) $session->get('user_id');
        $currentPasskeys = $page === 'account-security' ? ($passkeys[$currentUserId] ?? []) : [];
        $currentTotpKeys = $page === 'account-security' ? ($totpKeys[$currentUserId] ?? []) : [];
        $currentTotpCredentialCount = 0;
        if ($page === 'account-security') {
            foreach ($currentPasskeys as &$passkey) {
                $decision = $this->authPolicy->credentialRemovalPolicy($currentUserId, 'webauthn', (int) $passkey['id']);
                $passkey['removal_allowed'] = $decision['allowed']
                    && $this->authPolicy->hasValidAuthenticationPathAfterRemoval($currentUserId, 'webauthn', (int) $passkey['id']);
                $passkey['removal_warning'] = $decision['warning'];
                $passkey['removal_methods'] = $decision['allowed_methods'];
                $passkey['removal_reason'] = $passkey['removal_allowed']
                    ? null
                    : ($decision['reason'] ?: 'Removing this credential would leave no valid sign-in path. Use the authorized admin or recovery path.');
            }
            unset($passkey);
            foreach ($currentTotpKeys as &$key) {
                $decision = $this->authPolicy->credentialRemovalPolicy($currentUserId, 'totp', (int) $key['id']);
                $key['removal_allowed'] = $decision['allowed']
                    && $this->authPolicy->hasValidAuthenticationPathAfterRemoval($currentUserId, 'totp', (int) $key['id']);
                $key['removal_warning'] = $decision['warning'];
                $key['removal_methods'] = $decision['allowed_methods'];
                $key['removal_reason'] = $key['removal_allowed']
                    ? null
                    : ($decision['reason'] ?: 'Removing this credential would leave no valid sign-in path. Use the authorized admin or recovery path.');
            }
            unset($key);
            $currentTotpCredentialCount = $this->authPolicy->activeTotpCredentialCount($currentUserId);
        }

        return new HtmlResponse($this->renderer->render($page, [
            'users' => $users,
            'totpKeys' => $totpKeys,
            'passkeys' => $passkeys,
            'entities' => $this->entities->list(),
            'documents' => $this->documents->list(),
            'editDocument' => $editDoc,
            'editEntity' => $editEntity,
            'csrfToken' => $guard->generateToken(),
            'passwordMin' => $this->passwords->getMinLength(),
            'passwordMax' => $this->passwords->getMaxLength(),
            'passwordGenerator' => $this->generatorConfig,
            'errors' => $errors,
            'messages' => $messages,
            'currentUserId' => $currentUserId,
            'authSetupRequired' => $session->get('auth_setup_required') === true,
            'currentPasskeys' => $currentPasskeys,
            'currentTotpKeys' => $currentTotpKeys,
            'currentTotpCredentialCount' => $currentTotpCredentialCount,
            'auditLog' => $this->audit->recent(50),
            'fail2ban' => $this->fail2ban->status(),
            'authLimits' => [
                'webauthn' => $this->settings->int('auth.limit.webauthn', $this->webauthnLimit, 100, 1)['value'],
                'totp' => $this->settings->int('auth.limit.totp', $this->totpLimit, 100, 1)['value'],
            ],
            'section' => $section,
        ], $this->title($page)));
    }

    private function title(string $page): string
    {
        return match ($page) {
            'workspace' => 'Verwaltung · LexNova',
            'account-security' => 'Anmeldesicherheit · LexNova',
            'admin-users' => 'Benutzerkonten · LexNova',
            'admin-security' => 'Instanzsicherheit · LexNova',
            'admin-audit' => 'Audit-Protokoll · LexNova',
            default => 'Administration · LexNova',
        };
    }
}
