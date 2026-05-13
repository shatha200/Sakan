<?php

namespace App\Repository;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Repository admin-only pour la gestion des charges en backoffice.
 */
class AdminChargeRepository
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

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
                COUNT(*) AS total_charges,
                COALESCE(SUM(CAST(montant AS DECIMAL(10,3))), 0) AS total_montant,
                SUM(CASE WHEN statut_paiement = :sNonPaye THEN 1 ELSE 0 END) AS nb_non_paye,
                COALESCE(SUM(CASE WHEN statut_paiement = :sNonPaye THEN CAST(montant AS DECIMAL(10,3)) ELSE 0 END), 0) AS montant_non_paye,
                SUM(CASE WHEN statut_paiement = :sPaye THEN 1 ELSE 0 END) AS nb_paye,
                COALESCE(SUM(CASE WHEN statut_paiement = :sPaye THEN CAST(montant AS DECIMAL(10,3)) ELSE 0 END), 0) AS montant_paye,
                SUM(CASE WHEN type_charge = :tElec THEN 1 ELSE 0 END) AS nb_electricite,
                SUM(CASE WHEN type_charge = :tEau THEN 1 ELSE 0 END) AS nb_eau,
                SUM(CASE WHEN type_charge = :tNet THEN 1 ELSE 0 END) AS nb_internet,
                SUM(CASE WHEN type_charge = :tAutre THEN 1 ELSE 0 END) AS nb_autre
            FROM charges_mensuelles
        ";
        return $this->conn()->fetchAssociative($sql, [
            'sNonPaye' => 'NON_PAYE',
            'sPaye'    => 'PAYE',
            'tElec'    => 'ELECTRICITE',
            'tEau'     => 'EAU',
            'tNet'     => 'INTERNET',
            'tAutre'   => 'AUTRE',
        ]) ?: [];
    }

    // ─── Liste complète avec filtres ─────────────────────────────────
    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function findAll(array $filters = []): array
    {
        $params = [];
        $where = [];

        $sql = "
            SELECT
                cm.id,
                cm.type_charge,
                cm.periode,
                cm.statut_paiement,
                CAST(cm.montant AS DECIMAL(10,3)) AS montant_a_payer,
                cm.date_ajout AS date_creation,
                cm.date_modification AS date_paiement,
                cm.fichier_facture,
                ul.nom AS nom_locataire,
                ul.email AS email_locataire,
                up.nom AS nom_proprietaire,
                a.titre AS bien_titre
            FROM charges_mensuelles cm
            LEFT JOIN contrat c ON cm.contrat_id = c.id
            LEFT JOIN annonce a ON c.annonceId = a.id
            LEFT JOIN utilisateur ul ON c.locataireId = ul.id
            LEFT JOIN utilisateur up ON CAST(a.proprietaireId AS UNSIGNED) = up.id
        ";

        if (!empty($filters['statut']) && $filters['statut'] !== 'TOUS') {
            $where[] = 'cm.statut_paiement = :statut';
            $params['statut'] = $filters['statut'];
        }
        if (!empty($filters['type']) && $filters['type'] !== 'TOUS') {
            $where[] = 'cm.type_charge = :type';
            $params['type'] = $filters['type'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(ul.nom LIKE :search OR a.titre LIKE :search OR cm.periode LIKE :search)";
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY cm.date_ajout DESC LIMIT 500';

        return $this->conn()->fetchAllAssociative($sql, $params);
    }

    // ─── Détail d'une charge ─────────────────────────────────────────
    /** @return array<string, mixed>|null */
    public function findDetail(int $id): ?array
    {
        $sql = "
            SELECT
                cm.id,
                cm.type_charge,
                cm.periode,
                cm.statut_paiement,
                CAST(cm.montant AS DECIMAL(10,3)) AS montant_a_payer,
                cm.date_ajout AS date_creation,
                cm.date_modification AS date_paiement,
                cm.fichier_facture,
                cm.description AS notes,
                cm.contrat_id,
                ul.id AS locataire_id,
                ul.nom AS nom_locataire,
                ul.email AS email_locataire,
                ul.telephone AS tel_locataire,
                up.id AS proprietaire_id,
                up.nom AS nom_proprietaire,
                up.email AS email_proprietaire,
                a.titre AS bien_titre,
                a.id AS annonce_id
            FROM charges_mensuelles cm
            LEFT JOIN contrat c ON cm.contrat_id = c.id
            LEFT JOIN annonce a ON c.annonceId = a.id
            LEFT JOIN utilisateur ul ON c.locataireId = ul.id
            LEFT JOIN utilisateur up ON CAST(a.proprietaireId AS UNSIGNED) = up.id
            WHERE cm.id = :id
        ";
        return $this->conn()->fetchAssociative($sql, ['id' => $id]) ?: null;
    }

    // ─── Marquer une charge comme payée (action admin) ───────────────
    public function marquerPaye(int $id, string $methode, ?string $reference, ?string $datePaiement): bool
    {
        try {
            $descAppend = "\n[ADMIN] Payé via " . $methode . " - Ref: " . ($reference ?? 'N/A') . " - Date: " . ($datePaiement ?? date('Y-m-d'));
            
            $this->conn()->executeStatement("
                UPDATE charges_mensuelles SET
                    statut_paiement = :statutPaye,
                    date_modification = CURRENT_TIMESTAMP(),
                    description = CONCAT(COALESCE(description, ''), :desc)
                WHERE id = :id
            ", [
                'desc'       => $descAppend,
                'id'         => $id,
                'statutPaye' => 'PAYE',
            ]);
            return true;
        } catch (\Exception $e) {
            error_log('[AdminChargeRepository] marquerPaye: ' . $e->getMessage());
            return false;
        }
    }

    // ─── Charges impayées (vue prioritaire) ──────────────────────────
    /** @return list<array<string, mixed>> */
    public function findImpayees(): array
    {
        $sql = "
            SELECT
                cm.id,
                cm.type_charge,
                cm.periode,
                CAST(cm.montant AS DECIMAL(10,3)) AS montant_a_payer,
                cm.date_ajout AS date_creation,
                DATEDIFF(CURRENT_DATE(), STR_TO_DATE(cm.date_ajout, '%Y-%m-%d %H:%i:%s')) AS jours_creation,
                ul.nom AS nom_locataire,
                ul.email AS email_locataire,
                a.titre AS bien_titre
            FROM charges_mensuelles cm
            LEFT JOIN contrat c ON cm.contrat_id = c.id
            LEFT JOIN annonce a ON c.annonceId = a.id
            LEFT JOIN utilisateur ul ON c.locataireId = ul.id
            WHERE cm.statut_paiement = :sNonPaye
            ORDER BY jours_creation DESC
        ";
        return $this->conn()->fetchAllAssociative($sql, [
            'sNonPaye' => 'NON_PAYE',
        ]);
    }

    // ─── Pour le dashboard (top chargées impayées) ───────────────────
    /** @return list<array<string, mixed>> */
    public function getTopImpayees(int $limit = 5): array
    {
        $sql = "
            SELECT
                cm.type_charge,
                a.titre AS bien_titre,
                CAST(cm.montant AS DECIMAL(10,3)) AS montant,
                cm.periode
            FROM charges_mensuelles cm
            LEFT JOIN contrat c ON cm.contrat_id = c.id
            LEFT JOIN annonce a ON c.annonceId = a.id
            WHERE cm.statut_paiement = :sNonPaye
            ORDER BY CAST(cm.montant AS DECIMAL(10,3)) DESC
            LIMIT " . (int)$limit;
        return $this->conn()->fetchAllAssociative($sql, [
            'sNonPaye' => 'NON_PAYE',
        ]);
    }

    // ─── Création manuelle d'une charge ──────────────────────────────
    /** @param array<string, mixed> $data */
    public function creer(array $data): int
    {
        $sql = "
            INSERT INTO charges_mensuelles (
                contrat_id, type_charge, montant, statut_paiement, periode, description, fichier_facture, date_ajout, date_modification
            ) VALUES (
                :contrat, :type, :montant, :statut, :periode, :notes, :fichier, CURRENT_TIMESTAMP(), CURRENT_TIMESTAMP()
            )
        ";
        $this->conn()->executeStatement($sql, [
            'contrat' => $data['contrat_id'],
            'type'    => $data['type_charge'],
            'montant' => $data['montant_a_payer'],
            'statut'  => $data['statut_paiement'],
            'periode' => $data['periode'],
            'notes'   => $data['notes'] ?? null,
            'fichier' => $data['fichier_facture'] ?? null,
        ]);

        return (int) $this->conn()->lastInsertId();
    }
}
