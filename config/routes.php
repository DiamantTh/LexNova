<?php

declare(strict_types=1);

use LexNova\Handler\Admin\DashboardHandler;
use LexNova\Handler\Admin\DocumentCreateHandler;
use LexNova\Handler\Admin\DocumentUpdateHandler;
use LexNova\Handler\Admin\EntityCreateHandler;
use LexNova\Handler\Admin\LoginHandler;
use LexNova\Handler\Admin\LogoutHandler;
use LexNova\Handler\Admin\UserCreateHandler;
use LexNova\Handler\Admin\UserUpdateHandler;
use LexNova\Handler\Install\InstallHandler;
use LexNova\Handler\Public\DocumentHandler;
use LexNova\Middleware\AdminAuthMiddleware;
use Mezzio\Application;

return static function (Application $app): void {

    // ── Install ──────────────────────────────────────────────────────────────
    $app->route('/install[/]', InstallHandler::class, ['GET', 'POST'], 'install');

    // ── Admin ────────────────────────────────────────────────────────────────
    $app->get('/admin[/]', [AdminAuthMiddleware::class, DashboardHandler::class], 'admin.dashboard');

    $app->post('/admin/login', LoginHandler::class, 'admin.login');
    $app->get('/admin', LoginHandler::class, 'admin.login.form');        // fallback for non-authed GET

    $app->post('/admin/logout', [AdminAuthMiddleware::class, LogoutHandler::class], 'admin.logout');

    $app->post('/admin/users/create',
        [AdminAuthMiddleware::class, UserCreateHandler::class],
        'admin.users.create'
    );
    $app->post('/admin/users/{id:\d+}/update',
        [AdminAuthMiddleware::class, UserUpdateHandler::class],
        'admin.users.update'
    );

    $app->post('/admin/entities/create',
        [AdminAuthMiddleware::class, EntityCreateHandler::class],
        'admin.entities.create'
    );

    $app->post('/admin/documents/create',
        [AdminAuthMiddleware::class, DocumentCreateHandler::class],
        'admin.documents.create'
    );
    $app->route('/admin/documents/{id:\d+}/edit',
        [AdminAuthMiddleware::class, DocumentUpdateHandler::class],
        ['GET', 'POST'],
        'admin.documents.edit'
    );

    // ── Public document display ──────────────────────────────────────────────
    $app->get('/{hash:[0-9a-f]{32}}/{type:imprint|privacy}[/{lang:[a-zA-Z]{2,8}(-[a-zA-Z0-9]{1,8})*}]',
        DocumentHandler::class,
        'document.view'
    );
};
