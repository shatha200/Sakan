<?php

namespace App\Repository;

use App\Entity\Contrat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contrat>
 */
class ContratRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contrat::class);
    }

    /**
     * Sauvegarde un contrat
     */
    public function save(Contrat $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Supprime un contrat
     */
    public function remove(Contrat $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Trouve les contrats d'un locataire avec filtres optionnels.
     * @return array<string, mixed>
     */
    public function findByLocataire(int $locataireId, ?string $statut = null, ?string $search = null, int $maxResults = 200): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.annonce', 'a')
            ->leftJoin('c.locataire', 'l')
            ->addSelect('a', 'l')
            ->andWhere('l.id = :locataireId')
            ->setParameter('locataireId', $locataireId)
            ->orderBy('c.id', 'DESC')
            ->setMaxResults($maxResults);

        if ($statut !== null && $statut !== '') {
            $qb->andWhere('c.statut = :statut')
               ->setParameter('statut', $statut);
        }

        if ($search !== null && $search !== '') {
            $qb->andWhere('a.titre LIKE :search OR a.description LIKE :search OR CAST(c.id AS string) = :searchExact')
               ->setParameter('search', '%' . $search . '%')
               ->setParameter('searchExact', $search);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Trouve les contrats d'un propriétaire (via ses annonces)
     * @return array<string, mixed>
     */
    public function findByProprietaire(int $proprietaireId, ?string $statut = null, ?string $search = null, int $maxResults = 200): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.annonce', 'a')
            ->leftJoin('a.proprietaire', 'p')
            ->leftJoin('c.locataire', 'l')
            ->addSelect('a', 'p', 'l')
            ->andWhere('p.id = :proprietaireId')
            ->setParameter('proprietaireId', $proprietaireId)
            ->orderBy('c.id', 'DESC')
            ->setMaxResults($maxResults);

        if ($statut !== null && $statut !== '') {
            $qb->andWhere('c.statut = :statut')
               ->setParameter('statut', $statut);
        }

        if ($search !== null && $search !== '') {
            $qb->andWhere('a.titre LIKE :search OR l.email LIKE :search OR l.nom LIKE :search OR CAST(c.id AS string) = :searchExact')
               ->setParameter('search', '%' . $search . '%')
               ->setParameter('searchExact', $search);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Trouve les contrats actifs d'un locataire
     * @return array<string, mixed>
     */
    public function findActiveByLocataire(int $locataireId): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.annonce', 'a')
            ->addSelect('a')
            ->andWhere('c.locataire = :locataireId')
            ->andWhere('c.statut IN (:statuts)')
            ->setParameter('locataireId', $locataireId)
            ->setParameter('statuts', ['ACTIF', 'EN_ATTENTE_SIGNATURE'])
            ->orderBy('c.dateDebut', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve un contrat avec toutes ses relations (pour dashboard)
     */
    public function findWithRelations(int $id): ?Contrat
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.annonce', 'a')
            ->leftJoin('c.locataire', 'l')
            ->addSelect('a', 'l')
            ->andWhere('c.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Trouve les contrats par statut
     * @return array<string, mixed>
     */
    public function findByStatut(string $statut): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.statut = :statut')
            ->setParameter('statut', $statut)
            ->orderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les contrats en attente de signature
     * @return array<string, mixed>
     */
    public function findEnAttenteSignature(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.statut = :statut')
            ->setParameter('statut', 'EN_ATTENTE_SIGNATURE')
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les contrats qui se terminent bientôt (dans les 30 jours)
     * @return array<string, mixed>
     */
    public function findExpiringSoon(): array
    {
        $dateLimit = new \DateTime();
        $dateLimit->modify('+30 days');

        return $this->createQueryBuilder('c')
            ->andWhere('c.dateFin <= :dateLimit')
            ->andWhere('c.dateFin >= :today')
            ->andWhere('c.statut = :statut')
            ->setParameter('dateLimit', $dateLimit->format('Y-m-d'))
            ->setParameter('today', (new \DateTime())->format('Y-m-d'))
            ->setParameter('statut', 'ACTIF')
            ->orderBy('c.dateFin', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche de contrats par critères
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    public function search(array $criteria): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.annonce', 'a')
            ->leftJoin('c.locataire', 'l')
            ->addSelect('a', 'l');

        if (!empty($criteria['locataire_id'])) {
            $qb->andWhere('l.id = :locataireId')
               ->setParameter('locataireId', $criteria['locataire_id']);
        }

        if (!empty($criteria['proprietaire_id'])) {
            $qb->andWhere('a.proprietaire = :proprietaireId')
               ->setParameter('proprietaireId', $criteria['proprietaire_id']);
        }

        if (!empty($criteria['statut'])) {
            $qb->andWhere('c.statut = :statut')
               ->setParameter('statut', $criteria['statut']);
        }

        if (!empty($criteria['search'])) {
            $qb->andWhere('a.titre LIKE :search OR l.email LIKE :search')
               ->setParameter('search', '%' . $criteria['search'] . '%');
        }

        return $qb
            ->orderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Compte les contrats par statut pour un utilisateur
     * @return array<string, mixed>
     */
    public function countByStatutForUser(int $userId, string $role): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c.statut, COUNT(c.id) as count')
            ->groupBy('c.statut');

        if ($role === 'LOCATAIRE') {
            $qb->andWhere('c.locataire = :userId')
               ->setParameter('userId', $userId);
        } else {
            $qb->leftJoin('c.annonce', 'a')
               ->andWhere('a.proprietaire = :userId')
               ->setParameter('userId', $userId);
        }

        $results = $qb->getQuery()->getResult();

        $counts = [
            'ACTIF' => 0,
            'EN_ATTENTE_SIGNATURE' => 0,
            'TERMINE' => 0,
            'RESILIE' => 0,
        ];

        foreach ($results as $result) {
            $counts[$result['statut']] = (int) $result['count'];
        }

        return $counts;
    }
}
