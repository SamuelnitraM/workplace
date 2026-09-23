<?php

namespace App\Service;

use App\Entity\ArmyList;
use App\Entity\Group;
use App\Entity\GroupMessage;
use App\Entity\Post;
use App\Entity\PrivateMessage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Compteurs d'activité pour le tableau de bord admin.
 * Une seule requête agrégée par entité : total + créations sur 24h / 7j / 30j (champ createdAt).
 */
class AdminStatsService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RetentionService $retentionService,
        private readonly PresenceService $presenceService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getDashboardStats(): array
    {
        $now = new \DateTimeImmutable();

        $private = $this->countByPeriod(PrivateMessage::class, $now);
        $group = $this->countByPeriod(GroupMessage::class, $now);
        $messagesTotal = ['total' => $private['total'] + $group['total']];
        foreach (RetentionService::PERIODS as $n) {
            $messagesTotal[$n] = $private[$n] + $group[$n];
        }

        return [
            'periods' => RetentionService::PERIODS,
            'users' => $this->retentionService->getUserStats($now),
            'online' => [
                'count' => $this->presenceService->countOnline($now),
                'users' => $this->presenceService->findOnline(12, $now),
                'windowSeconds' => PresenceService::ONLINE_WINDOW_SECONDS,
            ],
            'posts' => $this->countByPeriod(Post::class, $now),
            'messages' => ['private' => $private, 'group' => $group, 'sum' => $messagesTotal],
            'groups' => $this->countByPeriod(Group::class, $now),
            'armyLists' => $this->countByPeriod(ArmyList::class, $now),
        ];
    }

    /**
     * @param class-string $entity
     *
     * @return array<int|string, int> ['total' => int, 1 => int, 7 => int, 30 => int]
     */
    private function countByPeriod(string $entity, \DateTimeImmutable $now): array
    {
        $qb = $this->em->createQueryBuilder()->from($entity, 'e');
        $select = ['COUNT(e.id) AS total'];
        foreach (RetentionService::PERIODS as $n) {
            $select[] = sprintf('SUM(CASE WHEN e.createdAt >= :since%1$d THEN 1 ELSE 0 END) AS p%1$d', $n);
            $qb->setParameter('since'.$n, $now->modify(sprintf('-%d days', $n)));
        }

        $row = $qb->select(implode(', ', $select))->getQuery()->getSingleResult();

        $result = ['total' => (int) $row['total']];
        foreach (RetentionService::PERIODS as $n) {
            $result[$n] = (int) $row['p'.$n];
        }

        return $result;
    }
}
