<?php

namespace App\Service;

use App\Entity\Contrat;
use App\Entity\PaiementLoyer;
use App\Entity\ChargesMensuelles;
use App\Entity\Caution;
use App\Repository\PaiementLoyerRepository;
use App\Repository\ChargesMensuellesRepository;
use App\Repository\CautionRepository;
use App\Repository\ContratRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service de liaison entre Contrat et Module Finance
 * 
 * Ce service permet d'intégrer les contrats avec :
 * - Les paiements de loyer
 * - Les charges mensuelles
 * - Les cautions
 * - Le calcul du solde financier
 */
class ContratFinanceService
{
    private EntityManagerInterface $em;
    private ContratRepository $contratRepo;

    public function __construct(
        EntityManagerInterface $em,
        ContratRepository $contratRepo,
        PaiementLoyerRepository $paiementRepo,
        ChargesMensuellesRepository $chargesRepo,
        CautionRepository $cautionRepo
    ) {
        $this->em = $em;
        $this->contratRepo = $contratRepo;
        // Repos injected for future use / autowiring compatibility
        unset($paiementRepo, $chargesRepo, $cautionRepo);
    }

    /**
     * Récupère le tableau de bord financier complet d'un contrat
     * @return array<string, mixed>
     */
    public function getContratDashboard(int $contratId): array
    {
        $contrat = $this->contratRepo->find($contratId);
        
        if (!$contrat) {
            return ['error' => 'Contrat non trouvé'];
        }

        $paiements = $this->findPaiementsByContrat($contratId);
        $charges = $this->findChargesByContrat($contratId);
        $caution = $this->findCautionByContrat($contratId);

        return [
            'contrat' => $contrat,
            'paiements' => [
                'total' => $this->calculerTotalPaiements($paiements),
                'en_attente' => $this->calculerPaiementsEnAttente($paiements),
                'retard' => $this->calculerPaiementsRetard($paiements),
                'liste' => $paiements,
            ],
            'charges' => [
                'total' => $this->calculerTotalCharges($charges),
                'paye' => $this->calculerChargesPayees($charges),
                'en_attente' => $this->calculerChargesEnAttente($charges),
                'liste' => $charges,
            ],
            'caution' => $caution ? [
                'montant_initial' => $caution->getMontantInitial(),
                'montant_retenu' => $caution->getMontantRetention(),
                'montant_rembourse' => $caution->getMontantRembourse(),
                'solde' => $caution->getMontantDisponible(),
                'statut' => $caution->getStatut(),
            ] : null,
            'solde_global' => $this->calculerSoldeGlobal($contratId),
        ];
    }

    /**
     * Crée automatiquement les paiements de loyer pour un contrat
     * Basé sur la durée du contrat et le montant mensuel
     */
    public function creerPaiementsAutomatiques(Contrat $contrat): void
    {
        if (!$contrat->getDateDebut() || !$contrat->getDateFin() || !$contrat->getMontant()) {
            throw new \InvalidArgumentException('Le contrat doit avoir des dates et un montant');
        }

        $dateDebut = new \DateTime($contrat->getDateDebut());
        $dateFin = new \DateTime($contrat->getDateFin());
        $montant = (float) $contrat->getMontant();
        
        $interval = $dateDebut->diff($dateFin);
        $nbMois = ($interval->y * 12) + $interval->m;

        for ($i = 0; $i <= $nbMois; $i++) {
            $datePaiement = clone $dateDebut;
            $datePaiement->modify("+{$i} months");
            
            $periode = $datePaiement->format('Y-m');
            
            // Vérifier si un paiement existe déjà pour cette période
            $existant = $this->findPaiementByContratAndPeriode((int)$contrat->getId(), $periode);
            
            if (!$existant) {
                $paiement = new PaiementLoyer();
                $paiement->setContrat($contrat);
                $paiement->setPeriode($periode);
                $paiement->setMontant((string) $montant);
                $paiement->setStatut('A_PAYER');
                $paiement->setDateEcheance($datePaiement->format('Y-m-d'));
                
                $this->em->persist($paiement);
            }
        }
        
        $this->em->flush();
    }

