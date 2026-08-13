<?php

declare(strict_types=1);

namespace LexNova\Application;

use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Laminas\HttpHandlerRunner\Emitter\EmitterInterface;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface;
use Laminas\I18n\Translator\Translator;
use Laminas\Stratigility\Middleware\ErrorHandler;
use LexNova\Clock\SystemClock;
use LexNova\Factory\DoctrineConnectionFactory;
use LexNova\Factory\LoggerFactory;
use LexNova\Handler\Admin\Fail2BanSettingHandler;
use LexNova\Handler\Admin\LoginHandler;
use LexNova\Handler\Admin\TotpKeyDeleteHandler;
use LexNova\Handler\Admin\TotpResetHandler;
use LexNova\Handler\Auth\PasskeyLoginHandler;
use LexNova\Handler\Auth\PasskeyRegisterHandler;
use LexNova\Handler\Auth\TotpEnrollHandler;
use LexNova\Handler\Auth\TotpVerifyHandler;
use LexNova\Handler\Error\NotFoundHandler;
use LexNova\Middleware\AdminAuthMiddleware;
use LexNova\Middleware\InstalledCheckMiddleware;
use LexNova\Middleware\SecurityHeadersMiddleware;
use LexNova\Service\AuditService;
use LexNova\Service\DocumentService;
use LexNova\Service\EntityService;
use LexNova\Service\Fail2BanLogService;
use LexNova\Service\InstallRateLimitService;
use LexNova\Service\InstallService;
use LexNova\Service\PasskeyService;
use LexNova\Service\Password\BreachedPasswordCheckerInterface;
use LexNova\Service\Password\DicewareGenerator;
use LexNova\Service\Password\HibpRangePasswordChecker;
use LexNova\Service\Password\NullBreachedPasswordChecker;
use LexNova\Service\Password\RandomPasswordGenerator;
use LexNova\Service\PasswordService;
use LexNova\Service\RateLimitService;
use LexNova\Service\SystemSettingService;
use LexNova\Service\TotpService;
use LexNova\Service\UserService;
use LexNova\Twig\EmailExtension;
use LexNova\Twig\TranslationExtension;
use Mezzio\Application;
use Mezzio\Container\ApplicationFactory;
use Mezzio\Container\ApplicationPipelineFactory;
use Mezzio\Container\EmitterFactory;
use Mezzio\Container\ErrorHandlerFactory;
use Mezzio\Container\ErrorResponseGeneratorFactory;
use Mezzio\Container\MiddlewareContainerFactory;
use Mezzio\Container\MiddlewareFactoryFactory;
use Mezzio\Container\RequestHandlerRunnerFactory;
use Mezzio\Container\ServerRequestFactoryFactory;
use Mezzio\Csrf\CsrfGuardFactoryInterface;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Csrf\CsrfMiddlewareFactory;
use Mezzio\Csrf\SessionCsrfGuardFactory;
use Mezzio\Middleware\ErrorResponseGenerator;
use Mezzio\MiddlewareContainer;
use Mezzio\MiddlewareFactory;
use Mezzio\MiddlewareFactoryInterface;
use Mezzio\Response\ServerRequestErrorResponseGenerator;
use Mezzio\Router\FastRouteRouter;
use Mezzio\Router\Middleware\DispatchMiddleware;
use Mezzio\Router\Middleware\DispatchMiddlewareFactory;
use Mezzio\Router\Middleware\RouteMiddleware;
use Mezzio\Router\Middleware\RouteMiddlewareFactory;
use Mezzio\Router\RouteCollector;
use Mezzio\Router\RouteCollectorInterface;
use Mezzio\Router\RouterInterface;
use Mezzio\Session\Ext\PhpSessionPersistence;
use Mezzio\Session\SessionMiddleware;
use Mezzio\Session\SessionMiddlewareFactory;
use Mezzio\Session\SessionPersistenceInterface;
use Mezzio\Template\TemplateRendererInterface;
use Mezzio\Twig\TwigEnvironmentFactory;
use Mezzio\Twig\TwigExtension;
use Mezzio\Twig\TwigExtensionFactory;
use Mezzio\Twig\TwigRenderer;
use Mezzio\Twig\TwigRendererFactory;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Twig\Environment;

