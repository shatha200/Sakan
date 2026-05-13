<?php

namespace App\Service;

use App\Entity\Utilisateur;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

class UserSecurityStateService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function bootstrapUser(Utilisateur $user, bool $emailVerified = true): void
    {
        $userId = (int) $user->getId();
        if ($userId <= 0) {
            return;
        }

        try {
            $this->connection->executeStatement(
                'INSERT INTO user_security_state (
                    user_id,
                    email_verified,
                    email_verified_at,
                    failed_warning_sent,
                    updated_at
                ) VALUES (
                    :user_id,
                    :email_verified,
                    :email_verified_at,
                    0,
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    updated_at = NOW()',
                [
                    'user_id' => $userId,
                    'email_verified' => $emailVerified ? 1 : 0,
                    'email_verified_at' => $emailVerified ? (new \DateTimeImmutable())->format('Y-m-d H:i:s') : null,
                ]
            );
        } catch (\Throwable) {
            // Keep auth flows working even if the security state table is unavailable.
        }
    }

    public function isEmailVerified(Utilisateur $user): bool
    {
        if (trim((string) $user->getGoogleSub()) !== '') {
            return true;
        }

        $userId = (int) $user->getId();
        if ($userId <= 0) {
            return true;
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT email_verified FROM user_security_state WHERE user_id = :user_id LIMIT 1',
                ['user_id' => $userId]
            );
        } catch (\Throwable) {
            return true;
        }

        if (!is_array($row)) {
            return true;
        }

        return (int) ($row['email_verified'] ?? 1) === 1;
    }

    public function markEmailVerified(Utilisateur $user): void
    {
        $this->upsertState((int) $user->getId(), [
            'email_verified' => 1,
            'email_verified_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function noteSuccessfulLogin(Utilisateur $user, ?string $ipAddress): void
    {
        $loginData = [
            'last_login_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'last_login_ip' => $this->normalizeIp($ipAddress),
        ];

        $this->upsertState((int) $user->getId(), [
            'last_login_at' => $loginData['last_login_at'],
            'last_login_ip' => $loginData['last_login_ip'],
            'failed_warning_sent' => 0,
        ]);
        $this->cacheLastLoginData((int) $user->getId(), $loginData);
    }

    public function hasFailedWarningBeenSent(Utilisateur $user): bool
    {
        $userId = (int) $user->getId();
        if ($userId <= 0) {
            return false;
        }

        try {
            $value = $this->connection->fetchOne(
                'SELECT failed_warning_sent FROM user_security_state WHERE user_id = :user_id',
                ['user_id' => $userId]
            );
        } catch (\Throwable) {
            return false;
        }

        return (int) $value === 1;
    }

    public function noteFailedWarningSent(Utilisateur $user): void
    {
        $this->upsertState((int) $user->getId(), [
            'failed_warning_sent' => 1,
        ]);
    }

    public function notePasswordChanged(Utilisateur $user): void
    {
        $this->upsertState((int) $user->getId(), [
            'last_password_change_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array{last_login_at:?string,last_login_ip:?string}
     */
    public function getLastLoginData(Utilisateur $user): array
    {
        return $this->getLastLoginDataByUserId((int) $user->getId());
    }

    /**
     * @return array{last_login_at:?string,last_login_ip:?string}
     */
    public function getLastLoginDataFromSession(Utilisateur $user): array
    {
        return $this->getCachedLastLoginData((int) $user->getId()) ?? [
            'last_login_at' => null,
            'last_login_ip' => null,
        ];
    }

    /**
     * @return array{last_login_at:?string,last_login_ip:?string}
     */
    public function getLastLoginDataByUserId(int $userId): array
    {
        if ($userId <= 0) {
            return [
                'last_login_at' => null,
                'last_login_ip' => null,
            ];
        }

        $cached = $this->getCachedLastLoginData($userId);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT last_login_at, last_login_ip FROM user_security_state WHERE user_id = :user_id LIMIT 1',
                ['user_id' => $userId]
            );
        } catch (\Throwable) {
            $row = false;
        }

        if (!is_array($row)) {
            return [
                'last_login_at' => null,
                'last_login_ip' => null,
            ];
        }

        $loginData = [
            'last_login_at' => isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
            'last_login_ip' => isset($row['last_login_ip']) ? (string) $row['last_login_ip'] : null,
        ];
        $this->cacheLastLoginData($userId, $loginData);

        return $loginData;
    }

    public function ensureStateTableExists(): bool
    {
        // Schema changes are managed outside request handling; never run DDL here.
        return true;
    }

    /**
     * @param array<string,mixed> $values
     */
    private function upsertState(int $userId, array $values): void
    {
        if ($userId <= 0) {
            return;
        }

        $allowed = [
            'email_verified',
            'email_verified_at',
            'last_login_at',
            'last_login_ip',
            'last_password_change_at',
            'failed_warning_sent',
        ];

        $columns = ['user_id'];
        $placeholders = [':user_id'];
        $updates = [];
        $params = ['user_id' => $userId];

        foreach ($allowed as $column) {
            if (!array_key_exists($column, $values)) {
                continue;
            }

            $columns[] = $column;
            $placeholders[] = ':'.$column;
            $updates[] = $column.' = VALUES('.$column.')';
            $params[$column] = $values[$column];
        }

        $updates[] = 'updated_at = NOW()';

        try {
            $this->connection->executeStatement(
                'INSERT INTO user_security_state ('.implode(', ', $columns).') VALUES ('.implode(', ', $placeholders).')
                 ON DUPLICATE KEY UPDATE '.implode(', ', $updates),
                $params
            );
        } catch (\Throwable) {
            // Ignore state persistence failures.
        }
    }

    private function normalizeIp(?string $ipAddress): ?string
    {
        $ipAddress = trim((string) $ipAddress);
        if ($ipAddress === '') {
            return null;
        }

        return substr($ipAddress, 0, 64);
    }

    /**
     * @param array{last_login_at:?string,last_login_ip:?string} $loginData
     */
    private function cacheLastLoginData(int $userId, array $loginData): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            $this->requestStack->getSession()->set($this->lastLoginSessionKey($userId), $loginData);
        } catch (\Throwable) {
        }
    }

    /**
     * @return array{last_login_at:?string,last_login_ip:?string}|null
     */
    private function getCachedLastLoginData(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        try {
            $value = $this->requestStack->getSession()->get($this->lastLoginSessionKey($userId));
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($value)) {
            return null;
        }

        return [
            'last_login_at' => isset($value['last_login_at']) ? (string) $value['last_login_at'] : null,
            'last_login_ip' => isset($value['last_login_ip']) ? (string) $value['last_login_ip'] : null,
        ];
    }

    private function lastLoginSessionKey(int $userId): string
    {
        return 'user_security_last_login_'.$userId;
    }
}
