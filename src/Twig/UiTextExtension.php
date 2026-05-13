<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class UiTextExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('month_year_fr', [$this, 'formatMonthYearFr']),
        ];
    }

    public function formatMonthYearFr(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            $date = $value;
        } else {
            try {
                $date = new \DateTimeImmutable((string) $value);
            } catch (\Throwable) {
                return '';
            }
        }

        $months = [
            1 => 'janvier',
            2 => 'février',
            3 => 'mars',
            4 => 'avril',
            5 => 'mai',
            6 => 'juin',
            7 => 'juillet',
            8 => 'août',
            9 => 'septembre',
            10 => 'octobre',
            11 => 'novembre',
            12 => 'décembre',
        ];

        $monthNumber = (int) $date->format('n');
        $month = $months[$monthNumber] ?? '';
        if ($month === '') {
            return $date->format('m/Y');
        }

        return $month . ' ' . $date->format('Y');
    }
}
