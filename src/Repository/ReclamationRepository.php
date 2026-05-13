<?php

namespace App\Repository;

use App\Entity\Reclamation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reclamation>
 */
class ReclamationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reclamation::class);
    }

    public function getFilteredReclamationsQuery(?int $locataireId, string $search, string $type, string $status, ?int $proprietaireId = null): \Doctrine\ORM\Query
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.typeId', 't')
            ->addSelect('t')
            ->leftJoin('r.traitement', 'tr')
            ->addSelect('tr')
            ->leftJoin('r.contrat', 'c')
            ->addSelect('c')
            ->leftJoin('c.annonce', 'a')
            ->addSelect('a')
            ->orderBy('r.date', 'DESC');

        if ($locataireId !== null) {
            $qb->andWhere('r.locataire_id = :locataireId')
               ->setParameter('locataireId', $locataireId);
        }

        if ($proprietaireId !== null) {
            $qb->andWhere('a.proprietaire = :proprietaireId')
               ->setParameter('proprietaireId', $proprietaireId);
        }

        if ($status !== '' && $status !== 'TOUS') {
            $qb->andWhere('r.statut = :status')
               ->setParameter('status', $status);
        }

        if ($type !== '' && $type !== 'TOUS') {
            $qb->andWhere('t.libelle = :type OR r.type_autre = :type')
               ->setParameter('type', $type);
        }

        if ($search !== '') {
            if ($locataireId !== null) {
                // Locataire side: search by description containing the term
                $qb->andWhere('r.description LIKE :search')
                   ->setParameter('search', '%' . $search . '%');
            } else {
                // Owner side: strictly search by Locataire name containing the phrase
                $qb->andWhere('r.locataire_id IN (SELECT u.id FROM App\Entity\Utilisateur u WHERE LOWER(u.nom) LIKE LOWER(:search))')
                   ->setParameter('search', '%' . strtolower($search) . '%');
            }
        }

        return $qb->getQuery();
    }
}
