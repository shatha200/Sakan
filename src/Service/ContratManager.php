<?php

namespace App\Service;

use App\Entity\Contrat;

class ContratManager
{
    private const STATUTS_VALIDES = [
        'PROPOSE',
        'EN_ATTENTE_SIGNATURE',
        'ACTIF',
        'TERMINE',
        'REFUSE',
        'RESILIE'
    ];

    public function validate(Contrat $contrat): bool
    {
        if (empty($contrat->getMontant()) || 
            (float)$contrat->getMontant() <= 0) {
            throw new \InvalidArgumentException(
                'Le montant du loyer doit être supérieur à zéro'
            );
        }

        if (empty($contrat->getDateDebut()) || 
            empty($contrat->getDateFin())) {
            throw new \InvalidArgumentException(
                'Les dates de début et de fin sont obligatoires'
            );
        }

        $dateDebut = new \DateTime($contrat->getDateDebut());
        $dateFin = new \DateTime($contrat->getDateFin());

        if ($dateFin <= $dateDebut) {
            throw new \InvalidArgumentException(
                'La date de fin doit être postérieure à la date de début'
            );
        }

        if (!in_array($contrat->getStatut(), self::STATUTS_VALIDES)) {
            throw new \InvalidArgumentException(
                'Le statut du contrat est invalide'
            );
        }

        return true;
    }

    public function isFullySigned(Contrat $contrat): bool
    {
        return $contrat->isSigneLocataire() 
            && $contrat->isSigneProprietaire();
    }

    public function getDureeMois(Contrat $contrat): int
    {
        if (empty($contrat->getDateDebut()) || 
            empty($contrat->getDateFin())) {
            throw new \InvalidArgumentException(
                'Les dates sont obligatoires pour calculer la durée'
            );
        }

        $dateDebut = new \DateTime($contrat->getDateDebut());
        $dateFin = new \DateTime($contrat->getDateFin());
        $diff = $dateDebut->diff($dateFin);

        return ($diff->y * 12) + $diff->m;
    }
}
