<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

class NotificationService
{
    private const THRESHOLDS = [1, 10, 20, 50, 100, 200, 500, 1000];
    private const SESSION_TTL = 1800;
    private const PREVIEW_LIMIT = 100;

    /** @var array<int, list<array<string, mixed>>> */
    private array $recentPreviewCache = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function trackAnnonceView(int $annonceId, ?int $viewerId): void
    {
        if ($annonceId <= 0) {
            return;
        }

        try {
            $session = $this->requestStack->getSession();
            $key = 'seen_annonce_'.$annonceId;
            $now = time();
            $last = (int) $session->get($key, 0);
            if ($last && ($now - $last) < self::SESSION_TTL) {
                return;
            }
            $session->set($key, $now);
        } catch (\Throwable) {
            // no session: still count the view
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT proprietaireId, titre, vues, vues_palier FROM annonce WHERE id = :id',
                ['id' => $annonceId]
            );
        } catch (\Throwable) {
            return;
        }
        if (!$row) {
            return;
        }

        $ownerId = (int) ($row['proprietaireId'] ?? 0);
        if ($viewerId !== null && $viewerId === $ownerId) {
            return;
        }

        try {
            $this->connection->executeStatement(
                'UPDATE annonce SET vues = COALESCE(vues, 0) + 1 WHERE id = :id',
                ['id' => $annonceId]
            );
        } catch (\Throwable) {
            return;
        }

        $newVues = ((int) ($row['vues'] ?? 0)) + 1;
        $lastPalier = (int) ($row['vues_palier'] ?? 0);
        $reached = $lastPalier;
        foreach (self::THRESHOLDS as $t) {
            if ($t > $lastPalier && $newVues >= $t) {
                $reached = $t;
            }
        }

        if ($reached > $lastPalier && $ownerId > 0) {
            $message = sprintf(
                'Votre annonce "%s" a dépassé %d consultations.',
                (string) ($row['titre'] ?? 'sans titre'),
                $reached
            );
            try {
                $this->connection->executeStatement(
                    'INSERT INTO notification (destinataire_id, annonce_id, type, message, is_read, created_at)
                     VALUES (:d, :a, :t, :m, 0, NOW())',
                    ['d' => $ownerId, 'a' => $annonceId, 't' => 'VUES_PALIER', 'm' => $message]
                );
                unset($this->recentPreviewCache[$ownerId]);
                $this->connection->executeStatement(
                    'UPDATE annonce SET vues_palier = :p WHERE id = :id',
                    ['p' => $reached, 'id' => $annonceId]
                );
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    public function getUnreadCount(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $count = 0;
        foreach ($this->getRecentPreview($userId) as $notification) {
            if ((int) ($notification['is_read'] ?? 1) === 0) {
                ++$count;
            }
        }

        return $count;
    }

    /** @return list<array<string, mixed>> */
    public function getRecent(int $userId, int $limit = 10): array
    {
        if ($userId <= 0) {
            return [];
        }
        $limit = max(1, min(100, $limit));

        if ($limit <= self::PREVIEW_LIMIT) {
            return array_slice($this->getRecentPreview($userId), 0, $limit);
        }

        try {
            return $this->connection->fetchAllAssociative(
                'SELECT id, annonce_id, type, message, is_read, created_at
                 FROM notification WHERE destinataire_id = :u
                 ORDER BY id DESC LIMIT '.$limit,
                ['u' => $userId]
            );
        } catch (\Throwable) {
            return [];
        }
    }

    public function markRead(int $id, int $userId): void
    {
        if ($id <= 0 || $userId <= 0) {
            return;
        }
        try {
            $this->connection->executeStatement(
                'UPDATE notification SET is_read = 1 WHERE id = :id AND destinataire_id = :u',
                ['id' => $id, 'u' => $userId]
            );
            unset($this->recentPreviewCache[$userId]);
        } catch (\Throwable) {
        }
    }

    public function markAllRead(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            $this->connection->executeStatement(
                'UPDATE notification SET is_read = 1 WHERE destinataire_id = :u AND is_read = 0',
                ['u' => $userId]
            );
            unset($this->recentPreviewCache[$userId]);
        } catch (\Throwable) {
        }
    }

    /**
     * Insère une notification générique dans la table `notification` existante.
     * Utilisé par le moteur de détection d'anomalies (fuites / surconsommation).
     *
     * @param int    $userId  ID du destinataire (locataire ou propriétaire)
     * @param string $type    Le type de notification (ex: 'ALERTE_CONSOMMATION', 'ALERTE_BIEN')
     * @param string $message Le message affiché à l'utilisateur
     */
    public function addNotification(int $userId, string $type, string $message): void
    {
        if ($userId <= 0 || $message === '') {
            return;
        }
        try {
            $this->connection->executeStatement(
                'INSERT INTO notification (destinataire_id, annonce_id, type, message, is_read, created_at)
                 VALUES (:d, NULL, :t, :m, 0, NOW())',
                ['d' => $userId, 't' => $type, 'm' => $message]
            );
            unset($this->recentPreviewCache[$userId]);
        } catch (\Throwable $e) {
            error_log('[NotificationService] addNotification error: ' . $e->getMessage());
        }
    }

    /** @return list<array<string, mixed>> */
    private function getRecentPreview(int $userId): array
    {
        if (isset($this->recentPreviewCache[$userId])) {
            return $this->recentPreviewCache[$userId];
        }

        try {
            $notifications = $this->connection->fetchAllAssociative(
                'SELECT id, annonce_id, type, message, is_read, created_at
                 FROM notification WHERE destinataire_id = :u
                 ORDER BY id DESC LIMIT '.self::PREVIEW_LIMIT,
                ['u' => $userId]
            );
        } catch (\Throwable) {
            $notifications = [];
        }

        return $this->recentPreviewCache[$userId] = $notifications;
    }
}
