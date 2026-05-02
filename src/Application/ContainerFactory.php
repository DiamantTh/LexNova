<?php

declare(strict_types=1);

namespace LexNova\Application;

use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Laminas\I18n\Translator\Translator;
use LexNova\Clock\SystemClock;
use LexNova\Factory\DoctrineConnectionFactory;
use LexNova\Factory\LoggerFactory;
use LexNova\Handler\Admin\LoginHandler;
use LexNova\Handler\Admin\TotpKeyDeleteHandler;
use LexNova\Handler\Admin\TotpResetHandler;
use LexNova\Handler\Auth\TotpEnrollHandler;
use LexNova\Handler\Auth\TotpVerifyHandler;
use LexNova\Middleware\AdminAuthMiddleware;
use LexNova\Middleware\InstalledCheckMiddleware;
use LexNova\Service\AuditService;
use LexNova\Service\DocumentService;
use LexNova\Service\EntityService;
use LexNova\Service\InstallService;
use LexNova\Service\Password\BreachedPasswordCheckerInterface;
use LexNova\Service\Password\DicewareGenerator;
use LexNova\Service\Password\HibpRangePasswordChecker;
use LexNova\Service\Password\NullBreachedPasswordChecker;
use LexNova\Service\Password\RandomPasswordGenerator;
use LexNova\Service\PasswordService;
use LexNova\Service\RateLimitService;
use LexNova\Service\TotpService;
use LexNova\Service\UserService;
use LexNova\Twig\EmailExtension;
use LexNova\Twig\TranslationExtension;
use Mezzio\Application;
use Mezzio\ApplicationPipeline;
use Mezzio\Container\ApplicationFactory;
use Mezzio\Container\MiddlewareContainerFactory;
use Mezzio\Container\MiddlewareFactoryFactory;
use Mezzio\Container\RequestHandlerRunnerFactory;
use Mezzio\Csrf\CsrfGuardFactoryInterface;
use Mezzio\Csrf\SessionCsrfGuardFactory;
use Mezzio\MiddlewareContainer;
use Mezzio\MiddlewareFactory;
use Mezzio\Response\ServerRequestErrorResponseGenerator;
use Mezzio\Router\FastRoute\FastRouteRouter;
use Mezzio\Router\RouteCollector;
use Mezzio\Router\RouterInterface;
use Mezzio\Session\Ext\PhpSessionPersistence;
use Mezzio\Session\SessionPersistenceInterface;
use Mezzio\Template\TemplateRendererInterface;
use Mezzio\Twig\TwigEnvironment;
use Mezzio\Twig\TwigEnvironmentFactory;
use Mezzio\Twig\TwigRenderer;
use Mezzio\Twig\TwigRendererFactory;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class ContainerFactory
{
    public static function create(): ContainerInterface
    {
        // src/Application/ContainerFactory.php → dirname 3 levels up = project root
        $root = dirname(__FILE__, 3);

        // ── Config loading: config.toml when installed, empty array before ───────
        $configToml = $root . '/configs/config.toml';
        $config = is_file($configToml)
            ? toml_decode((string) file_get_contents($configToml), asArray: true)
            : [];

        // Security config ships in the repository and is always loaded separately.
        // Merge: config.toml [security] (user settings like totp_app_key) wins first,
        // then security.toml values are overlaid (repo-managed policy takes precedence).
        $securityToml = $root . '/configs/security.toml';
        if (is_file($securityToml)) {
            $repoSecurity = toml_decode((string) file_get_contents($securityToml), asArray: true);
            $config['security'] = array_replace_recursive(
                $config['security'] ?? [],
                $repoSecurity,
            );
        }

        // ── Runtime path defaults ─────────────────────────────────────────────────
        // Applied when no config.toml exists yet (pre-install) or when a value is absent.
        // ConfigureStep writes these with the correct absolute paths; the defaults here
        // only take effect during the installation wizard itself.
        $config['install']['lock'] ??= $root . '/data/install.lock';
        $config['install']['password_file'] ??= $root . '/data/install.pw';
        $config['install']['config_file'] ??= $root . '/configs/config.toml';
        $config['log']['path'] ??= $root . '/logs/lexnova.log';
        $config['log']['level'] ??= 'warning';
        $config['session']['name'] ??= 'lexnova_session';
        $config['session']['secure'] ??= false;
        $config['session']['httponly'] ??= true;
        $config['session']['samesite'] ??= 'Lax';
        $config['app']['locale'] ??= 'de';

        // ── Ensure runtime directories exist ─────────────────────────────────────
        foreach ([$root . '/cache/twig', $root . '/cache/app', $root . '/logs'] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // ── Framework config ──────────────────────────────────────────────────────
        // twig.cache can be set to false in config.toml to disable template caching
        $twigCache = (bool) ($config['twig']['cache'] ?? true);

        $config['templates'] = [
            'extension' => 'html.twig',
            'paths' => [
                $root . '/templates',
                'error' => $root . '/templates/error',
            ],
        ];
        $config['twig'] = [
            'cache_dir' => $twigCache ? $root . '/cache/twig' : false,
            'debug' => false,
            'auto_reload' => true,
            'timezone' => 'UTC',
            'globals' => ['twig_cache_enabled' => $twigCache],
            'extensions' => [
                EmailExtension::class,
                TranslationExtension::class,
            ],
        ];

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        $builder->addDefinitions([
            'config' => $config,

            // ── PSR-7 factory ───────────────────────────────────────────────────────
            ResponseFactoryInterface::class => fn () => new \Laminas\Diactoros\ResponseFactory(),

            // ── Mezzio plumbing ─────────────────────────────────────────────────────
            RouterInterface::class => fn () => new FastRouteRouter(),

            RouteCollector::class => fn (ContainerInterface $c) => new RouteCollector($c->get(RouterInterface::class)),

            MiddlewareContainer::class => fn (ContainerInterface $c) => (new MiddlewareContainerFactory())($c),

            MiddlewareFactory::class => fn (ContainerInterface $c) => (new MiddlewareFactoryFactory())($c),

            ApplicationPipeline::class => fn () => new ApplicationPipeline(),

            RequestHandlerRunner::class => fn (ContainerInterface $c) => (new RequestHandlerRunnerFactory())($c),

            Application::class => fn (ContainerInterface $c) => (new ApplicationFactory())($c),

            // ── Twig ────────────────────────────────────────────────────────────────
            TwigEnvironment::class => fn (ContainerInterface $c) => (new TwigEnvironmentFactory())($c),

            TwigRenderer::class => fn (ContainerInterface $c) => (new TwigRendererFactory())($c),

            TemplateRendererInterface::class => fn (ContainerInterface $c) => $c->get(TwigRenderer::class),

            // ── Session & CSRF ───────────────────────────────────────────────────────
            SessionPersistenceInterface::class => fn () => new PhpSessionPersistence(),

            CsrfGuardFactoryInterface::class => fn () => new SessionCsrfGuardFactory(),

            // ── Infrastructure ──────────────────────────────────────────────────────
            // PHP 8.4 native lazy proxy: Connection is only established on first use.
            Connection::class => fn (ContainerInterface $c) => (new \ReflectionClass(Connection::class))->newLazyProxy(
                fn (Connection $proxy): Connection => (new DoctrineConnectionFactory())($c),
            ),

            LoggerInterface::class => fn (ContainerInterface $c) => (new LoggerFactory())($c),

            // ── Clock ────────────────────────────────────────────────────────────────
            ClockInterface::class => fn () => new SystemClock(),

            // ── Twig extensions ──────────────────────────────────────────────────────
            Translator::class => function (ContainerInterface $c) use ($root): Translator {
                $locale = str_replace('-', '_', (string) ($c->get('config')['app']['locale'] ?? 'de'));
                $translator = new Translator();
                $translator->setLocale($locale);
                $translator->setFallbackLocale('en');
                $translator->addTranslationFilePattern(
                    'phparray',
                    $root . '/resources/translations',
                    '%s.php',
                );

                return $translator;
            },

            TranslationExtension::class => fn (ContainerInterface $c) => new TranslationExtension(
                $c->get(Translator::class),
                (string) ($c->get('config')['app']['locale'] ?? 'de'),
            ),

            EmailExtension::class => fn (ContainerInterface $c) => new EmailExtension(
                $c->get(ClockInterface::class),
                (array) ($c->get('config')['security']['email_subject'] ?? []),
            ),

            // ── Application services ────────────────────────────────────────────────
            PasswordService::class => fn (ContainerInterface $c) => new PasswordService(
                $c->get('config'),
                $c->get(BreachedPasswordCheckerInterface::class),
            ),

            // ── Cache ────────────────────────────────────────────────────────────────
            // PSR-16 filesystem cache (symfony/cache) – used by DocumentService
            // to cache public document lookups for 1 hour; invalidated on write.
            CacheInterface::class => fn () => new Psr16Cache(new FilesystemAdapter('lexnova', 3600, $root . '/cache/app')),

            // PSR-16 cache dedicated to HIBP range lookups (24 h TTL handled by service).
            'cache.hibp' => fn () => new Psr16Cache(new FilesystemAdapter('hibp', 86400, $root . '/cache/hibp')),

            // ── Breached-password checker (HIBP, optional) ──────────────────────────
            BreachedPasswordCheckerInterface::class => function (ContainerInterface $c): BreachedPasswordCheckerInterface {
                $hibp = $c->get('config')['security']['password_policy']['hibp'] ?? [];
                if (!(bool) ($hibp['enabled'] ?? false)) {
                    return new NullBreachedPasswordChecker();
                }

                return new HibpRangePasswordChecker(
                    cache: $c->get('cache.hibp'),
                    logger: $c->get(LoggerInterface::class),
                    failOpen: (bool) ($hibp['fail_open'] ?? true),
                    timeoutMs: max(100, (int) ($hibp['timeout_ms'] ?? 1500)),
                    endpoint: (string) ($hibp['endpoint'] ?? HibpRangePasswordChecker::DEFAULT_ENDPOINT),
                );
            },

            // ── Password generators ─────────────────────────────────────────────────
            DicewareGenerator::class => fn (ContainerInterface $c) => new DicewareGenerator(
                wordCount: (int) ($c->get('config')['security']['generator']['diceware']['word_count'] ?? 6),
                separator: (string) ($c->get('config')['security']['generator']['diceware']['separator'] ?? '-'),
                wordlistPath: $root . '/resources/eff_large_wordlist.php',
            ),

            RandomPasswordGenerator::class => fn (ContainerInterface $c) => new RandomPasswordGenerator(
                length: (int) ($c->get('config')['security']['generator']['random']['length'] ?? 20),
                requireUpper: (bool) ($c->get('config')['security']['generator']['random']['require_upper'] ?? true),
                requireDigits: (bool) ($c->get('config')['security']['generator']['random']['require_digits'] ?? true),
                requireSymbols: (bool) ($c->get('config')['security']['generator']['random']['require_symbols'] ?? true),
            ),

            UserService::class => fn (ContainerInterface $c) => new UserService($c->get(Connection::class), $c->get(PasswordService::class)),

            EntityService::class => fn (ContainerInterface $c) => new EntityService($c->get(Connection::class)),

            DocumentService::class => fn (ContainerInterface $c) => new DocumentService($c->get(Connection::class), $c->get(CacheInterface::class)),

            TotpService::class => fn (ContainerInterface $c) => new TotpService(
                appKey: (string) ($c->get('config')['security']['totp_app_key'] ?? ''),
                digits: (int) ($c->get('config')['security']['totp']['digits'] ?? 8),
                algorithm: (string) ($c->get('config')['security']['totp']['algorithm'] ?? 'sha256'),
                period: (int) ($c->get('config')['security']['totp']['period'] ?? 30),
                window: (int) ($c->get('config')['security']['totp']['window'] ?? 1),
            ),

            InstallService::class => fn (ContainerInterface $c) => new InstallService($c->get('config')),

            RateLimitService::class => fn (ContainerInterface $c) => new RateLimitService(
                $c->get(Connection::class),
                maxAttempts: (int) ($c->get('config')['security']['rate_limit']['max_attempts'] ?? 5),
                blockSeconds: (int) ($c->get('config')['security']['rate_limit']['block_seconds'] ?? 300),
            ),

            AuditService::class => fn (ContainerInterface $c) => new AuditService($c->get(Connection::class)),

            // ── Handlers: Install ───────────────────────────────────────────────────
            \LexNova\Handler\Install\InstallHandler::class => fn (ContainerInterface $c) => new \LexNova\Handler\Install\InstallHandler(
                $c->get(InstallService::class),
                $c->get(PasswordService::class),
                $c->get(TemplateRendererInterface::class),
                $c->get('config'),
            ),

            // ── Handlers: Admin (Login) ─────────────────────────────────────────────
            LoginHandler::class => fn (ContainerInterface $c) => new LoginHandler(
                $c->get(UserService::class),
                $c->get(RateLimitService::class),
                $c->get(AuditService::class),
                $c->get(TemplateRendererInterface::class),
            ),

            \LexNova\Handler\Admin\DashboardHandler::class => fn (ContainerInterface $c) => new \LexNova\Handler\Admin\DashboardHandler(
                $c->get(UserService::class),
                $c->get(EntityService::class),
                $c->get(DocumentService::class),
                $c->get(PasswordService::class),
                $c->get(AuditService::class),
                $c->get(TemplateRendererInterface::class),
                (array) ($c->get('config')['security']['generator'] ?? []),
            ),

            // ── Handlers: Auth (TOTP) ────────────────────────────────────────────────
            TotpVerifyHandler::class => fn (ContainerInterface $c) => new TotpVerifyHandler(
                $c->get(TotpService::class),
                $c->get(UserService::class),
                $c->get(RateLimitService::class),
                $c->get(AuditService::class),
                $c->get(TemplateRendererInterface::class),
            ),

            TotpEnrollHandler::class => fn (ContainerInterface $c) => new TotpEnrollHandler(
                $c->get(TotpService::class),
                $c->get(UserService::class),
                $c->get(TemplateRendererInterface::class),
            ),

            TotpResetHandler::class => fn (ContainerInterface $c) => new TotpResetHandler(
                $c->get(UserService::class),
                $c->get(AuditService::class),
            ),

            TotpKeyDeleteHandler::class => fn (ContainerInterface $c) => new TotpKeyDeleteHandler(
                $c->get(UserService::class),
                $c->get(AuditService::class),
            ),

            \LexNova\Handler\Admin\UserDeleteHandler::class => fn (ContainerInterface $c) => new \LexNova\Handler\Admin\UserDeleteHandler(
                $c->get(UserService::class),
                $c->get(AuditService::class),
            ),

            \LexNova\Handler\Admin\UserCreateHandler::class => fn (ContainerInterface $c) => new \LexNova\Handler\Admin\UserCreateHandler(
                $c->get(UserService::class),
                $c->get(PasswordService::class),
                $c->get(AuditService::class),
            ),

            \LexNova\Handler\Admin\UserUpdateHandler::class => fn (ContainerInterface $c) => new \LexNova\Handler\Admin\UserUpdateHandler(
                $c->get(UserService::class),
                $c->get(PasswordService::class),
                $c->get(AuditService::class),
            ),

            \LexNova\Handler\Admin\EntityDeleteHandler::class => fn (ContainerInterface $c) => new \LexNova\Handler\Admin\EntityDeleteHandler(
                $c->get(EntityService::class),
                $c->get(AuditService::class),
            ),

            \LexNova\Handler\Admin\EntityUpdateHandler::class => fn (ContainerInterface $c) => new \LexNova\Handler\Admin\EntityUpdateHandler(
                $c->get(EntityService::class),
                $c->get(AuditService::class),
            ),

            \LexNova\Handler\Admin\EntityCreateHandler::class => fn (ContainerInterface $c) => new \LexNova\Handler\Admin\EntityCreateHandler(
                $c->get(EntityService::class),
                $c->get(AuditService::class),
            ),

            \LexNova\Handler\Admin\DocumentDeleteHandler::class => fn (ContainerInterface $c) => new \LexNova\Handler\Admin\DocumentDeleteHandler(
                $c->get(DocumentService::class),
                $c->get(AuditService::class),
            ),

            \LexNova\Handler\Admin\DocumentCreateHandler::class => fn (ContainerInterface $c) => new \LexNova\Handler\Admin\DocumentCreateHandler(
                $c->get(DocumentService::class),
                $c->get(EntityService::class),
                $c->get(AuditService::class),
            ),

            \LexNova\Handler\Admin\DocumentUpdateHandler::class => fn (ContainerInterface $c) => new \LexNova\Handler\Admin\DocumentUpdateHandler(
                $c->get(DocumentService::class),
                $c->get(EntityService::class),
                $c->get(AuditService::class),
            ),

            // ── Middleware ───────────────────────────────────────────────────────────
            AdminAuthMiddleware::class => fn (ContainerInterface $c) => new AdminAuthMiddleware(
                $c->get(ResponseFactoryInterface::class),
            ),

            InstalledCheckMiddleware::class => fn (ContainerInterface $c) => new InstalledCheckMiddleware(
                $c->get(InstallService::class),
            ),

            // ── Error handling ───────────────────────────────────────────────────────
            // Replace Mezzio's default plain-text 404/500 responses with styled templates.
            \Mezzio\Handler\NotFoundHandler::class => fn (ContainerInterface $c) => new \Mezzio\Handler\NotFoundHandler(
                $c->get(ResponseFactoryInterface::class),
                $c->get(TemplateRendererInterface::class),
                'error::404',
            ),

            ServerRequestErrorResponseGenerator::class => fn (ContainerInterface $c) => new ServerRequestErrorResponseGenerator(
                $c->get(ResponseFactoryInterface::class),
                false,
                $c->get(TemplateRendererInterface::class),
                'error::500',
            ),

            // ── Console commands ─────────────────────────────────────────────────────
            \LexNova\Console\UserCreateCommand::class => fn (ContainerInterface $c) => new \LexNova\Console\UserCreateCommand(
                $c->get(UserService::class),
                $c->get(PasswordService::class),
                $c->get(DicewareGenerator::class),
                $c->get(RandomPasswordGenerator::class),
            ),

            \LexNova\Console\UserSetPasswordCommand::class => fn (ContainerInterface $c) => new \LexNova\Console\UserSetPasswordCommand(
                $c->get(UserService::class),
                $c->get(PasswordService::class),
                $c->get(DicewareGenerator::class),
                $c->get(RandomPasswordGenerator::class),
            ),

            \LexNova\Console\UserTotpResetCommand::class => fn (ContainerInterface $c) => new \LexNova\Console\UserTotpResetCommand(
                $c->get(UserService::class),
            ),

            \LexNova\Console\UserDeleteCommand::class => fn (ContainerInterface $c) => new \LexNova\Console\UserDeleteCommand(
                $c->get(UserService::class),
            ),

            \LexNova\Console\EntityListCommand::class => fn (ContainerInterface $c) => new \LexNova\Console\EntityListCommand(
                $c->get(EntityService::class),
            ),
        ]);

        return $builder->build();
    }
}
