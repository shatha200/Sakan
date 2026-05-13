<?php

namespace App\Service;

use App\Entity\Reservation;

class ReservationManager
{
    private const STATUTS_VALIDES = [
        'En attente',
        'Approuvée',
        'Refusée',
        'Annulée'
    ];

    public function validate(Reservation $reservation): bool
    {
        if ($reservation->getDateDebut() === null) {
            throw new \InvalidArgumentException(
                'La date de début est obligatoire'
            );
        }

        if ($reservation->getDateFin() === null) {
            throw new \InvalidArgumentException(
                'La date de fin est obligatoire'
            );
        }

        $now = new \DateTime();
        if ($reservation->getDateDebut() < $now) {
            throw new \InvalidArgumentException(
                'La date de début ne peut pas être dans le passé'
            );
        }

        if ($reservation->getDateFin() <= $reservation->getDateDebut()) {
            throw new \InvalidArgumentException(
                'La date de fin doit être postérieure à la date de début'
            );
        }

        if (!in_array($reservation->getStatut(), self::STATUTS_VALIDES)) {
            throw new \InvalidArgumentException(
                'Le statut de la réservation est invalide'
            );
        }

        return true;
    }

    public function peutCreerContrat(Reservation $reservation): bool
    {
        if (strtolower((string)$reservation->getStatut()) !== 'approuvée') {
            throw new \InvalidArgumentException(
                'Un contrat ne peut être créé que depuis une réservation approuvée'
            );
        }

        return true;
    }
}
