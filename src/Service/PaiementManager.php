<?php

namespace App\Service;

use App\Entity\PaiementLoyer;

/**
 * Service métier dédié à la validation des paiements de loyer.
 * Contient uniquement de la logique pure (pas de dépendances Doctrine).
 */
class PaiementManager
{
    /** Statuts acceptés par l'application */
    private const STATUTS_VALIDES = ['A_PAYER', 'EN_ATTENTE', 'PAYE', 'EN_RETARD'];

    /**
     * Valide un paiement de loyer selon les règles métier.
     *
     * Règle 1 : Le montant doit être strictement supérieur à 0.
     * Règle 2 : Le statut doit appartenir à la liste des statuts valides.
     * Règle 3 : La date d'échéance est obligatoire (non nulle, non vide).
     *
     * @throws \InvalidArgumentException si une règle est violée
     */
    public function validate(PaiementLoyer $paiement): bool
    {
        // Règle 1 — montant > 0
        if ((float) $paiement->getMontant() <= 0) {
            throw new \InvalidArgumentException(
                'Le montant du loyer doit être supérieur à zéro'
            );
        }

        // Règle 2 — statut valide
        if (!in_array($paiement->getStatut(), self::STATUTS_VALIDES, true)) {
            throw new \InvalidArgumentException(
                'Le statut du paiement est invalide'
            );
        }

        // Règle 3 — date d'échéance obligatoire
        // getDateEcheance() retourne string (jamais null) → on vérifie uniquement la chaîne vide
        if (trim($paiement->getDateEcheance()) === '') {
            throw new \InvalidArgumentException(
                "La date d'échéance est obligatoire"
            );
        }

        return true;
    }
}
