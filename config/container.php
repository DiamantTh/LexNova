<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Laminas\HttpHandlerRunner\RequestHandlerRunner;
use LexNova\Factory\DoctrineConnectionFactory;
use LexNova\Factory\LoggerFactory;
use LexNova\Middleware\AdminAuthMiddleware;
use LexNova\Middleware\InstalledCheckMiddleware;
use LexNova\Service\DocumentService;
use LexNova\Service\EntityService;
use LexNova\Service\InstallService;
use LexNova\Service\PasswordService;
use LexNova\Service\UserService;
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
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;

$root       = dirname(__DIR__);
$configFile = $root . '/config/config.php';
$config     = is_file($configFile)
    ? require $configFile
    : require $root . '/config/config.example.php';

// Merge framework config into app config
$config['templates'] = [
    'extension' => 'html.twig',
    'paths'     => [$root . '/templates'],
];
$config['twig'] = [
    'cache_dir'   => $root . '/data/twig-cache',
    'debug'       => false,
    'auto_reload' => true,
    'timezone'    => 'UTC',
    'globals'     => [],
];

$builder = new ContainerBuilder();
$builder->useAutowiring(true);

$builder->addDefinitions([

    'config' => $config,

    // ── PSR-7 factory ───────────────────────────────────────────────────────
    ResponseFactoryInterface::class => fn() =>
        new Laminas\Diactoros\ResponseFactory(),

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

    // ── Application services ────────────────────────────────────────────────
    PasswordService::class => fn(ContainerInterface $c) =>
        new PasswordService($c->get('config')),

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

]);

return $builder->build();
