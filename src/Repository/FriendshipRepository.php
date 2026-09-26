<?php

namespace App\Repository;

use Doctrine\DBAL\ParameterType;
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

    public function areFriends(User $user1, User $user2): bool
    {
        return $this->findExisting($user1, $user2)?->getStatus() === 'accepted';
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

    /**
     * Friends of a member (accepted friendships), ordered by username.
     *
     * @return User[]
     */
    public function findFriendsOf(User $user): array
    {
        $friends = array_map(
            static fn (Friendship $friendship): User => $friendship->getRequester() === $user ? $friendship->getReceiver() : $friendship->getRequester(),
            $this->findAcceptedFriends($user)
        );
        usort($friends, static fn (User $left, User $right): int => strcasecmp((string) $left->getUsername(), (string) $right->getUsername()));
        return $friends;
    }

    /**
     * Friend suggestions: friends of the member's friends, the most mutual friends first. Members already linked
     * to the member by a friendship (accepted or pending) and members blocked either way are left out.
     *
     * @return list<array{user: User, mutual: int}>
     */
    public function findSuggestions(User $user, int $limit): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT candidates.candidate_id, COUNT(*) AS mutual
             FROM (
                 SELECT CASE WHEN other.requester_id = mine.friend_id THEN other.receiver_id ELSE other.requester_id END AS candidate_id
                 FROM (
                     SELECT CASE WHEN requester_id = :member THEN receiver_id ELSE requester_id END AS friend_id
                     FROM friendship
                     WHERE status = 'accepted' AND (requester_id = :member OR receiver_id = :member)
                 ) mine
                 INNER JOIN friendship other ON other.status = 'accepted' AND (other.requester_id = mine.friend_id OR other.receiver_id = mine.friend_id)
             ) candidates
             WHERE candidates.candidate_id <> :member
               AND NOT EXISTS (
                   SELECT 1 FROM friendship existing
                   WHERE (existing.requester_id = :member AND existing.receiver_id = candidates.candidate_id)
                      OR (existing.receiver_id = :member AND existing.requester_id = candidates.candidate_id)
               )
               AND NOT EXISTS (
                   SELECT 1 FROM user_block block
                   WHERE (block.blocker_id = :member AND block.blocked_id = candidates.candidate_id)
                      OR (block.blocked_id = :member AND block.blocker_id = candidates.candidate_id)
               )
             GROUP BY candidates.candidate_id
             ORDER BY mutual DESC, candidates.candidate_id DESC
             LIMIT :limit",
            ['member' => $user->getId(), 'limit' => $limit],
            ['limit' => ParameterType::INTEGER]
        );
        if ($rows === []) {
            return [];
        }
        $users = [];
        foreach ($this->getEntityManager()->getRepository(User::class)->findBy(['id' => array_column($rows, 'candidate_id')]) as $candidate) {
            $users[$candidate->getId()] = $candidate;
        }
        $suggestions = [];
        foreach ($rows as $row) {
            if (isset($users[(int) $row['candidate_id']])) {
                $suggestions[] = ['user' => $users[(int) $row['candidate_id']], 'mutual' => (int) $row['mutual']];
            }
        }
        return $suggestions;
    }
}