final class ContainerFactory
{
    public static function create(): ContainerInterface
    {
        // src/Application/ContainerFactory.php → dirname 3 levels up = project root
        $root = dirname(__FILE__, 3);

        // ── Config loading: config.toml when installed, empty array before ───────
        $configToml = $root . '/config/config.toml';
        $instanceConfig = is_file($configToml)
            ? toml_decode((string) file_get_contents($configToml), asArray: true)
            : [];
        $config = [];

        // Repository settings are defaults. Instance-specific config.toml values
        // are applied afterwards so documented options such as HIBP and rate-limit
        // tuning actually take effect on the installed system.
        $securityToml = $root . '/config/security.toml';
        if (is_file($securityToml)) {
            $repoSecurity = toml_decode((string) file_get_contents($securityToml), asArray: true);
            $config['security'] = $repoSecurity;
        }
        $config = array_replace_recursive($config, $instanceConfig);

        // ── Runtime path defaults ─────────────────────────────────────────────────
        // Applied when no config.toml exists yet (pre-install) or when a value is absent.
        // ConfigureStep writes these with the correct absolute paths; the defaults here
        // only take effect during the installation wizard itself.
        $config['install']['lock'] ??= $root . '/data/install.lock';
        $config['install']['password_file'] ??= $root . '/data/install.pw';
        $config['install']['config_file'] ??= $root . '/config/config.toml';
        $config['log']['path'] ??= $root . '/var/log/lexnova.log';
        $config['log']['level'] ??= 'warning';
        $config['session']['name'] ??= 'lexnova_session';
        $config['session']['secure'] ??= str_starts_with((string) ($config['app']['base_url'] ?? ''), 'https://');
        $config['session']['httponly'] ??= true;
        $config['session']['samesite'] ??= 'Strict';
        $config['session']['cookie_lifetime'] ??= 0;
        $config['session']['cookie_path'] ??= '/';
        $config['app']['locale'] ??= 'de';

        // ── Ensure runtime directories exist ─────────────────────────────────────
        foreach ([$root . '/var/cache/twig', $root . '/var/cache/app', $root . '/var/log'] as $dir) {
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
            'cache_dir' => $twigCache ? $root . '/var/cache/twig' : false,
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
            ResponseInterface::class => fn () => static fn (): ResponseInterface => new \Laminas\Diactoros\Response(),

            // ── Mezzio plumbing ─────────────────────────────────────────────────────
            RouterInterface::class => fn () => new FastRouteRouter(),

            RouteCollector::class => fn (ContainerInterface $c) => new RouteCollector($c->get(RouterInterface::class)),

            RouteCollectorInterface::class => fn (ContainerInterface $c) => $c->get(RouteCollector::class),

            MiddlewareContainer::class => fn (ContainerInterface $c) => (new MiddlewareContainerFactory())($c),

            MiddlewareFactory::class => fn (ContainerInterface $c) => (new MiddlewareFactoryFactory())($c),

            MiddlewareFactoryInterface::class => fn (ContainerInterface $c) => $c->get(MiddlewareFactory::class),

            // Mezzio intentionally uses this string as a pseudo-service name;
            // there is no Mezzio\ApplicationPipeline class to instantiate.
            'Mezzio\ApplicationPipeline' => fn (ContainerInterface $c) => (new ApplicationPipelineFactory())($c),

            EmitterInterface::class => fn (ContainerInterface $c) => (new EmitterFactory())($c),

            ServerRequestInterface::class => fn (ContainerInterface $c) => (new ServerRequestFactoryFactory())($c),

            RequestHandlerRunner::class => fn (ContainerInterface $c) => (new RequestHandlerRunnerFactory())($c),

            RequestHandlerRunnerInterface::class => fn (ContainerInterface $c) => $c->get(RequestHandlerRunner::class),

            Application::class => fn (ContainerInterface $c) => (new ApplicationFactory())($c),

            // ── Twig ────────────────────────────────────────────────────────────────
            Environment::class => fn (ContainerInterface $c) => (new TwigEnvironmentFactory())($c),

            TwigExtension::class => fn (ContainerInterface $c) => (new TwigExtensionFactory())($c),

            TwigRenderer::class => fn (ContainerInterface $c) => (new TwigRendererFactory())($c),

            TemplateRendererInterface::class => fn (ContainerInterface $c) => $c->get(TwigRenderer::class),

            // ── Session & CSRF ───────────────────────────────────────────────────────
            SessionPersistenceInterface::class => fn (ContainerInterface $c) => PhpSessionPersistence::fromConfigArray([
                'name' => (string) ($c->get('config')['session']['name'] ?? 'lexnova_session'),
                'cookie_lifetime' => (int) ($c->get('config')['session']['cookie_lifetime'] ?? 0),
                'cookie_path' => (string) ($c->get('config')['session']['cookie_path'] ?? '/'),
                'cookie_domain' => (string) ($c->get('config')['session']['cookie_domain'] ?? ''),
                'cookie_secure' => (bool) ($c->get('config')['session']['secure'] ?? true),
                'cookie_httponly' => (bool) ($c->get('config')['session']['httponly'] ?? true),
                'cookie_samesite' => (string) ($c->get('config')['session']['samesite'] ?? 'Strict'),
            ]),

            CsrfGuardFactoryInterface::class => fn () => new SessionCsrfGuardFactory(),

            SessionMiddleware::class => fn (ContainerInterface $c) => (new SessionMiddlewareFactory())($c),

            CsrfMiddleware::class => fn (ContainerInterface $c) => (new CsrfMiddlewareFactory())($c),

            RouteMiddleware::class => fn (ContainerInterface $c) => (new RouteMiddlewareFactory())($c),

            DispatchMiddleware::class => fn (ContainerInterface $c) => (new DispatchMiddlewareFactory())($c),

            ErrorResponseGenerator::class => fn (ContainerInterface $c) => (new ErrorResponseGeneratorFactory())($c),

            ErrorHandler::class => fn (ContainerInterface $c) => (new ErrorHandlerFactory())($c),

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
            // PSR-16 cache for public documents. Valkey speaks the Redis protocol;
            // when it is not configured or unavailable, the local filesystem cache
            // remains a safe, dependency-free fallback.
            CacheInterface::class => function (ContainerInterface $c) use ($root): CacheInterface {
                $cache = (array) ($c->get('config')['cache'] ?? []);
                if (($cache['adapter'] ?? 'filesystem') === 'valkey') {
                    try {
                        $host = (string) ($cache['host'] ?? '127.0.0.1');
                        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                            $host = '[' . $host . ']';
                        }
                        $username = (string) ($cache['username'] ?? '');
                        $password = (string) ($cache['password'] ?? '');
                        $auth = $username !== '' || $password !== ''
                            ? rawurlencode($username) . ':' . rawurlencode($password) . '@'
                            : '';
                        $scheme = (bool) ($cache['tls'] ?? false) ? 'valkeys' : 'valkey';
                        $port = min(65535, max(1, (int) ($cache['port'] ?? 6379)));
                        $database = max(0, (int) ($cache['database'] ?? 0));
                        $connection = RedisAdapter::createConnection(
                            "{$scheme}://{$auth}{$host}:{$port}/{$database}",
                        );

                        return new Psr16Cache(new RedisAdapter(
                            $connection,
                            (string) ($cache['namespace'] ?? 'lexnova'),
                            (int) ($cache['default_ttl'] ?? 3600),
                        ));
                    } catch (\Throwable) {
                        // Keep legal documents available if the optional cache backend is down.
                    }
                }

                return new Psr16Cache(new FilesystemAdapter('lexnova', 3600, $root . '/var/cache/app'));
            },

            // PSR-16 cache dedicated to HIBP range lookups (24 h TTL handled by service).
            'cache.hibp' => fn () => new Psr16Cache(new FilesystemAdapter('hibp', 86400, $root . '/var/cache/hibp')),

            // Cached system settings avoid a database query on every security event.
            'cache.settings' => fn () => new Psr16Cache(new FilesystemAdapter('settings', 0, $root . '/var/cache/settings')),

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

            PasskeyService::class => fn (ContainerInterface $c) => new PasskeyService(
                $c->get(Connection::class),
                (string) ($c->get('config')['app']['base_url'] ?? ''),
            ),

            EntityService::class => fn (ContainerInterface $c) => new EntityService($c->get(Connection::class)),

            DocumentService::class => fn (ContainerInterface $c) => new DocumentService($c->get(Connection::class), $c->get(CacheInterface::class)),

            \LexNova\Handler\Public\DocumentHandler::class => fn (ContainerInterface $c) => new \LexNova\Handler\Public\DocumentHandler(
                $c->get(EntityService::class),
                $c->get(DocumentService::class),
                $c->get(TemplateRendererInterface::class),
                $c->get(NotFoundHandler::class),
                (string) ($c->get('config')['app']['base_url'] ?? ''),
            ),

            TotpService::class => fn (ContainerInterface $c) => new TotpService(
                appKey: (string) ($c->get('config')['security']['totp_app_key'] ?? ''),
                digits: (int) ($c->get('config')['security']['totp']['digits'] ?? 8),
                algorithm: (string) ($c->get('config')['security']['totp']['algorithm'] ?? 'sha256'),
                period: (int) ($c->get('config')['security']['totp']['period'] ?? 30),
                window: (int) ($c->get('config')['security']['totp']['window'] ?? 1),
            ),

            InstallService::class => fn (ContainerInterface $c) => new InstallService($c->get('config')),

            SystemSettingService::class => fn (ContainerInterface $c) => new SystemSettingService(
                $c->get(Connection::class),
                $c->get('cache.settings'),
                max(5, (int) ($c->get('config')['security']['fail2ban']['settings_cache_ttl'] ?? 60)),
            ),

            Fail2BanLogService::class => function (ContainerInterface $c) use ($root): Fail2BanLogService {
                $settings = (array) ($c->get('config')['security']['fail2ban'] ?? []);
                $path = (string) ($settings['path'] ?? 'var/log/fail2ban.log');
                if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
                    $path = $root . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
                }

                return new Fail2BanLogService(
                    $c->get(SystemSettingService::class),
                    (bool) ($settings['enabled'] ?? false),
                    $path,
                );
            },

