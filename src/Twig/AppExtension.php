<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class AppExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('format_periode', [$this, 'formatPeriode']),
        ];
    }

    /**
     * Formate une période dans différents formats possibles
     * Formats supportés: 2025-T3 (trimestre), 2025-01-01 (date), 2025-01 (mois), etc.
     */
    public function formatPeriode(?string $periode): string
    {
        if (!$periode) {
            return 'Période N/A';
        }

        // Format trimestriel: 2025-T3 → Q3 2025 ou "3ème trimestre 2025"
        if (preg_match('/^(\d{4})-T(\d)$/', $periode, $matches)) {
            $year = $matches[1];
            $trimestre = (int)$matches[2];
            $months = [
                1 => 'Jan-Mar',
                2 => 'Avr-Jun',
                3 => 'Juil-Sep',
                4 => 'Oct-Déc'
            ];
            return $months[$trimestre] . ' ' . $year;
        }

        // Format mois: 2025-01 → Janvier 2025
        if (preg_match('/^(\d{4})-(\d{2})$/', $periode, $matches)) {
            $date = new \DateTime($periode . '-01');
            return $date->format('F Y');
        }

        // Format date standard: 2025-01-05
        try {
            $date = new \DateTime($periode);
            return $date->format('F Y');
        } catch (\Exception $e) {
            return $periode;
        }
    }
}
