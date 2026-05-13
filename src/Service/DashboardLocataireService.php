<?php

namespace App\Service;

use App\Dto\DashboardLocataireDto;
use Doctrine\DBAL\Connection;

class DashboardLocataireService
{
    public function __construct(
        private Connection $conn
    ) {}

    public function buildDashboard(int $locataireId, string $nomLocataire): DashboardLocataireDto
    {
        $dto = new DashboardLocataireDto();
        $dto->nomLocataire = $nomLocataire;
        
        // 1. Prochain Loyer
        $this->loadProchainLoyer($dto, $locataireId);
        
        // 2. Charges en attente
        $this->loadChargesAttente($dto, $locataireId);
        
        // 3. Caution
        $this->loadCaution($dto, $locataireId);
        
        // 4. Statistiques
        $this->loadStatistiques($dto, $locataireId);
        
        // 5. Contrat
        $this->loadContrat($dto, $locataireId);
        
        return $dto;
    }

    private function loadProchainLoyer(DashboardLocataireDto $dto, int $locataireId): void
    {
        $sql = "
            SELECT pl.montant, pl.penalite, pl.date_echeance, pl.statut
            FROM paiement_loyer pl
            INNER JOIN contrat c ON pl.contrat_id = c.id
            WHERE c.locataireId = :locataireId
              AND pl.statut IN ('EN_ATTENTE', 'EN_RETARD', 'PARTIEL')
            ORDER BY pl.date_echeance ASC
            LIMIT 1
        ";
        
        $row = $this->conn->executeQuery($sql, ['locataireId' => $locataireId])
            ->fetchAssociative();
        
        if ($row) {
            $montant = (float)$row['montant'];
            $penalite = (float)($row['penalite'] ?? 0);
            $dto->prochainLoyerMontant = $montant + $penalite;
            $dto->prochainLoyerEcheance = new \DateTime($row['date_echeance']);
            
            // Calcul jours
            $aujourdhui = new \DateTime();
            $aujourdhui->setTime(0, 0, 0);
            $echeance = new \DateTime($row['date_echeance']);
            $echeance->setTime(0, 0, 0);
            $diff = $echeance->diff($aujourdhui);
            $jours = (int)$diff->format('%r%a'); // positive if aujourdhui > echeance
            
            $dto->joursRetard = $jours;
            
            if ($jours > 0) {
                $dto->statutRetard = 'err';
            } elseif ($jours === 0) {
                $dto->statutRetard = 'warn';
            } else {
                $dto->statutRetard = 'ok';
            }
        }
    }

    private function loadChargesAttente(DashboardLocataireDto $dto, int $locataireId): void
    {
        // Doctrine Doctor fix: paramétrer 'PAYE' pour éviter le warning SQL injection
        $sql = "
            SELECT cm.type_charge, cm.montant, cm.partage_coloc, 
                   cm.part_locataire, cm.statut_paiement,
                   COALESCE((SELECT SUM(pc.montant_paye) FROM paiement_charges pc WHERE pc.charge_id = cm.id), 0) as montant_paye
            FROM charges_mensuelles cm
            INNER JOIN contrat c ON cm.contrat_id = c.id
            WHERE c.locataireId = :locataireId
              AND cm.statut_paiement != :statutExclu
        ";
        
        $rows = $this->conn->executeQuery($sql, [
            'locataireId' => $locataireId,
            'statutExclu' => 'PAYE',
        ])->fetchAllAssociative();
        
        $total = 0;
        foreach ($rows as $row) {
            $montant = $row['partage_coloc'] ? 
                (float)$row['part_locataire'] : (float)$row['montant'];
            $paye = (float)$row['montant_paye'];
            $reste = $montant - $paye;
            
            if ($reste > 0) {
                $total += $reste;
                $dto->chargesDetail[] = [
                    'type' => $row['type_charge'] ?? 'Charge',
                    'montant' => $reste
                ];
            }
        }
        
        $dto->totalChargesAttente = $total;
    }

    private function loadCaution(DashboardLocataireDto $dto, int $locataireId): void
    {
        $sql = "
            SELECT ca.montant_initial, ca.statut
            FROM caution ca
            INNER JOIN contrat c ON ca.contrat_id = c.id
            WHERE c.locataireId = :locataireId
            ORDER BY ca.date_creation DESC
            LIMIT 1
        ";
        
        $row = $this->conn->executeQuery($sql, ['locataireId' => $locataireId])
            ->fetchAssociative();
        
        if ($row) {
            $dto->cautionMontant = (float)$row['montant_initial'];
            $dto->cautionStatut = $row['statut'];
            
            $statut = strtoupper((string) $row['statut']);
            if (in_array($statut, ['DETENU', 'DÉTENU'])) {
                $dto->cautionStyle = 'ok';
            } elseif ($statut === 'RETENU') {
                $dto->cautionStyle = 'warn';
            } elseif (in_array($statut, ['TOTALEMENT_REMBOURSE', 'PARTIELLEMENT_REMBOURSE'])) {
                $dto->cautionStyle = 'accent';
            }
        }
    }

