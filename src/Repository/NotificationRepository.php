<?php

namespace App\Repository;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notification>
 */
class NotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function countUnread(User $user): int
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.recipient = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nombre de notifications non lues pour plusieurs destinataires (une seule requête).
     *
     * @param int[] $recipientIds
     * @return array<int, int> id destinataire => nombre
     */
    public function countUnreadByRecipients(array $recipientIds): array
    {
        if (!$recipientIds) {
            return [];
        }

        $rows = $this->createQueryBuilder('n')
            ->select('IDENTITY(n.recipient) AS recipientId', 'COUNT(n.id) AS total')
            ->where('n.recipient IN (:ids)')
            ->andWhere('n.readAt IS NULL')
            ->groupBy('n.recipient')
            ->setParameter('ids', $recipientIds)
            ->getQuery()
            ->getArrayResult();

        $counts = array_fill_keys($recipientIds, 0);
        foreach ($rows as $row) {
            $counts[(int) $row['recipientId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Notifications non lues portant la même clé d'agrégation, indexées par destinataire.
     *
     * @param int[] $recipientIds
     * @return array<int, Notification>
     */
    public function findUnreadByGroupKey(array $recipientIds, string $groupKey): array
    {
        if (!$recipientIds) {
            return [];
        }

        /** @var Notification[] $notifications */
        $notifications = $this->createQueryBuilder('n')
            ->where('n.recipient IN (:ids)')
            ->andWhere('n.groupKey = :groupKey')
            ->andWhere('n.readAt IS NULL')
            ->orderBy('n.updatedAt', 'DESC')
            ->setParameter('ids', $recipientIds)
            ->setParameter('groupKey', $groupKey)
            ->getQuery()
            ->getResult();

        $byRecipient = [];
        foreach ($notifications as $notification) {
            $byRecipient[$notification->getRecipient()->getId()] ??= $notification;
        }

        return $byRecipient;
    }

    /** @return Notification[] */
    public function findRecent(User $user, int $limit): array
    {
        return $this->listQueryBuilder($user)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Requête de la liste complète (paginée par le contrôleur). */
    public function createListQuery(User $user): Query
    {
        return $this->listQueryBuilder($user)->getQuery();
    }

    public function markAllRead(User $user): int
    {
        return $this->markReadQuery($user)->getQuery()->execute();
    }

    /** @param string[] $types */
    public function markReadByTypes(User $user, array $types): int
    {
        return $this->markReadQuery($user)
            ->andWhere('n.type IN (:types)')
            ->setParameter('types', $types)
            ->getQuery()
            ->execute();
    }

    public function markReadByGroupKey(User $user, string $groupKey): int
    {
        return $this->markReadQuery($user)
            ->andWhere('n.groupKey = :groupKey')
            ->setParameter('groupKey', $groupKey)
            ->getQuery()
            ->execute();
    }

    public function markReadByGroupKeyPrefix(User $user, string $prefix): int
    {
        return $this->markReadQuery($user)
            ->andWhere('n.groupKey LIKE :prefix')
            ->setParameter('prefix', addcslashes($prefix, '%_\\') . '%')
            ->getQuery()
            ->execute();
    }

    /**
     * Somme des messages non lus par clé d'agrégation, pour un type donné et un préfixe de clé.
     *
     * @return array<string, int> groupKey => nombre d'évènements non lus
     */
    public function sumUnreadCountsByGroupKey(User $user, string $type, string $prefix = ''): array
    {
        $qb = $this->createQueryBuilder('n')
            ->select('n.groupKey AS groupKey', 'SUM(n.count) AS total')
            ->where('n.recipient = :user')
            ->andWhere('n.readAt IS NULL')
            ->andWhere('n.type = :type')
            ->andWhere('n.groupKey IS NOT NULL')
            ->groupBy('n.groupKey')
            ->setParameter('user', $user)
            ->setParameter('type', $type);

        if ($prefix !== '') {
            $qb->andWhere('n.groupKey LIKE :prefix')->setParameter('prefix', addcslashes($prefix, '%_\\') . '%');
        }

        $counts = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $counts[(string) $row['groupKey']] = (int) $row['total'];
        }

        return $counts;
    }

    /** Supprime les notifications lues avant la date donnée ; retourne le nombre de lignes supprimées. */
    public function purgeReadBefore(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('n')
            ->delete()
            ->where('n.readAt IS NOT NULL')
            ->andWhere('n.readAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    private function listQueryBuilder(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('n')
            ->addSelect('a')
            ->leftJoin('n.actor', 'a')
            ->where('n.recipient = :user')
            ->orderBy('n.updatedAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setParameter('user', $user);
    }

    private function markReadQuery(User $user): QueryBuilder
    {
        return $this->createQueryBuilder('n')
            ->update()
            ->set('n.readAt', ':now')
            ->where('n.recipient = :user')
            ->andWhere('n.readAt IS NULL')
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user);
    }
}
