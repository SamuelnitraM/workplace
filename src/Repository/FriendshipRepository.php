<?php

namespace App\Repository;

use App\Entity\Friendship;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Friendship>
 */
class FriendshipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Friendship::class);
    }

    public function findExisting(User $user1, User $user2): ?Friendship
    {
        return $this->createQueryBuilder('f')
            ->where('(f.requester = :user1 AND f.receiver = :user2)')
            ->orWhere('(f.requester = :user2 AND f.receiver = :user1)')
            ->setParameter('user1', $user1)
            ->setParameter('user2', $user2)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findAcceptedFriends(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->where('(f.requester = :user OR f.receiver = :user)')
            ->andWhere('f.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', 'accepted')
            ->getQuery()
            ->getResult();
    }

    public function findPendingReceived(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.receiver = :user')
            ->andWhere('f.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', 'pending')
            ->getQuery()
            ->getResult();
    }

    public function findPendingSent(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.requester = :user')
            ->andWhere('f.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', 'pending')
            ->getQuery()
            ->getResult();
    }

    public function findBlocked(User $user): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.receiver = :user')
            ->andWhere('f.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', 'blocked')
            ->getQuery()
            ->getResult();
    }
}
