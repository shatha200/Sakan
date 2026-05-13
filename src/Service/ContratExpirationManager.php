<?php

namespace App\Service;

use App\Entity\Contrat;

class ContratExpirationManager
{
    public function daysUntilContractEnd(
        ?string $dateFin,
        \DateTimeImmutable $today
    ): ?int {
        if ($dateFin === null || $dateFin === '') {
            return null;
        }

        $fin = new \DateTimeImmutable($dateFin);

        if ($fin < $today) {
            return null;
        }

        return (int)$today->diff($fin)->days;
    }

    public function isExpiringSoon(
        Contrat $contrat,
        int $maxJours = 7
    ): bool {
        if ($contrat->getStatut() !== 'ACTIF') {
            return false;
        }

        $today = new \DateTimeImmutable();
        $jours = $this->daysUntilContractEnd(
            $contrat->getDateFin(),
            $today
        );

        if ($jours === null) {
            return false;
        }

        return $jours <= $maxJours;
    }
}
