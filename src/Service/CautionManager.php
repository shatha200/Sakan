<?php

namespace App\Service;

use App\Entity\Caution;

/**
 * Service métier dédié à la validation des cautions.
 * Contient uniquement de la logique pure (pas de dépendances Doctrine).
 */
class CautionManager
{
    /**
     * Valide une caution selon les règles métier.
     *
     * Règle 1 : Le montant initial doit être strictement supérieur à 0.
     * Règle 2 : Le montant de rétention ne peut pas dépasser le montant initial.
     * Règle 3 : Si une rétention est présente (> 0), la description est obligatoire.
     *
     * @throws \InvalidArgumentException si une règle est violée
     */
    public function validate(Caution $caution): bool
    {
        $initial   = (float) $caution->getMontantInitial();
        $retention = (float) $caution->getMontantRetention();

        // Règle 1 — montant initial > 0
        if ($initial <= 0) {
            throw new \InvalidArgumentException(
                'Le montant initial de la caution doit être supérieur à zéro'
            );
        }

        // Règle 2 — rétention ≤ initial
        if ($retention > $initial) {
            throw new \InvalidArgumentException(
                'Le montant de rétention ne peut pas dépasser le montant initial'
            );
        }

        // Règle 3 — description obligatoire quand rétention > 0
        if ($retention > 0 && empty($caution->getDescriptionRetenue())) {
            throw new \InvalidArgumentException(
                'La description est obligatoire quand il y a une rétention'
            );
        }

        return true;
    }
}
