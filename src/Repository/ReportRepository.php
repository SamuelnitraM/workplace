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
}
