<?php

namespace App\Repository;

use App\Entity\ForumImage;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ForumImage>
 */
class ForumImageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ForumImage::class);
    }

    public function countSince(User $uploader, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.uploader = :uploader')
            ->andWhere('i.createdAt >= :since')
            ->setParameter('uploader', $uploader)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
