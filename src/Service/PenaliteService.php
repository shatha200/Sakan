<?php

namespace App\Service;

use App\Entity\ReglePenalite;

class PenaliteService
{
    /**
     * Calcule la pénalité selon la formule métier
     */
    public function calculerPenalite(
        float $loyer,
        int $joursRetard,
        int $delaiGrace,
        float $penaliteFixe,
        float $pourcentage,
        float $plafond
    ): float {
        // Si dans le délai de grâce = 0
        if ($joursRetard <= $delaiGrace) {
            return 0.0;
        }

        // Calcul variable: Loyer × Pourcentage / 100
        $variable = $loyer * ($pourcentage / 100);

        // Total: Fixe + Variable
        $total = $penaliteFixe + $variable;

        // Plafond maximum: Loyer × Plafond% / 100
        $plafondMax = $loyer * ($plafond / 100);

        // Appliquer plafond si dépassé
        return min($total, $plafondMax);
    }

    /**
     * Retourne les configurations des profils prédéfinis
     * @return array<string, mixed>
     */
    public function getProfilConfig(string $profil): array
    {
        $configs = [
            'tolerant' => [
                'nom' => 'Tolérant',
                'icone' => '😊',
                'description' => 'Bon locataire, approche bienveillante',
                'delaiGrace' => 7,
                'montantFixe' => 5.00,
                'pourcentage' => 1.00,
                'plafond' => 8.00,
                'couleur' => '#10b981'
            ],
            'standard' => [
                'nom' => 'Standard',
                'icone' => '⚖️',
                'description' => 'Équilibre, plupart des situations',
                'delaiGrace' => 5,
                'montantFixe' => 10.00,
                'pourcentage' => 2.50,
                'plafond' => 10.00,
                'couleur' => '#3b82f6'
            ],
            'strict' => [
                'nom' => 'Strict',
                'icone' => '😠',
                'description' => 'Locataire problématique',
                'delaiGrace' => 3,
                'montantFixe' => 15.00,
                'pourcentage' => 5.00,
                'plafond' => 15.00,
                'couleur' => '#ef4444'
            ]
        ];

        return $configs[$profil] ?? $configs['standard'];
    }

    /**
     * Retourne tous les profils disponibles
     * @return array<string, mixed>
     */
    public function getAllProfils(): array
    {
        return [
            'tolerant' => $this->getProfilConfig('tolerant'),
            'standard' => $this->getProfilConfig('standard'),
            'strict' => $this->getProfilConfig('strict')
        ];
    }

    /**
     * Simule le calcul de pénalité pour affichage temps réel
     * @return array<string, mixed>
     */
    public function simulerPenalite(
        float $loyer,
        int $joursRetard,
        ?ReglePenalite $regle = null,
        ?string $profil = null
    ): array {
        if ($regle) {
            $delaiGrace = $regle->getDelaiGraceJours();
            $penaliteFixe = (float) $regle->getPenaliteFixe();
            $pourcentage = (float) $regle->getPenalitePourcentage();
            $plafond = (float) $regle->getPlafondPourcentage();
        } elseif ($profil) {
            $config = $this->getProfilConfig($profil);
            $delaiGrace = $config['delaiGrace'];
            $penaliteFixe = $config['montantFixe'];
            $pourcentage = $config['pourcentage'];
            $plafond = $config['plafond'];
        } else {
            $delaiGrace = 5;
            $penaliteFixe = 10.00;
            $pourcentage = 2.50;
            $plafond = 10.00;
        }

        $penalite = $this->calculerPenalite(
            $loyer,
            $joursRetard,
            $delaiGrace,
            $penaliteFixe,
            $pourcentage,
            $plafond
        );

        return [
            'loyer' => $loyer,
            'joursRetard' => $joursRetard,
            'delaiGrace' => $delaiGrace,
            'penaliteFixe' => $penaliteFixe,
            'pourcentage' => $pourcentage,
            'plafond' => $plafond,
            'penaliteCalculee' => $penalite,
            'dansDelaiGrace' => $joursRetard <= $delaiGrace
        ];
    }
}
