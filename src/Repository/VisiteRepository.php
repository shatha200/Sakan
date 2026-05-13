<?php

namespace App\Repository;

use App\Entity\Visite;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Visite> */
class VisiteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Visite::class);
    }

    /**
     * Vérifie si une visite (En attente ou Confirmée) existe déjà 
     * pour ce bien dans un créneau de ±1 heure.
     */
    public function hasConflict(\App\Entity\Annonce $annonce, \DateTimeInterface $dateHeure, ?int $excludeId = null): bool
    {
        $windowStart = (clone \DateTime::createFromInterface($dateHeure))->modify('-1 hour');
        $windowEnd   = (clone \DateTime::createFromInterface($dateHeure))->modify('+1 hour');

        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.annonce = :annonce')
            ->andWhere('v.statut IN (:statuses)')
            ->andWhere('v.dateHeure >= :windowStart AND v.dateHeure <= :windowEnd')
            ->setParameter('annonce', $annonce)
            ->setParameter('statuses', ['En attente', 'Confirmée'])
            ->setParameter('windowStart', $windowStart)
            ->setParameter('windowEnd', $windowEnd);

        if ($excludeId) {
            $qb->andWhere('v.id != :excludeId')
               ->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
