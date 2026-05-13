<?php
declare(strict_types=1);
namespace App\Repository;

use App\Entity\FaceDescriptor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<FaceDescriptor> */
class FaceDescriptorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FaceDescriptor::class);
    }
}
