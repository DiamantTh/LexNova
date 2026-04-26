<?php

declare(strict_types=1);

namespace LexNova\Application;

use LexNova\Handler\Admin\DashboardHandler;
use LexNova\Handler\Admin\DocumentCreateHandler;
use LexNova\Handler\Admin\DocumentDeleteHandler;
use LexNova\Handler\Admin\DocumentUpdateHandler;
use LexNova\Handler\Admin\EntityCreateHandler;
use LexNova\Handler\Admin\EntityDeleteHandler;
use LexNova\Handler\Admin\EntityUpdateHandler;
use LexNova\Handler\Admin\LoginHandler;
use LexNova\Handler\Admin\LogoutHandler;
use LexNova\Handler\Admin\TotpResetHandler;
use LexNova\Handler\Admin\TotpKeyDeleteHandler;
use LexNova\Handler\Admin\UserCreateHandler;
use LexNova\Handler\Admin\UserDeleteHandler;
use LexNova\Handler\Admin\UserUpdateHandler;
use LexNova\Handler\Auth\TotpEnrollHandler;
use LexNova\Handler\Auth\TotpVerifyHandler;
use LexNova\Handler\Install\InstallHandler;
use LexNova\Handler\Public\DocumentHandler;
use LexNova\Middleware\AdminAuthMiddleware;
use Mezzio\Application;

final class Routes
{
    public static function configure(Application $app): void
    {
        // ── Install ──────────────────────────────────────────────────────────────
        $app->route('/install[/]', InstallHandler::class, ['GET', 'POST'], 'install');

        // ── Admin ────────────────────────────────────────────────────────────────
        $app->get('/admin[/]', [AdminAuthMiddleware::class, DashboardHandler::class], 'admin.dashboard');

        $app->route('/admin/login', LoginHandler::class, ['GET', 'POST'], 'admin.login');

        $app->post('/admin/logout', [AdminAuthMiddleware::class, LogoutHandler::class], 'admin.logout');

        // ── TOTP: verification during login (no AdminAuthMiddleware — user not yet logged in)
        $app->route('/admin/totp/verify', TotpVerifyHandler::class, ['GET', 'POST'], 'admin.totp.verify');

        // ── TOTP: enrollment + reset (requires admin session)
        $app->route('/admin/totp/enroll',
            [AdminAuthMiddleware::class, TotpEnrollHandler::class],
            ['GET', 'POST'],
            'admin.totp.enroll'
        );
        $app->post('/admin/totp/reset/{id:\d+}',
            [AdminAuthMiddleware::class, TotpResetHandler::class],
            'admin.totp.reset'
        );
        $app->post('/admin/users/{userId:\d+}/totp-keys/{keyId:\d+}/delete',
            [AdminAuthMiddleware::class, TotpKeyDeleteHandler::class],
            'admin.totp.key.delete'
        );

        $app->post('/admin/users/create',
            [AdminAuthMiddleware::class, UserCreateHandler::class],
            'admin.users.create'
        );
        $app->post('/admin/users/{id:\d+}/update',
            [AdminAuthMiddleware::class, UserUpdateHandler::class],
            'admin.users.update'
        );
        $app->post('/admin/users/{id:\d+}/delete',
            [AdminAuthMiddleware::class, UserDeleteHandler::class],
            'admin.users.delete'
        );

        $app->post('/admin/entities/create',
            [AdminAuthMiddleware::class, EntityCreateHandler::class],
            'admin.entities.create'
        );
        $app->route('/admin/entities/{id:\d+}/edit',
            [AdminAuthMiddleware::class, EntityUpdateHandler::class],
            ['GET', 'POST'],
            'admin.entities.edit'
        );
        $app->post('/admin/entities/{id:\d+}/delete',
            [AdminAuthMiddleware::class, EntityDeleteHandler::class],
            'admin.entities.delete'
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
        $app->post('/admin/documents/{id:\d+}/delete',
            [AdminAuthMiddleware::class, DocumentDeleteHandler::class],
            'admin.documents.delete'
        );

        // ── Public document display ──────────────────────────────────────────────
        $app->get('/{hash:[0-9a-f]{32}}/{type:imprint|privacy}[/{lang:[a-zA-Z]{2,8}(-[a-zA-Z0-9]{1,8})*}]',
            DocumentHandler::class,
            'document.view'
        );
    }
}
