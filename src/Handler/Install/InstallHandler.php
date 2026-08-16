<?php

declare(strict_types=1);

namespace LexNova\Handler\Install;

use Laminas\Diactoros\Response\HtmlResponse;
use LexNova\Frontend\SveltePageRenderer;
use LexNova\Handler\Install\Step\ConfigureStep;
use LexNova\Handler\Install\Step\InitStep;
use LexNova\Handler\Install\Step\PrerequisiteCheck;
use LexNova\Handler\Install\Step\UnlockStep;
use LexNova\Service\Fail2BanLogService;
use LexNova\Service\InstallRateLimitService;
use LexNova\Service\InstallService;
use LexNova\Service\PasswordService;
use Mezzio\Csrf\CsrfMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Routes all /install requests through a three-step flow:
 *
 *   unlock    – visitor enters the one-time install password
 *   configure – DB connection + admin account + app locale
 *   done      – installer locked, login at /admin suggested
 *
 * Each step's logic lives in its own class under Step\, keeping this
 * handler as a thin orchestrator only.
 */
final readonly class InstallHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly InstallService $install,
        private readonly InstallRateLimitService $rateLimit,
        private readonly PasswordService $passwords,
        private readonly SveltePageRenderer $renderer,
        private readonly LoggerInterface $logger,
        private readonly Fail2BanLogService $fail2ban,
        private readonly PrerequisiteCheck $prerequisites,
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);

        // Already installed — render the "done" step so the user gets a helpful
        // message instead of a hard 404.
        if ($this->install->isLocked()) {
            return new HtmlResponse($this->renderer->render('install', [
                'step' => 'done',
                'errors' => [],
                'messages' => [],
                'generatedPassword' => null,
                'installReady' => true,
                'formData' => [],
                'cacheSupport' => $this->prerequisites->cacheAdapterSupport(),
                'csrfToken' => $guard->generateToken(),
            ], 'Installation · LexNova'));
        }

        $security = $this->config['security']['password'] ?? [];
        // dirname(__DIR__, 3): src/Handler/Install → src/Handler → src → project root
        $root = dirname(__DIR__, 3);

        // ── Prerequisites ─────────────────────────────────────────────────
        $prereq = $this->prerequisites->run();

        // ── Step: Init ────────────────────────────────────────────────────
        $init = (new InitStep())->handle($this->install, $security);
        $errors = $init['errors'];
        $messages = $init['messages'];
        $generatedPassword = $init['generatedPassword'];
        $installReady = $init['installReady'];
        $installerUnlocked = false;
        $formData = [];

        $csrfValid = true;
        if ($request->getMethod() === 'POST') {
            $submittedBody = (array) ($request->getParsedBody() ?? []);
            $csrfValid = $guard->validateToken((string) ($submittedBody['__csrf'] ?? ''));
            if (!$csrfValid) {
                $errors[] = 'Invalid session token.';
            }
        }

        if ($request->getMethod() === 'POST' && $prereq['blocked']) {
            $errors[] = 'Required system prerequisites are missing or incompatible.';
        }

        if ($request->getMethod() === 'POST' && $installReady && $csrfValid && !$prereq['blocked']) {
            $body = (array) ($request->getParsedBody() ?? []);
            $action = trim((string) ($body['action'] ?? ''));
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');

            $defaultDbPath = $root . '/data/lexnova.sqlite';

            $formData = [
                'dbType' => trim((string) ($body['db_type'] ?? 'sqlite')),
                'dbHost' => trim((string) ($body['db_host'] ?? 'localhost')),
                'dbName' => trim((string) ($body['db_name'] ?? '')),
                'dbPort' => trim((string) ($body['db_port'] ?? '')),
                'dbPath' => trim((string) ($body['db_path'] ?? $defaultDbPath)),
                'dbUser' => trim((string) ($body['db_user'] ?? '')),
                'dbPassword' => (string) ($body['db_password'] ?? ''),
                'adminUsername' => trim((string) ($body['admin_username'] ?? '')),
                'adminPassword' => (string) ($body['admin_password'] ?? ''),
                'adminConfirm' => (string) ($body['admin_password_confirm'] ?? ''),
                'appBaseUrl' => trim((string) ($body['app_base_url'] ?? '')),
                'appLocale' => trim((string) ($body['app_locale'] ?? 'de')),
                'operatorName' => trim((string) ($body['operator_name'] ?? '')),
                'operatorContact' => trim((string) ($body['operator_contact'] ?? '')),
                'cacheAdapter' => trim((string) ($body['cache_adapter'] ?? 'filesystem')),
                'cacheHost' => trim((string) ($body['cache_host'] ?? '127.0.0.1')),
                'cachePort' => trim((string) ($body['cache_port'] ?? '6379')),
                'cacheDatabase' => trim((string) ($body['cache_database'] ?? '0')),
                'cacheUsername' => trim((string) ($body['cache_username'] ?? '')),
                'cachePassword' => (string) ($body['cache_password'] ?? ''),
                'cacheTls' => isset($body['cache_tls']) ? '1' : '0',
            ];

            // ── Step: Unlock ──────────────────────────────────────────────
            if ($this->rateLimit->isBlocked($ip)) {
                $this->fail2ban->record($ip);
                $seconds = $this->rateLimit->secondsRemaining($ip);
                $errors[] = "Too many failed attempts. Try again in {$seconds} seconds.";
            } else {
                $unlock = (new UnlockStep())->handle($this->install, (string) ($body['install_pw'] ?? ''));
                $errors = array_merge($errors, $unlock['errors']);
                $installerUnlocked = $unlock['installerUnlocked'];
                if ($installerUnlocked) {
                    $this->rateLimit->recordSuccess($ip);
                } else {
                    $this->rateLimit->recordFailure($ip);
                    $this->fail2ban->record($ip);
                }
            }

            // ── Step: Configure ───────────────────────────────────────────
            if ($action === 'install' && $installerUnlocked) {
                $configure = (new ConfigureStep())->handle(
                    $this->install,
                    $this->passwords,
                    $formData,
                    $security,
                    $root,
                    $this->logger,
                );

                if ($configure['completed']) {
                    return new HtmlResponse($this->renderer->render('install', [
                        'step' => 'done',
                        'errors' => [],
                        'messages' => [
                            'Installation complete. You can now log in at /admin.',
                            'Remove data/install.pw after verifying access.',
                        ],
                        'generatedPassword' => null,
                        'installReady' => true,
                        'formData' => [],
                        'cacheSupport' => $this->prerequisites->cacheAdapterSupport(),
                        'operatorName' => $configure['operator_name'] ?? null,
                        'csrfToken' => $guard->generateToken(),
                    ], 'Installation abgeschlossen · LexNova'));
                }

                $errors = array_merge($errors, $configure['errors']);
            }
        }

        $step = $installerUnlocked ? 'configure' : 'unlock';

        return new HtmlResponse($this->renderer->render('install', [
            'step' => $step,
            'errors' => $errors,
            'messages' => $messages,
            'generatedPassword' => $generatedPassword,
            'installReady' => $installReady,
            'formData' => $formData,
            'prerequisites' => $prereq,
            'cacheSupport' => $this->prerequisites->cacheAdapterSupport(),
            'csrfToken' => $guard->generateToken(),
        ], 'Installation · LexNova'));
    }
}
