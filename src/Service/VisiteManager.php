<?php

namespace App\Service;

use App\Entity\Visite;

class VisiteManager
{
    private const STATUTS_VALIDES = [
        'En attente',
        'Confirmée',
        'Refusée',
        'Annulée'
    ];

    private const INTERVALLE_MIN_MINUTES = 120;

    public function validate(Visite $visite): bool
    {
        if ($visite->getDateHeure() === null) {
            throw new \InvalidArgumentException(
                'La date et heure de la visite sont obligatoires'
            );
        }

        $now = new \DateTime();
        if ($visite->getDateHeure() <= $now) {
            throw new \InvalidArgumentException(
                'La date de visite doit être dans le futur'
            );
        }

        if (!in_array($visite->getStatut(), self::STATUTS_VALIDES)) {
            throw new \InvalidArgumentException(
                'Le statut de la visite est invalide'
            );
        }

        return true;
    }

    public function hasConflitIntervalle(
        \DateTimeInterface $nouvelleVisite,
        \DateTimeInterface $visiteExistante
    ): bool {
        $diffMinutes = abs(
            ($nouvelleVisite->getTimestamp() - $visiteExistante->getTimestamp()) / 60
        );

        return $diffMinutes < self::INTERVALLE_MIN_MINUTES;
    }

    public function peutGenererQrPass(Visite $visite): bool
    {
        $statutsAutorises = ['acceptée', 'confirmée'];

        if (!in_array(strtolower((string)$visite->getStatut()), $statutsAutorises)) {
            throw new \InvalidArgumentException(
                'Le QR pass ne peut être généré que pour une visite confirmée ou acceptée'
            );
        }

        return true;
    }
}
