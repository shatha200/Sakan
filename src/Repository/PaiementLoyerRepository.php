<?php

namespace App\Repository;

use App\Entity\PaiementLoyer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PaiementLoyer> */
class PaiementLoyerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaiementLoyer::class);
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    // ─────────────────────────────────────────────────────────────────
    // 1. PAIEMENTS ATTENDUS
    // ─────────────────────────────────────────────────────────────────
    /** @return list<array<string, mixed>> */
    public function findLoyersARecevoir(int $proprietaireId): array
    {
        $sql = "
            SELECT 
                u.nom AS nom_locataire,
                u.telephone,
                u.email,
                a.titre AS nom_bien,
                pl.id AS paiement_id,
                CAST(pl.montant AS DECIMAL(10,3)) AS montant,
                pl.date_echeance,
                DATEDIFF(STR_TO_DATE(pl.date_echeance, '%Y-%m-%d'), CURRENT_DATE()) AS jours_restants,
                pl.periode,
                pl.statut
            FROM paiement_loyer pl
            INNER JOIN contrat c ON pl.contrat_id = c.id
            INNER JOIN annonce a ON c.annonceId = a.id
            INNER JOIN utilisateur u ON c.locataireId = u.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
              AND pl.statut = :statutAttente
              AND MONTH(STR_TO_DATE(CONCAT(pl.periode, :suffixJour), '%Y-%m-%d')) = MONTH(CURRENT_DATE())
              AND YEAR(STR_TO_DATE(CONCAT(pl.periode, :suffixJour), '%Y-%m-%d')) = YEAR(CURRENT_DATE())
            ORDER BY STR_TO_DATE(pl.date_echeance, '%Y-%m-%d') ASC
        ";

        return $this->conn()->fetchAllAssociative($sql, [
            'propId' => $proprietaireId,
            'statutAttente' => 'EN_ATTENTE',
            'suffixJour' => '-01',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. RETARDS DE PAIEMENT
    // ─────────────────────────────────────────────────────────────────
    /** @return list<array<string, mixed>> */
    public function findLoyersEnRetard(int $proprietaireId): array
    {
        $sql = "
            SELECT 
                u.nom AS nom_locataire,
                u.telephone,
                u.email,
                a.titre AS nom_bien,
                pl.id AS paiement_id,
                CAST(pl.montant AS DECIMAL(10,3)) AS montant,
                CAST(COALESCE(pl.penalite, :zero) AS DECIMAL(10,3)) AS penalite,
                pl.date_echeance,
                pl.statut,
                DATEDIFF(CURRENT_DATE(), STR_TO_DATE(pl.date_echeance, '%Y-%m-%d')) AS jours_retard
            FROM paiement_loyer pl
            INNER JOIN contrat c ON pl.contrat_id = c.id
            INNER JOIN annonce a ON c.annonceId = a.id
            INNER JOIN utilisateur u ON c.locataireId = u.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
              AND pl.statut IN (:statutRetard, :statutPartiel)
            ORDER BY jours_retard DESC
        ";

        return $this->conn()->fetchAllAssociative($sql, [
            'propId' => $proprietaireId,
            'zero' => '0',
            'statutRetard' => 'EN_RETARD',
            'statutPartiel' => 'PARTIEL',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. HISTORIQUE / JOURNAL
    // ─────────────────────────────────────────────────────────────────
    /** @return list<array<string, mixed>> */
    public function findLoyersHistorique(int $proprietaireId, int $year): array
    {
        $sql = "
            SELECT 
                DATE_FORMAT(STR_TO_DATE(CONCAT(pl.periode, :suffixJour), '%Y-%m-%d'), '%b %Y') AS periode_label,
                u.nom AS nom_locataire,
                a.titre AS nom_bien,
                CAST(pl.montant AS DECIMAL(10,3)) AS montant,
                CAST(COALESCE(pl.penalite, :zero) AS DECIMAL(10,3)) AS penalite,
                pl.date_paiement,
                pl.periode,
                pl.statut,
                pl.id AS paiement_id,
                (SELECT f.fichier 
                 FROM facture f
                 WHERE f.type_reference = :typeRef 
                   AND f.reference_id = pl.id 
                   AND f.type_document = :typeDoc 
                 ORDER BY f.id DESC LIMIT 1) AS quittance_url
            FROM paiement_loyer pl
            INNER JOIN contrat c ON pl.contrat_id = c.id
            INNER JOIN annonce a ON c.annonceId = a.id
            INNER JOIN utilisateur u ON c.locataireId = u.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId 
              AND YEAR(STR_TO_DATE(CONCAT(pl.periode, :suffixJour), '%Y-%m-%d')) = :year
            ORDER BY STR_TO_DATE(CONCAT(pl.periode, :suffixJour), '%Y-%m-%d') DESC
        ";

        return $this->conn()->fetchAllAssociative($sql, [
            'propId'  => $proprietaireId,
            'year'    => $year,
            'suffixJour' => '-01',
            'zero'    => '0',
            'typeRef' => 'PAIEMENT_LOYER',
            'typeDoc' => 'QUITTANCE',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // 4. ACTION : MARQUER COMME PAYÉ (Locataire → Propriétaire)
    // ─────────────────────────────────────────────────────────────────
    public function marquerPaye(int $id, string $methode, string $reference): bool
    {
        try {
            $sql = "
                UPDATE paiement_loyer SET 
                    statut = :statutPaye,
                    date_paiement = CURRENT_TIMESTAMP(),
                    methode_paiement = :methode,
                    reference_transaction = :ref,
                    date_modification = CURRENT_TIMESTAMP()
                WHERE id = :id
            ";
            
            $this->conn()->executeStatement($sql, [
                'methode' => $methode,
                'ref'     => $reference,
                'id'      => $id,
                'statutPaye' => 'PAYE',
            ]);
            
            return true;
        } catch (\Exception $e) {
            error_log('[LoyerRepository] marquerPaye error: ' . $e->getMessage());
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // 5. PÉNALITÉS - KPIs ET STATISTIQUES
    // ─────────────────────────────────────────────────────────────────

    /**
     * KPI: Total des pénalités recouvrées
     */
    public function getTotalPenalitesRecouvrees(int $proprietaireId): float
    {
        $sql = "
            SELECT COALESCE(SUM(CAST(pl.penalite AS DECIMAL(10,3))), 0) as total
            FROM paiement_loyer pl
            JOIN contrat c ON pl.contrat_id = c.id
            JOIN annonce a ON c.annonceId = a.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
              AND CAST(pl.penalite AS DECIMAL(10,3)) > 0 
              AND pl.statut = :statutPaye
        ";
        
        $result = $this->conn()->executeQuery($sql, [
            'propId' => $proprietaireId,
            'statutPaye' => 'PAYE',
        ])->fetchOne();
        return (float) ($result ?? 0.0);
    }

    /**
     * KPI: Performance du mois courant (pénalités encaissées ce mois)
     */
    public function getTotalPenalitesMois(int $proprietaireId): float
    {
        $sql = "
            SELECT COALESCE(SUM(CAST(pl.penalite AS DECIMAL(10,3))), 0) as total_mois
            FROM paiement_loyer pl
            JOIN contrat c ON pl.contrat_id = c.id
            JOIN annonce a ON c.annonceId = a.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
              AND CAST(pl.penalite AS DECIMAL(10,3)) > 0 
              AND pl.statut = :statutPaye
              AND MONTH(STR_TO_DATE(pl.date_paiement, '%Y-%m-%d')) = MONTH(CURRENT_DATE)
              AND YEAR(STR_TO_DATE(pl.date_paiement, '%Y-%m-%d')) = YEAR(CURRENT_DATE)
        ";
        
        $result = $this->conn()->executeQuery($sql, [
            'propId' => $proprietaireId,
            'statutPaye' => 'PAYE',
        ])->fetchOne();
        return (float) ($result ?? 0.0);
    }

    /**
     * KPI: Nombre de retards identifiés ce mois
     */
    public function getNombreRetardsMois(int $proprietaireId): int
    {
        $sql = "
            SELECT COUNT(*) as nb_retards
            FROM paiement_loyer pl
            JOIN contrat c ON pl.contrat_id = c.id
            JOIN annonce a ON c.annonceId = a.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
              AND (pl.statut IN (:statutRetard, :statutPartiel) 
                   OR (pl.statut = :statutPaye AND CAST(pl.penalite AS DECIMAL(10,3)) > 0))
              AND MONTH(STR_TO_DATE(pl.date_echeance, '%Y-%m-%d')) = MONTH(CURRENT_DATE)
              AND YEAR(STR_TO_DATE(pl.date_echeance, '%Y-%m-%d')) = YEAR(CURRENT_DATE)
        ";
        
        $result = $this->conn()->executeQuery($sql, [
            'propId' => $proprietaireId,
            'statutRetard' => 'EN_RETARD',
            'statutPartiel' => 'PARTIEL',
            'statutPaye' => 'PAYE',
        ])->fetchOne();
        return (int) ($result ?? 0);
    }

    /**
     * KPI: Bien avec le plus de retards
     */
    public function getTopRetardataire(int $proprietaireId): ?string
    {
        $sql = "
            SELECT a.titre
            FROM paiement_loyer pl
            JOIN contrat c ON pl.contrat_id = c.id
            JOIN annonce a ON c.annonceId = a.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
              AND (CAST(pl.penalite AS DECIMAL(10,3)) > 0 OR pl.statut = :statutRetard)
            GROUP BY a.id, a.titre
            ORDER BY COUNT(*) DESC
            LIMIT 1
        ";
        
        $result = $this->conn()->executeQuery($sql, [
            'propId' => $proprietaireId,
            'statutRetard' => 'EN_RETARD',
        ])->fetchOne();
        return $result ?: null;
    }

    /**
     * Graphique: Évolution des encaissements sur 12 mois
     * @return list<array<string, mixed>>
     */
    public function getEvolutionEncaissements(int $proprietaireId): array
    {
        $sql = "
            SELECT 
                DATE_FORMAT(STR_TO_DATE(pl.date_paiement, '%Y-%m-%d'), '%Y-%m') AS mois,
                COALESCE(SUM(CAST(pl.penalite AS DECIMAL(10,3))), 0) AS total
            FROM paiement_loyer pl
            JOIN contrat c ON pl.contrat_id = c.id
            JOIN annonce a ON c.annonceId = a.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
              AND CAST(pl.penalite AS DECIMAL(10,3)) > 0 
              AND pl.statut = :statutPaye
              AND STR_TO_DATE(pl.date_paiement, '%Y-%m-%d') >= DATE_SUB(CURRENT_DATE, INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(STR_TO_DATE(pl.date_paiement, '%Y-%m-%d'), '%Y-%m')
            ORDER BY mois ASC
        ";
        
        return $this->conn()->fetchAllAssociative($sql, [
            'propId' => $proprietaireId,
            'statutPaye' => 'PAYE',
        ]);
    }

    /**
     * Graphique: Répartition par bien (top 10)
     * @return list<array<string, mixed>>
     */
    public function getRepartitionParBien(int $proprietaireId): array
    {
        $sql = "
            SELECT 
                a.titre,
                COALESCE(SUM(CAST(pl.penalite AS DECIMAL(10,3))), 0) AS total
            FROM paiement_loyer pl
            JOIN contrat c ON pl.contrat_id = c.id
            JOIN annonce a ON c.annonceId = a.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
              AND CAST(pl.penalite AS DECIMAL(10,3)) > 0 
              AND pl.statut = :statutPaye
            GROUP BY a.id, a.titre
            ORDER BY total DESC
            LIMIT 10
        ";
        
        return $this->conn()->fetchAllAssociative($sql, [
            'propId' => $proprietaireId,
            'statutPaye' => 'PAYE',
        ]);
    }

    /**
     * Journal des encaissements (historique paginé avec recherche)
     * @return list<array<string, mixed>>
     */
    public function getHistoriqueEncaissements(int $proprietaireId, ?string $search = null, int $page = 1, int $limit = 5): array
    {
        $offset = ($page - 1) * $limit;
        $params = [
            'propId' => $proprietaireId,
            'suffixJour' => '-01',
            'statutPaye' => 'PAYE',
        ];
        
        $sql = "
            SELECT 
                pl.id AS paiement_id,
                pl.date_paiement,
                a.titre AS propriete,
                u.nom AS locataire,
                DATE_FORMAT(STR_TO_DATE(CONCAT(pl.periode, :suffixJour), '%Y-%m-%d'), '%m/%Y') AS periode,
                CAST(pl.montant AS DECIMAL(10,3)) AS montant,
                DATEDIFF(STR_TO_DATE(pl.date_paiement, '%Y-%m-%d'), STR_TO_DATE(pl.date_echeance, '%Y-%m-%d')) AS jours_retard,
                CAST(pl.penalite AS DECIMAL(10,3)) AS penalite,
                pl.statut,
                pl.methode_paiement
            FROM paiement_loyer pl
            JOIN contrat c ON pl.contrat_id = c.id
            JOIN annonce a ON c.annonceId = a.id
            JOIN utilisateur u ON c.locataireId = u.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
              AND CAST(pl.penalite AS DECIMAL(10,3)) > 0 
              AND pl.statut = :statutPaye
        ";
        
        if ($search) {
            $sql .= " AND (LOWER(a.titre) LIKE :search OR LOWER(u.nom) LIKE :search OR DATE_FORMAT(STR_TO_DATE(CONCAT(pl.periode, :suffixJour), '%Y-%m-%d'), '%m/%Y') LIKE :search)";
            $params['search'] = '%' . strtolower($search) . '%';
        }
        
        $sql .= " ORDER BY STR_TO_DATE(pl.date_paiement, '%Y-%m-%d') DESC LIMIT " . (int) $limit . " OFFSET " . (int) $offset;
        
        return $this->conn()->fetchAllAssociative($sql, $params);
    }

    /**
     * Compte total pour pagination
     */
    public function countHistoriqueEncaissements(int $proprietaireId, ?string $search = null): int
    {
        $params = [
            'propId' => $proprietaireId,
            'statutPaye' => 'PAYE',
            'suffixJour' => '-01',
        ];
        
        $sql = "
            SELECT COUNT(*) 
            FROM paiement_loyer pl
            JOIN contrat c ON pl.contrat_id = c.id
            JOIN annonce a ON c.annonceId = a.id
            JOIN utilisateur u ON c.locataireId = u.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
              AND CAST(pl.penalite AS DECIMAL(10,3)) > 0 
              AND pl.statut = :statutPaye
        ";
        
        if ($search) {
            $sql .= " AND (LOWER(a.titre) LIKE :search OR LOWER(u.nom) LIKE :search OR DATE_FORMAT(STR_TO_DATE(CONCAT(pl.periode, :suffixJour), '%Y-%m-%d'), '%m/%Y') LIKE :search)";
            $params['search'] = '%' . strtolower($search) . '%';
        }
        
        $result = $this->conn()->executeQuery($sql, $params)->fetchOne();
        return (int) ($result ?? 0);
    }
}