            InstallRateLimitService::class => fn (ContainerInterface $c) => new InstallRateLimitService(
                $root . '/var/cache/install-rate-limit',
                $c->get(ClockInterface::class),
                maxAttempts: (int) ($c->get('config')['security']['rate_limit']['max_attempts'] ?? 5),
                blockSeconds: (int) ($c->get('config')['security']['rate_limit']['block_seconds'] ?? 300),
            ),

            RateLimitService::class => fn (ContainerInterface $c) => new RateLimitService(
                $c->get(Connection::class),
                $c->get(ClockInterface::class),
                maxAttempts: (int) ($c->get('config')['security']['rate_limit']['max_attempts'] ?? 5),
                blockSeconds: (int) ($c->get('config')['security']['rate_limit']['block_seconds'] ?? 300),
            ),

            AuditService::class => fn (ContainerInterface $c) => new AuditService($c->get(Connection::class)),

            // ── Handlers: Install ───────────────────────────────────────────────────
            \LexNova\Handler\Install\InstallHandler::class => fn (ContainerInterface $c) => new \LexNova\Handler\Install\InstallHandler(
                $c->get(InstallService::class),
                $c->get(InstallRateLimitService::class),
                $c->get(PasswordService::class),
                $c->get(TemplateRendererInterface::class),
                $c->get(LoggerInterface::class),
                $c->get(Fail2BanLogService::class),
                $c->get('config'),
            ),

