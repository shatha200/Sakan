<?php

namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;

class DashboardService
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    private function conn(): \Doctrine\DBAL\Connection
    {
        return $this->em->getConnection();
    }

    /**
     * KPI 1: Revenus ce mois
     * @return array<string, mixed>
     */
    public function getRevenueThisMonth(int $proprietaireId): array
    {
        // Encaissé - avec CAST pour les montants VARCHAR et STR_TO_DATE pour les dates
        // Doctrine Doctor Fix: parameterizing literals
        $sqlEncaisse = "
            SELECT COALESCE(SUM(CAST(pl.montant AS DECIMAL(10,3)) + CAST(COALESCE(pl.penalite, :zero) AS DECIMAL(10,3))), 0) AS encaisse 
            FROM paiement_loyer pl
            INNER JOIN contrat c ON pl.contrat_id = c.id
            INNER JOIN annonce a ON c.annonceId = a.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
            AND pl.statut = :statutPaye
            AND MONTH(STR_TO_DATE(pl.date_paiement, '%Y-%m-%d')) = MONTH(CURRENT_DATE())
            AND YEAR(STR_TO_DATE(pl.date_paiement, '%Y-%m-%d')) = YEAR(CURRENT_DATE())
        ";
        
        $encaisse = $this->conn()->fetchOne($sqlEncaisse, [
            'propId' => $proprietaireId,
            'zero' => '0',
            'statutPaye' => 'PAYE',
        ]);
        
        // Prévu
        $sqlPrevu = "
            SELECT COALESCE(SUM(CAST(c.montant AS DECIMAL(10,3))), 0) AS prevu 
            FROM contrat c
            INNER JOIN annonce a ON c.annonceId = a.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
            AND LOWER(c.statut) = :statutActif
        ";
        
        $prevu = $this->conn()->fetchOne($sqlPrevu, [
            'propId' => $proprietaireId,
            'statutActif' => 'actif',
        ]);
        
        return [
            'encaisse' => (float)$encaisse,
            'prevu'    => (float)$prevu,
        ];
    }
    
    /**
     * KPI 2: Taux recouvrement
     * @return array<string, mixed>
     */
    public function getTauxRecouvrement(int $proprietaireId): array
    {
        $sql = "
            SELECT 
                COUNT(*) AS total,
                SUM(CASE WHEN pl.statut = :statutPaye THEN 1 ELSE 0 END) AS payes,
                SUM(CASE WHEN pl.statut IN (:statutRetard, :statutPartiel) THEN 1 ELSE 0 END) AS retards
            FROM paiement_loyer pl
            INNER JOIN contrat c ON pl.contrat_id = c.id
            INNER JOIN annonce a ON c.annonceId = a.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
            AND YEAR(STR_TO_DATE(CONCAT(pl.periode, '-01'), '%Y-%m-%d')) = YEAR(CURRENT_DATE())
        ";
        
        $row = $this->conn()->fetchAssociative($sql, [
            'propId' => $proprietaireId,
            'statutPaye' => 'PAYE',
            'statutRetard' => 'EN_RETARD',
            'statutPartiel' => 'PARTIEL',
        ]);
        
        $total = (int)($row['total'] ?? 0);
        $payes = (int)($row['payes'] ?? 0);
        $taux = $total > 0 ? round(($payes * 100.0 / $total), 0) : 0;
        
        return [
            'total'   => $total,
            'payes'   => $payes,
            'retards' => (int)($row['retards'] ?? 0),
            'taux'    => $taux,
        ];
    }
    
    /**
     * KPI 3: Alertes urgentes
     * @return list<array<string, mixed>>
     */
    public function getAlertesUrgentes(int $proprietaireId): array
    {
        $sql = "
            SELECT 
                pl.id AS paiement_id,
                u.nom AS nom_locataire,
                u.telephone,
                a.titre AS nom_bien,
                pl.montant,
                pl.penalite,
                pl.date_echeance,
                DATEDIFF(CURRENT_DATE(), STR_TO_DATE(pl.date_echeance, '%Y-%m-%d')) AS jours_retard
            FROM paiement_loyer pl
            INNER JOIN contrat c ON pl.contrat_id = c.id
            INNER JOIN annonce a ON c.annonceId = a.id
            INNER JOIN utilisateur u ON c.locataireId = u.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
            AND pl.statut IN (:statutRetard, :statutPartiel)
            ORDER BY jours_retard DESC
            LIMIT 5
        ";
        
        return $this->conn()->fetchAllAssociative($sql, [
            'propId' => $proprietaireId,
            'statutRetard' => 'EN_RETARD',
            'statutPartiel' => 'PARTIEL',
        ]);
    }
    
    /**
     * Cautions à rembourser (Actions)
     * @return list<array<string, mixed>>
     */
    public function getCautionsARembourser(int $proprietaireId): array
    {
        $sql = "
            SELECT 
                ca.id AS caution_id,
                u.nom AS nom_locataire,
                a.titre AS nom_bien,
                (ca.montant_initial - ca.montant_retention - ca.montant_rembourse) AS a_rembourser,
                c.date_fin,
                DATEDIFF(DATE_ADD(STR_TO_DATE(c.date_fin, '%Y-%m-%d'), INTERVAL 2 MONTH), CURRENT_DATE()) AS jours_restants
            FROM caution ca
            INNER JOIN contrat c ON ca.contrat_id = c.id
            INNER JOIN annonce a ON c.annonceId = a.id
            INNER JOIN utilisateur u ON c.locataireId = u.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
            AND ca.statut = :statutDetenu
            AND STR_TO_DATE(c.date_fin, '%Y-%m-%d') < CURRENT_DATE()
            ORDER BY jours_restants ASC
        ";
        
        return $this->conn()->fetchAllAssociative($sql, [
            'propId' => $proprietaireId,
            'statutDetenu' => 'DETENU',
        ]);
    }
    
    /**
     * KPI 4: Tendance
     * @return array<string, mixed>
     */
    public function getTendance(int $proprietaireId): array
    {
        $sql = "
            SELECT 
                COALESCE(SUM(CASE 
                    WHEN MONTH(STR_TO_DATE(pl.date_paiement, '%Y-%m-%d')) = MONTH(CURRENT_DATE()) 
                     AND YEAR(STR_TO_DATE(pl.date_paiement, '%Y-%m-%d')) = YEAR(CURRENT_DATE())
                    THEN CAST(pl.montant AS DECIMAL(10,3)) + CAST(COALESCE(pl.penalite, :zero) AS DECIMAL(10,3)) ELSE 0 
                END), 0) AS mois_actuel,
                COALESCE(SUM(CASE 
                    WHEN MONTH(STR_TO_DATE(pl.date_paiement, '%Y-%m-%d')) = MONTH(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))
                     AND YEAR(STR_TO_DATE(pl.date_paiement, '%Y-%m-%d')) = YEAR(DATE_SUB(CURRENT_DATE(), INTERVAL 1 MONTH))
                    THEN CAST(pl.montant AS DECIMAL(10,3)) + CAST(COALESCE(pl.penalite, :zero) AS DECIMAL(10,3)) ELSE 0 
                END), 0) AS mois_precedent
            FROM paiement_loyer pl
            INNER JOIN contrat c ON pl.contrat_id = c.id
            INNER JOIN annonce a ON c.annonceId = a.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
            AND pl.statut = :statutPaye
        ";
        
        $row = $this->conn()->fetchAssociative($sql, [
            'propId' => $proprietaireId,
            'zero' => '0',
            'statutPaye' => 'PAYE',
        ]);
        
        $actuel = (float)($row['mois_actuel'] ?? 0);
        $precedent = (float)($row['mois_precedent'] ?? 0);
        
        $pct = 0;
        if ($precedent > 0) {
            $pct = round((($actuel - $precedent) * 100) / $precedent, 1);
        }
        
        return [
            'mois_actuel'    => $actuel,
            'mois_precedent' => $precedent,
            'pourcentage'    => $pct,
        ];
    }
    
    /**
     * Graphique: Évolution multi-séries
     * @return list<array<string, mixed>>
     */
    public function getEvolutionMultiSeries(int $proprietaireId): array
    {
        // Loyers + Pénalités - avec CAST et STR_TO_DATE pour VARCHAR
        $sqlLoyers = "
            SELECT 
                DATE_FORMAT(STR_TO_DATE(CONCAT(pl.periode, '-01'), '%Y-%m-%d'), '%Y-%m') AS mois,
                DATE_FORMAT(STR_TO_DATE(CONCAT(pl.periode, '-01'), '%Y-%m-%d'), '%b') AS mois_label,
                SUM(CAST(pl.montant AS DECIMAL(10,3))) AS loyers,
                SUM(CAST(COALESCE(pl.penalite, :zero) AS DECIMAL(10,3))) AS penalites
            FROM paiement_loyer pl
            INNER JOIN contrat c ON pl.contrat_id = c.id
            INNER JOIN annonce a ON c.annonceId = a.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
            AND pl.statut = :statutPaye
            AND STR_TO_DATE(CONCAT(pl.periode, '-01'), '%Y-%m-%d') >= DATE_SUB(CURRENT_DATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(STR_TO_DATE(CONCAT(pl.periode, '-01'), '%Y-%m-%d'), '%Y-%m'), DATE_FORMAT(STR_TO_DATE(CONCAT(pl.periode, '-01'), '%Y-%m-%d'), '%b')
            ORDER BY mois ASC
        ";
        
        $list = $this->conn()->fetchAllAssociative($sqlLoyers, [
            'propId' => $proprietaireId,
            'zero' => '0',
            'statutPaye' => 'PAYE',
        ]);
        
        // Charges - avec CAST et STR_TO_DATE pour VARCHAR
        $sqlCharges = "
            SELECT 
                DATE_FORMAT(STR_TO_DATE(CONCAT(cm.periode, '-01'), '%Y-%m-%d'), '%Y-%m') AS mois,
                SUM(CAST(cm.montant AS DECIMAL(10,3))) AS charges
            FROM charges_mensuelles cm
            INNER JOIN contrat c ON CAST(cm.contrat_id AS UNSIGNED) = c.id
            INNER JOIN annonce a ON c.annonceId = a.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
            AND STR_TO_DATE(CONCAT(cm.periode, '-01'), '%Y-%m-%d') >= DATE_SUB(CURRENT_DATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(STR_TO_DATE(CONCAT(cm.periode, '-01'), '%Y-%m-%d'), '%Y-%m')
        ";
        
        $chargesMap = [];
        $chargesResult = $this->conn()->fetchAllAssociative($sqlCharges, ['propId' => $proprietaireId]);
        foreach ($chargesResult as $row) {
            $chargesMap[$row['mois']] = (float)$row['charges'];
        }
        
        // Fusion
        foreach ($list as &$row) {
            $row['charges'] = $chargesMap[$row['mois']] ?? 0.0;
            $row['loyers'] = (float)$row['loyers'];
            $row['penalites'] = (float)$row['penalites'];
        }
        unset($row); // break ref
        
        return $list;
    }
    
    /**
     * Contrats actifs
     * @return list<array<string, mixed>>
     */
    public function getContratsDetails(int $proprietaireId): array
    {
        $sql = "
            SELECT 
                a.titre AS nom_bien,
                u.nom AS nom_locataire,
                u.telephone,
                u.email,
                c.montant AS loyer_mensuel,
                c.date_debut,
                c.date_fin,
                COALESCE(ca.montant_initial, 0) AS caution,
                c.statut,
                (SELECT COUNT(*) FROM paiement_loyer pl 
                 WHERE pl.contrat_id = c.id AND pl.statut = :statutRetard) AS loyers_en_retard,
                (SELECT COUNT(*) FROM paiement_loyer pl 
                 WHERE pl.contrat_id = c.id AND pl.statut = :statutPaye) AS loyers_payes
            FROM contrat c
            INNER JOIN annonce a ON c.annonceId = a.id
            INNER JOIN utilisateur u ON c.locataireId = u.id
            LEFT JOIN caution ca ON ca.contrat_id = c.id
            WHERE CAST(a.proprietaireId AS UNSIGNED) = :propId
            AND LOWER(c.statut) = :statutActif
            ORDER BY a.titre
        ";
        
        return $this->conn()->fetchAllAssociative($sql, [
            'propId' => $proprietaireId,
            'statutRetard' => 'EN_RETARD',
            'statutPaye' => 'PAYE',
            'statutActif' => 'actif',
        ]);
    }
}
