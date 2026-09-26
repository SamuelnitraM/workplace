<?php

namespace App\Repository;

use App\Entity\Report;
use App\Entity\User;
use App\Moderation\ReportTargetType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Report>
 */
class ReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Report::class);
    }

    public function countPending(): int
    {
        return $this->count(['status' => Report::STATUS_PENDING]);
    }

    public function hasPendingReportFrom(User $reporter, ReportTargetType $targetType, int $targetId): bool
    {
        return $this->count([
            'reporter' => $reporter,
            'targetType' => $targetType,
            'targetId' => $targetId,
            'status' => Report::STATUS_PENDING,
        ]) > 0;
    }

    /**
     * Pending reports of one target, oldest first: a decision closes all of them at once.
     *
     * @return Report[]
     */
    public function findPendingForTarget(ReportTargetType $targetType, int $targetId): array
    {
        return $this->findBy(
            ['targetType' => $targetType, 'targetId' => $targetId, 'status' => Report::STATUS_PENDING],
            ['createdAt' => 'ASC'],
        );
    }

    /**
     * Every report of one target, most recent first (history shown to moderators).
     *
     * @return Report[]
     */
    public function findAllForTarget(ReportTargetType $targetType, int $targetId): array
    {
        return $this->findBy(['targetType' => $targetType, 'targetId' => $targetId], ['createdAt' => 'DESC']);
    }

    /**
     * Every report about the contents of a member, most recent first (moderation history of the member).
     *
     * @return Report[]
     */
    public function findForTargetAuthor(User $author): array
    {
        return $this->findBy(['targetAuthor' => $author], ['createdAt' => 'DESC']);
    }

    /** Average time between a report and its decision, in seconds, NULL without closed report; since the given date when set. */
    public function averageHandlingSeconds(?\DateTimeImmutable $since = null): ?float
    {
        $average = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, handled_at)) FROM report WHERE status = :closed AND handled_at IS NOT NULL'
            . ($since !== null ? ' AND handled_at >= :since' : ''),
            ['closed' => Report::STATUS_CLOSED] + ($since !== null ? ['since' => $since->format('Y-m-d H:i:s')] : []),
        );
        return $average === null || $average === false ? null : (float) $average;
    }

    /**
     * Number of reports per value of a column (reason or target_type), most frequent first.
     *
     * @return array<string, int>
     */
    public function countGroupedBy(string $column): array
    {
        if (!in_array($column, ['reason', 'target_type'], true)) {
            throw new \InvalidArgumentException(sprintf('Reports cannot be grouped by "%s".', $column));
        }
        $rows = $this->getEntityManager()->getConnection()->fetchAllKeyValue(
            sprintf('SELECT %1$s, COUNT(*) AS total FROM report GROUP BY %1$s ORDER BY total DESC', $column)
        );
        return array_map('intval', $rows);
    }

    /**
     * Resolutions of every closed report (a combined decision counts each of its resolutions).
     *
     * @return array<string, int> keyed by resolution value, most frequent first
     */
    public function countResolutions(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT resolution, resolutions FROM report WHERE status = :closed AND resolution IS NOT NULL',
            ['closed' => Report::STATUS_CLOSED],
        );
        $counts = [];
        foreach ($rows as $row) {
            $values = $row['resolutions'] !== null ? json_decode($row['resolutions'], true) : [$row['resolution']];
            foreach ($values as $value) {
                $counts[$value] = ($counts[$value] ?? 0) + 1;
            }
        }
        arsort($counts);
        return $counts;
    }
}
