<?php

declare(strict_types=1);

namespace LexNova\Service;

use Doctrine\DBAL\Connection;

/**
 * Writes append-only security and application events to audit_events.
 *
 * All write actions (create / update / delete / TOTP events) pass through here.
 * CLI commands pass actor_id = null and ip = null.
 */
final readonly class AuditService
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param int|null    $actorId   Acting user's ID; null for CLI/system
     * @param string|null $actorName Logged-in user's username; null for CLI/system
     * @param string      $action    Short machine-readable action (e.g. 'user.create')
     * @param string|null $target    Affected object (e.g. 'user:3' or 'entity:7')
     * @param string|null $detail    Human-readable extra info
     * @param string|null $ip        Remote IP; null for CLI
     */
    public function log(
        ?int $actorId,
        ?string $actorName,
        string $action,
        ?string $target = null,
        ?string $detail = null,
        ?string $ip = null,
        ?int $effectiveUserId = null,
    ): void {
        $this->db->insert('audit_events', [
            'actor_user_id' => $actorId,
            'effective_user_id' => $effectiveUserId,
            'actor_name' => $actorName,
            'action' => $action,
            'target' => $target,
            'detail' => $detail,
            'ip' => $ip,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Returns the most recent $limit audit entries, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 100): array
    {
        return $this->db->createQueryBuilder()
            ->select('id', 'actor_user_id AS actor_id', 'effective_user_id', 'actor_name', 'action', 'target', 'detail', 'ip', 'created_at')
            ->from('audit_events')
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
