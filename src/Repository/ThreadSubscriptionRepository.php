<?php

namespace App\Repository;

use App\Entity\Thread;
use App\Entity\ThreadSubscription;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ThreadSubscription>
 */
class ThreadSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ThreadSubscription::class);
    }

    public function isSubscribed(User $user, Thread $thread): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM thread_subscription WHERE user_id = ? AND thread_id = ?',
            [$user->getId(), $thread->getId()]
        );
    }

    /** Abonne le membre (idempotent, sans exception en cas de requête concurrente). */
    public function subscribe(User $user, Thread $thread): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT IGNORE INTO thread_subscription (user_id, thread_id, created_at) VALUES (?, ?, ?)',
            [$user->getId(), $thread->getId(), (new \DateTimeImmutable())->format('Y-m-d H:i:s')]
        );
    }

    public function unsubscribe(User $user, Thread $thread): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM thread_subscription WHERE user_id = ? AND thread_id = ?',
            [$user->getId(), $thread->getId()]
        );
    }

    /**
     * Abonnés d'un sujet, hors membres exclus (ex. l'auteur de la réponse).
     *
     * @param int[] $excludedIds
     * @return User[]
     */
    public function findSubscribers(Thread $thread, array $excludedIds = []): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->innerJoin(ThreadSubscription::class, 's', 'WITH', 's.user = u')
            ->where('s.thread = :thread')
            ->setParameter('thread', $thread);
        if ($excludedIds !== []) {
            $qb->andWhere('u.id NOT IN (:excluded)')->setParameter('excluded', $excludedIds);
        }

        return $qb->getQuery()->getResult();
    }
}
