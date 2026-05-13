<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

class CautionLocataireService
{
    public function __construct(private Connection $conn) {}

    /** @return list<array<string, mixed>> */
    public function getLogementsByLocataire(int $locataireId): array
    {
        $sql = "
            SELECT COALESCE(a.id, 0) as log_id, c.id AS contrat_id,
                COALESCE(a.titre, CONCAT(:prefixContrat, c.id)) as titre,
                COALESCE(a.description, :defaultDesc) as description
            FROM contrat c
            LEFT JOIN annonce a ON c.annonceId = a.id
            WHERE c.locataireId = :locataireId
            ORDER BY c.date_debut DESC
        ";
        
        return $this->conn->executeQuery($sql, [
            'locataireId' => $locataireId,
            'prefixContrat' => 'Contrat #',
            'defaultDesc' => 'Adresse non spécifiée'
        ])->fetchAllAssociative();
    }

    /** @return list<array<string, mixed>> */
    public function getAllByLocataire(int $locataireId): array
    {
        $sql = "
            SELECT ca.*, c.montant AS loyer_mensuel, c.date_debut, c.date_fin, c.statut AS statut_contrat
            FROM caution ca
            INNER JOIN contrat c ON ca.contrat_id = c.id
            WHERE c.locataireId = :locataireId
            ORDER BY ca.date_creation DESC
        ";
        
        $cautions = $this->conn->executeQuery($sql, ['locataireId' => $locataireId])->fetchAllAssociative();
        return $this->enrichCautions($cautions);
    }

    /** @return array<string, mixed>|null */
    public function getByContrat(int $contratId): ?array
    {
        $sql = "SELECT ca.* FROM caution ca WHERE ca.contrat_id = :contratId LIMIT 1";
        $caution = $this->conn->executeQuery($sql, ['contratId' => $contratId])->fetchAssociative();
        
        if (!$caution) return null;
        return $this->enrichCautions([$caution])[0];
    }
    
    /** @return list<array<string, mixed>> */
    public function getPhotosByCaution(int $cautionId): array
    {
        $sql = "SELECT * FROM caution_retenue_photo WHERE caution_id = :cautionId ORDER BY id ASC";
        return $this->conn->executeQuery($sql, ['cautionId' => $cautionId])->fetchAllAssociative();
    }

    /**
     * Récupère une caution par ID avec vérification locataire (sécurité)
     * @return array<string, mixed>|null
     */
    public function getByIdAndLocataire(int $cautionId, int $locataireId): ?array
    {
        $sql = "
            SELECT ca.* 
            FROM caution ca
            INNER JOIN contrat c ON ca.contrat_id = c.id
            WHERE ca.id = :cautionId AND c.locataireId = :locataireId
            LIMIT 1
        ";
        
        $caution = $this->conn->executeQuery($sql, [
            'cautionId' => $cautionId,
            'locataireId' => $locataireId,
        ])->fetchAssociative();
        
        if (!$caution) return null;
        return $this->enrichCautions([$caution])[0];
    }

    /**
     * Effectue un remboursement avec mise à jour auto du statut
     * Requête 6 du cahier des charges Java
     */
    public function rembourser(int $cautionId, float $montantRembourse): bool
    {
        try {
            $sql = "
                UPDATE caution 
                SET 
                    montant_rembourse = montant_rembourse + :montantRembourse,
                    date_remboursement = CURRENT_TIMESTAMP,
                    date_modification = CURRENT_TIMESTAMP,
                    statut = CASE 
                        WHEN montant_initial - (montant_retention + (montant_rembourse + :montantRembourse2)) <= 0 
                        THEN :statutTotal 
                        ELSE :statutPartiel 
                    END
                WHERE id = :cautionId
            ";
            
            $rows = $this->conn->executeStatement($sql, [
                'montantRembourse' => $montantRembourse,
                'montantRembourse2' => $montantRembourse,
                'cautionId' => $cautionId,
                'statutTotal' => 'TOTALEMENT_REMBOURSE',
                'statutPartiel' => 'PARTIELLEMENT_REMBOURSE',
            ]);
            
            return $rows > 0;
        } catch (\Exception $e) {
            error_log('[CautionLocataireService] rembourser: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * @param list<array<string, mixed>> $cautions
     * @return list<array<string, mixed>>
     */
    private function enrichCautions(array $cautions): array
    {
        foreach ($cautions as &$c) {
            $init = (float)($c['montant_initial'] ?? 0);
            $ret = (float)($c['montant_retention'] ?? 0);
            $remb = (float)($c['montant_rembourse'] ?? 0);
            $c['montant_disponible'] = $init - $ret - $remb;
        }
        return $cautions;
    }
}
