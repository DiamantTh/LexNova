<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\RedirectResponse;
use LexNova\Service\AuditService;
use LexNova\Service\Fail2BanLogService;
use LexNova\Service\SystemSettingService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class Fail2BanSettingHandler implements RequestHandlerInterface
{
    public function __construct(
        private SystemSettingService $settings,
        private AuditService $audit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $guard = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        $body = (array) ($request->getParsedBody() ?? []);
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        if (!$guard->validateToken((string) ($body['__csrf'] ?? ''))) {
            $session->set('flash_errors', ['Invalid session token.']);

            return new RedirectResponse('/admin');
        }

        $mode = (string) ($body['mode'] ?? 'config');
        if (!in_array($mode, ['config', 'enabled', 'disabled'], true)) {
            $session->set('flash_errors', ['Invalid Fail2ban setting.']);

            return new RedirectResponse('/admin');
        }

        if ($mode === 'config') {
            $this->settings->remove(Fail2BanLogService::SETTING_KEY);
        } else {
            $this->settings->setBool(Fail2BanLogService::SETTING_KEY, $mode === 'enabled');
        }

        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        $this->audit->log(
            (int) ($session->get('user_id') ?? 0),
            (string) ($session->get('username') ?? ''),
            'security.fail2ban.update',
            'system-setting:' . Fail2BanLogService::SETTING_KEY,
            'mode:' . $mode,
            $ip,
        );
        $session->set('flash_messages', ['Fail2ban logging setting updated.']);

        return new RedirectResponse('/admin');
    }
}
