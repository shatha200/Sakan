<?php

namespace App\Service;

use App\Repository\AdminChargeRepository;
use App\Repository\AdminLoyerRepository;

class AdminFinanceAlerteService
{
    public function __construct(
        private readonly AdminLoyerRepository $loyerRepo,
        private readonly AdminChargeRepository $chargeRepo
    ) {}

    /**
     * Retourne le nombre total d'alertes financières urgentes.
     * (Loyers en retard + Charges impayées)
     */
    public function countAlertes(): int
    {
        $retards = $this->loyerRepo->findEnRetard();
        $impayees = $this->chargeRepo->findImpayees();

        return count($retards) + count($impayees);
    }
}
