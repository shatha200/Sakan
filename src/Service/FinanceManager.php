<?php

namespace App\Service;

class FinanceManager
{
    public function calculerSoldeGlobal(
        float $totalLoyers,
        float $totalCharges,
        float $totalRecu,
        float $montantCaution
    ): float {
        return $totalLoyers + $totalCharges 
             - $totalRecu - $montantCaution;
    }

    public function isEnDette(float $solde): bool
    {
        return $solde > 0;
    }

    public function calculerMontantDisponibleCaution(
        float $montantInitial,
        float $montantRetention,
        float $montantRembourse
    ): float {
        if ($montantInitial <= 0) {
            throw new \InvalidArgumentException(
                'Le montant initial de la caution doit être positif'
            );
        }

        $disponible = $montantInitial 
                    - $montantRetention 
                    - $montantRembourse;

        if ($disponible < 0) {
            throw new \InvalidArgumentException(
                'Le montant disponible ne peut pas être négatif'
            );
        }

        return $disponible;
    }

    public function determinerStatutPaiementCharge(
        float $montantTotal,
        float $totalPaye
    ): string {
        if ($montantTotal <= 0) {
            throw new \InvalidArgumentException(
                'Le montant total doit être supérieur à zéro'
            );
        }

        if ($totalPaye >= $montantTotal) {
            return 'PAYE';
        }

        if ($totalPaye > 0) {
            return 'PARTIEL';
        }

        return 'NON_PAYE';
    }

    public function validerReferenceTransaction(
        ?string $reference
    ): bool {
        if (empty($reference) || trim($reference) === '') {
            throw new \InvalidArgumentException(
                'La référence de transaction est obligatoire'
            );
        }

        return true;
    }
}
