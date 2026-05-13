<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class AdminDataService
{
    /** @var array<string, bool> */
    private array $tableExistsCache = [];
    /** @var array<string, array<string, string>> */
    private array $columnsCache = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /** @return array<string, mixed> */
    public function getDashboardData(): array
    {
        return [
            'metrics' => [
                'users' => $this->countRows('utilisateur'),
                'annonces' => $this->countWithStatusLike('annonce', ['statut', 'status'], 'DISPONIBLE'),
                'reservations' => $this->countRows('reservation'),
                'reclamations' => $this->countWithStatusLike('reclamation', ['statut', 'status'], 'RESOLU'),
            ],
            'security_metrics' => $this->getSecurityMetrics(),
            'user_growth' => $this->getUsersGrowth(),
            'security_daily' => $this->getSecurityDailyOverview(),
            'annonce_status' => $this->getAnnonceStatusDistribution(),
            'recent_reclamations' => $this->getReclamations('', '', 5),
            'recent_annonces' => $this->getAnnonces('', '', 5),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function getUsers(string $search = '', string $role = ''): array
    {
        if (!$this->tableExists('utilisateur')) {
            return [];
        }

        $id = $this->fc('utilisateur', ['id']);
        if ($id === null) {
            return [];
        }

        $nom = $this->fc('utilisateur', ['nom']);
        $email = $this->fc('utilisateur', ['email']);
        $roleCol = $this->fc('utilisateur', ['role']);
        $statut = $this->fc('utilisateur', ['statut', 'status']);
        $tel = $this->fc('utilisateur', ['telephone']);
        $date = $this->fc('utilisateur', ['dateInscription', 'date_inscription', 'created_at']);
        $hasSecurityState = $this->tableExists('user_security_state');

        $sql = 'SELECT u.'.$this->id($id).' AS id, '.
            ($nom ? 'COALESCE(u.'.$this->id($nom).", '')" : "''").' AS nom, '.
            ($email ? 'COALESCE(u.'.$this->id($email).", '')" : "''").' AS email, '.
            ($roleCol ? 'COALESCE(u.'.$this->id($roleCol).", '')" : "''").' AS role, '.
            ($statut ? 'COALESCE(u.'.$this->id($statut).", '')" : "'ACTIF'").' AS statut, '.
            ($tel ? 'COALESCE(u.'.$this->id($tel).", '')" : "''").' AS telephone, '.
            ($date ? 'u.'.$this->id($date) : 'NULL').' AS date_inscription, '.
            ($hasSecurityState ? 'uss.last_login_at' : 'NULL').' AS last_login_at, '.
            ($hasSecurityState ? 'uss.last_login_ip' : 'NULL').' AS last_login_ip '.
            'FROM '.$this->id('utilisateur').' u';
        if ($hasSecurityState) {
            $sql .= ' LEFT JOIN '.$this->id('user_security_state').' uss ON uss.user_id = u.'.$this->id($id);
        }

        $where = [];
        $params = [];

        if (trim($search) !== '') {
            $params['q'] = '%'.strtolower(trim($search)).'%';
            $parts = [];
            foreach ([$nom, $email, $tel] as $col) {
                if ($col !== null) {
                    $parts[] = 'LOWER(COALESCE(u.'.$this->id($col).", '')) LIKE :q";
                }
            }
            if (!empty($parts)) {
                $where[] = '('.implode(' OR ', $parts).')';
            }
        }

        if ($roleCol !== null && trim($role) !== '' && strtoupper($role) !== 'TOUS') {
            $where[] = 'UPPER(COALESCE(u.'.$this->id($roleCol).", '')) = :r";
            $params['r'] = strtoupper(trim($role));
        }

        if (!empty($where)) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY u.'.$this->id($id).' DESC LIMIT 500';

        try {
            $rows = $this->connection->fetchAllAssociative($sql, $params);
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['role'] = strtoupper((string) ($row['role'] ?? ''));
            $row['statut'] = strtoupper((string) ($row['statut'] ?? 'ACTIF'));
            $row['date_inscription'] = $this->fmtDate($row['date_inscription'] ?? null);
            $row['last_login_at'] = $this->fmtDateTime($row['last_login_at'] ?? null);
            $row['last_login_ip'] = trim((string) ($row['last_login_ip'] ?? '')) ?: '-';
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function getUsersStats(): array
    {
        $out = ['total' => 0, 'active' => 0, 'suspended' => 0, 'new_this_month' => 0];
        $out['total'] = $this->countRows('utilisateur');
        if (!$this->tableExists('utilisateur')) {
            return $out;
        }

        $statusCol = $this->fc('utilisateur', ['statut', 'status']);
        $dateCol = $this->fc('utilisateur', ['dateInscription', 'date_inscription', 'created_at']);
        if ($statusCol !== null) {
            $out['active'] = $this->countByExactStatus('utilisateur', $statusCol, 'ACTIF');
            $out['suspended'] = $this->countByExactStatus('utilisateur', $statusCol, 'SUSPENDU');
        }
        if ($dateCol !== null) {
            try {
                $out['new_this_month'] = (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM '.$this->id('utilisateur').' WHERE YEAR('.$this->id($dateCol).') = YEAR(CURRENT_DATE()) AND MONTH('.$this->id($dateCol).') = MONTH(CURRENT_DATE())'
                );
            } catch (\Throwable) {
                $out['new_this_month'] = 0;
            }
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public function getAnnonces(string $search = '', string $status = '', int $limit = 300): array
    {
        if (!$this->tableExists('annonce')) {
            return [];
        }
        $id = $this->fc('annonce', ['id']);
        $titre = $this->fc('annonce', ['titre', 'title']);
        $statut = $this->fc('annonce', ['statut', 'status']);
        if ($id === null || $titre === null) {
            return [];
        }

        $fk = $this->fc('annonce', ['proprietaireId', 'id_utilisateur', 'id_proprietaire', 'proprietaire_id']);
        $canJoin = $fk !== null && $this->tableExists('utilisateur');
        $uId = $canJoin ? $this->fc('utilisateur', ['id']) : null;
        $uNom = $canJoin ? $this->fc('utilisateur', ['nom']) : null;
        $uEmail = $canJoin ? $this->fc('utilisateur', ['email']) : null;

        $sql = 'SELECT a.'.$this->id($id).' AS id, COALESCE(a.'.$this->id($titre).", '') AS titre, ".
            ($statut ? 'COALESCE(a.'.$this->id($statut).", '')" : "'NON DEFINI'").' AS statut, '.
            ($fk ? 'a.'.$this->id($fk) : 'NULL').' AS owner_id, '.
            (($canJoin && $uNom) ? 'COALESCE(u.'.$this->id($uNom).", '')" : "'-'").' AS owner_name, '.
            (($canJoin && $uEmail) ? 'COALESCE(u.'.$this->id($uEmail).", '')" : "'-'").' AS owner_email '.
            'FROM '.$this->id('annonce').' a';
        if ($canJoin && $uId !== null) {
            $sql .= ' LEFT JOIN '.$this->id('utilisateur').' u ON a.'.$this->id($fk).' = u.'.$this->id($uId);
        }

        $where = [];
        $params = [];
        if (trim($search) !== '') {
            $params['q'] = '%'.strtolower(trim($search)).'%';
            $parts = ['LOWER(COALESCE(a.'.$this->id($titre).", '')) LIKE :q"];
            if ($canJoin && $uNom !== null) {
                $parts[] = 'LOWER(COALESCE(u.'.$this->id($uNom).", '')) LIKE :q";
            }
            if ($canJoin && $uEmail !== null) {
                $parts[] = 'LOWER(COALESCE(u.'.$this->id($uEmail).", '')) LIKE :q";
            }
            $where[] = '('.implode(' OR ', $parts).')';
        }
        if ($statut !== null && trim($status) !== '' && strtoupper($status) !== 'TOUS') {
            $where[] = 'UPPER(COALESCE(a.'.$this->id($statut).", '')) LIKE :s";
            $params['s'] = '%'.strtoupper(trim($status)).'%';
        }
        if (!empty($where)) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY a.'.$this->id($id).' DESC LIMIT '.max(1, min(1000, $limit));

        try {
            $rows = $this->connection->fetchAllAssociative($sql, $params);
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['statut'] = strtoupper((string) ($row['statut'] ?? 'NON DEFINI'));
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function getAnnoncesStats(): array
    {
        $stats = ['total' => 0, 'disponibles' => 0, 'occupees' => 0, 'bientot' => 0];
        $list = $this->getAnnonces('', '', 1000);
        $stats['total'] = count($list);
        foreach ($list as $row) {
            $s = strtoupper((string) ($row['statut'] ?? ''));
            if (str_contains($s, 'DISP')) {
                $stats['disponibles']++;
            } elseif (str_contains($s, 'OCC')) {
                $stats['occupees']++;
            } elseif (str_contains($s, 'BIENT')) {
                $stats['bientot']++;
            }
        }

        return $stats;
    }

    /** @return array<int, array<string, mixed>> */
    public function getReservations(string $search = '', string $status = '', int $limit = 300): array
    {
        return $this->linkedRows('reservation', ['locataireId', 'id_client', 'id_utilisateur', 'locataire_id'], $search, $status, $limit, 'Reservation #');
    }

    /** @return array<string, mixed> */
    public function getReservationsStats(): array
    {
        return $this->statusStats(
            $this->getReservations('', '', 1000),
            [
                'pending' => ['ATTENTE', 'PENDING'],
                'confirmed' => ['CONFIRM'],
                'cancelled' => ['ANNUL', 'CANCEL'],
            ]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function getContrats(string $search = '', string $status = '', int $limit = 300): array
    {
        if (!$this->tableExists('contrat')) {
            return [];
        }

        try {
            $sql = "
                SELECT
                    c.id,
                    UPPER(COALESCE(c.statut, '')) AS statut,
                    COALESCE(c.montant, '-') AS montant,
                    COALESCE(c.date_debut, '') AS date_debut,
                    COALESCE(c.date_fin, '') AS date_fin,
                    COALESCE(c.signe_locataire, 0) AS signe_locataire,
                    COALESCE(c.signe_proprietaire, 0) AS signe_proprietaire,
                    COALESCE(loc.nom, '-') AS locataire_nom,
                    COALESCE(loc.email, '') AS locataire_email,
                    COALESCE(prop.nom, '-') AS proprietaire_nom,
                    COALESCE(prop.email, '') AS proprietaire_email,
                    COALESCE(a.titre, '-') AS annonce_titre
                FROM contrat c
                LEFT JOIN utilisateur loc  ON c.locataireId    = loc.id
                LEFT JOIN annonce a         ON c.annonceId       = a.id
                LEFT JOIN utilisateur prop  ON a.proprietaireId  = prop.id
            ";

            $where = [];
            $params = [];

            if (trim($search) !== '') {
                $params['q'] = '%' . strtolower(trim($search)) . '%';
                $where[] = "(CAST(c.id AS CHAR) LIKE :q
                          OR LOWER(COALESCE(loc.nom,''))   LIKE :q
                          OR LOWER(COALESCE(loc.email,'')) LIKE :q
                          OR LOWER(COALESCE(prop.nom,''))  LIKE :q
                          OR LOWER(COALESCE(a.titre,''))   LIKE :q)";
            }
            if (trim($status) !== '' && strtoupper($status) !== 'TOUS') {
                $params['s'] = '%' . strtoupper(trim($status)) . '%';
                $where[] = "UPPER(COALESCE(c.statut,'')) LIKE :s";
            }

            if ($where) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= ' ORDER BY c.id DESC LIMIT ' . max(1, min(1000, $limit));

            $rows = $this->connection->fetchAllAssociative($sql, $params);

            foreach ($rows as &$row) {
                $row['label'] = 'Contrat #' . $row['id'];
                $row['client_name'] = $row['locataire_nom'];
            }

            return $rows;
        } catch (\Throwable) {
            return $this->linkedRows('contrat', ['locataireId', 'id_client', 'id_utilisateur', 'id_locataire'], $search, $status, $limit, 'Contrat #');
        }
    }

    /** @return array<string, mixed> */
    public function getContratsStats(): array
    {
        return $this->statusStats(
            $this->getContrats('', '', 1000),
            [
                'active' => ['ACTIF'],
                'ended' => ['RESIL', 'TERMINE', 'CLOTURE'],
                'pending' => ['ATTENTE', 'PENDING', 'SIGNATURE'],
            ]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function getUserContrats(int $userId): array
    {
        if (!$this->tableExists('contrat')) {
            return [];
        }
        try {
            $sql = "
                SELECT
                    c.id,
                    UPPER(COALESCE(c.statut, '')) AS statut,
                    COALESCE(c.montant, '-') AS montant,
                    COALESCE(c.date_debut, '') AS date_debut,
                    COALESCE(c.date_fin, '') AS date_fin,
                    COALESCE(c.signe_locataire, 0) AS signe_locataire,
                    COALESCE(c.signe_proprietaire, 0) AS signe_proprietaire,
                    COALESCE(loc.nom, '-') AS locataire_nom,
                    COALESCE(prop.nom, '-') AS proprietaire_nom,
                    COALESCE(a.titre, '-') AS annonce_titre
                FROM contrat c
                LEFT JOIN utilisateur loc  ON c.locataireId   = loc.id
                LEFT JOIN annonce a         ON c.annonceId      = a.id
                LEFT JOIN utilisateur prop  ON a.proprietaireId = prop.id
                WHERE c.locataireId = :uid OR a.proprietaireId = :uid
                ORDER BY c.id DESC
                LIMIT 50
            ";
            return $this->connection->fetchAllAssociative($sql, ['uid' => $userId]);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getReclamations(string $search = '', string $status = '', int $limit = 300): array
    {
        if (!$this->tableExists('reclamation')) {
            return [];
        }
        $id = $this->fc('reclamation', ['id']);
        if ($id === null) {
            return [];
        }

        $type = $this->fc('reclamation', ['type', 'type_id']);
        $typeAutre = $this->fc('reclamation', ['type_autre', 'typeAutre']);
        $statut = $this->fc('reclamation', ['statut', 'status']);
        $description = $this->fc('reclamation', ['description']);
        $date = $this->fc('reclamation', ['date', 'date_creation', 'created_at']);
        $fk = $this->fc('reclamation', ['id_demandeur', 'id_utilisateur', 'idUtilisateur', 'locataire_id']);
        $canJoin = $fk !== null && $this->tableExists('utilisateur');
        $uId = $canJoin ? $this->fc('utilisateur', ['id']) : null;
        $uNom = $canJoin ? $this->fc('utilisateur', ['nom']) : null;

        $hasTypeJoin = $this->tableExists('reclamation_type') && $type !== null;
        $rtId = $hasTypeJoin ? $this->fc('reclamation_type', ['id']) : null;
        $rtLibelle = $hasTypeJoin ? $this->fc('reclamation_type', ['libelle']) : null;
        $hasTypeJoin = $hasTypeJoin && $rtId && $rtLibelle;

        $typeExpr = "'-'";
        if ($hasTypeJoin) {
            if ($typeAutre) {
                $typeExpr = "COALESCE(CONCAT(rt.".$this->id($rtLibelle).", IF(r.".$this->id($typeAutre)." IS NOT NULL AND r.".$this->id($typeAutre)." != '', CONCAT(' (', r.".$this->id($typeAutre).", ')'), '')), '-')";
            } else {
                $typeExpr = "COALESCE(rt.".$this->id($rtLibelle).", '-')";
            }
        } elseif ($type) {
            $typeExpr = "COALESCE(r.".$this->id($type).", '-')";
        }

        $sql = 'SELECT r.'.$this->id($id).' AS id, '.
            $typeExpr.' AS type, '.
            ($statut ? 'COALESCE(r.'.$this->id($statut).", '')" : "'-'").' AS statut, '.
            ($description ? 'r.'.$this->id($description) : "''").' AS description, '.
            ($date ? 'r.'.$this->id($date) : 'NULL').' AS date_reclamation, '.
            (($canJoin && $uNom) ? 'COALESCE(u.'.$this->id($uNom).", '')" : "'-'").' AS utilisateur '.
            'FROM '.$this->id('reclamation').' r';
        if ($canJoin && $uId !== null) {
            $sql .= ' LEFT JOIN '.$this->id('utilisateur').' u ON r.'.$this->id($fk).' = u.'.$this->id($uId);
        }
        if ($hasTypeJoin) {
            $sql .= ' LEFT JOIN '.$this->id('reclamation_type').' rt ON r.'.$this->id((string)$type).' = rt.'.$this->id((string)$rtId);
        }

        $where = [];
        $params = [];
        if (trim($search) !== '') {
            $params['q'] = '%'.strtolower(trim($search)).'%';
            $parts = ['CAST(r.'.$this->id($id).' AS CHAR) LIKE :q'];
            if ($hasTypeJoin) {
                $parts[] = 'LOWER(COALESCE(rt.'.$this->id($rtLibelle).", '')) LIKE :q";
            } elseif ($type !== null) {
                $parts[] = 'LOWER(COALESCE(r.'.$this->id($type).", '')) LIKE :q";
            }
            if ($canJoin && $uNom !== null) {
                $parts[] = 'LOWER(COALESCE(u.'.$this->id($uNom).", '')) LIKE :q";
            }
            $where[] = '('.implode(' OR ', $parts).')';
        }
        if ($statut !== null && trim($status) !== '' && strtoupper($status) !== 'TOUS') {
            $where[] = 'UPPER(COALESCE(r.'.$this->id($statut).", '')) LIKE :s";
            $params['s'] = '%'.strtoupper(trim($status)).'%';
        }
        if (!empty($where)) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY r.'.$this->id($id).' DESC LIMIT '.max(1, min(1000, $limit));

        try {
            $rows = $this->connection->fetchAllAssociative($sql, $params);
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['statut'] = strtoupper((string) ($row['statut'] ?? ''));
            $row['date_reclamation'] = $this->fmtDate($row['date_reclamation'] ?? null);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function getReclamationsStats(): array
    {
        return $this->statusStats(
            $this->getReclamations('', '', 1000),
            [
                'open' => ['EN_ATTENTE', 'ATTENTE', 'OUVERT'],
                'progress' => ['EN_COURS', 'COURS'],
                'resolved' => ['RESOLU', 'RESOL'],
            ]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function getProprietaires(): array
    {
        if (!$this->tableExists('utilisateur')) {
            return [];
        }
        $id = $this->fc('utilisateur', ['id']);
        $nom = $this->fc('utilisateur', ['nom']);
        $email = $this->fc('utilisateur', ['email']);
        $roleCol = $this->fc('utilisateur', ['role']);
        if ($id === null || $roleCol === null) {
            return [];
        }

        $sql = 'SELECT '.$this->id($id).' AS id, '.
            ($nom ? 'COALESCE('.$this->id($nom).", '')" : "''").' AS nom, '.
            ($email ? 'COALESCE('.$this->id($email).", '')" : "''").' AS email '.
            'FROM '.$this->id('utilisateur').
            ' WHERE UPPER(COALESCE('.$this->id($roleCol).", '')) = 'PROPRIETAIRE'".
            ' ORDER BY '.($nom ? $this->id($nom) : $this->id($id)).' ASC';

        try {
            return $this->connection->fetchAllAssociative($sql);
        } catch (\Throwable) {
            return [];
        }
    }

    public function createAnnonce(?int $ownerId, string $title, string $status, ?string $description = null): bool
    {
        if (!$this->tableExists('annonce')) {
            return false;
        }
        $titleCol = $this->fc('annonce', ['titre', 'title']);
        $statusCol = $this->fc('annonce', ['statut', 'status']);
        $fkCol = $this->fc('annonce', ['proprietaireId', 'id_utilisateur', 'id_proprietaire', 'proprietaire_id']);
        $descCol = $this->fc('annonce', ['description']);
        if ($titleCol === null) {
            return false;
        }

        $statusNorm = strtolower(trim($status));
        $map = ['disponible' => 'disponible', 'occupe' => 'occupe', 'occupé' => 'occupe', 'bientot' => 'bientot', 'bientôt' => 'bientot', 'bientot disponible' => 'bientot'];
        $statusNorm = $map[$statusNorm] ?? 'disponible';

        $cols = [$this->id($titleCol)];
        $placeholders = [':t'];
        $params = ['t' => trim($title)];

        if ($statusCol !== null) {
            $cols[] = $this->id($statusCol);
            $placeholders[] = ':s';
            $params['s'] = $statusNorm;
        }
        if ($fkCol !== null && $ownerId !== null && $ownerId > 0) {
            $cols[] = $this->id($fkCol);
            $placeholders[] = ':pid';
            $params['pid'] = $ownerId;
        }
        if ($descCol !== null && $description !== null && trim($description) !== '') {
            $cols[] = $this->id($descCol);
            $placeholders[] = ':d';
            $params['d'] = trim($description);
        }

        $sql = 'INSERT INTO '.$this->id('annonce').' ('.implode(', ', $cols).') VALUES ('.implode(', ', $placeholders).')';

        try {
            return $this->connection->executeStatement($sql, $params) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function updateAnnonce(int $id, string $title, string $status): bool
    {
        if (!$this->tableExists('annonce')) {
            return false;
        }
        $idCol = $this->fc('annonce', ['id']);
        $titleCol = $this->fc('annonce', ['titre', 'title']);
        $statusCol = $this->fc('annonce', ['statut', 'status']);
        if ($idCol === null || $titleCol === null || $statusCol === null) {
            return false;
        }

        $statusNorm = strtolower(trim($status));
        $map = ['disponible' => 'disponible', 'occupe' => 'occupe', 'occupé' => 'occupe', 'bientot' => 'bientot', 'bientôt' => 'bientot', 'bientot disponible' => 'bientot'];
        $statusNorm = $map[$statusNorm] ?? $statusNorm;

        try {
            return $this->connection->executeStatement(
                'UPDATE '.$this->id('annonce').' SET '.$this->id($titleCol).' = :t, '.$this->id($statusCol).' = :s WHERE '.$this->id($idCol).' = :id',
                ['t' => trim($title), 's' => $statusNorm, 'id' => $id]
            ) >= 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function updateReservationStatus(int $id, string $status): bool
    {
        return $this->updateStatus('reservation', $id, $status);
    }

    public function updateContratStatus(int $id, string $status): bool
    {
        return $this->updateStatus('contrat', $id, $status);
    }

    public function updateReclamation(int $id, string $type, string $status): bool
    {
        if (!$this->tableExists('reclamation')) {
            return false;
        }
        $idCol = $this->fc('reclamation', ['id']);
        $typeCol = $this->fc('reclamation', ['type']);
        $statusCol = $this->fc('reclamation', ['statut', 'status']);
        if ($idCol === null || $statusCol === null) {
            return false;
        }

        $set = [$this->id($statusCol).' = :s'];
        $params = ['s' => strtoupper(trim($status)), 'id' => $id];
        if ($typeCol !== null) {
            $set[] = $this->id($typeCol).' = :t';
            $params['t'] = trim($type);
        }

        try {
            return $this->connection->executeStatement(
                'UPDATE '.$this->id('reclamation').' SET '.implode(', ', $set).' WHERE '.$this->id($idCol).' = :id',
                $params
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public function deleteAnnonce(int $id): bool
    {
        if (!$this->tableExists('annonce')) {
            return false;
        }

        try {
            $children = $this->connection->fetchAllAssociative(
                'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE '.
                'WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = :t AND REFERENCED_COLUMN_NAME = :c',
                ['t' => 'annonce', 'c' => 'id']
            );
        } catch (\Throwable) {
            $children = [];
        }

        try {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        } catch (\Throwable) {
            // ignore if not permitted; we'll rely on explicit child deletes
        }

        try {
            foreach ($children as $child) {
                $table = (string) ($child['TABLE_NAME'] ?? '');
                $column = (string) ($child['COLUMN_NAME'] ?? '');
                if ($table === '' || $column === '') {
                    continue;
                }
                try {
                    $this->connection->executeStatement(
                        'DELETE FROM '.$this->id($table).' WHERE '.$this->id($column).' = :id',
                        ['id' => $id]
                    );
                } catch (\Throwable) {
                    // continue best-effort
                }
            }

            $affected = $this->connection->executeStatement(
                'DELETE FROM '.$this->id('annonce').' WHERE id = :id',
                ['id' => $id]
            );
        } catch (\Throwable) {
            try { $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1'); } catch (\Throwable) {}
            return false;
        }

        try { $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1'); } catch (\Throwable) {}

        return $affected > 0;
    }
    public function deleteReservation(int $id): bool { return $this->deleteById('reservation', $id); }
    public function deleteContrat(int $id): bool { return $this->deleteById('contrat', $id); }
   

    public function deleteReclamation(int $id): bool
    {
        if (!$this->tableExists('reclamation')) {
            return false;
        }

        try {
            if ($this->tableExists('traitement')) {
                $this->connection->executeStatement(
                    'DELETE FROM '.$this->id('traitement').' WHERE '.$this->id('reclamationId').' = :id',
                    ['id' => $id]
                );
            }
        } catch (\Throwable) {}

        try {
            if ($this->tableExists('reclamation_image')) {
                $this->connection->executeStatement(
                    'DELETE FROM '.$this->id('reclamation_image').' WHERE '.$this->id('reclamationId').' = :id',
                    ['id' => $id]
                );
            }
        } catch (\Throwable) {}

        return $this->deleteById('reclamation', $id);
    }

    /** @return array<string, string> */
    public function getAdminSettings(): array
    {
        $defaults = [
            'notif_email' => 'admin@sakan.tn',
            'notif_reservation' => '1',
            'notif_reclamation' => '1',
            'notif_retard' => '0',
            'sec_2fa' => '0',
            'sec_lock' => '1',
            'sec_audit' => '1',
            'ui_animations' => '1',
            'ui_compact' => '0',
        ];

        if (!$this->ensureSettingsTable()) {
            return $defaults;
        }

        try {
            $rows = $this->connection->fetchAllAssociative('SELECT setting_key, setting_value FROM '.$this->id('admin_setting'));
        } catch (\Throwable) {
            return $defaults;
        }

        foreach ($rows as $row) {
            $k = (string) ($row['setting_key'] ?? '');
            if ($k !== '' && array_key_exists($k, $defaults)) {
                $defaults[$k] = (string) ($row['setting_value'] ?? '');
            }
        }

        return $defaults;
    }

    /** @param array<string, mixed> $settings */
    public function saveAdminSettings(array $settings): bool
    {
        if (!$this->ensureSettingsTable()) {
            return false;
        }

        $keys = [
            'notif_email',
            'notif_reservation',
            'notif_reclamation',
            'notif_retard',
            'sec_2fa',
            'sec_lock',
            'sec_audit',
            'ui_animations',
            'ui_compact',
        ];

        $sql = 'INSERT INTO '.$this->id('admin_setting').' (setting_key, setting_value, updated_at) VALUES (:k, :v, NOW()) '.
            'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()';
        try {
            foreach ($keys as $key) {
                if (!array_key_exists($key, $settings)) {
                    continue;
                }
                $this->connection->executeStatement($sql, ['k' => $key, 'v' => (string) $settings[$key]]);
            }
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    public function checkDatabaseConnection(): bool
    {
        try {
            $this->connection->fetchOne('SELECT 1');
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /** @return array<string, string> */
    public function createSqlExportFile(string $targetDir): array
    {
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Impossible de creer le dossier d export SQL.');
        }

        $databaseName = 'database';
        try {
            $raw = (string) $this->connection->fetchOne('SELECT DATABASE()');
            if ($raw !== '') {
                $databaseName = $raw;
            }
        } catch (\Throwable) {
            // Keep default name.
        }

        $safeDbName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $databaseName) ?: 'database';
        $timestamp = (new \DateTimeImmutable())->format('Ymd_His');
        $fileName = sprintf('%s_export_%s.sql', $safeDbName, $timestamp);
        $filePath = rtrim($targetDir, '\\/').DIRECTORY_SEPARATOR.$fileName;

        $handle = @fopen($filePath, 'wb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Impossible de creer le fichier d export SQL.');
        }

        try {
            fwrite($handle, "-- Export SQL Sakan\n");
            fwrite($handle, '-- Date: '.(new \DateTimeImmutable())->format('Y-m-d H:i:s')."\n");
            fwrite($handle, '-- Base: '.$databaseName."\n\n");
            fwrite($handle, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
            fwrite($handle, "START TRANSACTION;\n");
            fwrite($handle, "SET NAMES utf8mb4;\n");

            $tables = $this->listTables();
            foreach ($tables as $table) {
                $createStatement = $this->fetchCreateTableStatement($table);
                if ($createStatement === null) {
                    continue;
                }

                fwrite($handle, "\n-- ----------------------------\n");
                fwrite($handle, '-- Table: '.$table."\n");
                fwrite($handle, "-- ----------------------------\n");
                fwrite($handle, 'DROP TABLE IF EXISTS '.$this->id($table).";\n");
                fwrite($handle, $createStatement.";\n\n");
                $this->writeTableRowsAsInsertStatements($handle, $table);
                fwrite($handle, "\n");
            }

            fwrite($handle, "COMMIT;\n");
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($filePath);
            throw new \RuntimeException('Echec de l export SQL: '.$exception->getMessage(), 0, $exception);
        }

        fclose($handle);

        return [
            'path' => $filePath,
            'name' => $fileName,
        ];
    }

    /** @return array<string, string> */
    public function createCsvExportFile(string $targetDir): array
    {
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Impossible de creer le dossier d export CSV.');
        }

        $databaseName = 'database';
        try {
            $raw = (string) $this->connection->fetchOne('SELECT DATABASE()');
            if ($raw !== '') {
                $databaseName = $raw;
            }
        } catch (\Throwable) {
            // Keep default name.
        }

        $safeDbName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $databaseName) ?: 'database';
        $timestamp = (new \DateTimeImmutable())->format('Ymd_His');
        $fileName = sprintf('%s_export_%s.csv', $safeDbName, $timestamp);
        $filePath = rtrim($targetDir, '\\/').DIRECTORY_SEPARATOR.$fileName;

        $handle = @fopen($filePath, 'wb');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Impossible de creer le fichier d export CSV.');
        }

        try {
            // UTF-8 BOM for better Excel compatibility on Windows.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Export CSV Sakan']);
            fputcsv($handle, ['Date', (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
            fputcsv($handle, ['Base', $databaseName]);
            fwrite($handle, "\n");

            $tables = $this->listTables();
            foreach ($tables as $table) {
                fputcsv($handle, ['# Table', $table]);
                $this->writeTableRowsAsCsv($handle, $table);
                fwrite($handle, "\n");
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($filePath);
            throw new \RuntimeException('Echec de l export CSV: '.$exception->getMessage(), 0, $exception);
        }

        fclose($handle);

        return [
            'path' => $filePath,
            'name' => $fileName,
        ];
    }

    /** @return array<int, string> */
    private function listTables(): array
    {
        try {
            $rows = $this->connection->fetchFirstColumn('SHOW TABLES');
        } catch (\Throwable) {
            return [];
        }

        $tables = [];
        foreach ($rows as $row) {
            $name = (string) $row;
            if ($name !== '') {
                $tables[] = $name;
            }
        }

        return $tables;
    }

    private function fetchCreateTableStatement(string $table): ?string
    {
        try {
            $row = $this->connection->fetchNumeric('SHOW CREATE TABLE '.$this->id($table));
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($row) || !isset($row[1])) {
            return null;
        }

        return (string) $row[1];
    }

    private function writeTableRowsAsInsertStatements(mixed $handle, string $table): void
    {
        try {
            $stmt = $this->connection->executeQuery('SELECT * FROM '.$this->id($table));
        } catch (\Throwable) {
            return;
        }

        $columns = null;
        while (($row = $stmt->fetchAssociative()) !== false) {
            if (!is_array($columns)) {
                $columns = array_keys($row);
            }

            $quotedColumns = array_map(fn (string $column): string => $this->id($column), $columns);
            $values = [];
            foreach ($columns as $column) {
                $values[] = $this->toSqlLiteral($row[$column] ?? null);
            }

            $sql = 'INSERT INTO '.$this->id($table).' ('.implode(', ', $quotedColumns).') VALUES ('.implode(', ', $values).");\n";
            fwrite($handle, $sql);
        }
    }

    private function writeTableRowsAsCsv(mixed $handle, string $table): void
    {
        $columns = $this->fetchTableColumns($table);
        if (!empty($columns)) {
            fputcsv($handle, $columns);
        }

        try {
            $stmt = $this->connection->executeQuery('SELECT * FROM '.$this->id($table));
        } catch (\Throwable) {
            fputcsv($handle, ['Erreur de lecture de la table '.$table]);
            return;
        }

        while (($row = $stmt->fetchAssociative()) !== false) {
            if (empty($columns)) {
                $columns = array_keys($row);
                fputcsv($handle, $columns);
            }

            $values = [];
            foreach ($columns as $column) {
                $values[] = $this->toCsvValue($row[$column] ?? null);
            }

            fputcsv($handle, $values);
        }
    }

    /** @return array<int, string> */
    private function fetchTableColumns(string $table): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative('SHOW COLUMNS FROM '.$this->id($table));
        } catch (\Throwable) {
            return [];
        }

        $columns = [];
        foreach ($rows as $row) {
            $name = (string) ($row['Field'] ?? '');
            if ($name !== '') {
                $columns[] = $name;
            }
        }

        return $columns;
    }

    private function toCsvValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_resource($value)) {
            @rewind($value);
            return '0x'.bin2hex((string) stream_get_contents($value));
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function toSqlLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if ($value instanceof \DateTimeInterface) {
            return "'".$this->escapeSqlString($value->format('Y-m-d H:i:s'))."'";
        }

        if (is_resource($value)) {
            @rewind($value);
            $bytes = (string) stream_get_contents($value);
            return "X'".bin2hex($bytes)."'";
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $stringValue = (string) $value;
        if ($stringValue === '') {
            return "''";
        }

        if (!preg_match('//u', $stringValue) || str_contains($stringValue, "\0")) {
            return "X'".bin2hex($stringValue)."'";
        }

        return "'".$this->escapeSqlString($stringValue)."'";
    }

    private function escapeSqlString(string $value): string
    {
        return str_replace(
            ["\\", "\x00", "\n", "\r", "\x1a", "'"],
            ["\\\\", "\\0", "\\n", "\\r", "\\Z", "\\'"],
            $value
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function getUsersGrowth(): array
    {
        if (!$this->tableExists('utilisateur')) {
            return [];
        }
        $dateCol = $this->fc('utilisateur', ['dateInscription', 'date_inscription', 'created_at']);
        if ($dateCol === null) {
            return [];
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT DATE_FORMAT('.$this->id($dateCol).", '%Y-%m') AS ym, COUNT(*) AS c FROM ".$this->id('utilisateur').
                ' WHERE '.$this->id($dateCol).' IS NOT NULL GROUP BY ym ORDER BY ym DESC LIMIT 8'
            );
        } catch (\Throwable) {
            return [];
        }

        $rows = array_reverse($rows);
        $out = [];
        foreach ($rows as $row) {
            $ym = (string) ($row['ym'] ?? '');
            if ($ym === '') {
                continue;
            }
            $d = \DateTimeImmutable::createFromFormat('Y-m', $ym);
            $out[] = ['label' => $d ? $d->format('M Y') : $ym, 'count' => (int) ($row['c'] ?? 0)];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function getSecurityMetrics(): array
    {
        $metrics = [
            'registrations_today' => 0,
            'failed_logins_today' => 0,
            'disabled_accounts' => 0,
            'google_accounts' => 0,
        ];

        if ($this->tableExists('utilisateur')) {
            $dateCol = $this->fc('utilisateur', ['dateInscription', 'date_inscription', 'created_at']);
            $statusCol = $this->fc('utilisateur', ['statut', 'status']);
            $googleCol = $this->fc('utilisateur', ['google_sub']);

            if ($dateCol !== null) {
                try {
                    $metrics['registrations_today'] = (int) $this->connection->fetchOne(
                        'SELECT COUNT(*) FROM '.$this->id('utilisateur').' WHERE DATE('.$this->id($dateCol).') = CURRENT_DATE()'
                    );
                } catch (\Throwable) {
                    $metrics['registrations_today'] = 0;
                }
            }

            if ($statusCol !== null) {
                $metrics['disabled_accounts'] = $this->countByExactStatus('utilisateur', $statusCol, 'SUSPENDU');
            }

            if ($googleCol !== null) {
                try {
                    $metrics['google_accounts'] = (int) $this->connection->fetchOne(
                        'SELECT COUNT(*) FROM '.$this->id('utilisateur').' WHERE TRIM(COALESCE('.$this->id($googleCol).", '')) <> ''"
                    );
                } catch (\Throwable) {
                    $metrics['google_accounts'] = 0;
                }
            }
        }

        if ($this->ensureLoginAttemptTable()) {
            try {
                $metrics['failed_logins_today'] = (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM '.$this->id('auth_login_attempt').' WHERE `success` = 0 AND DATE(`attempted_at`) = CURRENT_DATE()'
                );
            } catch (\Throwable) {
                $metrics['failed_logins_today'] = 0;
            }
        }

        return $metrics;
    }

    /** @return array<int, array<string, mixed>> */
    private function getSecurityDailyOverview(int $days = 7): array
    {
        $days = max(1, min(31, $days));
        $series = [];
        $start = new \DateTimeImmutable('-'.($days - 1).' days');

        for ($i = 0; $i < $days; $i++) {
            $date = $start->modify('+'.$i.' days');
            $key = $date->format('Y-m-d');
            $series[$key] = [
                'label' => $date->format('d M'),
                'registrations' => 0,
                'failed_logins' => 0,
            ];
        }

        if ($this->tableExists('utilisateur')) {
            $dateCol = $this->fc('utilisateur', ['dateInscription', 'date_inscription', 'created_at']);
            if ($dateCol !== null) {
                try {
                    $rows = $this->connection->fetchAllAssociative(
                        'SELECT DATE('.$this->id($dateCol).') AS d, COUNT(*) AS c FROM '.$this->id('utilisateur').
                        ' WHERE '.$this->id($dateCol).' >= :start GROUP BY DATE('.$this->id($dateCol).') ORDER BY d ASC',
                        ['start' => $start->format('Y-m-d 00:00:00')]
                    );
                    foreach ($rows as $row) {
                        $key = (string) ($row['d'] ?? '');
                        if (isset($series[$key])) {
                            $series[$key]['registrations'] = (int) ($row['c'] ?? 0);
                        }
                    }
                } catch (\Throwable) {
                    // Keep zero-filled series.
                }
            }
        }

        if ($this->ensureLoginAttemptTable()) {
            try {
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT DATE(`attempted_at`) AS d, COUNT(*) AS c FROM '.$this->id('auth_login_attempt').
                    ' WHERE `success` = 0 AND `attempted_at` >= :start GROUP BY DATE(`attempted_at`) ORDER BY d ASC',
                    ['start' => $start->format('Y-m-d 00:00:00')]
                );
                foreach ($rows as $row) {
                    $key = (string) ($row['d'] ?? '');
                    if (isset($series[$key])) {
                        $series[$key]['failed_logins'] = (int) ($row['c'] ?? 0);
                    }
                }
            } catch (\Throwable) {
                // Keep zero-filled series.
            }
        }

        return array_values($series);
    }

    /** @return array<int, array<string, mixed>> */
    private function getAnnonceStatusDistribution(): array
    {
        if (!$this->tableExists('annonce')) {
            return [];
        }
        $statusCol = $this->fc('annonce', ['statut', 'status']);
        if ($statusCol === null) {
            return [];
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT UPPER(COALESCE('.$this->id($statusCol).", 'NON DEFINI')) AS s, COUNT(*) AS c FROM ".$this->id('annonce').' GROUP BY s ORDER BY c DESC'
            );
        } catch (\Throwable) {
            return [];
        }

        $total = 0;
        foreach ($rows as $row) {
            $total += (int) ($row['c'] ?? 0);
        }
        $out = [];
        foreach ($rows as $row) {
            $count = (int) ($row['c'] ?? 0);
            $out[] = [
                'status' => (string) ($row['s'] ?? 'NON DEFINI'),
                'count' => $count,
                'percent' => $total > 0 ? (int) round(($count / $total) * 100) : 0,
            ];
        }

        return $out;
    }

    /**
     * @param array<int, string> $fkCandidates
     * @return array<int, array<string, mixed>>
     */
    private function linkedRows(string $table, array $fkCandidates, string $search, string $status, int $limit, string $prefix): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }
        $id = $this->fc($table, ['id']);
        $statut = $this->fc($table, ['statut', 'status']);
        if ($id === null) {
            return [];
        }

        $fk = $this->fc($table, $fkCandidates);
        $canJoin = $fk !== null && $this->tableExists('utilisateur');
        $uId = $canJoin ? $this->fc('utilisateur', ['id']) : null;
        $uNom = $canJoin ? $this->fc('utilisateur', ['nom']) : null;

        $sql = 'SELECT x.'.$this->id($id).' AS id, '.
            ($statut ? 'COALESCE(x.'.$this->id($statut).", '')" : "'-'").' AS statut, '.
            ($fk ? 'x.'.$this->id($fk) : 'NULL').' AS client_id, '.
            (($canJoin && $uNom) ? 'COALESCE(u.'.$this->id($uNom).", '')" : "'-'").' AS client_name '.
            'FROM '.$this->id($table).' x';
        if ($canJoin && $uId !== null) {
            $sql .= ' LEFT JOIN '.$this->id('utilisateur').' u ON x.'.$this->id($fk).' = u.'.$this->id($uId);
        }

        $where = [];
        $params = [];
        if (trim($search) !== '') {
            $params['q'] = '%'.strtolower(trim($search)).'%';
            $parts = ['CAST(x.'.$this->id($id).' AS CHAR) LIKE :q'];
            if ($canJoin && $uNom !== null) {
                $parts[] = 'LOWER(COALESCE(u.'.$this->id($uNom).", '')) LIKE :q";
            }
            $where[] = '('.implode(' OR ', $parts).')';
        }
        if ($statut !== null && trim($status) !== '' && strtoupper($status) !== 'TOUS') {
            $where[] = 'UPPER(COALESCE(x.'.$this->id($statut).", '')) LIKE :s";
            $params['s'] = '%'.strtoupper(trim($status)).'%';
        }
        if (!empty($where)) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }
        $sql .= ' ORDER BY x.'.$this->id($id).' DESC LIMIT '.max(1, min(1000, $limit));

        try {
            $rows = $this->connection->fetchAllAssociative($sql, $params);
        } catch (\Throwable) {
            return [];
        }
        foreach ($rows as &$row) {
            $row['statut'] = strtoupper((string) ($row['statut'] ?? ''));
            $row['label'] = $prefix.(string) $row['id'];
            if (trim((string) ($row['client_name'] ?? '')) === '' && isset($row['client_id'])) {
                $row['client_name'] = 'ID '.$row['client_id'];
            }
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array<int, string>> $rules
     * @return array<string, int>
     */
    private function statusStats(array $rows, array $rules): array
    {
        $out = ['total' => count($rows)];
        foreach (array_keys($rules) as $key) {
            $out[$key] = 0;
        }

        foreach ($rows as $row) {
            $status = strtoupper((string) ($row['statut'] ?? ''));
            foreach ($rules as $key => $needles) {
                foreach ($needles as $needle) {
                    if (str_contains($status, strtoupper($needle))) {
                        $out[$key]++;
                        continue 3;
                    }
                }
            }
        }

        return $out;
    }

    private function updateStatus(string $table, int $id, string $status): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }
        $idCol = $this->fc($table, ['id']);
        $statusCol = $this->fc($table, ['statut', 'status']);
        if ($idCol === null || $statusCol === null) {
            return false;
        }

        try {
            return $this->connection->executeStatement(
                'UPDATE '.$this->id($table).' SET '.$this->id($statusCol).' = :s WHERE '.$this->id($idCol).' = :id',
                ['s' => strtoupper(trim($status)), 'id' => $id]
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function deleteById(string $table, int $id): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }
        $idCol = $this->fc($table, ['id']);
        if ($idCol === null) {
            return false;
        }

        try {
            return $this->connection->executeStatement(
                'DELETE FROM '.$this->id($table).' WHERE '.$this->id($idCol).' = :id',
                ['id' => $id]
            ) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function ensureSettingsTable(): bool
    {
        if ($this->tableExists('admin_setting')) {
            return true;
        }
        try {
            $this->connection->executeStatement(
                'CREATE TABLE IF NOT EXISTS '.$this->id('admin_setting').' ('.
                $this->id('id').' INT AUTO_INCREMENT PRIMARY KEY, '.
                $this->id('setting_key').' VARCHAR(120) NOT NULL UNIQUE, '.
                $this->id('setting_value').' TEXT NULL, '.
                $this->id('updated_at').' DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)'
            );
        } catch (\Throwable) {
            return false;
        }

        $this->tableExistsCache = [];
        $this->columnsCache = [];

        return $this->tableExists('admin_setting');
    }

    private function ensureLoginAttemptTable(): bool
    {
        if ($this->tableExists('auth_login_attempt')) {
            return true;
        }

        try {
            $this->connection->executeStatement(
                'CREATE TABLE IF NOT EXISTS '.$this->id('auth_login_attempt').' ('.
                $this->id('id').' INT AUTO_INCREMENT PRIMARY KEY, '.
                $this->id('identifier').' VARCHAR(190) NOT NULL, '.
                $this->id('user_id').' INT NULL, '.
                $this->id('success').' TINYINT(1) NOT NULL DEFAULT 0, '.
                $this->id('reason')." VARCHAR(64) NOT NULL DEFAULT 'bad_password', ".
                $this->id('ip_address').' VARCHAR(64) NULL, '.
                $this->id('attempted_at').' DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, '.
                $this->id('lock_triggered').' TINYINT(1) NOT NULL DEFAULT 0, '.
                'INDEX '.$this->id('idx_auth_login_attempt_identifier').' ('.$this->id('identifier').'), '.
                'INDEX '.$this->id('idx_auth_login_attempt_user').' ('.$this->id('user_id').'), '.
                'INDEX '.$this->id('idx_auth_login_attempt_attempted_at').' ('.$this->id('attempted_at').'))'
            );
        } catch (\Throwable) {
            return false;
        }

        $this->tableExistsCache = [];
        $this->columnsCache = [];

        return $this->tableExists('auth_login_attempt');
    }

    private function countRows(string $table): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        try {
            return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM '.$this->id($table));
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countByExactStatus(string $table, string $statusCol, string $wanted): int
    {
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM '.$this->id($table).' WHERE UPPER(COALESCE('.$this->id($statusCol).", '')) = :s",
                ['s' => strtoupper($wanted)]
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @param array<int, string> $statusCandidates */
    private function countWithStatusLike(string $table, array $statusCandidates, string $needle): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        $statusCol = $this->fc($table, $statusCandidates);
        if ($statusCol === null) {
            return $this->countRows($table);
        }
        try {
            return (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM '.$this->id($table).' WHERE UPPER(COALESCE('.$this->id($statusCol).", '')) LIKE :s",
                ['s' => '%'.strtoupper($needle).'%']
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableExistsCache)) {
            return $this->tableExistsCache[$table];
        }
        try {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
                ['t' => $table]
            ) > 0;
        } catch (\Throwable) {
            $exists = false;
        }
        $this->tableExistsCache[$table] = $exists;

        return $exists;
    }

    /** @param array<int, string> $candidates */
    private function fc(string $table, array $candidates): ?string
    {
        $columns = $this->columns($table);
        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($columns[$key])) {
                return $columns[$key];
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function columns(string $table): array
    {
        if (isset($this->columnsCache[$table])) {
            return $this->columnsCache[$table];
        }
        if (!$this->tableExists($table)) {
            $this->columnsCache[$table] = [];
            return [];
        }
        try {
            $list = $this->connection->fetchFirstColumn(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t',
                ['t' => $table]
            );
        } catch (\Throwable) {
            $list = [];
        }
        $out = [];
        foreach ($list as $col) {
            $name = (string) $col;
            if ($name !== '') {
                $out[strtolower($name)] = $name;
            }
        }
        $this->columnsCache[$table] = $out;

        return $out;
    }

    private function id(string $identifier): string
    {
        return '`'.str_replace('`', '', $identifier).'`';
    }

    private function fmtDate(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '-';
        }
        try {
            return (new \DateTimeImmutable((string) $raw))->format('d/m/Y');
        } catch (\Throwable) {
            return (string) $raw;
        }
    }

    private function fmtDateTime(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            return '-';
        }

        try {
            return (new \DateTimeImmutable((string) $raw))->format('d/m/Y H:i');
        } catch (\Throwable) {
            return (string) $raw;
        }
    }

    // -----------------------------------------------------------------------
    // Fiche utilisateur
    // -----------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public function getUserFiche(int $id): ?array
    {
        $profile = $this->getUserFicheProfile($id);
        if ($profile === null) {
            return null;
        }

        $role = strtoupper((string) ($profile['role'] ?? ''));

        $reservations = $role === 'PROPRIETAIRE'
            ? $this->getUserFicheContratsViaAnnonce('reservation', $id)
            : $this->getUserFicheLinkedRows('reservation', ['locataireId', 'id_client', 'id_utilisateur', 'locataire_id'], $id);

        $contrats = $role === 'PROPRIETAIRE'
            ? $this->getUserFicheContratsViaAnnonce('contrat', $id)
            : $this->getUserFicheLinkedRows('contrat', ['locataireId', 'id_client', 'id_utilisateur', 'id_locataire'], $id);

        return [
            'profile' => $profile,
            'security' => $this->getUserFicheSecurity($id),
            'login_attempts' => $this->getUserFicheLoginAttempts($id),
            'reservations' => $reservations,
            'contrats' => $contrats,
            'reclamations' => $this->getUserFicheReclamations($id),
            'paiements_loyer' => $this->getUserFichePaiementsLoyer($id, $role),
            'paiements_charges' => $this->getUserFichePaiementsCharges($id, $role),
            'audit_actions' => $this->getUserFicheAuditActions($id),
            'email_history' => $this->getUserFicheEmailHistory($id),
            'annonces' => $role === 'PROPRIETAIRE' ? $this->getUserFicheAnnonces($id) : [],
            'webauthn' => $this->getUserFicheWebAuthn($id),
            'face'     => $this->getUserFicheFace($id),
        ];
    }

    /** @return array<string, mixed>|null */
    private function getUserFicheProfile(int $id): ?array
    {
        if (!$this->tableExists('utilisateur')) {
            return null;
        }

        $idCol = $this->fc('utilisateur', ['id']);
        if ($idCol === null) {
            return null;
        }

        $nom = $this->fc('utilisateur', ['nom']);
        $email = $this->fc('utilisateur', ['email']);
        $roleCol = $this->fc('utilisateur', ['role']);
        $statut = $this->fc('utilisateur', ['statut', 'status']);
        $tel = $this->fc('utilisateur', ['telephone']);
        $date = $this->fc('utilisateur', ['dateInscription', 'date_inscription', 'created_at']);
        $googleSub = $this->fc('utilisateur', ['google_sub']);
        $twoFa = $this->fc('utilisateur', ['two_factor_enabled']);
        $telVerified = $this->fc('utilisateur', ['telephone_verified']);

        $sql =
            'SELECT u.'.$this->id($idCol).' AS id, '.
            ($nom ? 'COALESCE(u.'.$this->id($nom).", '') AS nom, " : "'' AS nom, ").
            ($email ? 'COALESCE(u.'.$this->id($email).", '') AS email, " : "'' AS email, ").
            ($roleCol ? 'COALESCE(u.'.$this->id($roleCol).", '') AS role, " : "'' AS role, ").
            ($statut ? 'COALESCE(u.'.$this->id($statut).", 'ACTIF') AS statut, " : "'ACTIF' AS statut, ").
            ($tel ? 'COALESCE(u.'.$this->id($tel).", '') AS telephone, " : "'' AS telephone, ").
            ($date ? 'u.'.$this->id($date).' AS date_inscription, ' : 'NULL AS date_inscription, ').
            ($googleSub ? 'u.'.$this->id($googleSub).' AS google_sub, ' : 'NULL AS google_sub, ').
            ($twoFa ? 'u.'.$this->id($twoFa).' AS two_factor_enabled, ' : '0 AS two_factor_enabled, ').
            ($telVerified ? 'u.'.$this->id($telVerified).' AS telephone_verified ' : '0 AS telephone_verified ').
            'FROM '.$this->id('utilisateur').' u '.
            'WHERE u.'.$this->id($idCol).' = :id LIMIT 1';

        try {
            $row = $this->connection->fetchAssociative($sql, ['id' => $id]);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($row)) {
            return null;
        }

        $row['role'] = strtoupper((string) ($row['role'] ?? ''));
        $row['statut'] = strtoupper((string) ($row['statut'] ?? 'ACTIF'));
        $row['date_inscription'] = $this->fmtDate($row['date_inscription'] ?? null);
        $row['is_google'] = trim((string) ($row['google_sub'] ?? '')) !== '';
        $row['two_factor_enabled'] = (bool) (int) ($row['two_factor_enabled'] ?? 0);
        $row['telephone_verified'] = (bool) (int) ($row['telephone_verified'] ?? 0);

        return $row;
    }

    /** @return array<string, mixed> */
    private function getUserFicheSecurity(int $id): array
    {
        $out = [
            'email_verified' => true,
            'email_verified_at' => null,
            'last_login_at' => null,
            'last_login_ip' => null,
            'last_password_change_at' => null,
        ];

        if (!$this->tableExists('user_security_state')) {
            return $out;
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT email_verified, email_verified_at, last_login_at, last_login_ip, last_password_change_at FROM user_security_state WHERE user_id = :id LIMIT 1',
                ['id' => $id]
            );
        } catch (\Throwable) {
            return $out;
        }

        if (!is_array($row)) {
            return $out;
        }

        $out['email_verified'] = (bool) (int) ($row['email_verified'] ?? 1);
        $out['email_verified_at'] = $row['email_verified_at'] !== null ? $this->fmtDateTime($row['email_verified_at']) : null;
        $out['last_login_at'] = $row['last_login_at'] !== null ? $this->fmtDateTime($row['last_login_at']) : null;
        $out['last_login_ip'] = trim((string) ($row['last_login_ip'] ?? '')) ?: null;
        $out['last_password_change_at'] = $row['last_password_change_at'] !== null ? $this->fmtDateTime($row['last_password_change_at']) : null;

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function getUserFicheLoginAttempts(int $id, int $limit = 20): array
    {
        if (!$this->tableExists('auth_login_attempt')) {
            return [];
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT success, reason, ip_address, attempted_at, lock_triggered FROM auth_login_attempt WHERE user_id = :id ORDER BY attempted_at DESC LIMIT '.max(1, min(100, $limit)),
                ['id' => $id]
            );
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['success'] = (bool) (int) ($row['success'] ?? 0);
            $row['lock_triggered'] = (bool) (int) ($row['lock_triggered'] ?? 0);
            $row['attempted_at'] = $this->fmtDateTime($row['attempted_at'] ?? null);
        }

        return $rows;
    }

    /**
     * @param array<int, string> $fkCandidates
     * @return array<int, array<string, mixed>>
     */
    private function getUserFicheLinkedRows(string $table, array $fkCandidates, int $userId, int $limit = 50): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }

        $idCol = $this->fc($table, ['id']);
        $fk = $this->fc($table, $fkCandidates);
        if ($idCol === null || $fk === null) {
            return [];
        }

        $statut = $this->fc($table, ['statut', 'status', 'etat']);
        $dateDebut = $this->fc($table, ['dateDebut', 'date_debut', 'date_reservation', 'date_creation', 'created_at', 'date', 'dateCreation']);
        $dateFin = $this->fc($table, ['dateFin', 'date_fin', 'date_echeance', 'date_expiration']);
        $montant = $this->fc($table, ['montant', 'montant_loyer', 'montant_charges', 'prix', 'amount', 'loyer']);

        $sql =
            'SELECT x.'.$this->id($idCol).' AS id, '.
            ($statut ? 'COALESCE(x.'.$this->id($statut).", '') AS statut, " : "'' AS statut, ").
            ($dateDebut ? 'x.'.$this->id($dateDebut).' AS date_debut, ' : 'NULL AS date_debut, ').
            ($dateFin ? 'x.'.$this->id($dateFin).' AS date_fin, ' : 'NULL AS date_fin, ').
            ($montant ? 'x.'.$this->id($montant).' AS montant ' : 'NULL AS montant ').
            'FROM '.$this->id($table).' x '.
            'WHERE x.'.$this->id($fk).' = :uid '.
            'ORDER BY x.'.$this->id($idCol).' DESC '.
            'LIMIT '.max(1, min(200, $limit));

        try {
            $rows = $this->connection->fetchAllAssociative($sql, ['uid' => $userId]);
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['statut'] = strtoupper((string) ($row['statut'] ?? ''));
            $row['date_debut'] = $row['date_debut'] !== null ? $this->fmtDate($row['date_debut']) : '-';
            $row['date_fin'] = $row['date_fin'] !== null ? $this->fmtDate($row['date_fin']) : '-';
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function getUserFichePaiementsLoyer(int $userId, string $role): array
    {
        if (!$this->tableExists('paiement_loyer') || !$this->tableExists('contrat')) {
            return [];
        }

        try {
            if ($role === 'PROPRIETAIRE') {
                // Propriétaire: paiements des contrats liés à ses annonces
                $sql = '
                    SELECT 
                        pl.id,
                        pl.statut,
                        pl.periode,
                        COALESCE(pl.montant, 0) AS montant,
                        COALESCE(pl.penalite, 0) AS penalite,
                        pl.date_paiement,
                        pl.date_echeance,
                        a.titre AS bien,
                        u.nom AS locataire
                    FROM paiement_loyer pl
                    INNER JOIN contrat c ON pl.contrat_id = c.id
                    INNER JOIN annonce a ON c.annonceId = a.id
                    INNER JOIN utilisateur u ON c.locataireId = u.id
                    WHERE a.proprietaireId = :uid
                    ORDER BY pl.id DESC
                    LIMIT 100
                ';
            } else {
                // Locataire: paiements des contrats où il est locataire
                $sql = '
                    SELECT 
                        pl.id,
                        pl.statut,
                        pl.periode,
                        COALESCE(pl.montant, 0) AS montant,
                        COALESCE(pl.penalite, 0) AS penalite,
                        pl.date_paiement,
                        pl.date_echeance,
                        a.titre AS bien,
                        u.nom AS proprietaire
                    FROM paiement_loyer pl
                    INNER JOIN contrat c ON pl.contrat_id = c.id
                    INNER JOIN annonce a ON c.annonceId = a.id
                    INNER JOIN utilisateur u ON a.proprietaireId = u.id
                    WHERE c.locataireId = :uid
                    ORDER BY pl.id DESC
                    LIMIT 100
                ';
            }

            $rows = $this->connection->fetchAllAssociative($sql, ['uid' => $userId]);
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['statut'] = strtoupper((string) ($row['statut'] ?? ''));
            $row['montant'] = (float) $row['montant'];
            $row['penalite'] = (float) $row['penalite'];
            $row['total'] = $row['montant'] + $row['penalite'];
            $row['date_paiement'] = $row['date_paiement'] !== null ? $this->fmtDate($row['date_paiement']) : '-';
            $row['date_echeance'] = $row['date_echeance'] !== null ? $this->fmtDate($row['date_echeance']) : '-';
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function getUserFichePaiementsCharges(int $userId, string $role): array
    {
        if (!$this->tableExists('paiement_charges') || !$this->tableExists('charges_mensuelles') || !$this->tableExists('contrat')) {
            return [];
        }

        try {
            if ($role === 'PROPRIETAIRE') {
                // Propriétaire: paiements des charges des contrats liés à ses annonces
                $sql = '
                    SELECT 
                        pc.id,
                        COALESCE(pc.montant_paye, 0) AS montant,
                        pc.date_paiement,
                        pc.methode_paiement,
                        pc.reference_transaction,
                        cm.periode,
                        cm.type_charge,
                        a.titre AS bien,
                        u.nom AS locataire
                    FROM paiement_charges pc
                    INNER JOIN charges_mensuelles cm ON pc.charge_id = cm.id
                    INNER JOIN contrat c ON cm.contrat_id = c.id
                    INNER JOIN annonce a ON c.annonceId = a.id
                    INNER JOIN utilisateur u ON c.locataireId = u.id
                    WHERE a.proprietaireId = :uid
                    ORDER BY pc.id DESC
                    LIMIT 100
                ';
            } else {
                // Locataire: paiements des charges des contrats où il est locataire
                $sql = '
                    SELECT 
                        pc.id,
                        COALESCE(pc.montant_paye, 0) AS montant,
                        pc.date_paiement,
                        pc.methode_paiement,
                        pc.reference_transaction,
                        cm.periode,
                        cm.type_charge,
                        a.titre AS bien,
                        u.nom AS proprietaire
                    FROM paiement_charges pc
                    INNER JOIN charges_mensuelles cm ON pc.charge_id = cm.id
                    INNER JOIN contrat c ON cm.contrat_id = c.id
                    INNER JOIN annonce a ON c.annonceId = a.id
                    INNER JOIN utilisateur u ON a.proprietaireId = u.id
                    WHERE c.locataireId = :uid
                    ORDER BY pc.id DESC
                    LIMIT 100
                ';
            }

            $rows = $this->connection->fetchAllAssociative($sql, ['uid' => $userId]);
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['montant'] = (float) $row['montant'];
            $row['date_paiement'] = $row['date_paiement'] !== null ? $this->fmtDate($row['date_paiement']) : '-';
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function getUserFichePaiementsLoyerPaginated(int $userId, string $role, int $page, int $limit): array
    {
        $allItems = $this->getUserFichePaiementsLoyer($userId, $role);

        $adapter = new \Pagerfanta\Adapter\ArrayAdapter($allItems);
        $pagerfanta = new \Pagerfanta\Pagerfanta($adapter);
        $pagerfanta->setMaxPerPage($limit);
        $pagerfanta->setCurrentPage($page);

        return [
            'items' => $pagerfanta->getCurrentPageResults(),
            'pager' => $pagerfanta,
        ];
    }

    /** @return array<string, mixed> */
    public function getUserFichePaiementsChargesPaginated(int $userId, string $role, int $page, int $limit): array
    {
        $allItems = $this->getUserFichePaiementsCharges($userId, $role);

        $adapter = new \Pagerfanta\Adapter\ArrayAdapter($allItems);
        $pagerfanta = new \Pagerfanta\Pagerfanta($adapter);
        $pagerfanta->setMaxPerPage($limit);
        $pagerfanta->setCurrentPage($page);

        return [
            'items' => $pagerfanta->getCurrentPageResults(),
            'pager' => $pagerfanta,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function getUserFicheReclamations(int $id): array
    {
        if (!$this->tableExists('reclamation')) {
            return [];
        }

        $idCol = $this->fc('reclamation', ['id']);
        $fk = $this->fc('reclamation', ['id_demandeur', 'id_utilisateur', 'idUtilisateur']);
        if ($idCol === null || $fk === null) {
            return [];
        }

        $type = $this->fc('reclamation', ['type']);
        $statut = $this->fc('reclamation', ['statut', 'status']);
        $date = $this->fc('reclamation', ['date', 'date_creation', 'created_at', 'dateCreation']);

        $sql =
            'SELECT r.'.$this->id($idCol).' AS id, '.
            ($type ? 'COALESCE(r.'.$this->id($type).", '-') AS type, " : "'-' AS type, ").
            ($statut ? 'COALESCE(r.'.$this->id($statut).", '') AS statut, " : "'' AS statut, ").
            ($date ? 'r.'.$this->id($date).' AS date_reclamation ' : 'NULL AS date_reclamation ').
            'FROM '.$this->id('reclamation').' r '.
            'WHERE r.'.$this->id($fk).' = :uid '.
            'ORDER BY r.'.$this->id($idCol).' DESC LIMIT 50';

        try {
            $rows = $this->connection->fetchAllAssociative($sql, ['uid' => $id]);
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['statut'] = strtoupper((string) ($row['statut'] ?? ''));
            $row['date_reclamation'] = $row['date_reclamation'] !== null ? $this->fmtDate($row['date_reclamation']) : '-';
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function getUserFicheContratsViaAnnonce(string $table, int $userId): array
    {
        if (!$this->tableExists($table) || !$this->tableExists('annonce')) {
            return [];
        }

        $idCol = $this->fc($table, ['id']);
        $annonceFk = $this->fc($table, ['annonceId', 'annonce_id', 'id_annonce']);
        $aId = $this->fc('annonce', ['id']);
        $aPropFk = $this->fc('annonce', ['proprietaireId', 'id_proprietaire', 'proprietaire_id', 'id_utilisateur']);

        if ($idCol === null || $annonceFk === null || $aId === null || $aPropFk === null) {
            return [];
        }

        $statut = $this->fc($table, ['statut', 'status', 'etat']);
        $dateDebut = $this->fc($table, ['dateDebut', 'date_debut', 'date_reservation', 'date_creation', 'created_at', 'date']);
        $dateFin = $this->fc($table, ['dateFin', 'date_fin', 'date_echeance', 'date_expiration']);
        $montant = $this->fc($table, ['montant', 'montant_loyer', 'prix', 'amount', 'loyer']);

        $locFk = $this->fc($table, ['locataireId', 'id_locataire', 'locataire_id', 'id_client']);
        $uId = $locFk !== null && $this->tableExists('utilisateur') ? $this->fc('utilisateur', ['id']) : null;
        $uNom = $uId !== null ? $this->fc('utilisateur', ['nom']) : null;

        $sql =
            'SELECT x.'.$this->id($idCol).' AS id, '.
            ($statut ? 'COALESCE(x.'.$this->id($statut).", '') AS statut, " : "'' AS statut, ").
            ($dateDebut ? 'x.'.$this->id($dateDebut).' AS date_debut, ' : 'NULL AS date_debut, ').
            ($dateFin ? 'x.'.$this->id($dateFin).' AS date_fin, ' : 'NULL AS date_fin, ').
            ($montant ? 'x.'.$this->id($montant).' AS montant, ' : 'NULL AS montant, ').
            (($locFk && $uNom) ? 'COALESCE(u.'.$this->id($uNom).", '-') AS locataire_nom " : "'-' AS locataire_nom ").
            'FROM '.$this->id($table).' x '.
            'INNER JOIN '.$this->id('annonce').' a ON x.'.$this->id($annonceFk).' = a.'.$this->id($aId).' '.
            (($locFk && $uId) ? 'LEFT JOIN '.$this->id('utilisateur').' u ON x.'.$this->id($locFk).' = u.'.$this->id($uId).' ' : '').
            'WHERE a.'.$this->id($aPropFk).' = :uid '.
            'ORDER BY x.'.$this->id($idCol).' DESC LIMIT 100';

        try {
            $rows = $this->connection->fetchAllAssociative($sql, ['uid' => $userId]);
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['statut'] = strtoupper((string) ($row['statut'] ?? ''));
            $row['date_debut'] = $row['date_debut'] !== null ? $this->fmtDate($row['date_debut']) : '-';
            $row['date_fin'] = $row['date_fin'] !== null ? $this->fmtDate($row['date_fin']) : '-';
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function getUserFicheAuditActions(int $id): array
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT admin_name, admin_email, action, reason, payload_json, created_at FROM admin_user_audit WHERE target_user_id = :id ORDER BY created_at DESC, id DESC LIMIT 50',
                ['id' => $id]
            );
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as &$row) {
            $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
            $row['payload'] = is_array($payload) ? $payload : [];
            $row['created_at'] = $this->fmtDateTime($row['created_at'] ?? null);
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private function getUserFicheAnnonces(int $id): array
    {
        if (!$this->tableExists('annonce')) {
            return [];
        }

        $idCol = $this->fc('annonce', ['id']);
        $fk = $this->fc('annonce', ['proprietaireId', 'id_proprietaire', 'proprietaire_id', 'id_utilisateur']);
        if ($idCol === null || $fk === null) {
            return [];
        }

        $titre = $this->fc('annonce', ['titre', 'title']);
        $ville = $this->fc('annonce', ['ville', 'city']);
        $statut = $this->fc('annonce', ['statut', 'status']);
        $prix = $this->fc('annonce', ['prix', 'price', 'montant']);

        $sql =
            'SELECT a.'.$this->id($idCol).' AS id, '.
            ($titre ? 'COALESCE(a.'.$this->id($titre).", '') AS titre, " : "'' AS titre, ").
            ($ville ? 'COALESCE(a.'.$this->id($ville).", '') AS ville, " : "'' AS ville, ").
            ($statut ? 'COALESCE(a.'.$this->id($statut).", '') AS statut, " : "'' AS statut, ").
            ($prix ? 'a.'.$this->id($prix).' AS prix ' : 'NULL AS prix ').
            'FROM '.$this->id('annonce').' a '.
            'WHERE a.'.$this->id($fk).' = :uid '.
            'ORDER BY a.'.$this->id($idCol).' DESC LIMIT 100';

        try {
            $rows = $this->connection->fetchAllAssociative($sql, ['uid' => $id]);
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['statut'] = strtoupper((string) ($row['statut'] ?? ''));
        }

        return $rows;
    }

    // -----------------------------------------------------------------------
    // Email history
    // -----------------------------------------------------------------------

    public function ensureEmailHistoryTable(): bool
    {
        if ($this->tableExists('user_email_history')) {
            return true;
        }

        try {
            $this->connection->executeStatement(
                'CREATE TABLE IF NOT EXISTS user_email_history (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    old_email VARCHAR(190) NOT NULL,
                    new_email VARCHAR(190) NOT NULL,
                    changed_by_admin_id INT NULL,
                    changed_by_admin_name VARCHAR(190) NULL,
                    source VARCHAR(64) NOT NULL DEFAULT \'admin\',
                    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_ueh_user_id (user_id),
                    INDEX idx_ueh_changed_at (changed_at),
                    CONSTRAINT fk_ueh_user FOREIGN KEY (user_id) REFERENCES utilisateur(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
            );
        } catch (\Throwable) {
            return false;
        }

        $this->tableExistsCache = [];
        $this->columnsCache = [];

        return $this->tableExists('user_email_history');
    }

    public function logEmailChange(int $userId, string $oldEmail, string $newEmail, ?int $adminId = null, ?string $adminName = null, string $source = 'admin'): void
    {
        if (strtolower(trim($oldEmail)) === strtolower(trim($newEmail))) {
            return;
        }

        if (!$this->ensureEmailHistoryTable()) {
            return;
        }

        try {
            $this->connection->insert('user_email_history', [
                'user_id' => $userId,
                'old_email' => substr(trim($oldEmail), 0, 190),
                'new_email' => substr(trim($newEmail), 0, 190),
                'changed_by_admin_id' => $adminId,
                'changed_by_admin_name' => $adminName !== null ? substr(trim($adminName), 0, 190) : null,
                'source' => substr($source, 0, 64),
                'changed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // Best effort — must not break the main edit flow.
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getUserFicheEmailHistory(int $id): array
    {
        if (!$this->tableExists('user_email_history')) {
            return [];
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT old_email, new_email, changed_by_admin_name, source, changed_at FROM user_email_history WHERE user_id = :id ORDER BY changed_at DESC LIMIT 50',
                ['id' => $id]
            );
        } catch (\Throwable) {
            return [];
        }

        foreach ($rows as &$row) {
            $row['changed_at'] = $this->fmtDateTime($row['changed_at'] ?? null);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function getUserFicheWebAuthn(int $id): array
    {
        if (!$this->tableExists('webauthn_credential')) {
            return ['count' => 0, 'devices' => []];
        }
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT device_name, created_at FROM webauthn_credential WHERE utilisateur_id = :id ORDER BY created_at DESC',
                ['id' => $id]
            );
        } catch (\Throwable) {
            return ['count' => 0, 'devices' => []];
        }
        $devices = array_map(fn($r) => [
            'name'       => $r['device_name'] ?? 'Appareil inconnu',
            'created_at' => $this->fmtDateTime($r['created_at'] ?? null),
        ], $rows);
        return ['count' => count($devices), 'devices' => $devices];
    }

    /** @return array<string, mixed> */
    private function getUserFicheFace(int $id): array
    {
        if (!$this->tableExists('face_descriptor')) {
            return ['enrolled' => false, 'enrolled_at' => null];
        }
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT created_at FROM face_descriptor WHERE utilisateur_id = :id LIMIT 1',
                ['id' => $id]
            );
        } catch (\Throwable) {
            return ['enrolled' => false, 'enrolled_at' => null];
        }
        if (!$row) {
            return ['enrolled' => false, 'enrolled_at' => null];
        }
        return ['enrolled' => true, 'enrolled_at' => $this->fmtDateTime($row['created_at'] ?? null)];
    }
}
