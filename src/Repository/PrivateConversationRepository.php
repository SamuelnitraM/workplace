<?php

namespace App\Repository;

use App\Entity\PrivateConversation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PrivateConversation>
 */
class PrivateConversationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrivateConversation::class);
    }

    public function findBetween(User $user1, User $user2): ?PrivateConversation
    {
        return $this->createQueryBuilder('c')
            ->where('(c.participant1 = :user1 AND c.participant2 = :user2)')
            ->orWhere('(c.participant1 = :user2 AND c.participant2 = :user1)')
            ->setParameter('user1', $user1)
            ->setParameter('user2', $user2)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Conversations of a member, most recently active first, both participants loaded by the same query.
     *
     * @return PrivateConversation[]
     */
    public function findUserConversations(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('participant1', 'participant2')
            ->innerJoin('c.participant1', 'participant1')
            ->innerJoin('c.participant2', 'participant2')
            ->where('c.participant1 = :user OR c.participant2 = :user')
            ->setParameter('user', $user)
            ->orderBy('c.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
