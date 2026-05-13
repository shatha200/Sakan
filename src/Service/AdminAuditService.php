<?php

namespace App\Service;

use App\Entity\Utilisateur;
use Doctrine\DBAL\Connection;

class AdminAuditService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function logUserAction(?Utilisateur $admin, ?Utilisateur $target, string $action, array $payload = [], ?string $reason = null): void
    {
        if (!$this->ensureAuditTableExists()) {
            return;
        }

        try {
            $this->connection->insert('admin_user_audit', [
                'admin_user_id' => $admin?->getId(),
                'admin_name' => $this->safeString($admin?->getNom()),
                'admin_email' => $this->safeString($admin?->getEmail()),
                'target_user_id' => $target?->getId(),
                'target_name' => $this->safeString($target?->getNom()),
                'target_email' => $this->safeString($target?->getEmail()),
                'action' => substr(trim($action), 0, 64),
                'reason' => $reason !== null ? substr(trim($reason), 0, 255) : null,
                'payload_json' => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Audit is best effort and must not break admin actions.
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getAuditRows(string $search = '', int $limit = 200): array
    {
        if (!$this->ensureAuditTableExists()) {
            return [];
        }

        $limit = max(1, min(500, $limit));
        $sql = 'SELECT id, admin_name, admin_email, target_name, target_email, action, reason, payload_json, created_at
                FROM admin_user_audit';
        $where = [];
        $params = [];

        if (trim($search) !== '') {
            $where[] = '(LOWER(COALESCE(admin_name, \'\')) LIKE :q
                OR LOWER(COALESCE(admin_email, \'\')) LIKE :q
                OR LOWER(COALESCE(target_name, \'\')) LIKE :q
                OR LOWER(COALESCE(target_email, \'\')) LIKE :q
                OR LOWER(COALESCE(action, \'\')) LIKE :q)';
            $params['q'] = '%'.strtolower(trim($search)).'%';
        }

        if ($where !== []) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }

        $sql .= ' ORDER BY created_at DESC, id DESC LIMIT '.$limit;

        try {
            $rows = $this->connection->fetchAllAssociative($sql, $params);
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as &$row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            $row['payload'] = is_array($payload) ? $payload : [];
            $row['summary'] = $this->summarizePayload($row['payload'], (string) ($row['reason'] ?? ''));
        }

        return $rows;
    }

    public function ensureAuditTableExists(): bool
    {
        try {
            $this->connection->executeStatement(
                'CREATE TABLE IF NOT EXISTS admin_user_audit (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    admin_user_id INT NULL,
                    admin_name VARCHAR(190) NULL,
                    admin_email VARCHAR(190) NULL,
                    target_user_id INT NULL,
                    target_name VARCHAR(190) NULL,
                    target_email VARCHAR(190) NULL,
                    action VARCHAR(64) NOT NULL,
                    reason VARCHAR(255) NULL,
                    payload_json LONGTEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_admin_user_audit_created_at (created_at),
                    INDEX idx_admin_user_audit_action (action),
                    INDEX idx_admin_user_audit_target_user (target_user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
            );
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function summarizePayload(array $payload, string $reason): string
    {
        $chunks = [];

        if (isset($payload['changed_fields']) && is_array($payload['changed_fields']) && $payload['changed_fields'] !== []) {
            $chunks[] = 'Champs modifies: '.implode(', ', array_map('strval', $payload['changed_fields']));
        }

        if (isset($payload['role'])) {
            $chunks[] = 'Role: '.(string) $payload['role'];
        }

        if (isset($payload['statut'])) {
            $chunks[] = 'Statut: '.(string) $payload['statut'];
        }

        if (isset($payload['last_login_at']) && (string) $payload['last_login_at'] !== '') {
            $chunks[] = 'Derniere connexion: '.(string) $payload['last_login_at'];
        }

        if ($reason !== '') {
            $chunks[] = 'Motif: '.$reason;
        }

        return implode(' | ', $chunks);
    }

    private function safeString(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : substr($value, 0, 190);
    }
}
