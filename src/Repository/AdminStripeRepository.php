<?php

namespace App\Repository;

use Doctrine\ORM\EntityManagerInterface;

class AdminStripeRepository
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /** @return array<string, mixed> */
    public function getKpis(): array
    {
        return [
            'total_volume' => 0,
            'nb_transactions' => 0,
            'nb_reussis' => 0,
            'nb_echecs' => 0
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function findAll(array $filters = []): array
    {
        // Dans l'avenir, interroger l'API Stripe ou la table locale de logs.
        return [];
    }
}