    private function loadStatistiques(DashboardLocataireDto $dto, int $locataireId): void
    {
        // Total mois
        $sqlMois = "
            SELECT COALESCE(
                (SELECT SUM(pl.montant + pl.penalite) 
                 FROM paiement_loyer pl
                 INNER JOIN contrat c ON pl.contrat_id = c.id
                 WHERE c.locataireId = :id AND pl.statut = :statutPaye
                   AND MONTH(pl.date_paiement) = MONTH(CURRENT_DATE)
                   AND YEAR(pl.date_paiement) = YEAR(CURRENT_DATE)), 0
            ) + COALESCE(
                (SELECT SUM(pc.montant_paye)
                 FROM paiement_charges pc
                 INNER JOIN charges_mensuelles cm ON pc.charge_id = cm.id
                 INNER JOIN contrat c ON cm.contrat_id = c.id
                 WHERE c.locataireId = :id
                   AND MONTH(pc.date_paiement) = MONTH(CURRENT_DATE)
                   AND YEAR(pc.date_paiement) = YEAR(CURRENT_DATE)), 0
            ) as total
        ";
        
        $dto->totalPayeMois = (float)$this->conn->executeQuery($sqlMois, [
            'id' => $locataireId,
            'statutPaye' => 'PAYE',
        ])->fetchOne();
        
        // Total année
        $sqlAnnee = "
            SELECT COALESCE(
                (SELECT SUM(pl.montant + pl.penalite) 
                 FROM paiement_loyer pl
                 INNER JOIN contrat c ON pl.contrat_id = c.id
                 WHERE c.locataireId = :id AND pl.statut = :statutPaye
                   AND YEAR(pl.date_paiement) = YEAR(CURRENT_DATE)), 0
            ) + COALESCE(
                (SELECT SUM(pc.montant_paye)
                 FROM paiement_charges pc
                 INNER JOIN charges_mensuelles cm ON pc.charge_id = cm.id
                 INNER JOIN contrat c ON cm.contrat_id = c.id
                 WHERE c.locataireId = :id
                   AND YEAR(pc.date_paiement) = YEAR(CURRENT_DATE)), 0
            ) as total
        ";
        
        $dto->totalPayeAnnee = (float)$this->conn->executeQuery($sqlAnnee, [
            'id' => $locataireId,
            'statutPaye' => 'PAYE',
        ])->fetchOne();
        
        // Ponctualité
        $sqlStats = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN pl.statut = :statutPaye THEN 1 ELSE 0 END) as payes
            FROM paiement_loyer pl
            INNER JOIN contrat c ON pl.contrat_id = c.id
            WHERE c.locataireId = :id AND pl.statut != :statutExclu
        ";
        
        $stats = $this->conn->executeQuery($sqlStats, [
            'id' => $locataireId,
            'statutPaye' => 'PAYE',
            'statutExclu' => 'EN_ATTENTE',
        ])->fetchAssociative();
        
        // If there are no past-due or paid payments, consider all pending 'EN_ATTENTE' to adjust total
        if (!$stats || $stats['total'] == 0) {
            $sqlStatsAll = "
                SELECT COUNT(*) as total, SUM(CASE WHEN pl.statut = :statutPaye THEN 1 ELSE 0 END) as payes
                FROM paiement_loyer pl
                INNER JOIN contrat c ON pl.contrat_id = c.id
                WHERE c.locataireId = :id
            ";
            $stats = $this->conn->executeQuery($sqlStatsAll, [
                'id' => $locataireId,
                'statutPaye' => 'PAYE',
            ])->fetchAssociative();
        }
        
        if ($stats && $stats['total'] > 0) {
            $dto->pourcentageATemps = ((int)$stats['payes'] / (int)$stats['total']) * 100;
            
            if ($dto->pourcentageATemps >= 80) {
                $dto->ponctualiteStyle = 'ok';
            } elseif ($dto->pourcentageATemps >= 50) {
                $dto->ponctualiteStyle = 'warn';
            } else {
                $dto->ponctualiteStyle = 'err';
            }
        }
    }

    private function loadContrat(DashboardLocataireDto $dto, int $locataireId): void
    {
        $sql = "
            SELECT a.titre, c.montant, c.statut, c.date_debut, c.date_fin
            FROM contrat c
            INNER JOIN annonce a ON c.annonceId = a.id
            WHERE c.locataireId = :locataireId
            ORDER BY c.date_debut DESC
            LIMIT 1
        ";
        
        $row = $this->conn->executeQuery($sql, ['locataireId' => $locataireId])
            ->fetchAssociative();
        
        if ($row) {
            $dto->bienLoue = $row['titre'];
            $dto->loyerMensuel = (float)$row['montant'];
            $dto->statutContrat = strtoupper((string) $row['statut']);
            
            $statut = strtolower((string) $row['statut']);
            if ($statut === 'actif') {
                $dto->statutContratStyle = 'ok';
            } elseif (in_array($statut, ['terminé', 'termine'])) {
                $dto->statutContratStyle = 'warn';
            } else {
                $dto->statutContratStyle = 'err';
            }
            
            $debut = $row['date_debut'] ? (new \DateTime($row['date_debut']))->format('d/m/Y') : '?';
            $fin = $row['date_fin'] ? (new \DateTime($row['date_fin']))->format('d/m/Y') : '?';
            $dto->periodeContrat = "$debut → $fin";
        }
    }
}
