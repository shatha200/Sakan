<?php

namespace App\Repository;

use App\Entity\Utilisateur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Utilisateur>
 */
class UtilisateurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Utilisateur::class);
    }

    public function findOneByIdentifier(string $identifier): ?Utilisateur
    {
        $normalized = strtolower(trim($identifier));
        if ($normalized === '') {
            return null;
        }

        return $this->createQueryBuilder('u')
            ->where('LOWER(TRIM(u.email)) = :identifier')
            ->setParameter('identifier', $normalized)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function emailExists(string $email): bool
    {
        return $this->findOneByIdentifier($email) !== null;
    }

    public function findOneByGoogleSub(string $googleSub): ?Utilisateur
    {
        $normalized = trim($googleSub);
        if ($normalized === '') {
            return null;
        }

        return $this->createQueryBuilder('u')
            ->where('TRIM(u.google_sub) = :googleSub')
            ->setParameter('googleSub', $normalized)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function emailExistsForAnotherUser(string $email, int $excludeUserId): bool
    {
        $normalized = strtolower(trim($email));
        if ($normalized === '') {
            return false;
        }

        $count = (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('LOWER(TRIM(u.email)) = :email')
            ->andWhere('u.id != :excludeUserId')
            ->setParameter('email', $normalized)
            ->setParameter('excludeUserId', $excludeUserId)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function phoneExists(string $telephone, ?string $telephoneE164 = null): bool
    {
        $telephone = trim($telephone);
        $telephoneE164 = $telephoneE164 !== null ? trim($telephoneE164) : null;

        if ($telephone === '' && ($telephoneE164 === null || $telephoneE164 === '')) {
            return false;
        }

        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)');

        if ($telephone !== '' && $telephoneE164 !== null && $telephoneE164 !== '') {
            $qb->where('TRIM(u.telephone) = :telephone')
                ->orWhere('TRIM(u.telephone_e164) = :telephoneE164')
                ->setParameter('telephone', $telephone)
                ->setParameter('telephoneE164', $telephoneE164);
        } elseif ($telephone !== '') {
            $qb->where('TRIM(u.telephone) = :telephone')
                ->setParameter('telephone', $telephone);
        } else {
            $qb->where('TRIM(u.telephone_e164) = :telephoneE164')
                ->setParameter('telephoneE164', (string) $telephoneE164);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function phoneExistsForAnotherUser(string $telephone, ?string $telephoneE164, int $excludeUserId): bool
    {
        $telephone = trim($telephone);
        $telephoneE164 = $telephoneE164 !== null ? trim($telephoneE164) : null;

        if ($telephone === '' && ($telephoneE164 === null || $telephoneE164 === '')) {
            return false;
        }

        $qb = $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.id != :excludeUserId')
            ->setParameter('excludeUserId', $excludeUserId);

        if ($telephone !== '' && $telephoneE164 !== null && $telephoneE164 !== '') {
            $qb->andWhere('(TRIM(u.telephone) = :telephone OR TRIM(u.telephone_e164) = :telephoneE164)')
                ->setParameter('telephone', $telephone)
                ->setParameter('telephoneE164', $telephoneE164);
        } elseif ($telephone !== '') {
            $qb->andWhere('TRIM(u.telephone) = :telephone')
                ->setParameter('telephone', $telephone);
        } else {
            $qb->andWhere('TRIM(u.telephone_e164) = :telephoneE164')
                ->setParameter('telephoneE164', (string) $telephoneE164);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
