<?php

namespace App\Repository;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Repository admin-only pour la gestion des cautions en backoffice.
 */
class AdminCautionRepository
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }

    // ─── KPIs globaux ────────────────────────────────────────────────
    /** @return array<string, mixed> */
    public function getKpis(): array
    {
        $sql = "
            SELECT
                COUNT(*) AS total_cautions,
                COALESCE(SUM(CAST(montant_initial AS DECIMAL(10,3))), 0) AS total_detenu,
                SUM(CASE WHEN statut IN (:sDetenu, :sDetenuAccent) THEN 1 ELSE 0 END) AS nb_actives,
                COALESCE(SUM(CASE WHEN statut IN (:sDetenu, :sDetenuAccent) THEN (CAST(montant_initial AS DECIMAL(10,3)) - CAST(montant_retention AS DECIMAL(10,3)) - CAST(montant_rembourse AS DECIMAL(10,3))) ELSE 0 END), 0) AS montant_actif,
                SUM(CASE WHEN statut = :sTotalRembourse THEN 1 ELSE 0 END) AS nb_remboursees,
                SUM(CASE WHEN statut = :sPartielRembourse THEN 1 ELSE 0 END) AS nb_partielles,
                SUM(CASE WHEN CAST(montant_retention AS DECIMAL(10,3)) > 0 THEN 1 ELSE 0 END) AS nb_retenues
            FROM caution
        ";
        return $this->conn()->fetchAssociative($sql, [
            'sDetenu'          => 'DETENU',
            'sDetenuAccent'    => 'DÉTENU',
            'sTotalRembourse'  => 'TOTALEMENT_REMBOURSE',
            'sPartielRembourse' => 'PARTIELLEMENT_REMBOURSE',
        ]) ?: [];
    }

    // ─── Liste complète avec filtres ─────────────────────────────────
    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function findAll(array $filters = []): array
    {
        $params = [
            'zero' => '0',
        ];
        $where = [];

        $sql = "
            SELECT
                ca.id,
                ca.statut,
                CAST(ca.montant_initial AS DECIMAL(10,3)) AS montant_initial,
                CAST(COALESCE(ca.montant_retention, :zero) AS DECIMAL(10,3)) AS montant_retention,
                CAST(COALESCE(ca.montant_rembourse, :zero) AS DECIMAL(10,3)) AS montant_rembourse,
                (CAST(ca.montant_initial AS DECIMAL(10,3)) - CAST(COALESCE(ca.montant_retention, :zero) AS DECIMAL(10,3)) - CAST(COALESCE(ca.montant_rembourse, :zero) AS DECIMAL(10,3))) AS montant_disponible,
                ca.date_creation,
                ca.date_remboursement,
                ul.nom AS nom_locataire,
                ul.email AS email_locataire,
                up.nom AS nom_proprietaire,
                a.titre AS bien_titre,
                c.date_debut,
                c.date_fin
            FROM caution ca
            LEFT JOIN contrat c ON ca.contrat_id = c.id
            LEFT JOIN annonce a ON c.annonceId = a.id
            LEFT JOIN utilisateur ul ON c.locataireId = ul.id
            LEFT JOIN utilisateur up ON CAST(a.proprietaireId AS UNSIGNED) = up.id
        ";

        if (!empty($filters['statut']) && $filters['statut'] !== 'TOUS') {
            $where[] = 'ca.statut = :statut';
            $params['statut'] = $filters['statut'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(ul.nom LIKE :search OR a.titre LIKE :search OR up.nom LIKE :search)";
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY ca.date_creation DESC LIMIT 500';

        return $this->conn()->fetchAllAssociative($sql, $params);
    }

    // ─── Détail d'une caution ────────────────────────────────────────
    /** @return array<string, mixed>|null */
    public function findDetail(int $id): ?array
    {
        $sql = "
            SELECT
                ca.id,
                ca.statut,
                CAST(ca.montant_initial AS DECIMAL(10,3)) AS montant_initial,
                CAST(COALESCE(ca.montant_retention, :zero) AS DECIMAL(10,3)) AS montant_retention,
                CAST(COALESCE(ca.montant_rembourse, :zero) AS DECIMAL(10,3)) AS montant_rembourse,
                (CAST(ca.montant_initial AS DECIMAL(10,3)) - CAST(COALESCE(ca.montant_retention, :zero) AS DECIMAL(10,3)) - CAST(COALESCE(ca.montant_rembourse, :zero) AS DECIMAL(10,3))) AS montant_disponible,
                ca.date_creation,
                ca.date_remboursement,
                ca.notes,
                ca.contrat_id,
                ul.id AS locataire_id,
                ul.nom AS nom_locataire,
                ul.email AS email_locataire,
                ul.telephone AS tel_locataire,
                up.id AS proprietaire_id,
                up.nom AS nom_proprietaire,
                up.email AS email_proprietaire,
                a.titre AS bien_titre,
                a.id AS annonce_id,
                c.date_debut,
                c.date_fin,
                DATEDIFF(COALESCE(c.date_fin, DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY)), CURRENT_DATE()) AS jours_restants_contrat
            FROM caution ca
            LEFT JOIN contrat c ON ca.contrat_id = c.id
            LEFT JOIN annonce a ON c.annonceId = a.id
            LEFT JOIN utilisateur ul ON c.locataireId = ul.id
            LEFT JOIN utilisateur up ON CAST(a.proprietaireId AS UNSIGNED) = up.id
            WHERE ca.id = :id
        ";
        return $this->conn()->fetchAssociative($sql, [
            'id'   => $id,
            'zero' => '0',
        ]) ?: null;
    }
}
