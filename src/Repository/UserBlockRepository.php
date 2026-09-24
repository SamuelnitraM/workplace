<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserBlock;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserBlock>
 */
class UserBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserBlock::class);
    }

    public function findOneByPair(User $blocker, User $blocked): ?UserBlock
    {
        return $this->findOneBy(['blocker' => $blocker, 'blocked' => $blocked]);
    }

    /** True when either member blocked the other. */
    public function isBlockedEitherWay(User $firstUser, User $secondUser): bool
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('(b.blocker = :first AND b.blocked = :second) OR (b.blocker = :second AND b.blocked = :first)')
            ->setParameter('first', $firstUser)
            ->setParameter('second', $secondUser)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Members blocked by the given member, most recent first.
     *
     * @return UserBlock[]
     */
    public function findBlockedBy(User $blocker): array
    {
        return $this->createQueryBuilder('b')
            ->addSelect('u')
            ->innerJoin('b.blocked', 'u')
            ->where('b.blocker = :blocker')
            ->setParameter('blocker', $blocker)
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
