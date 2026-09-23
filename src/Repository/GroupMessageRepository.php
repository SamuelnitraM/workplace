<?php

namespace App\Repository;

use App\Entity\GroupChannel;
use App\Entity\GroupMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GroupMessage>
 */
class GroupMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GroupMessage::class);
    }

    /**
     * Messages épinglés d'un channel, le plus récemment épinglé d'abord
     * (indépendamment des messages chargés dans le fil ; index channel_id + pinned_at).
     *
     * @return GroupMessage[]
     */
    public function findPinnedByChannel(GroupChannel $channel): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('a', 'p')
            ->join('m.author', 'a')
            ->leftJoin('m.pinnedBy', 'p')
            ->andWhere('m.channel = :channel')
            ->andWhere('m.pinnedAt IS NOT NULL')
            ->setParameter('channel', $channel)
            ->orderBy('m.pinnedAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Fil d'un channel (ordre chronologique) avec auteurs et titres chargés en une requête (pas de N+1).
     *
     * @return GroupMessage[]
     */
    public function findByChannelWithAuthors(GroupChannel $channel): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('a', 'tb')
            ->join('m.author', 'a')
            ->leftJoin('a.titleBadge', 'tb')
            ->andWhere('m.channel = :channel')
            ->setParameter('channel', $channel)
            ->orderBy('m.createdAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countPinnedByChannel(GroupChannel $channel): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.channel = :channel')
            ->andWhere('m.pinnedAt IS NOT NULL')
            ->setParameter('channel', $channel)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
