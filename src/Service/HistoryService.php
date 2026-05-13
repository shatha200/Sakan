<?php

namespace App\Service;

use App\Dto\PaymentHistoryDto;
use Doctrine\ORM\EntityManagerInterface;

class HistoryService
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
     * Parse une période dans différents formats possibles
     * Formats supportés: 2025-T3 (trimestre), 2025-01-01 (date), 2025-01 (mois), etc.
     */
    private function parsePeriode(?string $periode): ?\DateTimeInterface
    {
        if (!$periode) return null;

        // Format trimestriel: 2025-T3 → 2025-09-01 (début du trimestre)
        if (preg_match('/^(\d{4})-T(\d)$/', $periode, $matches)) {
            $year = $matches[1];
            $trimestre = (int)$matches[2];
            $month = (($trimestre - 1) * 3) + 1;
            return new \DateTime("$year-" . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . "-01");
        }

        // Format mois: 2025-01 → 2025-01-01
        if (preg_match('/^(\d{4})-(\d{2})$/', $periode, $matches)) {
            return new \DateTime($periode . '-01');
        }

        // Format date standard: 2025-01-05
        try {
            return new \DateTime($periode);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Parse une date avec gestion d'erreur
     */
    private function parseDate(?string $date): ?\DateTimeInterface
    {
        if (!$date) return null;

        try {
            return new \DateTime($date);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Historique unifié: Loyers + Charges payés
     * @return list<\App\Dto\PaymentHistoryDto>
     */
    public function getUnifiedHistory(int $locataireId, ?int $contratId = null): array
    {
        $sql = "
            SELECT 'LOYER' AS type_paiement, 
                   pl.id AS paiement_id, 
                   NULL AS charge_id,
                   pl.periode, 
                   CAST(pl.montant AS DECIMAL(10,3)) AS montant, 
                   CAST(COALESCE(pl.penalite, '0') AS DECIMAL(10,3)) AS penalite, 
                   CAST(pl.montant AS DECIMAL(10,3)) + CAST(COALESCE(pl.penalite, '0') AS DECIMAL(10,3)) AS montant_total,
                   pl.date_paiement, 
                   pl.methode_paiement, 
                   pl.reference_transaction,
                   pl.statut,
                   'Loyer' AS description
            FROM paiement_loyer pl
            INNER JOIN contrat c ON pl.contrat_id = c.id
            WHERE c.locataireId = :locataireId 
              AND pl.statut = 'PAYE'
              " . ($contratId ? "AND c.id = :contratId" : "") . "
            
            UNION ALL
            
            SELECT 'CHARGE' AS type_paiement,
                   pc.id AS paiement_id,
                   pc.charge_id,
                   cm.periode,
                   CAST(pc.montant_paye AS DECIMAL(10,3)) AS montant,
                   0.00 AS penalite,
                   CAST(pc.montant_paye AS DECIMAL(10,3)) AS montant_total,
                   pc.date_paiement,
                   pc.methode_paiement,
                   pc.reference_transaction,
                   'PAYE' AS statut,
                   CONCAT(cm.type_charge, ' - ', COALESCE(cm.description, '')) AS description
            FROM paiement_charges pc
            INNER JOIN charges_mensuelles cm ON CAST(pc.charge_id AS UNSIGNED) = cm.id
            INNER JOIN contrat c ON CAST(cm.contrat_id AS UNSIGNED) = c.id
            WHERE c.locataireId = :locataireId
              " . ($contratId ? "AND c.id = :contratId" : "") . "
            ORDER BY STR_TO_DATE(date_paiement, '%Y-%m-%d') DESC
        ";
        
        $params = ['locataireId' => $locataireId];
        if ($contratId) {
            $params['contratId'] = $contratId;
        }

        $stmt = $this->conn()->executeQuery($sql, $params);

        $records = [];
        while ($row = $stmt->fetchAssociative()) {
            $dto = new PaymentHistoryDto();
            $dto->type = $row['type_paiement'];
            $dto->paiementId = (int)$row['paiement_id'];
            $dto->chargeId = $row['charge_id'] ? (int)$row['charge_id'] : null;
            $dto->periode = $this->parsePeriode($row['periode']);
            $dto->montant = (float)$row['montant'];
            $dto->penalite = (float)$row['penalite'];
            $dto->montantTotal = (float)$row['montant_total'];
            $dto->datePaiement = $this->parseDate($row['date_paiement']);
            $dto->methode = $row['methode_paiement'];
            $dto->reference = $row['reference_transaction'];
            $dto->statut = $row['statut'];
            $dto->description = $row['description'];
            $records[] = $dto;
        }
        
        return $records;
    }
    
    /**
     * Statistiques globales
     * @return array<string, mixed>
     */
    public function getStats(int $locataireId): array
    {
        // Doctrine Doctor fix: Fractionnement de la méga-requête (qui contenait 7 JOINs) en deux requêtes simples
        // pour éviter le "Excessive Eager Loading" et améliorer les performances du moteur MySQL.

        // 1. Stats Loyers
        $sqlLoyers = "
            SELECT 
                COALESCE(SUM(CAST(pl.montant AS DECIMAL(10,3)) + CAST(COALESCE(pl.penalite, :zero) AS DECIMAL(10,3))), 0) AS total_loyers,
                COALESCE(SUM(CAST(pl.penalite AS DECIMAL(10,3))), 0) AS total_penalites,
                COUNT(*) AS count_loyers
            FROM paiement_loyer pl
            INNER JOIN contrat c ON pl.contrat_id = c.id
            WHERE c.locataireId = :id AND pl.statut = :statutPaye
        ";
        $statsLoyers = $this->conn()->executeQuery($sqlLoyers, [
            'id' => $locataireId,
            'zero' => '0',
            'statutPaye' => 'PAYE',
        ])->fetchAssociative();

        // 2. Stats Charges
        $sqlCharges = "
            SELECT 
                COALESCE(SUM(CAST(pc.montant_paye AS DECIMAL(10,3))), 0) AS total_charges,
                COUNT(*) AS count_charges
            FROM paiement_charges pc
            INNER JOIN charges_mensuelles cm ON CAST(pc.charge_id AS UNSIGNED) = cm.id
            INNER JOIN contrat c ON CAST(cm.contrat_id AS UNSIGNED) = c.id
            WHERE c.locataireId = :id
        ";
        $statsCharges = $this->conn()->executeQuery($sqlCharges, ['id' => $locataireId])->fetchAssociative();

        if ($statsLoyers === false) {
            $statsLoyers = ['total_loyers' => 0, 'total_penalites' => 0, 'count_loyers' => 0];
        }
        if ($statsCharges === false) {
            $statsCharges = ['total_charges' => 0, 'count_charges' => 0];
        }

        return [
            'totalLoyers'     => (float)$statsLoyers['total_loyers'],
            'totalCharges'    => (float)$statsCharges['total_charges'],
            'totalPenalites'  => (float)$statsLoyers['total_penalites'],
            'nombrePaiements' => (int)$statsLoyers['count_loyers'] + (int)$statsCharges['count_charges'],
        ];
    }
    
    /**
     * Logements du locataire
     * @return list<array<string, mixed>>
     */
    public function getLogements(int $locataireId): array
    {
        // Doctrine Doctor fix: Paramétrer les chaînes littérales
        $sql = "
            SELECT 
                COALESCE(a.id, 0) as log_id,
                c.id AS contrat_id,
                COALESCE(a.titre, CONCAT(:prefixContrat, c.id)) as titre,
                COALESCE(a.description, :defaultDesc) as description
            FROM contrat c
            LEFT JOIN annonce a ON c.annonceId = a.id
            WHERE c.locataireId = :locataireId
            ORDER BY c.date_debut DESC
        ";
        
        return $this->conn()->executeQuery($sql, [
            'locataireId'   => $locataireId,
            'prefixContrat' => 'Contrat #',
            'defaultDesc'   => 'Adresse non spécifiée',
        ])->fetchAllAssociative();
    }
    
    /**
     * Recherche par référence
     * @return list<array<string, mixed>>
     */
    public function searchByReference(int $locataireId, string $ref): array
    {
        $sql = "
            SELECT 'LOYER' AS type_paiement, 
                   pl.id AS paiement_id,
                   pl.periode,
                   CAST(pl.montant AS DECIMAL(10,3)) + CAST(COALESCE(pl.penalite, '0') AS DECIMAL(10,3)) AS montant_total,
                   pl.date_paiement,
                   pl.reference_transaction
            FROM paiement_loyer pl
            INNER JOIN contrat c ON pl.contrat_id = c.id
            WHERE c.locataireId = :locataireId 
              AND pl.reference_transaction LIKE :ref
            
            UNION ALL
            
            SELECT 'CHARGE' AS type_paiement,
                   pc.id AS paiement_id,
                   cm.periode,
                   CAST(pc.montant_paye AS DECIMAL(10,3)) AS montant_total,
                   pc.date_paiement,
                   pc.reference_transaction
            FROM paiement_charges pc
            INNER JOIN charges_mensuelles cm ON CAST(pc.charge_id AS UNSIGNED) = cm.id
            INNER JOIN contrat c ON CAST(cm.contrat_id AS UNSIGNED) = c.id
            WHERE c.locataireId = :locataireId 
              AND pc.reference_transaction LIKE :ref
            ORDER BY STR_TO_DATE(date_paiement, '%Y-%m-%d') DESC
        ";
        
        return $this->conn()->executeQuery($sql, [
            'locataireId' => $locataireId,
            'ref' => '%' . $ref . '%'
        ])->fetchAllAssociative();
    }
    
    /**
     * URL Quittance
     */
    public function getQuittanceUrl(int $paiementId): ?string
    {
        // Doctrine Doctor fix: Paramétrer les littéraux
        $sql = "
            SELECT fichier 
            FROM facture 
            WHERE type_reference = :typeRef
              AND reference_id = :paiementId
              AND type_document = :typeDoc
            ORDER BY id DESC 
            LIMIT 1
        ";
        
        $result = $this->conn()->executeQuery($sql, [
            'paiementId' => $paiementId,
            'typeRef'    => 'PAIEMENT_LOYER',
            'typeDoc'    => 'QUITTANCE',
        ])->fetchOne();
        
        return $result ?: null;
    }
}
