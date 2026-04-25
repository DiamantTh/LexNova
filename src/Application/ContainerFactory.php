<?php

declare(strict_types=1);

namespace LexNova\Application;

use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use LexNova\Clock\SystemClock;
use LexNova\Factory\DoctrineConnectionFactory;
use LexNova\Factory\LoggerFactory;
use LexNova\Middleware\AdminAuthMiddleware;
use LexNova\Middleware\InstalledCheckMiddleware;
use LexNova\Service\DocumentService;
use LexNova\Service\EntityService;
use LexNova\Service\InstallService;
use LexNova\Service\Password\DicewareGenerator;
use LexNova\Service\Password\RandomPasswordGenerator;
use LexNova\Service\PasswordService;
use LexNova\Service\UserService;
use LexNova\Twig\EmailExtension;
use Mezzio\Application;
use Mezzio\ApplicationPipeline;
use Mezzio\Container\ApplicationFactory;
use Mezzio\Container\MiddlewareContainerFactory;
use Mezzio\Container\MiddlewareFactoryFactory;
use Mezzio\Container\RequestHandlerRunnerFactory;
use Mezzio\Container\ServerRequestErrorResponseGeneratorFactory;
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

        // Security config ships in the repository and is always loaded separately
        $securityToml = $root . '/configs/security.toml';
        if (is_file($securityToml)) {
            $config['security'] = toml_decode((string) file_get_contents($securityToml), asArray: true);
        }

        // ── Runtime path defaults ─────────────────────────────────────────────────
        // Applied when no config.toml exists yet (pre-install) or when a value is absent.
        // ConfigureStep writes these with the correct absolute paths; the defaults here
        // only take effect during the installation wizard itself.
        $config['install']['lock']          ??= $root . '/data/install.lock';
        $config['install']['password_file'] ??= $root . '/data/install.pw';
        $config['install']['config_file']   ??= $root . '/configs/config.toml';
        $config['log']['path']              ??= $root . '/logs/lexnova.log';
        $config['log']['level']             ??= 'warning';
        $config['session']['name']          ??= 'lexnova_session';
        $config['session']['secure']        ??= false;
        $config['session']['httponly']      ??= true;
        $config['session']['samesite']      ??= 'Lax';

        // ── Ensure runtime directories exist ─────────────────────────────────────
        foreach ([$root . '/cache/twig', $root . '/logs'] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // ── Framework config ──────────────────────────────────────────────────────
        // twig.cache can be set to false in config.toml to disable template caching
        $twigCache = (bool) ($config['twig']['cache'] ?? true);

        $config['templates'] = [
            'extension' => 'html.twig',
            'paths'     => [$root . '/templates'],
        ];
        $config['twig'] = [
            'cache_dir'   => $twigCache ? $root . '/cache/twig' : false,
            'debug'       => false,
            'auto_reload' => true,
            'timezone'    => 'UTC',
            'globals'     => ['twig_cache_enabled' => $twigCache],
            'extensions'  => [
                EmailExtension::class,
            ],
        ];

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);

        $builder->addDefinitions([

            'config' => $config,

            // ── PSR-7 factory ───────────────────────────────────────────────────────
            ResponseFactoryInterface::class => fn() =>
                new \Laminas\Diactoros\ResponseFactory(),

            // ── Mezzio plumbing ─────────────────────────────────────────────────────
            RouterInterface::class => fn() =>
                new FastRouteRouter(),

            RouteCollector::class => fn(ContainerInterface $c) =>
                new RouteCollector($c->get(RouterInterface::class)),

            MiddlewareContainer::class => fn(ContainerInterface $c) =>
                (new MiddlewareContainerFactory())($c),

            MiddlewareFactory::class => fn(ContainerInterface $c) =>
                (new MiddlewareFactoryFactory())($c),

            ApplicationPipeline::class => fn() =>
                new ApplicationPipeline(),

            ServerRequestErrorResponseGenerator::class => fn(ContainerInterface $c) =>
                (new ServerRequestErrorResponseGeneratorFactory())($c),

            RequestHandlerRunner::class => fn(ContainerInterface $c) =>
                (new RequestHandlerRunnerFactory())($c),

            Application::class => fn(ContainerInterface $c) =>
                (new ApplicationFactory())($c),

            // ── Twig ────────────────────────────────────────────────────────────────
            TwigEnvironment::class => fn(ContainerInterface $c) =>
                (new TwigEnvironmentFactory())($c),

            TwigRenderer::class => fn(ContainerInterface $c) =>
                (new TwigRendererFactory())($c),

            TemplateRendererInterface::class => fn(ContainerInterface $c) =>
                $c->get(TwigRenderer::class),

            // ── Session & CSRF ───────────────────────────────────────────────────────
            SessionPersistenceInterface::class => fn() =>
                new PhpSessionPersistence(),

            CsrfGuardFactoryInterface::class => fn() =>
                new SessionCsrfGuardFactory(),

            // ── Infrastructure ──────────────────────────────────────────────────────
            // PHP 8.4 native lazy proxy: Connection is only established on first use.
            Connection::class => fn(ContainerInterface $c) =>
                (new \ReflectionClass(Connection::class))->newLazyProxy(
                    fn(Connection $proxy): Connection => (new DoctrineConnectionFactory())($c)
                ),

            LoggerInterface::class => fn(ContainerInterface $c) =>
                (new LoggerFactory())($c),

            // ── Clock ────────────────────────────────────────────────────────────────
            ClockInterface::class => fn() =>
                new SystemClock(),

            // ── Twig extensions ──────────────────────────────────────────────────────
            EmailExtension::class => fn(ContainerInterface $c) =>
                new EmailExtension(
                    $c->get(ClockInterface::class),
                    (array) ($c->get('config')['security']['email_subject'] ?? []),
                ),

            // ── Application services ────────────────────────────────────────────────
            PasswordService::class => fn(ContainerInterface $c) =>
                new PasswordService($c->get('config')),

            // ── Password generators ─────────────────────────────────────────────────
            DicewareGenerator::class => fn(ContainerInterface $c) => new DicewareGenerator(
                wordCount:    (int) ($c->get('config')['security']['generator']['diceware']['word_count'] ?? 6),
                separator:    (string) ($c->get('config')['security']['generator']['diceware']['separator'] ?? '-'),
                wordlistPath: $root . '/resources/eff_large_wordlist.php',
            ),

            RandomPasswordGenerator::class => fn(ContainerInterface $c) => new RandomPasswordGenerator(
                length:         (int)  ($c->get('config')['security']['generator']['random']['length'] ?? 20),
                requireUpper:   (bool) ($c->get('config')['security']['generator']['random']['require_upper'] ?? true),
                requireDigits:  (bool) ($c->get('config')['security']['generator']['random']['require_digits'] ?? true),
                requireSymbols: (bool) ($c->get('config')['security']['generator']['random']['require_symbols'] ?? true),
            ),

            UserService::class => fn(ContainerInterface $c) =>
                new UserService($c->get(Connection::class), $c->get(PasswordService::class)),

            EntityService::class => fn(ContainerInterface $c) =>
                new EntityService($c->get(Connection::class)),

            DocumentService::class => fn(ContainerInterface $c) =>
                new DocumentService($c->get(Connection::class)),

            InstallService::class => fn(ContainerInterface $c) =>
                new InstallService($c->get('config')),

            // ── Handlers: Install ───────────────────────────────────────────────────
            \LexNova\Handler\Install\InstallHandler::class => fn(ContainerInterface $c) =>
                new \LexNova\Handler\Install\InstallHandler(
                    $c->get(InstallService::class),
                    $c->get(PasswordService::class),
                    $c->get(TemplateRendererInterface::class),
                    $c->get('config'),
                ),

            // ── Middleware ───────────────────────────────────────────────────────────
            AdminAuthMiddleware::class => fn(ContainerInterface $c) =>
                new AdminAuthMiddleware(
                    $c->get(ResponseFactoryInterface::class)
                ),

            InstalledCheckMiddleware::class => fn(ContainerInterface $c) =>
                new InstalledCheckMiddleware(
                    $c->get(InstallService::class),
                    $c->get(ResponseFactoryInterface::class)
                ),

            // ── Console commands ─────────────────────────────────────────────────────
            \LexNova\Console\UserCreateCommand::class => fn(ContainerInterface $c) =>
                new \LexNova\Console\UserCreateCommand(
                    $c->get(UserService::class),
                    $c->get(PasswordService::class),
                    $c->get(DicewareGenerator::class),
                    $c->get(RandomPasswordGenerator::class),
                ),

            \LexNova\Console\UserSetPasswordCommand::class => fn(ContainerInterface $c) =>
                new \LexNova\Console\UserSetPasswordCommand(
                    $c->get(UserService::class),
                    $c->get(PasswordService::class),
                    $c->get(DicewareGenerator::class),
                    $c->get(RandomPasswordGenerator::class),
                ),

        ]);

        return $builder->build();
    }
}