            // ── Handlers: Admin (Login) ─────────────────────────────────────────────
            LoginHandler::class => fn (ContainerInterface $c) => new LoginHandler(
                $c->get(UserService::class),
                $c->get(RateLimitService::class),
                $c->get(AuditService::class),
                $c->get(TemplateRendererInterface::class),
                $c->get(Fail2BanLogService::class),
            ),

            \LexNova\Handler\Admin\DashboardHandler::class => fn (ContainerInterface $c) => new \LexNova\Handler\Admin\DashboardHandler(
                $c->get(UserService::class),
                $c->get(EntityService::class),
                $c->get(DocumentService::class),
                $c->get(PasswordService::class),
                $c->get(AuditService::class),
                $c->get(TemplateRendererInterface::class),
                $c->get(Fail2BanLogService::class),
                (array) ($c->get('config')['security']['generator'] ?? []),
            ),

            // ── Handlers: Auth (TOTP) ────────────────────────────────────────────────
            TotpVerifyHandler::class => fn (ContainerInterface $c) => new TotpVerifyHandler(
                $c->get(TotpService::class),
                $c->get(UserService::class),
                $c->get(RateLimitService::class),
                $c->get(AuditService::class),
                $c->get(TemplateRendererInterface::class),
                $c->get(Fail2BanLogService::class),
            ),

            PasskeyLoginHandler::class => fn (ContainerInterface $c) => new PasskeyLoginHandler(
                $c->get(PasskeyService::class), $c->get(RateLimitService::class), $c->get(AuditService::class),
                $c->get(Fail2BanLogService::class),
            ),

            PasskeyRegisterHandler::class => fn (ContainerInterface $c) => new PasskeyRegisterHandler(
                $c->get(PasskeyService::class), $c->get(UserService::class), $c->get(AuditService::class),
            ),

            Fail2BanSettingHandler::class => fn (ContainerInterface $c) => new Fail2BanSettingHandler(
                $c->get(SystemSettingService::class),
                $c->get(AuditService::class),
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

            SecurityHeadersMiddleware::class => fn () => new SecurityHeadersMiddleware(),

            // ── Error handling ───────────────────────────────────────────────────────
            // Replace Mezzio's default plain-text 404/500 responses with styled templates.
            NotFoundHandler::class => fn (ContainerInterface $c) => new NotFoundHandler(
                $c->get(TemplateRendererInterface::class),
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

            \LexNova\Console\InstallPrepareCommand::class => fn (ContainerInterface $c) => new \LexNova\Console\InstallPrepareCommand(
                $c->get(InstallService::class),
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
