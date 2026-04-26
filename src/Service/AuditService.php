<?php

declare(strict_types=1);

namespace LexNova\Service;

use Doctrine\DBAL\Connection;

/**
 * Writes structured audit log entries to the audit_log table.
 *
 * All write actions (create / update / delete / TOTP events) pass through here.
 * CLI commands pass actor_id = null and ip = null.
 */
final readonly class AuditService
{
    public function __construct(private readonly Connection $db) {}

    /**
     * @param int|null    $actorId   Logged-in user's ID; null for CLI/system
     * @param string|null $actorName Logged-in user's username; null for CLI/system
     * @param string      $action    Short machine-readable action (e.g. 'user.create')
     * @param string|null $target    Affected object (e.g. 'user:3' or 'entity:7')
     * @param string|null $detail    Human-readable extra info
     * @param string|null $ip        Remote IP; null for CLI
     */
    public function log(
        ?int    $actorId,
        ?string $actorName,
        string  $action,
        ?string $target  = null,
        ?string $detail  = null,
        ?string $ip      = null,
    ): void {
        $this->db->insert('audit_log', [
            'actor_id'   => $actorId,
            'actor_name' => $actorName,
            'action'     => $action,
            'target'     => $target,
            'detail'     => $detail,
            'ip'         => $ip,
            'created_at' => date('Y-m-d H:i:s'),
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
            ->select('id', 'actor_id', 'actor_name', 'action', 'target', 'detail', 'ip', 'created_at')
            ->from('audit_log')
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
