<?php

namespace App\Repository;

use App\Entity\Annonce;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Annonce>
 */
class AnnonceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Annonce::class);
    }

    /**
     * @return Annonce[] Returns an array of Annonce objects
     */
    public function findByProprietaire(int $proprietaireId): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.proprietaire = :proprietaireId')
            ->setParameter('proprietaireId', $proprietaireId)
            ->orderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Annonce[] Returns an array of Annonce objects with statut disponible
     */
    public function findDisponibles(): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.statut = :statut')
            ->setParameter('statut', 'disponible')
            ->orderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