    /**
     * Crée une caution associée au contrat
     */
    public function creerCaution(Contrat $contrat, float $montant, ?string $description = null): Caution
    {
        $caution = new Caution();
        $caution->setContratId((int)$contrat->getId());
        $caution->setMontantInitial((string) $montant);
        $caution->setStatut('DETENU');
        $caution->setDateCreation(new \DateTime());
        
        if ($description) {
            $caution->setDescriptionGemini($description);
        }
        
        $this->em->persist($caution);
        $this->em->flush();
        
        return $caution;
    }

    /**
     * Calcule le solde global du contrat (loyers + charges - caution retenue)
     */
    public function calculerSoldeGlobal(int $contratId): float
    {
        $contrat = $this->contratRepo->find($contratId);
        if (!$contrat) {
            return 0.0;
        }

        // Total loyers dûs
        $totalLoyers = 0.0;
        $paiements = $this->findPaiementsByContrat($contratId);
        foreach ($paiements as $paiement) {
            $totalLoyers += (float) $paiement->getMontant();
        }

        // Total charges
        $totalCharges = 0.0;
        $charges = $this->findChargesByContrat($contratId);
        foreach ($charges as $charge) {
            $totalCharges += (float) $charge->getMontant();
        }

        // Paiements reçus
        $totalRecu = $this->calculerTotalPaiementsRecus($contratId);

        // Caution
        $caution = $this->findCautionByContrat($contratId);
        $montantCaution = $caution ? (float) $caution->getMontantInitial() : 0.0;

        return $totalLoyers + $totalCharges - $totalRecu - $montantCaution;
    }

