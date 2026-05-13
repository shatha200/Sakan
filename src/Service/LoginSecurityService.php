<?php

namespace App\Service;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

class LoginSecurityService
{
    public const MAX_FAILED_ATTEMPTS = 5;
    public const SUPPORT_EMAIL = 'sakan.admin@gmail.com';

    public function __construct(
        private readonly Connection $connection,
        private readonly UtilisateurRepository $utilisateurRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserSecurityStateService $userSecurityStateService,
        private readonly SecurityNotificationService $securityNotificationService,
    ) {
    }

    public function getLockMessage(): string
    {
        return sprintf(
            'Compte desactive apres %d tentatives de mot de passe incorrectes. Merci de contacter notre support sur %s.',
            self::MAX_FAILED_ATTEMPTS,
            self::SUPPORT_EMAIL
        );
    }

    public function registerSuccessfulLogin(Utilisateur $user, ?string $ipAddress): void
    {
        $identifier = strtolower(trim((string) $user->getEmail()));
        if ($identifier === '') {
            return;
        }

        $this->ensureLoginAttemptTable();
        $this->insertAttempt(
            $identifier,
            $user->getId(),
            true,
            $this->normalizeIpAddress($ipAddress),
            'login_success'
        );
        $this->userSecurityStateService->noteSuccessfulLogin($user, $ipAddress);
        $this->securityNotificationService->sendLoginNotice($user, $ipAddress);
    }

    public function registerFailedAttempt(string $identifier, ?string $ipAddress): bool
    {
        $normalizedIdentifier = strtolower(trim($identifier));
        if ($normalizedIdentifier === '') {
            return false;
        }

        $this->ensureLoginAttemptTable();

        $user = $this->utilisateurRepository->findOneByIdentifier($normalizedIdentifier);
        $userId = $user instanceof Utilisateur ? $user->getId() : null;
        $attemptId = $this->insertAttempt(
            $normalizedIdentifier,
            $userId,
            false,
            $this->normalizeIpAddress($ipAddress),
            $user instanceof Utilisateur ? 'bad_password' : 'unknown_user'
        );

        if (!$user instanceof Utilisateur) {
            return false;
        }

        if (strtoupper((string) $user->getStatut()) === 'SUSPENDU') {
            return false;
        }

        $failedAttempts = $this->countConsecutiveFailedAttempts($normalizedIdentifier, $userId);

        if (
            $failedAttempts >= 2
            && !$this->userSecurityStateService->hasFailedWarningBeenSent($user)
        ) {
            $this->securityNotificationService->sendFailedAttemptsWarning($user, $failedAttempts);
            $this->userSecurityStateService->noteFailedWarningSent($user);
        }

        if ($failedAttempts < self::MAX_FAILED_ATTEMPTS) {
            return false;
        }

        $user->setStatut('SUSPENDU');
        $this->entityManager->flush();

        if ($attemptId !== null) {
            $this->markAttemptAsLockTriggered($attemptId);
        }

        $this->securityNotificationService->sendAccountSuspended($user);

        return true;
    }

    private function ensureLoginAttemptTable(): void
    {
        try {
            $this->connection->executeStatement(
                'CREATE TABLE IF NOT EXISTS `auth_login_attempt` (' .
                '`id` INT AUTO_INCREMENT PRIMARY KEY, ' .
                '`identifier` VARCHAR(190) NOT NULL, ' .
                '`user_id` INT NULL, ' .
                '`success` TINYINT(1) NOT NULL DEFAULT 0, ' .
                '`reason` VARCHAR(64) NOT NULL DEFAULT \'bad_password\', ' .
                '`ip_address` VARCHAR(64) NULL, ' .
                '`attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, ' .
                '`lock_triggered` TINYINT(1) NOT NULL DEFAULT 0, ' .
                'INDEX `idx_auth_login_attempt_identifier` (`identifier`), ' .
                'INDEX `idx_auth_login_attempt_user` (`user_id`), ' .
                'INDEX `idx_auth_login_attempt_attempted_at` (`attempted_at`) )'
            );
        } catch (\Throwable) {
            // The authenticator should keep working even if audit persistence fails.
        }
    }

    private function insertAttempt(
        string $identifier,
        ?int $userId,
        bool $success,
        ?string $ipAddress,
        string $reason,
    ): ?int {
        try {
            $this->connection->executeStatement(
                'INSERT INTO `auth_login_attempt` (`identifier`, `user_id`, `success`, `reason`, `ip_address`, `attempted_at`, `lock_triggered`) ' .
                'VALUES (:identifier, :user_id, :success, :reason, :ip_address, NOW(), 0)',
                [
                    'identifier' => $identifier,
                    'user_id' => $userId,
                    'success' => $success ? 1 : 0,
                    'reason' => substr(trim($reason), 0, 64),
                    'ip_address' => $ipAddress,
                ]
            );

            return (int) $this->connection->lastInsertId();
        } catch (\Throwable) {
            return null;
        }
    }

    private function markAttemptAsLockTriggered(int $attemptId): void
    {
        try {
            $this->connection->executeStatement(
                'UPDATE `auth_login_attempt` SET `lock_triggered` = 1 WHERE `id` = :id',
                ['id' => $attemptId]
            );
        } catch (\Throwable) {
            // Ignore audit flag failures.
        }
    }

    private function countConsecutiveFailedAttempts(string $identifier, ?int $userId): int
    {
        try {
            if ($userId !== null) {
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT `success` FROM `auth_login_attempt` WHERE `user_id` = :user_id OR `identifier` = :identifier ' .
                    'ORDER BY `attempted_at` DESC, `id` DESC LIMIT 20',
                    [
                        'user_id' => $userId,
                        'identifier' => $identifier,
                    ]
                );
            } else {
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT `success` FROM `auth_login_attempt` WHERE `identifier` = :identifier ' .
                    'ORDER BY `attempted_at` DESC, `id` DESC LIMIT 20',
                    ['identifier' => $identifier]
                );
            }
        } catch (\Throwable) {
            return 0;
        }

        $count = 0;
        foreach ($rows as $row) {
            if ((int) ($row['success'] ?? 0) === 1) {
                break;
            }

            $count++;
            if ($count >= self::MAX_FAILED_ATTEMPTS) {
                break;
            }
        }

        return $count;
    }

    private function normalizeIpAddress(?string $ipAddress): ?string
    {
        $ipAddress = trim((string) $ipAddress);
        if ($ipAddress === '') {
            return null;
        }

        return substr($ipAddress, 0, 64);
    }
}
