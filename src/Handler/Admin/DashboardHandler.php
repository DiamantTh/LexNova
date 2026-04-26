<?php

declare(strict_types=1);

namespace LexNova\Handler\Admin;

use Laminas\Diactoros\Response\HtmlResponse;
use LexNova\Service\AuditService;
use LexNova\Service\DocumentService;
use LexNova\Service\EntityService;
use LexNova\Service\PasswordService;
use LexNova\Service\UserService;
use Mezzio\Csrf\CsrfMiddleware;
use Mezzio\Session\SessionInterface;
use Mezzio\Session\SessionMiddleware;
use Mezzio\Template\TemplateRendererInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class DashboardHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly UserService               $users,
        private readonly EntityService             $entities,
        private readonly DocumentService           $documents,
        private readonly PasswordService           $passwords,
        private readonly AuditService              $audit,
        private readonly TemplateRendererInterface $renderer,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $guard  = $request->getAttribute(CsrfMiddleware::GUARD_ATTRIBUTE);
        /** @var SessionInterface $session */
        $session = $request->getAttribute(SessionMiddleware::SESSION_ATTRIBUTE);

        $errors   = $session->get('flash_errors', []);
        $messages = $session->get('flash_messages', []);
        $session->unset('flash_errors');
        $session->unset('flash_messages');

        $editId  = isset($request->getQueryParams()['doc_id'])
            ? (int) $request->getQueryParams()['doc_id']
            : null;
        $editDoc = $editId !== null ? $this->documents->findById($editId) : null;

        $editEntityId = isset($request->getQueryParams()['entity_id'])
            ? (int) $request->getQueryParams()['entity_id']
            : null;
        $editEntity = $editEntityId !== null ? $this->entities->findById($editEntityId) : null;

        $users = $this->users->list();

        // Load all TOTP keys per user (N+1 is acceptable — admin tool, few users).
        $totpKeys = [];
        foreach ($users as $u) {
            $totpKeys[(int) $u['id']] = $this->users->getTotpKeys((int) $u['id']);
        }

        return new HtmlResponse($this->renderer->render('admin/dashboard', [
            'users'           => $users,
            'totp_keys'       => $totpKeys,
            'entities'        => $this->entities->list(),
            'documents'       => $this->documents->list(),
            'editDoc'         => $editDoc,
            'editEntity'      => $editEntity,
            'csrf_token'      => $guard->generateToken(),
            'pw_min'          => $this->passwords->getMinLength(),
            'pw_max'          => $this->passwords->getMaxLength(),
            'errors'          => $errors,
            'messages'        => $messages,
            'current_user_id' => (int) $session->get('user_id'),
            'audit_log'       => $this->audit->recent(50),
        ]));
    }
}
