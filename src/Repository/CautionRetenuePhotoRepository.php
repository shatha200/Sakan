<?php

namespace App\Repository;

use App\Entity\CautionRetenuePhoto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CautionRetenuePhoto>
 */
class CautionRetenuePhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CautionRetenuePhoto::class);
    }

    /** @return list<array<string, mixed>> */
    public function findByCautionId(int $cautionId): array
    {
        $sql = "SELECT * FROM caution_retenue_photo WHERE caution_id = ? ORDER BY id ASC";
        
        return $this->getEntityManager()
            ->getConnection()
            ->executeQuery($sql, [$cautionId])
            ->fetchAllAssociative();
    }
}
