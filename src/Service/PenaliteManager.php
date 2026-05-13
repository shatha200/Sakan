<?php

namespace App\Service;

class PenaliteManager
{
    public function calculerPenalite(
        float $loyer,
        int $joursRetard,
        int $delaiGraceJours,
        float $penaliteFixe,
        float $penalitePourcentage,
        float $plafondPourcentage
    ): float {
        if ($loyer <= 0) {
            throw new \InvalidArgumentException(
                'Le loyer doit être supérieur à zéro'
            );
        }

        if ($joursRetard < 0) {
            throw new \InvalidArgumentException(
                'Le nombre de jours de retard ne peut pas être négatif'
            );
        }

        if ($plafondPourcentage < 0) {
            throw new \InvalidArgumentException(
                'Le plafond ne peut pas être négatif'
            );
        }

        if ($joursRetard <= $delaiGraceJours) {
            return 0.0;
        }

        $penaliteCalculee = $penaliteFixe 
            + ($loyer * $penalitePourcentage / 100);
        
        $plafond = $loyer * $plafondPourcentage / 100;

        return min($penaliteCalculee, $plafond);
    }

    public function isEnRetard(
        int $joursRetard,
        int $delaiGraceJours
    ): bool {
        return $joursRetard > $delaiGraceJours;
    }
}