    /**
     * Met à jour le statut financier du contrat
     */
    public function mettreAJourStatutFinancier(Contrat $contrat): void
    {
        $solde = $this->calculerSoldeGlobal((int)$contrat->getId());

        if ($solde <= 0) {
            $contrat->setStatut('PAYE');
        } elseif ($this->aDesRetards((int)$contrat->getId())) {
            $contrat->setStatut('EN_RETARD');
        } else {
            $contrat->setStatut('ACTIF');
        }
        
        // Note: la gestion des dates de modification est désactivée car le champ n'existe pas dans la BD
        $this->em->flush();
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // MÉTHODES PRIVÉES DE CALCUL
    // ═══════════════════════════════════════════════════════════════════════════

    /** @param array<int, PaiementLoyer> $paiements */
    private function calculerTotalPaiements(array $paiements): float
    {
        $total = 0.0;
        foreach ($paiements as $paiement) {
            $total += (float) $paiement->getMontant();
        }
        return $total;
    }

    /** @param array<int, PaiementLoyer> $paiements */
    private function calculerPaiementsEnAttente(array $paiements): float
    {
        $total = 0.0;
        foreach ($paiements as $paiement) {
            if ($paiement->getStatut() === 'A_PAYER') {
                $total += (float) $paiement->getMontant();
            }
        }
        return $total;
    }

    /** @param array<int, PaiementLoyer> $paiements */
    private function calculerPaiementsRetard(array $paiements): float
    {
        $total = 0.0;
        $aujourdhui = new \DateTime();
        
        foreach ($paiements as $paiement) {
            if ($paiement->getStatut() === 'A_PAYER' || $paiement->getStatut() === 'EN_RETARD') {
                $dateEcheance = new \DateTime($paiement->getDateEcheance());
                if ($dateEcheance < $aujourdhui) {
                    $total += (float) $paiement->getMontant();
                }
            }
        }
        return $total;
    }

    /** @param array<int, ChargesMensuelles> $charges */
    private function calculerTotalCharges(array $charges): float
    {
        $total = 0.0;
        foreach ($charges as $charge) {
            $total += (float) $charge->getMontant();
        }
        return $total;
    }

    /** @param array<int, ChargesMensuelles> $charges */
    private function calculerChargesPayees(array $charges): float
    {
        $total = 0.0;
        foreach ($charges as $charge) {
            if ($charge->getStatutPaiement() === 'PAYE') {
                $total += (float) $charge->getMontant();
            }
        }
        return $total;
    }

    /** @param array<int, ChargesMensuelles> $charges */
    private function calculerChargesEnAttente(array $charges): float
    {
        $total = 0.0;
        foreach ($charges as $charge) {
            if ($charge->getStatutPaiement() === 'EN_ATTENTE') {
                $total += (float) $charge->getMontant();
            }
        }
        return $total;
    }

    private function calculerTotalPaiementsRecus(int $contratId): float
    {
        $paiements = $this->findPaiementsByContratAndStatut($contratId, 'PAYE');
        
        return $this->calculerTotalPaiements($paiements);
    }

    private function aDesRetards(int $contratId): bool
    {
        $paiements = $this->findPaiementsByContratAndStatuts($contratId, ['A_PAYER', 'EN_RETARD']);
        
        $aujourdhui = new \DateTime();
        
        foreach ($paiements as $paiement) {
            $dateEcheance = new \DateTime($paiement->getDateEcheance());
            if ($dateEcheance < $aujourdhui) {
                return true;
            }
        }
        
        return false;
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // MÉTHODES HELPER - Requêtes directes via EntityManager
    // (évite l'instanciation des repositories personnalisés)
    // ═══════════════════════════════════════════════════════════════════════════

    /** @return array<int, PaiementLoyer> */
    private function findPaiementsByContrat(int $contratId): array
    {
        return $this->em->createQuery(
            'SELECT p FROM App\Entity\PaiementLoyer p WHERE p.contrat = :contratId'
        )->setParameter('contratId', $contratId)->getResult();
    }

    private function findPaiementByContratAndPeriode(int $contratId, string $periode): ?PaiementLoyer
    {
        return $this->em->createQuery(
            'SELECT p FROM App\Entity\PaiementLoyer p WHERE p.contrat = :contratId AND p.periode = :periode'
        )->setParameter('contratId', $contratId)
         ->setParameter('periode', $periode)
         ->getOneOrNullResult();
    }

    /** @return array<int, PaiementLoyer> */
    private function findPaiementsByContratAndStatut(int $contratId, string $statut): array
    {
        return $this->em->createQuery(
            'SELECT p FROM App\Entity\PaiementLoyer p WHERE p.contrat = :contratId AND p.statut = :statut'
        )->setParameter('contratId', $contratId)
         ->setParameter('statut', $statut)
         ->getResult();
    }

    /**
     * @param array<int, string> $statuts
     * @return array<int, PaiementLoyer>
     */
    private function findPaiementsByContratAndStatuts(int $contratId, array $statuts): array
    {
        return $this->em->createQuery(
            'SELECT p FROM App\Entity\PaiementLoyer p WHERE p.contrat = :contratId AND p.statut IN (:statuts)'
        )->setParameter('contratId', $contratId)
         ->setParameter('statuts', $statuts)
         ->getResult();
    }

    /** @return array<int, ChargesMensuelles> */
    private function findChargesByContrat(int $contratId): array
    {
        return $this->em->createQuery(
            'SELECT c FROM App\Entity\ChargesMensuelles c WHERE c.contratId = :contratId'
        )->setParameter('contratId', (string) $contratId)->getResult();
    }

    private function findCautionByContrat(int $contratId): ?Caution
    {
        return $this->em->createQuery(
            'SELECT c FROM App\Entity\Caution c WHERE c.contratId = :contratId'
        )->setParameter('contratId', $contratId)->getOneOrNullResult();
    }
}
