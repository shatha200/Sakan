<?php

namespace App\Repository;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Repository admin-only pour la gestion des loyers en backoffice.
 * Utilise DBAL pour des requêtes cross-tables performantes.
 */
class AdminLoyerRepository
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
                COUNT(*) AS total_loyers,
                COALESCE(SUM(CAST(montant AS DECIMAL(10,3))), 0) AS total_montant,
                SUM(CASE WHEN statut = :sPaye THEN 1 ELSE 0 END) AS nb_payes,
                COALESCE(SUM(CASE WHEN statut = :sPaye THEN CAST(montant AS DECIMAL(10,3)) ELSE 0 END), 0) AS montant_paye,
                SUM(CASE WHEN statut = :sAttente THEN 1 ELSE 0 END) AS nb_attente,
                COALESCE(SUM(CASE WHEN statut = :sAttente THEN CAST(montant AS DECIMAL(10,3)) ELSE 0 END), 0) AS montant_attente,
                SUM(CASE WHEN statut = :sRetard THEN 1 ELSE 0 END) AS nb_retard,
                COALESCE(SUM(CASE WHEN statut = :sRetard THEN CAST(montant AS DECIMAL(10,3)) ELSE 0 END), 0) AS montant_retard,
                COALESCE(AVG(CASE WHEN statut = :sRetard THEN DATEDIFF(CURRENT_DATE(), STR_TO_DATE(date_echeance, '%Y-%m-%d')) ELSE NULL END), 0) AS retard_moyen_jours
            FROM paiement_loyer
        ";
        return $this->conn()->fetchAssociative($sql, [
            'sPaye'   => 'PAYE',
            'sAttente' => 'EN_ATTENTE',
            'sRetard' => 'EN_RETARD',
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
                pl.id,
                pl.periode,
                pl.statut,
                CAST(pl.montant AS DECIMAL(10,3)) AS montant,
                CAST(COALESCE(pl.penalite, :zero) AS DECIMAL(10,3)) AS penalite,
                pl.date_echeance,
                pl.date_paiement,
                pl.methode_paiement,
                pl.reference_transaction,
                ul.nom AS nom_locataire,
                ul.email AS email_locataire,
                up.nom AS nom_proprietaire,
                a.titre AS bien_titre,
                DATEDIFF(CURRENT_DATE(), STR_TO_DATE(pl.date_echeance, '%Y-%m-%d')) AS jours_retard
            FROM paiement_loyer pl
            LEFT JOIN contrat c ON pl.contrat_id = c.id
            LEFT JOIN annonce a ON c.annonceId = a.id
            LEFT JOIN utilisateur ul ON c.locataireId = ul.id
            LEFT JOIN utilisateur up ON CAST(a.proprietaireId AS UNSIGNED) = up.id
        ";

        if (!empty($filters['statut']) && $filters['statut'] !== 'TOUS') {
            $where[] = 'pl.statut = :statut';
            $params['statut'] = $filters['statut'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(ul.nom LIKE :search OR ul.email LIKE :search OR a.titre LIKE :search OR pl.periode LIKE :search)";
            $params['search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['periode'])) {
            $where[] = 'pl.periode LIKE :periode';
            $params['periode'] = $filters['periode'] . '%';
        }

        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY pl.date_echeance DESC LIMIT 500';

        return $this->conn()->fetchAllAssociative($sql, $params);
    }

    // ─── Détail d'un loyer ───────────────────────────────────────────
    /** @return array<string, mixed>|null */
    public function findDetail(int $id): ?array
    {
        $sql = "
            SELECT
                pl.id,
                pl.periode,
                pl.statut,
                CAST(pl.montant AS DECIMAL(10,3)) AS montant,
                CAST(COALESCE(pl.penalite, '0') AS DECIMAL(10,3)) AS penalite,
                pl.date_echeance,
                pl.date_paiement,
                pl.methode_paiement,
                pl.reference_transaction,
                pl.contrat_id,
                ul.id AS locataire_id,
                ul.nom AS nom_locataire,
                ul.email AS email_locataire,
                ul.telephone AS tel_locataire,
                up.id AS proprietaire_id,
                up.nom AS nom_proprietaire,
                up.email AS email_proprietaire,
                up.telephone AS tel_proprietaire,
                a.titre AS bien_titre,
                a.id AS annonce_id,
                DATEDIFF(CURRENT_DATE(), STR_TO_DATE(pl.date_echeance, '%Y-%m-%d')) AS jours_retard
            FROM paiement_loyer pl
            LEFT JOIN contrat c ON pl.contrat_id = c.id
            LEFT JOIN annonce a ON c.annonceId = a.id
            LEFT JOIN utilisateur ul ON c.locataireId = ul.id
            LEFT JOIN utilisateur up ON CAST(a.proprietaireId AS UNSIGNED) = up.id
            WHERE pl.id = :id
        ";
        return $this->conn()->fetchAssociative($sql, ['id' => $id]) ?: null;
    }

    // ─── Forcer un paiement (action admin) ───────────────────────────
    public function forcerPaiement(int $id, string $methode, ?string $reference, ?string $datePaiement): bool
    {
        try {
            $this->conn()->executeStatement("
                UPDATE paiement_loyer SET
                    statut = :statutPaye,
                    methode_paiement = :methode,
                    reference_transaction = :ref,
                    date_paiement = :date_paiement,
                    date_modification = CURRENT_TIMESTAMP()
                WHERE id = :id
            ", [
                'methode'     => $methode,
                'ref'         => $reference ?? 'ADMIN-FORCE',
                'date_paiement' => $datePaiement ?? date('Y-m-d'),
                'id'          => $id,
                'statutPaye'  => 'PAYE',
            ]);
            return true;
        } catch (\Exception $e) {
            error_log('[AdminLoyerRepository] forcerPaiement: ' . $e->getMessage());
            return false;
        }
    }

    // ─── Loyers en retard (vue prioritaire) ──────────────────────────
    /** @return list<array<string, mixed>> */
    public function findEnRetard(): array
    {
        $sql = "
            SELECT
                pl.id,
                pl.periode,
                CAST(pl.montant AS DECIMAL(10,3)) AS montant,
                CAST(COALESCE(pl.penalite, :zero) AS DECIMAL(10,3)) AS penalite,
                pl.date_echeance,
                ul.nom AS nom_locataire,
                ul.email AS email_locataire,
                up.nom AS nom_proprietaire,
                a.titre AS bien_titre,
                DATEDIFF(CURRENT_DATE(), STR_TO_DATE(pl.date_echeance, '%Y-%m-%d')) AS jours_retard
            FROM paiement_loyer pl
            LEFT JOIN contrat c ON pl.contrat_id = c.id
            LEFT JOIN annonce a ON c.annonceId = a.id
            LEFT JOIN utilisateur ul ON c.locataireId = ul.id
            LEFT JOIN utilisateur up ON CAST(a.proprietaireId AS UNSIGNED) = up.id
            WHERE pl.statut = :sRetard
            ORDER BY jours_retard DESC
        ";
        return $this->conn()->fetchAllAssociative($sql, [
            'sRetard' => 'EN_RETARD',
            'zero'    => '0',
        ]);
    }

    // ─── Évolution sur 12 mois ───────────────────────────────────────
    /** @return list<array<string, mixed>> */
    public function getEvolution12Mois(): array
    {
        $sql = "
            SELECT 
                DATE_FORMAT(STR_TO_DATE(CONCAT(periode, :suffixJour), '%Y-%m-%d'), '%m/%Y') AS mois_annee,
                SUM(CASE WHEN statut = :sPaye THEN 1 ELSE 0 END) AS payes,
                SUM(CASE WHEN statut != :sPaye THEN 1 ELSE 0 END) AS impayes,
                COALESCE(SUM(CASE WHEN statut = :sPaye THEN CAST(montant AS DECIMAL(10,3)) ELSE 0 END), 0) AS montant_paye
            FROM paiement_loyer
            WHERE STR_TO_DATE(CONCAT(periode, :suffixJour), '%Y-%m-%d') >= DATE_SUB(CURRENT_DATE(), INTERVAL 12 MONTH)
            GROUP BY periode
            ORDER BY STR_TO_DATE(CONCAT(periode, :suffixJour), '%Y-%m-%d') ASC
        ";
        return $this->conn()->fetchAllAssociative($sql, [
            'sPaye'     => 'PAYE',
            'suffixJour' => '-01',
        ]);
    }

    // ─── Création manuelle d'un loyer ────────────────────────────────
    /** @param array<string, mixed> $data */
    public function creer(array $data): int
    {
        $sql = "
            INSERT INTO paiement_loyer (
                contrat_id, periode, montant, date_echeance, statut, penalite, date_creation, date_modification
            ) VALUES (
                :contrat, :periode, :montant, :date_echeance, :statut, :penalite, CURRENT_TIMESTAMP(), CURRENT_TIMESTAMP()
            )
        ";
        $this->conn()->executeStatement($sql, [
            'contrat'       => $data['contrat_id'],
            'periode'       => $data['periode'],
            'montant'       => $data['montant'],
            'date_echeance' => $data['date_echeance'],
            'statut'        => $data['statut'] ?? 'EN_ATTENTE',
            'penalite'      => $data['penalite'] ?? 0
        ]);

        return (int) $this->conn()->lastInsertId();
    }
}
