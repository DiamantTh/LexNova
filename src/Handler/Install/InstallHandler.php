<?php

declare(strict_types=1);

namespace LexNova\Handler\Install;

use Laminas\Diactoros\Response\HtmlResponse;
use LexNova\Handler\Install\Step\ConfigureStep;
use LexNova\Handler\Install\Step\InitStep;
use LexNova\Handler\Install\Step\PrerequisiteCheck;
use LexNova\Handler\Install\Step\UnlockStep;
use LexNova\Service\InstallService;
use LexNova\Service\PasswordService;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

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
        private readonly InstallService            $install,
        private readonly PasswordService           $passwords,
        private readonly TemplateRendererInterface $renderer,
        private readonly array                     $config,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Already installed — render the "done" step so the user gets a helpful
        // message instead of a hard 404.
        if ($this->install->isLocked()) {
            return new HtmlResponse($this->renderer->render('install/index', [
                'step'              => 'done',
                'errors'            => [],
                'messages'          => [],
                'generatedPassword' => null,
                'installReady'      => true,
                'formData'          => [],
            ]));
        }

        $security = $this->config['security']['password'] ?? [];
        // dirname(__DIR__, 3): src/Handler/Install → src/Handler → src → project root
        $root     = dirname(__DIR__, 3);

        // ── Prerequisites ─────────────────────────────────────────────────
        $prereq = (new PrerequisiteCheck($root))->run();

        // ── Step: Init ────────────────────────────────────────────────────
        $init              = (new InitStep())->handle($this->install, $security);
        $errors            = $init['errors'];
        $messages          = $init['messages'];
        $generatedPassword = $init['generatedPassword'];
        $installReady      = $init['installReady'];
        $installerUnlocked = false;
        $formData          = [];

        if ($request->getMethod() === 'POST' && $installReady) {
            $body   = (array) ($request->getParsedBody() ?? []);
            $action = trim((string) ($body['action'] ?? ''));

            $defaultDbPath = $root . '/data/lexnova.sqlite';

            $formData = [
                'dbType'        => trim((string) ($body['db_type']                ?? 'sqlite')),
                'dbHost'        => trim((string) ($body['db_host']                ?? 'localhost')),
                'dbName'        => trim((string) ($body['db_name']                ?? '')),
                'dbPort'        => trim((string) ($body['db_port']                ?? '')),
                'dbPath'        => trim((string) ($body['db_path']                ?? $defaultDbPath)),
                'dbUser'        => trim((string) ($body['db_user']                ?? '')),
                'dbPassword'    => (string)      ($body['db_password']            ?? ''),
                'adminUsername' => trim((string) ($body['admin_username']         ?? '')),
                'adminPassword' => (string)      ($body['admin_password']         ?? ''),
                'adminConfirm'  => (string)      ($body['admin_password_confirm'] ?? ''),
                'appLocale'     => trim((string) ($body['app_locale']             ?? 'de')),
            ];

            // ── Step: Unlock ──────────────────────────────────────────────
            $unlock            = (new UnlockStep())->handle($this->install, (string) ($body['install_pw'] ?? ''));
            $errors            = array_merge($errors, $unlock['errors']);
            $installerUnlocked = $unlock['installerUnlocked'];

            // ── Step: Configure ───────────────────────────────────────────
            if ($action === 'install' && $installerUnlocked) {
                $configure = (new ConfigureStep())->handle(
                    $this->install,
                    $this->passwords,
                    $formData,
                    $security,
                    $root,
                );

                if ($configure['completed']) {
                    return new HtmlResponse($this->renderer->render('install/index', [
                        'step'              => 'done',
                        'errors'            => [],
                        'messages'          => [
                            'Installation complete. You can now log in at /admin.',
                            'Remove install/install.pw after verifying access.',
                        ],
                        'generatedPassword' => null,
                        'installReady'      => true,
                        'formData'          => [],
                    ]));
                }

                $errors = array_merge($errors, $configure['errors']);
            }
        }

        $step = $installerUnlocked ? 'configure' : 'unlock';

        return new HtmlResponse($this->renderer->render('install/index', [
            'step'              => $step,
            'errors'            => $errors,
            'messages'          => $messages,
            'generatedPassword' => $generatedPassword,
            'installReady'      => $installReady,
            'formData'          => $formData,
            'prereq'            => $prereq,
        ]));
    }
}
