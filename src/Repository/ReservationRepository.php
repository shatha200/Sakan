<?php

namespace App\Repository;

use App\Entity\Reservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Reservation> */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    /**
     * Vérifie s'il existe une réservation (Approuvée ou En attente) qui chevauche la période donnée.
     * Cette version est plus stricte pour éviter les doublons de demandes.
     */
    public function hasConflict(\App\Entity\Annonce $annonce, \DateTimeInterface $start, \DateTimeInterface $end, ?int $excludeId = null): bool
    {
        $statuses = ['Approuvée', 'En attente'];

        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.annonce = :annonce')
            ->andWhere('r.statut IN (:statuses)')
            ->andWhere('r.dateDebut < :end AND r.dateFin > :start');

        if ($excludeId) {
            $qb->andWhere('r.id != :excludeId')
               ->setParameter('excludeId', $excludeId);
        }

        $count = $qb->setParameter('annonce', $annonce)
            ->setParameter('statuses', $statuses)
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }
}
