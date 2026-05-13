<?php

namespace App\Service;

use App\Entity\ChargesMensuelles;

/**
 * Service métier dédié à la validation des charges mensuelles.
 * Contient uniquement de la logique pure (pas de dépendances Doctrine).
 */
class ChargeManager
{
    /** Types de charges acceptés par l'application */
    private const VALID_TYPES = [
        'ELECTRICITE',
        'EAU',
        'GAZ',
        'CHAUFFAGE',
        'INTERNET',
        'ORDURES',
        'CHARGES_COPRO',
        'ENTRETIEN',
        'AUTRE',
    ];

    /**
     * Valide une charge mensuelle selon les règles métier.
     *
     * Règle 1 : Le montant doit être strictement supérieur à 0.
     * Règle 2 : Le type de charge doit appartenir à VALID_TYPES.
     * Règle 3 : Si partageColoc = '1', le nombre de colocataires doit être >= 2.
     *
     * @throws \InvalidArgumentException si une règle est violée
     */
    public function validate(ChargesMensuelles $charge): bool
    {
        // Règle 1 — montant > 0
        if ((float) $charge->getMontant() <= 0) {
            throw new \InvalidArgumentException(
                'Le montant de la charge doit être supérieur à zéro'
            );
        }

        // Règle 2 — type valide
        if (!in_array($charge->getTypeCharge(), self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException(
                'Le type de charge est invalide'
            );
        }

        // Règle 3 — partage coloc → nb colocataires >= 2
        if ($charge->getPartageColoc() === '1' && (int) $charge->getNombreColocataires() < 2) {
            throw new \InvalidArgumentException(
                'Le nombre de colocataires doit être au moins 2 pour activer le partage'
            );
        }

        return true;
    }
}
