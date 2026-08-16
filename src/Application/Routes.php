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
use LexNova\Handler\Admin\Fail2BanSettingHandler;
use LexNova\Handler\Admin\LoginHandler;
use LexNova\Handler\Admin\LogoutHandler;
use LexNova\Handler\Admin\SystemInfoHandler;
use LexNova\Handler\Admin\TotpKeyDeleteHandler;
use LexNova\Handler\Admin\TotpResetHandler;
use LexNova\Handler\Admin\UserCreateHandler;
use LexNova\Handler\Admin\UserDeleteHandler;
use LexNova\Handler\Admin\UserUpdateHandler;
use LexNova\Handler\Auth\PasskeyDeleteHandler;
use LexNova\Handler\Auth\PasskeyLoginHandler;
use LexNova\Handler\Auth\PasskeyRegisterHandler;
use LexNova\Handler\Auth\PasskeyUpdateHandler;
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
        $app->get('/verwaltung[/]', [AdminAuthMiddleware::class, DashboardHandler::class], 'workspace.dashboard');
        $app->get('/verwaltung/entities', [AdminAuthMiddleware::class, DashboardHandler::class], 'workspace.entities');
        $app->get('/verwaltung/documents', [AdminAuthMiddleware::class, DashboardHandler::class], 'workspace.documents');
        $app->get('/user/security', [AdminAuthMiddleware::class, DashboardHandler::class], 'user.security');

        $app->get('/admin[/]', [AdminAuthMiddleware::class, DashboardHandler::class], 'admin.dashboard');
        $app->get('/admin/users', [AdminAuthMiddleware::class, DashboardHandler::class], 'admin.users');
        $app->get('/admin/security', [AdminAuthMiddleware::class, DashboardHandler::class], 'admin.security');
        $app->get('/admin/audit', [AdminAuthMiddleware::class, DashboardHandler::class], 'admin.audit');

        $app->get('/admin/system', [AdminAuthMiddleware::class, SystemInfoHandler::class], 'admin.system');

        $app->route('/admin/login', LoginHandler::class, ['GET', 'POST'], 'admin.login');

        $app->post('/admin/logout', [AdminAuthMiddleware::class, LogoutHandler::class], 'admin.logout');

        // ── TOTP: verification during login (no AdminAuthMiddleware — user not yet logged in)
        $app->route('/admin/totp/verify', TotpVerifyHandler::class, ['GET', 'POST'], 'admin.totp.verify');

        $app->post('/admin/passkeys/login/options', PasskeyLoginHandler::class, 'admin.passkeys.login.options');
        $app->post('/admin/passkeys/login/finish', PasskeyLoginHandler::class, 'admin.passkeys.login.finish');
        $app->post('/admin/passkeys/register/options', [AdminAuthMiddleware::class, PasskeyRegisterHandler::class], 'admin.passkeys.register.options');
        $app->post('/admin/passkeys/register/finish', [AdminAuthMiddleware::class, PasskeyRegisterHandler::class], 'admin.passkeys.register.finish');
        $app->post('/admin/users/{userId:\d+}/passkeys/{credentialId:\d+}/delete',
            [AdminAuthMiddleware::class, PasskeyDeleteHandler::class],
            'admin.passkeys.delete',
        );
        $app->post('/admin/users/{userId:\d+}/passkeys/{credentialId:\d+}/update',
            [AdminAuthMiddleware::class, PasskeyUpdateHandler::class],
            'admin.passkeys.update',
        );

        // ── TOTP: enrollment + reset (requires admin session)
        $app->route('/admin/totp/enroll',
            [AdminAuthMiddleware::class, TotpEnrollHandler::class],
            ['GET', 'POST'],
            'admin.totp.enroll',
        );
        $app->post('/admin/totp/reset/{id:\d+}',
            [AdminAuthMiddleware::class, TotpResetHandler::class],
            'admin.totp.reset',
        );
        $app->post('/admin/users/{userId:\d+}/totp-keys/{keyId:\d+}/delete',
            [AdminAuthMiddleware::class, TotpKeyDeleteHandler::class],
            'admin.totp.key.delete',
        );

        $app->post('/admin/users/create',
            [AdminAuthMiddleware::class, UserCreateHandler::class],
            'admin.users.create',
        );
        $app->post('/admin/users/{id:\d+}/update',
            [AdminAuthMiddleware::class, UserUpdateHandler::class],
            'admin.users.update',
        );
        $app->post('/admin/users/{id:\d+}/delete',
            [AdminAuthMiddleware::class, UserDeleteHandler::class],
            'admin.users.delete',
        );

        $app->post('/admin/security/fail2ban',
            [AdminAuthMiddleware::class, Fail2BanSettingHandler::class],
            'admin.security.fail2ban',
        );

        $app->post('/admin/entities/create',
            [AdminAuthMiddleware::class, EntityCreateHandler::class],
            'admin.entities.create',
        );

        $app->post('/verwaltung/entities/create',
            [AdminAuthMiddleware::class, EntityCreateHandler::class],
            'workspace.entities.create',
        );
        $app->route('/verwaltung/entities/{id:\d+}/edit',
            [AdminAuthMiddleware::class, EntityUpdateHandler::class],
            ['GET', 'POST'],
            'workspace.entities.edit',
        );
        $app->post('/verwaltung/entities/{id:\d+}/delete',
            [AdminAuthMiddleware::class, EntityDeleteHandler::class],
            'workspace.entities.delete',
        );
        $app->route('/admin/entities/{id:\d+}/edit',
            [AdminAuthMiddleware::class, EntityUpdateHandler::class],
            ['GET', 'POST'],
            'admin.entities.edit',
        );
        $app->post('/admin/entities/{id:\d+}/delete',
            [AdminAuthMiddleware::class, EntityDeleteHandler::class],
            'admin.entities.delete',
        );

        $app->post('/admin/documents/create',
            [AdminAuthMiddleware::class, DocumentCreateHandler::class],
            'admin.documents.create',
        );
        $app->route('/admin/documents/{id:\d+}/edit',
            [AdminAuthMiddleware::class, DocumentUpdateHandler::class],
            ['GET', 'POST'],
            'admin.documents.edit',
        );
        $app->post('/admin/documents/{id:\d+}/delete',
            [AdminAuthMiddleware::class, DocumentDeleteHandler::class],
            'admin.documents.delete',
        );

        $app->post('/verwaltung/documents/create',
            [AdminAuthMiddleware::class, DocumentCreateHandler::class],
            'workspace.documents.create',
        );
        $app->route('/verwaltung/documents/{id:\d+}/edit',
            [AdminAuthMiddleware::class, DocumentUpdateHandler::class],
            ['GET', 'POST'],
            'workspace.documents.edit',
        );
        $app->post('/verwaltung/documents/{id:\d+}/delete',
            [AdminAuthMiddleware::class, DocumentDeleteHandler::class],
            'workspace.documents.delete',
        );

        // ── Public document display ──────────────────────────────────────────────
        // out.php is a virtual compatibility-style URL. Apache/Nginx route it
        // to index.php; no second executable PHP file is needed.
        $app->get('/out.php', DocumentHandler::class, 'document.view');
    }
}
